<?php
declare(strict_types=1);

/*
 * BKL Registrace – vstupní bod webu (eshop.bkludgerovice.cz/registrace/).
 * Vše ostatní (app/, config/, vendor/ …) je pro web zakázané v .htaccess.
 */

// Chybové hlášky nikdy nevypisovat návštěvníkům (platí jen pro registrace, nastavení hostingu se nemění).
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (PHP_VERSION_ID < 80100) {
    http_response_code(503);
    echo 'BKL Registrace vyžaduje PHP 8.1 nebo novější. Nastavte verzi PHP v administraci Wedos.';
    exit;
}

require __DIR__ . '/vendor/autoload.php';

use Bkl\App;
use Bkl\Http\Request;
use Bkl\Http\Router;

try {
    $app = App::boot(__DIR__);
} catch (\Throwable $e) {
    error_log('[BKL] start: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 300');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Registrace nedostupná</title>'
        . '<p style="font:16px/1.5 system-ui,sans-serif;padding:2em;max-width:40em">Registrační systém je momentálně nedostupný. Zkuste to prosím později.</p>';
    exit;
}

(new Router($app))->dispatch(Request::fromGlobals($app->config->basePath()))->send();
