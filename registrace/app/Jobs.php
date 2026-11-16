<?php
declare(strict_types=1);

namespace Bkl;

use Bkl\Domain\FioClient;
use Bkl\Domain\FioThrottled;

/**
 * Plánované úlohy. Stačí jedna úloha CRON+ na Wedosu každých 5 minut volající /registrace/cron?key=…
 * Každá podúloha si sama hlídá, kdy má běžet.
 */
final class Jobs
{
    /** @var callable(string):FioClient */
    private $fioFactory;

    public function __construct(private App $app, ?callable $fioFactory = null)
    {
        $this->fioFactory = $fioFactory ?? static fn (string $token): FioClient => new FioClient($token);
    }

    /** @return array<string,string> výsledky podúloh */
    public function runDue(?\DateTimeImmutable $now = null): array
    {
        $now ??= Util::now();
        $lock = $this->app->db->value("SELECT GET_LOCK('bkl_cron', 1)");
        if ((int) $lock !== 1) {
            return ['cron' => 'už běží'];
        }
        $out = [];
        $runId = $this->app->db->insert('job_runs', ['job' => 'cron', 'started_at' => Util::sqlDt($now)]);
        try {
            $out['fio'] = $this->safe('fio', fn () => $this->fio($now));
            $out['email'] = $this->safe('email', function (): string {
                [$s, $f] = $this->app->mailer->flush(60);
                return "odesláno $s, chyb $f";
            });
            $out['upominky'] = $this->safe('upominky', fn () => $this->overdue($now));
            if ($this->dueDaily('backup', $now, 3)) {
                $out['zaloha'] = $this->safe('zaloha', fn () => (new Backup($this->app))->run());
            }
            if ($this->dueDaily('summary', $now, 7)) {
                $out['souhrn'] = $this->safe('souhrn', fn () => $this->dailySummary($now));
            }
            $out['hlidani'] = $this->safe('hlidani', fn () => $this->watchdog($now));
            $this->app->db->update('job_runs', ['finished_at' => Util::sqlDt(Util::now()), 'result' => 'ok', 'message' => mb_substr(Util::json($out), 0, 1000)], ['id' => $runId]);
        } finally {
            $this->app->db->value("SELECT RELEASE_LOCK('bkl_cron')");
        }
        return $out;
    }

    private function safe(string $name, callable $fn): string
    {
        $started = Util::now();
        try {
            $msg = (string) $fn();
            $this->app->db->insert('job_runs', ['job' => $name, 'started_at' => Util::sqlDt($started), 'finished_at' => Util::sqlDt(Util::now()), 'result' => 'ok', 'message' => mb_substr($msg, 0, 1000)]);
            return $msg;
        } catch (\Throwable $e) {
            $this->app->db->insert('job_runs', ['job' => $name, 'started_at' => Util::sqlDt($started), 'finished_at' => Util::sqlDt(Util::now()), 'result' => 'chyba', 'message' => mb_substr(Log::scrub($e->getMessage()), 0, 1000)]);
            $this->app->log->system('chyba', $name, $e->getMessage());
            return 'CHYBA: ' . Log::scrub($e->getMessage());
        }
    }

    /** Má denní úloha běžet? (jednou denně, nejdřív v danou hodinu) */
    private function dueDaily(string $job, \DateTimeImmutable $now, int $hour): bool
    {
        if ((int) $now->format('G') < $hour) {
            return false;
        }
        $last = $this->app->db->value("SELECT MAX(started_at) FROM job_runs WHERE job = ? AND result = 'ok'", [$job === 'backup' ? 'zaloha' : 'souhrn']);
        return !$last || substr((string) $last, 0, 10) !== $now->format('Y-m-d');
    }

