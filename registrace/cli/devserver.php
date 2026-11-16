<?php
// Vývojový server: php -S 127.0.0.1:8080 -t .. registrace/cli/devserver.php  (napodobuje .htaccess)
$uri = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!str_starts_with($uri, '/registrace')) {
    return false; // e-shop, ples, logo – statické soubory
}
$rel = substr($uri, strlen('/registrace'));
if (str_starts_with($rel, '/assets/') && is_file(__DIR__ . '/..' . $rel)) {
    return false;
}
require __DIR__ . '/../index.php';
