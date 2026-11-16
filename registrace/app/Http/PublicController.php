<?php
declare(strict_types=1);

namespace Bkl\Http;

use Bkl\App;
use Bkl\Domain\Spd;
use Bkl\Security;
use Bkl\Util;
use Bkl\ValidationException;

final class PublicController
{
    private Security $sec;

    public function __construct(private App $app)
    {
        $this->sec = new Security($app->config, $app->db);
    }

    public function home(Request $req): Response
    {
        $rows = $this->app->db->all("SELECT * FROM events WHERE status IN ('otevreno','pripravovana','pozastaveno','uzavreno') ORDER BY starts_at");
        $html = $this->app->view()->render('public/home', ['events' => $rows, 'embed' => false, 'title' => 'Registrace na závody'], 'public/layout');
        return Response::html($html);
    }

    public function event(Request $req, string $slug, ?array $old = null, array $errors = [], int $status = 200): Response
    {
        $e = $this->app->events->bySlug($slug);
        if (!$e || $e['status'] === 'archivovana') {
            return $this->notFound($req);
        }
        $embed = $req->q('embed') === '1' || $req->p('embed') === '1';
        $av = $this->app->events->availability($e);
        $html = $this->app->view()->render('public/event', [
            'ev' => $e,
            'av' => $av,
            'embed' => $embed,
            'old' => $old,
            'errors' => $errors,
            'token' => $this->sec->formToken('reg-' . $e['id']),
            'title' => Util::tr($e['name']),
            'occupancy' => $this->app->events->occupancy((int) $e['id']),
        ], 'public/layout');
        return $this->framable(Response::html($html, $status), $e);
    }

    public function submit(Request $req, string $slug): Response
    {
        $e = $this->app->events->bySlug($slug);
        if (!$e) {
            return $this->notFound($req);
        }
        $post = $req->post;
        $embed = $req->p('embed') === '1';
        // Past na roboty: skryté pole musí zůstat prázdné.
        if ($req->p('website') !== '') {
            return Response::redirect($this->app->config->baseUrl() . '/' . $slug . ($embed ? '?embed=1' : ''));
        }
        $tokErr = $this->sec->checkFormToken('reg-' . $e['id'], $req->p('form_token'));
        if ($tokErr !== null) {
            return $this->event($req, $slug, $post, ['form' => $tokErr], 422);
        }
        if (!$this->sec->rateLimit('reg:' . $req->ip(), 10, 600)) {
            return $this->event($req, $slug, $post, ['form' => 'Z vašeho připojení přišlo příliš mnoho registrací. Zkuste to prosím za chvíli.'], 429);
        }
        try {
            $order = $this->app->registrations->register($e, $post, $req->ip());
        } catch (ValidationException $ex) {
            $errors = $ex->errors;
            if (!isset($errors['form'])) {
                $errors['form'] = isset($errors['event']) ? $errors['event'] : 'Formulář obsahuje chyby – zkontrolujte prosím označená pole.';
            }
            return $this->event($req, $slug, $post, $errors, 422);
        } catch (\Throwable $ex) {
            $this->app->log->system('chyba', 'registrace', 'Registraci se nepodařilo uložit: ' . $ex->getMessage(), ['event' => $slug]);
            return $this->event($req, $slug, $post, ['form' => 'Registraci se nepodařilo uložit kvůli technické chybě. Zkuste to prosím znovu, případně napište pořadateli.'], 500);
        }
        // E-mail zkusíme odeslat hned (zbytek fronty dořeší plánovaná úloha).
        try {
            $this->app->mailer->flush(10);
        } catch (\Throwable $ex) {
            $this->app->log->system('varovani', 'email', 'Okamžité odeslání selhalo: ' . $ex->getMessage());
        }
        return Response::redirect($this->app->config->baseUrl() . '/moje/' . $order['public_token'] . '?nova=1' . ($embed ? '&embed=1' : ''));
    }

    public function price(Request $req, string $slug): Response
    {
        $e = $this->app->events->bySlug($slug);
        if (!$e) {
            return Response::json(['ok' => false], 404);
        }
        if (!$this->sec->rateLimit('price:' . $req->ip(), 300, 600)) {
            return Response::json(['ok' => false], 429);
        }
        $in = $req->json() ?: $req->post;
        $p = $this->app->registrations->preview($e, $in);
        $p['ok'] = true;
        $p['fmt'] = [
            'total' => Util::money((int) $p['total']),
            'lines' => array_map(static fn ($l) => Util::money((int) $l['final']), $p['lines']),
        ];
        return Response::json($p);
    }