    /** Stažení plateb ze všech účtů s Fio tokenem. */
    public function fio(\DateTimeImmutable $now): string
    {
        $accounts = $this->app->db->all('SELECT * FROM bank_accounts WHERE active = 1 AND fio_token_key IS NOT NULL');
        $msgs = [];
        foreach ($accounts as $acc) {
            $token = $this->app->config->str('fio.' . $acc['fio_token_key']);
            if ($token === '') {
                $msgs[] = $acc['name'] . ': token není nastaven';
                continue;
            }
            $key = 'fio_last_' . $acc['id'];
            $last = (int) $this->app->db->value('SELECT value FROM settings WHERE name = ?', [$key]);
            if (time() - $last < 35) {
                $msgs[] = $acc['name'] . ': přeskočeno (limit 30 s)';
                continue;
            }
            $this->app->db->run('REPLACE INTO settings (name, value) VALUES (?, ?)', [$key, (string) time()]);
            try {
                $tx = ($this->fioFactory)($token)->transactions($now->modify('-30 days'), $now);
            } catch (FioThrottled $e) {
                $msgs[] = $acc['name'] . ': ' . $e->getMessage();
                continue;
            } catch (\Throwable $e) {
                $this->fioFailure($acc, $e);
                throw $e;
            }
            $this->app->db->run('REPLACE INTO settings (name, value) VALUES (?, ?)', ['fio_ok_' . $acc['id'], Util::sqlDt($now)]);
            $this->app->db->run('REPLACE INTO settings (name, value) VALUES (?, 0)', ['fio_fail_' . $acc['id']]);
            $new = $matched = 0;
            foreach ($tx as $t) {
                $r = $this->app->payments->recordBankTransaction($t, (int) $acc['id']);
                if ($r['new']) {
                    $new++;
                    if ($r['status'] === 'sparovano') {
                        $matched++;
                    }
                }
            }
            $msgs[] = $acc['name'] . ": pohybů " . count($tx) . ", nových plateb registrací $new, spárováno $matched";
        }
        if ($matched ?? 0) {
            $this->app->mailer->flush(30);
        }
        return implode('; ', $msgs) ?: 'žádný účet s tokenem';
    }

    /** @param array<string,mixed> $acc */
    private function fioFailure(array $acc, \Throwable $e): void
    {
        $k = 'fio_fail_' . $acc['id'];
        $n = (int) $this->app->db->value('SELECT value FROM settings WHERE name = ?', [$k]) + 1;
        $this->app->db->run('REPLACE INTO settings (name, value) VALUES (?, ?)', [$k, (string) $n]);
        if ($n === 3) {
            $this->alert('Párování plateb Fio nefunguje', 'Stažení plateb z Fio API selhalo třikrát po sobě (účet ' . $acc['name'] . '): ' . Log::scrub($e->getMessage()) . '. Zkontrolujte token v config.php a platnost tokenu v internetovém bankovnictví Fio.');
        }
    }

