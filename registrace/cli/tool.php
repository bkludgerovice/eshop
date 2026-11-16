<?php
declare(strict_types=1);

/*
 * Pomocné příkazy (spouští se z příkazové řádky, ne z webu):
 *   php cli/tool.php key                         – vygeneruje náhodné klíče do config.php
 *   php cli/tool.php migrate                     – provede databázové změny
 *   php cli/tool.php admin "Jméno" email@x.cz    – založí hlavního administrátora (heslo se vypíše)
 *   php cli/tool.php cron                        – spustí plánované úlohy (alternativa k URL)
 *   php cli/tool.php backup                      – okamžitá záloha
 *   php cli/tool.php decrypt soubor.enc > zaloha.sql   – rozšifruje zálohu (zeptá se na heslo)
 *   php cli/tool.php restore soubor.enc NAZEV_DB --potvrzuji – obnoví zálohu do ZADANÉ databáze
 *   php cli/tool.php demo                        – vytvoří ukázkovou akci (jen pro vývoj/test)
 */

require __DIR__ . '/../vendor/autoload.php';

use Bkl\App;
use Bkl\Backup;
use Bkl\Config;
use Bkl\Db;
use Bkl\Jobs;
use Bkl\Migrator;
use Bkl\Util;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
date_default_timezone_set(Util::TZ);
$root = dirname(__DIR__);
$cmd = $argv[1] ?? 'help';

function out(string $s): void
{
    fwrite(STDOUT, $s . PHP_EOL);
}

function askSecret(string $q): string
{
    fwrite(STDERR, $q);
    $v = getenv('BKL_BACKUP_PASSPHRASE');
    if ($v !== false && $v !== '') {
        fwrite(STDERR, "(z proměnné prostředí)\n");
        return $v;
    }
    @shell_exec('stty -echo 2>/dev/null');
    $s = trim((string) fgets(STDIN));
    @shell_exec('stty echo 2>/dev/null');
    fwrite(STDERR, "\n");
    return $s;
}

