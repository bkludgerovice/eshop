<?php
declare(strict_types=1);

namespace Bkl\Http;

use Bkl\Admin\AdminController;
use Bkl\App;

final class Router
{
    public function __construct(private App $app)
    {
    }

    public function dispatch(Request $req): Response
    {
        $p = $req->path;
        $pub = new PublicController($this->app);
        try {
            if ($p === '/' ) {
                return $pub->home($req);
            }
            if ($p === '/embed.js') {
                return $pub->embedJs($req);
            }
            if ($p === '/health') {
                return $pub->health($req);
            }
            if ($p === '/cron') {
                return (new CronController($this->app))->handle($req);
            }
            if ($p === '/admin' || str_starts_with($p, '/admin/')) {
                return (new AdminController($this->app))->dispatch($req, substr($p, 6) ?: '/');
            }
            if (preg_match('#^/moje/([A-Za-z0-9]{20,64})$#', $p, $m)) {
                return $pub->order($req, $m[1]);
            }
            if (preg_match('#^/([a-z0-9][a-z0-9\-]{1,99})(/startovka|/cena)?$#', $p, $m)) {
                $slug = $m[1];
                $sub = $m[2] ?? '';
                if ($sub === '/startovka') {
                    return $pub->startlist($req, $slug);
                }
                if ($sub === '/cena' && $req->isPost()) {
                    return $pub->price($req, $slug);
                }
                if ($sub === '') {
                    return $req->isPost() ? $pub->submit($req, $slug) : $pub->event($req, $slug);
                }
            }
            return $pub->notFound($req);
        } catch (\Throwable $e) {
            $this->app->log->system('chyba', 'aplikace', get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), ['path' => $p]);
            $msg = $this->app->config->isProduction() ? '' : '<pre>' . htmlspecialchars((string) $e) . '</pre>';
            return Response::html('<!doctype html><meta charset="utf-8"><title>Chyba</title><p style="font-family:sans-serif;padding:2em">Omlouváme se, nastala technická chyba. Zkuste to prosím za chvíli znovu.</p>' . $msg, 500);
        }
    }
}
