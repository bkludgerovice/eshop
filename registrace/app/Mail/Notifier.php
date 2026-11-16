<?php
declare(strict_types=1);

namespace Bkl\Mail;

use Bkl\Config;
use Bkl\Db;
use Bkl\Domain\EventRepo;
use Bkl\Domain\Spd;
use Bkl\Util;

/**
 * Automatické e-maily závodníkům. Text je v češtině (verze 1); šablony jsou připravené pro další jazyky.
 * Každý e-mail se zařadí do fronty s klíčem proti duplicitě a hned se zkusí odeslat.
 */
final class Notifier
{
    public function __construct(private Db $db, private EventRepo $events, private Mailer $mailer, private Config $config)
    {
    }

    public function orderCreated(int $orderId): void
    {
        $this->build('registrace', $orderId);
    }

    public function paymentReceived(int $orderId): void
    {
        $this->build('platba_prijata', $orderId);
    }

    public function reminder(int $orderId): void
    {
        $this->build('upominka', $orderId);
    }

    public function cancelled(int $orderId): void
    {
        $this->build('zruseni', $orderId);
    }

    /** Znovu poslat platební údaje / potvrzení (ruční akce z administrace). */
    public function resend(int $orderId, string $actor): void
    {
        $o = $this->db->one('SELECT payment_status FROM orders WHERE id = ?', [$orderId]);
        $type = in_array($o['payment_status'] ?? '', ['zaplaceno', 'preplaceno'], true) ? 'platba_prijata' : 'registrace';
        $this->build($type, $orderId, 'znovu-' . date('YmdHis') . '-' . substr(md5($actor), 0, 6));
    }