    /** Upomínky a zrušení po splatnosti podle nastavení akce. */
    public function overdue(\DateTimeImmutable $now): string
    {
        $today = $now->format('Y-m-d');
        $orders = $this->app->db->all(
            "SELECT o.*, e.reminder_days_before_due, e.overdue_action, e.overdue_grace_days
             FROM orders o JOIN events e ON e.id = o.event_id
             WHERE o.payment_status IN ('nezaplaceno') AND o.due_date IS NOT NULL
               AND EXISTS (SELECT 1 FROM registrations r WHERE r.order_id = o.id AND r.status = 'ceka_na_platbu')
               AND e.status NOT IN ('ukoncena','archivovana')"
        );
        $rem = $canc = $flag = 0;
        foreach ($orders as $o) {
            $due = new \DateTimeImmutable((string) $o['due_date'], Util::tz());
            $remindFrom = $due->modify('-' . (int) $o['reminder_days_before_due'] . ' days')->format('Y-m-d');
            $cancelAfter = $due->modify('+' . (int) $o['overdue_grace_days'] . ' days')->format('Y-m-d');
            if ($today > $cancelAfter) {
                if ($o['overdue_action'] === 'zrusit') {
                    foreach ($this->app->db->all("SELECT * FROM registrations WHERE order_id = ? AND status = 'ceka_na_platbu'", [(int) $o['id']]) as $r) {
                        $this->app->payments->setStatus($r, 'expirovana', 'system (nezaplaceno po splatnosti)');
                    }
                    $this->app->notifier->cancelled((int) $o['id']);
                    $canc++;
                } else {
                    $done = $this->app->db->value("SELECT COUNT(*) FROM audit_log WHERE entity = 'order' AND entity_id = ? AND action = 'po_splatnosti'", [(int) $o['id']]);
                    if (!(int) $done) {
                        $this->app->log->audit('order', (int) $o['id'], 'po_splatnosti', null, ['due' => $o['due_date']], 'system');
                        $this->app->log->system('varovani', 'platby', 'Objednávka ' . $o['vs'] . ' (' . $o['contact_name'] . ') je po splatnosti – rozhodněte o zrušení.', ['order_id' => (int) $o['id']]);
                        $flag++;
                    }
                }
            } elseif ($today >= $remindFrom && !$o['reminded_at'] && (int) $o['reminder_days_before_due'] > 0) {
                $this->app->notifier->reminder((int) $o['id']);
                $this->app->db->update('orders', ['reminded_at' => Util::sqlDt($now)], ['id' => (int) $o['id']]);
                $rem++;
            }
        }
        return "upomínek $rem, zrušeno $canc, k rozhodnutí $flag";
    }

