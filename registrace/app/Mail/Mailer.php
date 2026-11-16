<?php
declare(strict_types=1);

namespace Bkl\Mail;

use Bkl\Config;
use Bkl\Db;
use Bkl\Log;
use Bkl\Util;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Fronta e-mailů. Každá zpráva má klíč proti duplicitě (např. „registrace-123“) → stejný e-mail neodejde dvakrát.
 * Odesílá se přes SMTP Google Workspace (registrace@bkludgerovice.cz). Při dočasné chybě se opakuje.
 *
 * config mail.transport: 'smtp' (provoz) | 'file' (vývoj/test – uloží .eml do storage/mail) | 'null'
 */
class Mailer
{
    public const MAX_ATTEMPTS = 6;

    public function __construct(private Db $db, private Config $config, private Log $log, private string $storageDir)
    {
    }

    /**
     * @param list<array{cid:string,name:string,type:string,data:string}> $inline data = binární obsah
     */
    public function queue(string $dedupeKey, string $to, string $subject, string $html, array $inline = [], ?int $orderId = null, ?int $registrationId = null, ?string $bcc = null, ?string $replyTo = null, ?string $senderName = null): bool
    {
        $exists = $this->db->value('SELECT id FROM email_queue WHERE dedupe_key = ?', [$dedupeKey]);
        if ($exists) {
            return false;
        }
        try {
            $this->db->insert('email_queue', [
                'dedupe_key' => mb_substr($dedupeKey, 0, 190),
                'to_email' => $to,
                'bcc' => $bcc,
                'reply_to' => $replyTo,
                'sender_name' => $senderName,
                'subject' => mb_substr($subject, 0, 255),
                'body_html' => $html,
                'attachments' => $inline ? Util::json(array_map(static fn ($a) => ['cid' => $a['cid'], 'name' => $a['name'], 'type' => $a['type'], 'data' => base64_encode($a['data'])], $inline)) : null,
                'order_id' => $orderId,
                'registration_id' => $registrationId,
            ]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                return false;
            }
            throw $e;
        }
        return true;
    }

    /** Odešle čekající zprávy. Vrací [odesláno, chyb]. @return array{0:int,1:int} */
    public function flush(int $limit = 40): array
    {
        $rows = $this->db->all(
            "SELECT * FROM email_queue WHERE status = 'ceka' AND next_attempt_at <= ? ORDER BY id LIMIT " . max(1, $limit),
            [Util::sqlDt(Util::now())]
        );
        $sent = $failed = 0;
        foreach ($rows as $m) {
            // Zámek řádku – při souběžném běhu cronu a webu se zpráva nepošle dvakrát.
            $claimed = $this->db->run(
                "UPDATE email_queue SET attempts = attempts + 1, next_attempt_at = ? WHERE id = ? AND status = 'ceka' AND attempts = ?",
                [Util::sqlDt(Util::now()->modify('+10 minutes')), (int) $m['id'], (int) $m['attempts']]
            )->rowCount();
            if ($claimed !== 1) {
                continue;
            }
            try {
                $this->send($m);
                $this->db->update('email_queue', ['status' => 'odeslano', 'sent_at' => Util::sqlDt(Util::now()), 'last_error' => null], ['id' => (int) $m['id']]);
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                $attempts = (int) $m['attempts'] + 1;
                $final = $attempts >= self::MAX_ATTEMPTS;
                $delay = [1 => 5, 2 => 15, 3 => 60, 4 => 180, 5 => 600][$attempts] ?? 600;
                $this->db->update('email_queue', [
                    'status' => $final ? 'chyba' : 'ceka',
                    'last_error' => mb_substr(Log::scrub($e->getMessage()), 0, 1000),
                    'next_attempt_at' => Util::sqlDt(Util::now()->modify("+$delay minutes")),
                ], ['id' => (int) $m['id']]);
                $this->log->system($final ? 'chyba' : 'varovani', 'email', 'E-mail na ' . $m['to_email'] . ' se nepodařilo odeslat (pokus ' . $attempts . '): ' . $e->getMessage(), ['email_id' => (int) $m['id']]);
            }
        }
        return [$sent, $failed];
    }