    private function build(string $type, int $orderId, string $suffix = ''): void
    {
        $o = $this->db->one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if (!$o) {
            return;
        }
        $e = $this->events->byId((int) $o['event_id']);
        $regs = $this->db->all(
            'SELECT r.*, ra.name race_name FROM registrations r JOIN races ra ON ra.id = r.race_id WHERE r.order_id = ? ORDER BY r.id',
            [$orderId]
        );
        if (!$e || !$regs) {
            return;
        }
        $eventName = Util::tr($e['name']);
        $manageUrl = $this->config->baseUrl() . '/moje/' . $o['public_token'];
        $unpaid = !in_array($o['payment_status'], ['zaplaceno', 'preplaceno', 'vraceno'], true) && (int) $o['amount_total'] > (int) $o['amount_paid'];
        $inline = [];

        $intro = match ($type) {
            'registrace' => $unpaid
                ? '<p>děkujeme za registraci. Místo na startu máte zarezervované – <b>registrace bude potvrzená po přijetí platby</b>.</p>'
                : '<p>děkujeme za registraci. <b>Registrace je potvrzená.</b></p>',
            'platba_prijata' => '<p>platba dorazila, děkujeme. <b>Registrace je potvrzená.</b> Těšíme se na vás na startu!</p>',
            'upominka' => '<p>připomínáme platbu startovného. Platbu zatím na účtu nevidíme. Pokud jste už zaplatili, tento e-mail prosím ignorujte.</p>',
            'zruseni' => '<p>vaše registrace byla zrušena. Pokud jde o omyl nebo jste mezitím zaplatili, odpovězte prosím na tento e-mail.</p>',
            default => '',
        };
        $subject = match ($type) {
            'registrace' => ($unpaid ? 'Registrace přijata – platební údaje: ' : 'Registrace potvrzena: ') . $eventName,
            'platba_prijata' => 'Platba přijata, registrace potvrzena: ' . $eventName,
            'upominka' => 'Připomínka platby startovného: ' . $eventName,
            'zruseni' => 'Registrace zrušena: ' . $eventName,
            default => $eventName,
        };

        $rows = '';
        foreach ($regs as $r) {
            $rows .= '<tr><td style="padding:6px 10px 6px 0;border-bottom:1px solid #e3dedc"><b>' . Util::e($r['first_name'] . ' ' . $r['last_name']) . '</b><br><span style="color:#5d5857;font-size:13px">ročník ' . substr((string) $r['birth_date'], 0, 4) . ', ' . Util::e($r['club']) . '</span></td>'
                . '<td style="padding:6px 10px;border-bottom:1px solid #e3dedc">' . Util::e(Util::tr($r['race_name'])) . '</td>'
                . '<td style="padding:6px 0;border-bottom:1px solid #e3dedc;text-align:right;white-space:nowrap">' . Util::money((int) $r['price_final']) . '</td></tr>';
        }
        if ((int) $o['amount_charity'] > 0) {
            $rows .= '<tr><td colspan="2" style="padding:6px 10px 6px 0;border-bottom:1px solid #e3dedc">Dobrovolný příspěvek' . ($e['charity'] ? ' – ' . Util::e(Util::tr($e['charity']['purpose'])) : '') . '</td><td style="padding:6px 0;border-bottom:1px solid #e3dedc;text-align:right;white-space:nowrap">' . Util::money((int) $o['amount_charity']) . '</td></tr>';
        }
        $rows .= '<tr><td colspan="2" style="padding:8px 10px 0 0"><b>Celkem</b></td><td style="padding:8px 0 0;text-align:right;white-space:nowrap"><b>' . Util::money((int) $o['amount_total']) . '</b></td></tr>';
        $summary = '<table style="border-collapse:collapse;width:100%;font-size:15px;margin:12px 0">' . $rows . '</table>';

        $pay = '';
        if ($unpaid && in_array($type, ['registrace', 'upominka'], true)) {
            $rest = (int) $o['amount_total'] - (int) $o['amount_paid'];
            $spd = Spd::payload((string) $o['pay_iban'], $rest, (string) $o['currency'], (string) $o['vs'], $eventName . ' ' . $o['vs'], $o['due_date']);
            $inline[] = ['cid' => 'qrplatba', 'name' => 'qr-platba.png', 'type' => 'image/png', 'data' => Spd::png($spd, 5)];
            $pay = '<table style="border-collapse:collapse;margin:14px 0;background:#f7f5f4;border-radius:10px"><tr>'
                . '<td style="padding:12px;vertical-align:top"><img src="cid:qrplatba" width="170" height="170" alt="QR Platba"></td>'
                . '<td style="padding:12px 14px 12px 4px;vertical-align:top;font-size:15px;line-height:1.7">'
                . '<b>Platební údaje</b><br>Účet: <b>' . Util::e($o['pay_account']) . '</b><br>Částka: <b>' . Util::money($rest) . '</b><br>'
                . 'Variabilní symbol: <b>' . Util::e($o['vs']) . '</b><br>Splatnost: <b>' . Util::fmtDate($o['due_date']) . '</b></td></tr></table>'
                . '<p style="font-size:13px;color:#5d5857">Zaplaťte prosím jednou platbou za celou přihlášku a s uvedeným variabilním symbolem – platba se pak spáruje automaticky.</p>';
        }

        $checkin = '';
        if (in_array($type, ['registrace', 'platba_prijata'], true)) {
            $checkin = '<p style="margin-top:18px"><b>QR kód pro prezenci</b> – na místě ho ukažte u registrace, urychlí to vyzvednutí startovního čísla:</p><table style="border-collapse:collapse"><tr>';
            foreach ($regs as $i => $r) {
                $cid = 'prezence' . $i;
                $inline[] = ['cid' => $cid, 'name' => 'prezence-' . $r['checkin_code'] . '.png', 'type' => 'image/png', 'data' => Spd::png('BKL:' . $r['checkin_code'], 4)];
                $checkin .= '<td style="padding:4px 14px 4px 0;text-align:center;vertical-align:top;font-size:13px"><img src="cid:' . $cid . '" width="120" height="120" alt="QR prezence"><br>' . Util::e($r['first_name'] . ' ' . $r['last_name']) . '</td>';
                if ($i % 3 === 2) {
                    $checkin .= '</tr><tr>';
                }
            }
            $checkin .= '</tr></table>';
        }

        $html = self::wrap($e, '<p>Dobrý den,</p>' . $intro . $summary . $pay . $checkin
            . '<p>Svou registraci si můžete kdykoli zobrazit zde:<br><a href="' . Util::e($manageUrl) . '" style="color:' . Util::e($e['color_primary']) . '">' . Util::e($manageUrl) . '</a></p>'
            . '<p style="font-size:13px;color:#5d5857">Jméno, ročník, klub/město a trať jsou uvedeny ve veřejné startovní listině závodu.</p>');

        $copy = $this->config->str('mail.copy_to');
        $emails = [];
        foreach ($regs as $r) {
            $emails[mb_strtolower((string) $r['email'])] = true;
        }
        $contact = mb_strtolower((string) $o['contact_email']);
        $emails = array_keys([$contact => true] + $emails);
        foreach ($emails as $idx => $to) {
            $key = $type . '-' . $orderId . '-' . substr(md5($to), 0, 10) . ($suffix ? '-' . $suffix : '');
            $this->mailer->queue(
                $key,
                $to,
                $subject,
                $html,
                $inline,
                $orderId,
                null,
                $idx === 0 && $copy !== '' ? $copy : null,
                $e['reply_to'] ?: ($e['contact_email'] ?: null),
                $e['sender_name'] ?: null
            );
        }
    }

    /** @param array<string,mixed> $e */
    public static function wrap(array $e, string $inner): string
    {
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $e['color_primary']) ? $e['color_primary'] : '#db131e';
        $when = Util::dt((string) $e['starts_at']);
        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#141414;max-width:620px">'
            . '<div style="border-top:5px solid ' . $color . ';padding-top:12px;margin-bottom:8px"><b style="font-size:19px">' . Util::e(Util::tr($e['name'])) . '</b><br>'
            . '<span style="color:#5d5857">' . ($when ? $when->format('j. n. Y, H:i') : '') . ($e['venue'] ? ', ' . Util::e($e['venue']) : '') . '</span></div>'
            . $inner
            . '<p style="color:#5d5857;font-size:13px;margin-top:24px">Běžecký klub Ludgeřovice, z. s.'
            . ($e['contact_email'] ? '<br>' . Util::e($e['contact_email']) : '')
            . ($e['web_url'] ? '<br><a href="' . Util::e($e['web_url']) . '" style="color:' . $color . '">' . Util::e($e['web_url']) . '</a>' : '')
            . '</p></div>';
    }
}
