<?php
declare(strict_types=1);

namespace Bkl\Admin;

use Bkl\App;
use Bkl\Http\Request;
use Bkl\Http\Response;
use Bkl\Jobs;
use Bkl\Util;

/**
 * Administrace. Přihlášení přes session cookie (administrace se nevkládá do iframe).
 * Role: hlavni_admin (vše), spravce_akce (akce, registrace, platby), jen_cteni (jen prohlížení).
 */
final class AdminController
{
    /** @var array<string,mixed>|null */
    public ?array $user = null;

    public function __construct(public App $app)
    {
    }

    public function dispatch(Request $req, string $path): Response
    {
        $this->startSession();
        $path = '/' . trim($path, '/');
        if ($path === '/login') {
            return $this->login($req);
        }
        if ($path === '/setup') {
            return $this->setup($req);
        }
        $this->user = $this->currentUser();
        if (!$this->user) {
            return Response::redirect($this->url('login'));
        }
        if ($req->isPost() && !hash_equals((string) ($_SESSION['csrf'] ?? ''), $req->p('csrf'))) {
            return $this->page('admin/message', ['title' => 'Formulář vypršel', 'text' => 'Bezpečnostní token formuláře vypršel. Vraťte se zpět a akci zopakujte.'], 400);
        }
        if ($req->isPost() && $this->user['role'] === 'jen_cteni' && $path !== '/logout' && $path !== '/heslo') {
            return $this->page('admin/message', ['title' => 'Nemáte oprávnění', 'text' => 'Váš účet je jen pro čtení.'], 403);
        }
        $ev = new EventAdmin($this);
        $reg = new RegAdmin($this);
        return match (true) {
            $path === '/' => $this->dashboard(),
            $path === '/logout' => $this->logout(),
            $path === '/heslo' => $this->password($req),
            $path === '/uzivatele' => $this->users($req),
            $path === '/system' => $this->system($req),
            $path === '/akce' => $ev->index($req),
            $path === '/akce/nova' => $ev->create($req),
            (bool) preg_match('#^/akce/(\d+)$#', $path, $m) => $ev->edit($req, (int) $m[1]),
            (bool) preg_match('#^/akce/(\d+)/(trat|cena|vek|kupon|kupony|pole|moznost|charita|kategorie)$#', $path, $m) => $ev->sub($req, (int) $m[1], $m[2]),
            (bool) preg_match('#^/akce/(\d+)/registrace$#', $path, $m) => $reg->list($req, (int) $m[1]),
            (bool) preg_match('#^/akce/(\d+)/export$#', $path, $m) => $reg->export($req, (int) $m[1]),
            (bool) preg_match('#^/objednavka/(\d+)$#', $path, $m) => $reg->order($req, (int) $m[1]),
            $path === '/platby' => $reg->payments($req),
            default => $this->page('admin/message', ['title' => 'Nenalezeno', 'text' => 'Stránka neexistuje.'], 404),
        };
    }

    // ---------- pomocné ----------

    public function url(string $p = ''): string
    {
        return $this->app->config->baseUrl() . '/admin/' . ltrim($p, '/');
    }

    public function can(string $what): bool
    {
        $role = (string) ($this->user['role'] ?? '');
        return match ($what) {
            'edit' => in_array($role, ['hlavni_admin', 'spravce_akce'], true),
            'users' => $role === 'hlavni_admin',
            default => false,
        };
    }

    public function actor(): string
    {
        return 'admin:' . ($this->user['email'] ?? '?');
    }

    public function flash(string $msg, string $type = 'ok'): void
    {
        $_SESSION['flash'][] = [$type, $msg];
    }

    /** @param array<string,mixed> $vars */
    public function page(string $tpl, array $vars = [], int $status = 200): Response
    {
        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        $html = $this->app->view()->render($tpl, $vars + [
            'user' => $this->user,
            'csrf' => (string) ($_SESSION['csrf'] ?? ''),
            'adm' => fn (string $p = ''): string => $this->url($p),
            'flash' => $flash,
            'canEdit' => $this->can('edit'),
            'title' => $vars['title'] ?? 'Administrace',
        ], 'admin/layout');
        return Response::html($html, $status)
            ->with('Content-Security-Policy', "frame-ancestors 'none'")
            ->with('X-Robots-Tag', 'noindex, nofollow');
    }