    /** @param array<string,mixed> $m */
    protected function send(array $m): void
    {
        $transport = $this->config->str('mail.transport', 'file');
        $to = (string) $m['to_email'];
        $bcc = $m['bcc'] ? (string) $m['bcc'] : null;
        // Testovací prostředí nesmí psát skutečným závodníkům.
        if (!$this->config->isProduction()) {
            $allowed = (array) $this->config->get('mail.test_whitelist', []);
            $redirect = $this->config->str('mail.test_redirect');
            if (!in_array(mb_strtolower($to), array_map('mb_strtolower', $allowed), true)) {
                if ($redirect === '') {
                    throw new \RuntimeException('Testovací prostředí: příjemce není na seznamu povolených adres.');
                }
                $m['subject'] = '[TEST pro ' . $to . '] ' . $m['subject'];
                $to = $redirect;
            }
            $bcc = null;
        }
        if ($transport === 'null') {
            return;
        }
        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $from = $this->config->str('mail.from', 'registrace@bkludgerovice.cz');
        $mail->setFrom($from, (string) ($m['sender_name'] ?: $this->config->str('mail.from_name', 'Běžecký klub Ludgeřovice – Registrace')));
        $mail->addAddress($to);
        if ($bcc) {
            $mail->addBCC($bcc);
        }
        $mail->addReplyTo((string) ($m['reply_to'] ?: $this->config->str('mail.reply_to', $from)));
        $mail->Subject = (string) $m['subject'];
        $mail->isHTML(true);
        $mail->Body = (string) $m['body_html'];
        $mail->AltBody = self::toText((string) $m['body_html']);
        foreach ((array) json_decode((string) ($m['attachments'] ?? '[]'), true) as $a) {
            $mail->addStringEmbeddedImage((string) base64_decode((string) $a['data']), (string) $a['cid'], (string) $a['name'], PHPMailer::ENCODING_BASE64, (string) $a['type']);
        }
        foreach ((array) ($m['_files'] ?? []) as $name => $content) {
            $mail->addStringAttachment((string) $content, (string) $name);
        }
        if ($transport === 'file') {
            $mail->preSend();
            @mkdir($this->storageDir, 0775, true);
            file_put_contents(rtrim($this->storageDir, '/') . '/' . date('Ymd-His') . '-' . $m['id'] . '.eml', $mail->getSentMIMEMessage());
            return;
        }
        $mail->isSMTP();
        $mail->Host = $this->config->str('mail.smtp_host', 'smtp.gmail.com');
        $mail->Port = (int) $this->config->get('mail.smtp_port', 587);
        $mail->SMTPAuth = true;
        $mail->Username = $this->config->str('mail.smtp_user');
        $mail->Password = $this->config->str('mail.smtp_password');
        $mail->SMTPSecure = $mail->Port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout = 20;
        $mail->send();
    }

    /**
     * Okamžité odeslání mimo frontu (upozornění správci, zálohy). Přílohy: název => obsah.
     * @param array<string,string> $attachments
     */
    public function sendNow(string $to, string $subject, string $html, array $attachments = []): void
    {
        $m = ['id' => 'x' . bin2hex(random_bytes(3)), 'to_email' => $to, 'bcc' => null, 'reply_to' => null, 'sender_name' => null,
            'subject' => $subject, 'body_html' => $html, 'attachments' => null, '_files' => $attachments];
        $this->send($m);
    }

    public static function toText(string $html): string
    {
        $t = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $t = preg_replace('#</(p|tr|h\d|div|li)>#i', "\n", $t) ?? $t;
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8');
        $t = preg_replace("/[ \t]+/", ' ', $t) ?? $t;
        return trim(preg_replace("/\n\s+/", "\n", $t) ?? $t);
    }
}