    public function order(Request $req, string $token): Response
    {
        $o = strlen($token) >= 20 ? $this->app->db->one('SELECT * FROM orders WHERE public_token = ?', [$token]) : null;
        if (!$o) {
            return $this->notFound($req);
        }
        $e = $this->app->events->byId((int) $o['event_id']);
        $regs = $this->app->db->all(
            'SELECT r.*, ra.name race_name FROM registrations r JOIN races ra ON ra.id = r.race_id WHERE r.order_id = ? ORDER BY r.id',
            [(int) $o['id']]
        );
        $unpaid = !in_array($o['payment_status'], ['zaplaceno', 'preplaceno', 'vraceno'], true) && (int) $o['amount_total'] > (int) $o['amount_paid']
            && array_filter($regs, static fn ($r) => in_array($r['status'], ['ceka_na_platbu', 'ke_kontrole'], true));
        $qr = null;
        if ($unpaid) {
            $rest = (int) $o['amount_total'] - (int) $o['amount_paid'];
            $qr = Spd::svg(Spd::payload((string) $o['pay_iban'], $rest, (string) $o['currency'], (string) $o['vs'], Util::tr($e['name']) . ' ' . $o['vs'], $o['due_date']));
        }
        $checkins = [];
        foreach ($regs as $r) {
            $checkins[(int) $r['id']] = Spd::svg('BKL:' . $r['checkin_code']);
        }
        $html = $this->app->view()->render('public/order', [
            'ev' => $e, 'o' => $o, 'regs' => $regs, 'unpaid' => (bool) $unpaid, 'qr' => $qr, 'checkins' => $checkins,
            'fresh' => $req->q('nova') === '1', 'embed' => $req->q('embed') === '1', 'title' => 'Moje registrace – ' . Util::tr($e['name']),
            'noindex' => true,
        ], 'public/layout');
        return $this->framable(Response::html($html), $e)->with('X-Robots-Tag', 'noindex');
    }

    public function startlist(Request $req, string $slug): Response
    {
        $e = $this->app->events->bySlug($slug);
        if (!$e || $e['status'] === 'archivovana' || (int) $e['public_list_enabled'] !== 1) {
            return $this->notFound($req);
        }
        $rows = $this->app->db->all(
            "SELECT r.first_name, r.last_name, YEAR(r.birth_date) rocnik, r.club, r.race_id, r.status, r.bib
             FROM registrations r WHERE r.event_id = ? AND r.status IN ('ceka_na_platbu','potvrzena','ke_kontrole') AND r.anonymized_at IS NULL
             ORDER BY r.last_name, r.first_name",
            [(int) $e['id']]
        );
        $html = $this->app->view()->render('public/startlist', [
            'ev' => $e, 'rows' => $rows, 'embed' => $req->q('embed') === '1', 'title' => 'Startovní listina – ' . Util::tr($e['name']),
            'occupancy' => $this->app->events->occupancy((int) $e['id']),
        ], 'public/layout');
        return $this->framable(Response::html($html), $e);
    }

    public function embedJs(Request $req): Response
    {
        $js = (string) file_get_contents($this->app->root . '/assets/embed.js');
        $js = str_replace('__APP_URL__', $this->app->config->baseUrl(), $js);
        return new Response($js, 200, ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=300']);
    }

    public function health(Request $req): Response
    {
        try {
            $this->app->db->value('SELECT 1');
        } catch (\Throwable) {
            return Response::json(['ok' => false, 'db' => false], 503);
        }
        $pending = (new \Bkl\Migrator($this->app->db, $this->app->root . '/migrations'))->pending();
        if ($pending) {
            // Databáze je dostupná, jen chybí tabulky / změny – spustí je krok „migrace“ v nasazení.
            return Response::json(['ok' => false, 'db' => true, 'schema' => false, 'pending' => count($pending)], 503);
        }
        $last = $this->app->db->value("SELECT MAX(started_at) FROM job_runs WHERE job = 'cron'");
        return Response::json(['ok' => true, 'db' => true, 'schema' => true, 'last_cron' => $last]);
    }

    public function notFound(Request $req): Response
    {
        $html = $this->app->view()->render('public/notfound', ['embed' => $req->q('embed') === '1', 'title' => 'Stránka nenalezena'], 'public/layout');
        return Response::html($html, 404);
    }

    /** Povolí vložení do iframe jen z domén nastavených u akce. @param array<string,mixed> $e */
    private function framable(Response $r, array $e): Response
    {
        $origins = array_filter(preg_split('/[\s,]+/', (string) $e['embed_origins']) ?: [], static fn ($o) => (bool) preg_match('#^https?://[a-z0-9.\-]+(:\d+)?$#i', $o));
        return $r->with('Content-Security-Policy', "frame-ancestors 'self' " . implode(' ', $origins));
    }
}
