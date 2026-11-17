<?php
declare(strict_types=1);

namespace Bkl\Http;

use Bkl\App;
use Bkl\Backup;
use Bkl\Jobs;
use Bkl\Migrator;

/**
 * /cron?key=…               – plánované úlohy (Wedos CRON+ každých 5 minut)
 * /cron?key=…&task=backup   – okamžitá záloha (před nasazením)
 * /cron?key=…&task=migrate  – databázové změny po nasazení (vždy nejdřív záloha)
 * /cron?key=…&task=latest-backup – stažení poslední šifrované zálohy (GitHub Actions)
 * /cron?key=…&task=status   – stav (verze schématu, čekající migrace)
 */
final class CronController
{
    public function __construct(private App $app)
    {
    }

    public function handle(Request $req): Response
    {
        $key = $this->app->config->str('cron_key');
        $given = $req->q('key') ?: (string) ($req->server['HTTP_X_CRON_KEY'] ?? '');
        if (strlen($key) < 20 || !hash_equals($key, $given)) {
            return Response::text('forbidden', 403);
        }
        @set_time_limit(120);
        $task = $req->q('task');
        $m = new Migrator($this->app->db, $this->app->root . '/migrations');
        switch ($task) {
            case '':
                return Response::json(['ok' => true, 'result' => (new Jobs($this->app))->runDue()]);
            case 'backup':
                return Response::json(['ok' => true, 'result' => (new Backup($this->app))->run('rucni')]);
            case 'migrate':
                $pending = $m->pending();
                // Prázdnou databázi (první nasazení) není co zálohovat.
                $hasTables = (int) $this->app->db->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()') > 0;
                $backup = $pending && $hasTables ? (new Backup($this->app))->run('pred-migraci') : null;
                $done = $m->migrate();
                if ($done) {
                    $this->app->log->audit('system', null, 'migrace', null, ['applied' => $done], 'nasazeni');
                }
                return Response::json(['ok' => true, 'backup' => $backup, 'applied' => $done]);
            case 'status':
                return Response::json(['ok' => true, 'pending' => $m->pending(), 'php' => PHP_VERSION]);
            case 'latest-backup':
                $f = (new Backup($this->app))->latestFile();
                if (!$f) {
                    return Response::text('no backup', 404);
                }
                return new Response((string) file_get_contents($f), 200, [
                    'Content-Type' => 'application/octet-stream',
                    'Content-Disposition' => 'attachment; filename="' . basename($f) . '"',
                ]);
        }
        return Response::text('unknown task', 400);
    }
}