    public function back(string $p): Response
    {
        return Response::redirect($this->url($p));
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = str_starts_with($this->app->config->baseUrl(), 'https://');
        session_name('bkl_admin');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $this->app->config->basePath() . '/admin',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $dir = $this->app->root . '/storage/cache/sessions';
        if (is_dir($dir) || @mkdir($dir, 0700, true)) {
            session_save_path($dir);
        }
        session_start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
    }

    /** @return array<string,mixed>|null */
    private function currentUser(): ?array
    {
        $id = (int) ($_SESSION['uid'] ?? 0);
        if (!$id) {
            return null;
        }
        if (time() - (int) ($_SESSION['seen'] ?? 0) > 8 * 3600) {
            $_SESSION = [];
            return null;
        }
        $_SESSION['seen'] = time();
        $u = $this->app->db->one('SELECT * FROM admin_users WHERE id = ? AND active = 1', [$id]);
        return $u ?: null;
    }

    // ---------- přihlášení ----------

    private function login(Request $req): Response
    {
        $error = '';
        if ($req->isPost()) {
            $email = mb_strtolower(trim($req->p('email')));
            $u = $this->app->db->one('SELECT * FROM admin_users WHERE email = ? AND active = 1', [$email]);
            $sec = new \Bkl\Security($this->app->config, $this->app->db);
            if (!$sec->rateLimit('login:' . $req->ip(), 20, 900)) {
                $error = 'Příliš mnoho pokusů. Zkuste to za 15 minut.';
            } elseif ($u && $u['locked_until'] && $u['locked_until'] > Util::sqlDt(Util::now())) {
                $error = 'Účet je dočasně zablokovaný po opakovaných chybných pokusech. Zkuste to za 15 minut.';
            } elseif ($u && password_verify($req->p('password'), (string) $u['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['uid'] = (int) $u['id'];
                $_SESSION['seen'] = time();
                $_SESSION['csrf'] = bin2hex(random_bytes(16));
                $this->app->db->update('admin_users', ['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => Util::sqlDt(Util::now())], ['id' => (int) $u['id']]);
                if (password_needs_rehash((string) $u['password_hash'], PASSWORD_DEFAULT)) {
                    $this->app->db->update('admin_users', ['password_hash' => password_hash($req->p('password'), PASSWORD_DEFAULT)], ['id' => (int) $u['id']]);
                }
                $this->app->log->audit('admin', (int) $u['id'], 'prihlaseni', null, ['ip' => $req->ip()], 'admin:' . $u['email']);
                return Response::redirect($this->url());
            } else {
                if ($u) {
                    $fails = (int) $u['failed_logins'] + 1;
                    $this->app->db->update('admin_users', [
                        'failed_logins' => $fails,
                        'locked_until' => $fails >= 5 ? Util::sqlDt(Util::now()->modify('+15 minutes')) : null,
                    ], ['id' => (int) $u['id']]);
                }
                $error = 'Nesprávný e-mail nebo heslo.';
            }
        }
        return $this->page('admin/login', ['error' => $error, 'title' => 'Přihlášení']);
    }

    private function logout(): Response
    {
        $_SESSION = [];
        session_destroy();
        return Response::redirect($this->url('login'));
    }

    /** Založení prvního administrátora – jen se setup_key a jen dokud žádný admin neexistuje. */
    private function setup(Request $req): Response
    {
        $key = $this->app->config->str('setup_key');
        $given = $req->q('key') ?: $req->p('key');
        $count = (int) $this->app->db->value('SELECT COUNT(*) FROM admin_users');
        if ($count > 0 || strlen($key) < 8 || !hash_equals($key, $given)) {
            return $this->page('admin/message', ['title' => 'Nedostupné', 'text' => 'Nastavení prvního účtu už není dostupné.'], 403);
        }
        $error = '';
        if ($req->isPost()) {
            $name = Util::clean($req->p('name'), 150);
            $email = mb_strtolower(trim($req->p('email')));
            $pass = $req->p('password');
            if ($name === '' || !Util::isEmail($email) || mb_strlen($pass) < 10) {
                $error = 'Vyplňte jméno, platný e-mail a heslo (alespoň 10 znaků).';
            } else {
                $this->app->db->insert('admin_users', ['name' => $name, 'email' => $email, 'password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'role' => 'hlavni_admin']);
                return Response::redirect($this->url('login'));
            }
        }
        return $this->page('admin/setup', ['error' => $error, 'key' => $given, 'title' => 'První spuštění']);
    }

    private function password(Request $req): Response
    {
        $error = '';
        if ($req->isPost()) {
            if (!password_verify($req->p('old'), (string) $this->user['password_hash'])) {
                $error = 'Současné heslo nesouhlasí.';
            } elseif (mb_strlen($req->p('new')) < 10 || $req->p('new') !== $req->p('new2')) {
                $error = 'Nové heslo musí mít alespoň 10 znaků a obě pole se musí shodovat.';
            } else {
                $this->app->db->update('admin_users', ['password_hash' => password_hash($req->p('new'), PASSWORD_DEFAULT)], ['id' => (int) $this->user['id']]);
                $this->app->log->audit('admin', (int) $this->user['id'], 'zmena_hesla', null, null, $this->actor());
                $this->flash('Heslo bylo změněno.');
                return $this->back('');
            }
        }
        return $this->page('admin/password', ['error' => $error, 'title' => 'Změna hesla']);
    }

    // ---------- přehled ----------

    private function dashboard(): Response
    {
        $db = $this->app->db;
        $events = $db->all("SELECT * FROM events WHERE status NOT IN ('archivovana') ORDER BY starts_at DESC LIMIT 20");
        foreach ($events as &$e) {
            $id = (int) $e['id'];
            $e['stats'] = $db->one(
                "SELECT SUM(status IN ('ceka_na_platbu','potvrzena','ke_kontrole')) aktivni, SUM(status='potvrzena') zaplaceno,
                        SUM(status='ceka_na_platbu') ceka, SUM(status='ke_kontrole') kontrola FROM registrations WHERE event_id = ?",
                [$id]
            );
            $e['money'] = $db->one(
                "SELECT COALESCE(SUM(amount_paid),0) paid,
                        COALESCE(SUM(CASE WHEN payment_status IN ('zaplaceno','preplaceno') THEN amount_entry - amount_discount END),0) entry,
                        COALESCE(SUM(CASE WHEN payment_status IN ('zaplaceno','preplaceno') THEN amount_products END),0) products,
                        COALESCE(SUM(CASE WHEN payment_status IN ('zaplaceno','preplaceno') THEN amount_charity END),0) charity,
                        COALESCE(SUM(CASE WHEN payment_status IN ('nezaplaceno','castecne') THEN amount_total - amount_paid END),0) open
                 FROM orders WHERE event_id = ?",
                [$id]
            );
            $e['races_occ'] = $db->all(
                "SELECT ra.name, ra.capacity, COUNT(r.id) n FROM races ra LEFT JOIN registrations r ON r.race_id = ra.id AND r.status IN ('ceka_na_platbu','potvrzena','ke_kontrole') WHERE ra.event_id = ? GROUP BY ra.id ORDER BY ra.sort_order",
                [$id]
            );
        }
        unset($e);
        $unmatched = (int) $db->value("SELECT COUNT(*) FROM payments WHERE match_status = 'nesparovano'");
        $problems = $db->all("SELECT * FROM system_log WHERE level IN ('varovani','chyba','kriticka') ORDER BY id DESC LIMIT 10");
        $lastCron = $db->value("SELECT MAX(started_at) FROM job_runs WHERE job = 'cron'");
        $lastFio = $db->value("SELECT MAX(started_at) FROM job_runs WHERE job = 'fio' AND result = 'ok'");
        $lastBackup = $db->value("SELECT MAX(started_at) FROM job_runs WHERE job = 'zaloha' AND result = 'ok'");
        $mailErrors = (int) $db->value("SELECT COUNT(*) FROM email_queue WHERE status = 'chyba'");
        return $this->page('admin/dashboard', compact('events', 'unmatched', 'problems', 'lastCron', 'lastFio', 'lastBackup', 'mailErrors') + ['title' => 'Přehled']);
    }

    // ---------- uživatelé ----------

    private function users(Request $req): Response
    {
        if (!$this->can('users')) {
            return $this->page('admin/message', ['title' => 'Nemáte oprávnění', 'text' => 'Správu uživatelů má jen hlavní administrátor.'], 403);
        }
        $newPass = null;
        if ($req->isPost()) {
            $act = $req->p('action');
            if ($act === 'add') {
                $email = mb_strtolower(trim($req->p('email')));
                $role = in_array($req->p('role'), ['hlavni_admin', 'spravce_akce', 'jen_cteni'], true) ? $req->p('role') : 'jen_cteni';
                if (!Util::isEmail($email) || Util::clean($req->p('name'), 150) === '') {
                    $this->flash('Vyplňte jméno a platný e-mail.', 'err');
                } elseif ($this->app->db->value('SELECT id FROM admin_users WHERE email = ?', [$email])) {
                    $this->flash('Uživatel s tímto e-mailem už existuje.', 'err');
                } else {
                    $newPass = Util::token(14);
                    $id = $this->app->db->insert('admin_users', ['name' => Util::clean($req->p('name'), 150), 'email' => $email, 'password_hash' => password_hash($newPass, PASSWORD_DEFAULT), 'role' => $role]);
                    $this->app->log->audit('admin', $id, 'zalozeni', null, ['email' => $email, 'role' => $role], $this->actor());
                    $this->flash("Uživatel založen. Dočasné heslo: $newPass (předejte ho bezpečně a nechte změnit).");
                }
            } elseif ($act === 'reset' || $act === 'toggle') {
                $id = (int) $req->p('id');
                if ($id === (int) $this->user['id']) {
                    $this->flash('Vlastní účet tady měnit nelze.', 'err');
                } elseif ($act === 'reset') {
                    $newPass = Util::token(14);
                    $this->app->db->update('admin_users', ['password_hash' => password_hash($newPass, PASSWORD_DEFAULT), 'failed_logins' => 0, 'locked_until' => null], ['id' => $id]);
                    $this->app->log->audit('admin', $id, 'reset_hesla', null, null, $this->actor());
                    $this->flash("Nové dočasné heslo: $newPass");
                } else {
                    $this->app->db->run('UPDATE admin_users SET active = 1 - active WHERE id = ?', [$id]);
                    $this->app->log->audit('admin', $id, 'aktivace_zmena', null, null, $this->actor());
                    $this->flash('Změněno.');
                }
            }
            return $this->back('uzivatele');
        }
        $users = $this->app->db->all('SELECT * FROM admin_users ORDER BY active DESC, name');
        return $this->page('admin/users', ['users' => $users, 'title' => 'Uživatelé']);
    }

    // ---------- systém ----------

    private function system(Request $req): Response
    {
        if ($req->isPost() && $this->can('edit')) {
            $act = $req->p('action');
            $jobs = new Jobs($this->app);
            if ($act === 'fio') {
                $this->flash('Fio: ' . $jobs->fio(Util::now()));
            } elseif ($act === 'mail') {
                $this->app->db->run("UPDATE email_queue SET status = 'ceka', attempts = 0, next_attempt_at = NOW() WHERE status = 'chyba'");
                [$s, $f] = $this->app->mailer->flush(60);
                $this->flash("E-maily: odesláno $s, chyb $f");
            } elseif ($act === 'backup' && $this->can('users')) {
                $this->flash('Záloha: ' . (new \Bkl\Backup($this->app))->run('rucni'));
            } elseif ($act === 'cron') {
                $this->flash('Úlohy: ' . Util::json($jobs->runDue()));
            }
            return $this->back('system');
        }
        $db = $this->app->db;
        return $this->page('admin/system', [
            'title' => 'Systém',
            'logs' => $db->all('SELECT * FROM system_log ORDER BY id DESC LIMIT 100'),
            'jobs' => $db->all("SELECT * FROM job_runs WHERE job <> 'cron' ORDER BY id DESC LIMIT 40"),
            'mails' => $db->all('SELECT id, to_email, subject, status, attempts, last_error, created_at, sent_at FROM email_queue ORDER BY id DESC LIMIT 50'),
            'audit' => $db->all('SELECT * FROM audit_log ORDER BY id DESC LIMIT 60'),
            'backups' => array_map('basename', array_reverse(glob($this->app->root . '/storage/backups/*.enc') ?: [])),
            'php' => PHP_VERSION,
        ]);
    }
}