    /** Denní souhrn správci (registrace za 24 h, platby, problémy) + CSV přehled jako druhá stopa. */
    public function dailySummary(\DateTimeImmutable $now): string
    {
        $to = $this->app->config->str('alerts.admin_email');
        if ($to === '') {
            return 'admin e-mail není nastaven';
        }
        $since = Util::sqlDt($now->modify('-1 day'));
        $events = $this->app->db->all("SELECT * FROM events WHERE status IN ('otevreno','pozastaveno','uzavreno')");
        if (!$events) {
            return 'žádná aktivní akce';
        }
        $html = '<p>Dobrý den, souhrn registrací za posledních 24 hodin:</p>';
        $csv = "akce;vs;prijmeni;jmeno;rocnik;trat;stav;platba;cena;email;telefon\n";
        foreach ($events as $e) {
            $id = (int) $e['id'];
            $c = $this->app->db->one(
                "SELECT SUM(status IN ('ceka_na_platbu','potvrzena','ke_kontrole')) aktivni, SUM(status='potvrzena') zaplaceno,
                        SUM(status='ceka_na_platbu') ceka, SUM(created_at >= ?) nove FROM registrations WHERE event_id = ?",
                [$since, $id]
            );
            $money = $this->app->db->one("SELECT COALESCE(SUM(amount_paid),0) paid, COALESCE(SUM(CASE WHEN payment_status IN ('zaplaceno','preplaceno') THEN amount_charity END),0) charity FROM orders WHERE event_id = ?", [$id]);
            $html .= '<h3>' . Util::e(Util::tr($e['name'])) . '</h3><ul>'
                . '<li>Nové registrace za 24 h: <b>' . (int) $c['nove'] . '</b></li>'
                . '<li>Přihlášeno celkem: <b>' . (int) $c['aktivni'] . '</b>' . ($e['capacity'] !== null ? ' z ' . (int) $e['capacity'] : '') . ' (zaplaceno ' . (int) $c['zaplaceno'] . ', čeká ' . (int) $c['ceka'] . ')</li>'
                . '<li>Přijato celkem: <b>' . Util::money((int) $money['paid']) . '</b>, z toho charita ' . Util::money((int) $money['charity']) . '</li></ul>';
            foreach ($this->app->db->all(
                'SELECT o.vs, r.*, ra.name race_name, o.payment_status FROM registrations r JOIN orders o ON o.id = r.order_id JOIN races ra ON ra.id = r.race_id WHERE r.event_id = ? ORDER BY r.last_name',
                [$id]
            ) as $r) {
                $csv .= implode(';', array_map(static fn ($v) => str_replace([';', "\n"], ',', (string) $v), [
                    $e['slug'], $r['vs'], $r['last_name'], $r['first_name'], substr((string) $r['birth_date'], 0, 4), Util::tr($r['race_name']),
                    $r['status'], $r['payment_status'], number_format((int) $r['price_final'] / 100, 2, ',', ''), $r['email'], $r['phone'],
                ])) . "\n";
            }
        }
        $problems = $this->app->db->all("SELECT * FROM system_log WHERE level IN ('varovani','chyba','kriticka') AND created_at >= ? ORDER BY id DESC LIMIT 30", [$since]);
        if ($problems) {
            $html .= '<h3>Upozornění a chyby</h3><ul>';
            foreach ($problems as $p) {
                $html .= '<li>' . Util::e(Util::fmtDateTime($p['created_at']) . ' – ' . $p['message']) . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '<p><a href="' . Util::e($this->app->config->baseUrl() . '/admin') . '">Otevřít administraci</a></p><p style="color:#5d5857;font-size:13px">V příloze je kompletní seznam registrací (CSV) jako záložní kopie.</p>';
        $this->sendDirect($to, 'BKL Registrace – denní souhrn ' . $now->format('j. n. Y'), $html, ['registrace-' . $now->format('Y-m-d') . '.csv' => "\xEF\xBB\xBF" . $csv]);
        return 'odesláno';
    }

    /** Hlídání: neběžící záloha, chyby e-mailů. */
    private function watchdog(\DateTimeImmutable $now): string
    {
        $issues = [];
        $lastBackup = $this->app->db->value("SELECT MAX(started_at) FROM job_runs WHERE job = 'zaloha' AND result = 'ok'");
        if ($lastBackup && Util::dt((string) $lastBackup) < $now->modify('-36 hours')) {
            $issues[] = 'Záloha databáze neproběhla déle než 36 hodin.';
        }
        $failedMails = (int) $this->app->db->value("SELECT COUNT(*) FROM email_queue WHERE status = 'chyba' AND created_at >= ?", [Util::sqlDt($now->modify('-1 day'))]);
        if ($failedMails >= 3) {
            $issues[] = "Za posledních 24 hodin se nepodařilo odeslat $failedMails e-mailů – zkontrolujte nastavení SMTP.";
        }
        foreach ($issues as $i) {
            $k = 'alert_' . md5($i);
            $was = $this->app->db->value('SELECT value FROM settings WHERE name = ?', [$k]);
            if (!$was || $was !== $now->format('Y-m-d')) {
                $this->app->db->run('REPLACE INTO settings (name, value) VALUES (?, ?)', [$k, $now->format('Y-m-d')]);
                $this->alert('Problém v registracích', $i);
            }
        }
        return $issues ? implode(' ', $issues) : 'v pořádku';
    }

    /** Okamžité upozornění správci (mimo frontu, aby fungovalo i při problému s frontou). */
    public function alert(string $subject, string $text): void
    {
        $this->app->log->system('kriticka', 'upozorneni', $subject . ': ' . $text);
        $to = $this->app->config->str('alerts.admin_email');
        if ($to === '') {
            return;
        }
        try {
            $this->sendDirect($to, 'POZOR – ' . $subject, '<p>' . Util::e($text) . '</p><p><a href="' . Util::e($this->app->config->baseUrl() . '/admin') . '">Administrace</a></p>');
        } catch (\Throwable $e) {
            error_log('[BKL] alert failed: ' . $e->getMessage());
        }
    }

    /** @param array<string,string> $attachments název => obsah */
    public function sendDirect(string $to, string $subject, string $html, array $attachments = []): void
    {
        $this->app->mailer->sendNow($to, $subject, $html, $attachments);
    }
}