switch ($cmd) {
    case 'key':
        out("app_key:    " . bin2hex(random_bytes(32)));
        out("cron_key:   " . bin2hex(random_bytes(24)));
        out("setup_key:  " . bin2hex(random_bytes(16)));
        out("passphrase: " . Util::token(28) . "   (uschovejte i mimo server!)");
        break;

    case 'migrate':
        $app = App::boot($root);
        $done = (new Migrator($app->db, $root . '/migrations'))->migrate();
        out($done ? 'Provedeno: ' . implode(', ', $done) : 'Databáze je aktuální.');
        break;

    case 'admin':
        $app = App::boot($root);
        $name = $argv[2] ?? '';
        $email = mb_strtolower($argv[3] ?? '');
        if ($name === '' || !Util::isEmail($email)) {
            out('Použití: php cli/tool.php admin "Jméno Příjmení" email@domena.cz');
            exit(1);
        }
        $pass = Util::token(14);
        $app->db->insert('admin_users', ['name' => $name, 'email' => $email, 'password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'role' => 'hlavni_admin']);
        out("Administrátor založen. Přihlášení: $email / $pass  (heslo si po přihlášení změňte)");
        break;

    case 'cron':
        $app = App::boot($root);
        out(json_encode((new Jobs($app))->runDue(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        break;

    case 'backup':
        $app = App::boot($root);
        out((new Backup($app))->run('rucni'));
        break;

    case 'decrypt':
        $file = $argv[2] ?? '';
        $pass = askSecret('Heslo k záloze: ');
        echo gzdecode(Backup::decrypt((string) file_get_contents($file), $pass));
        break;

    case 'restore':
        $file = $argv[2] ?? '';
        $dbName = $argv[3] ?? '';
        if (!is_file($file) || $dbName === '' || !in_array('--potvrzuji', $argv, true)) {
            out('Použití: php cli/tool.php restore zaloha.sql.gz.enc NAZEV_CILOVE_DB --potvrzuji');
            out('Pozor: cílová databáze bude přepsána. Doporučeno obnovit nejdřív do NOVÉ prázdné databáze a zkontrolovat.');
            exit(1);
        }
        $config = Config::load($root . '/config/config.php');
        $config = $config->with(['db' => ['name' => $dbName]]);
        $pass = askSecret('Heslo k záloze: ');
        $sql = (string) gzdecode(Backup::decrypt((string) file_get_contents($file), $pass));
        Backup::restoreInto(Db::fromConfig($config), $sql);
        out("Obnoveno do databáze $dbName. Poté spusťte stažení plateb (cron), aby se doplnily platby z doby výpadku.");
        break;

    case 'demo':
        $app = App::boot($root);
        $db = $app->db;
        if ($db->value("SELECT id FROM events WHERE slug = 'zimni-beh-2027'")) {
            out('Ukázková akce už existuje.');
            break;
        }
        $id = $db->insert('events', [
            'slug' => 'zimni-beh-2027', 'vs_code' => 1,
            'name' => Util::json(['cs' => 'Zimní běh Hlučín 2027']),
            'description' => Util::json(['cs' => "Tradiční zimní běh v Hlučíně. Tratě 10 km a 3 km, společná kapacita 170 závodníků.\nStart a cíl u … (doplní pořadatel)."]),
            'starts_at' => '2027-01-24 10:00:00', 'venue' => 'Hlučín',
            'contact_email' => 'registrace@bkludgerovice.cz', 'web_url' => 'https://www.bkludgerovice.cz',
            'reg_opens_at' => '2026-11-01 00:00:00', 'reg_closes_at' => '2027-01-22 23:59:59',
            'status' => 'otevreno', 'capacity' => 170, 'payment_due_days' => 5,
        ]);
        $r10 = $db->insert('races', ['event_id' => $id, 'name' => Util::json(['cs' => '10 km']), 'distance_km' => 10, 'sort_order' => 1, 'bib_from' => 1, 'bib_to' => 299]);
        $r3 = $db->insert('races', ['event_id' => $id, 'name' => Util::json(['cs' => '3 km']), 'distance_km' => 3, 'sort_order' => 2, 'bib_from' => 301, 'bib_to' => 499]);
        $db->insert('price_tiers', ['event_id' => $id, 'name' => 'Online', 'amount' => 17000, 'channel' => 'online']);
        $db->insert('price_tiers', ['event_id' => $id, 'name' => 'Na místě', 'amount' => 17000, 'channel' => 'na_miste']);
        $db->insert('age_discounts', ['event_id' => $id, 'name' => 'Děti do 15 let', 'age_max' => 15, 'percent' => 50]);
        $db->insert('age_discounts', ['event_id' => $id, 'name' => 'Senioři od 60 let', 'age_min' => 60, 'percent' => 50]);
        $db->insert('coupons', ['event_id' => $id, 'code' => 'CLENBKL', 'kind' => 'clensky_spolecny', 'label' => 'Členové klubu', 'percent' => 50]);
        $db->insert('charity_campaigns', ['event_id' => $id, 'purpose' => Util::json(['cs' => 'Příspěvek pro Šimona a Matěje Rýmlovy']), 'recipient' => 'Šimon a Matěj Rýmlovi', 'preset_amounts' => '50,100,200']);
        $f = $db->insert('form_fields', ['event_id' => $id, 'field_key' => 'jidlo', 'type' => 'select', 'label' => Util::json(['cs' => 'Teplé jídlo po závodě']), 'is_product' => 1, 'sort_order' => 1]);
        $db->insert('form_field_options', ['field_id' => $f, 'label' => Util::json(['cs' => 'Gulášová polévka']), 'surcharge' => 6000, 'sort_order' => 1]);
        $db->insert('form_field_options', ['field_id' => $f, 'label' => Util::json(['cs' => 'Vegetariánská polévka']), 'surcharge' => 6000, 'sort_order' => 2]);
        out("Ukázková akce vytvořena (id $id): " . $app->config->baseUrl() . '/zimni-beh-2027');
        break;

    default:
        out(file_get_contents(__FILE__, false, null, 0, 1200) ?: '');
}
