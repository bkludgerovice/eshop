<?php
/*
 * VZOR konfigurace BKL Registrace.
 * Na serveru zkopírujte jako config/config.php a vyplňte. Soubor config.php NIKDY nedávejte do GitHubu ani do chatu.
 * (Je uveden v .gitignore a nasazení ho nepřepisuje.)
 */
return [
    'env' => 'prod',                       // 'prod' = ostrý provoz, 'test' = testovací prostředí (e-maily jen na povolené adresy)
    'base_url' => 'https://eshop.bkludgerovice.cz/registrace',

    // Náhodný tajný klíč (min. 32 znaků) – podpis formulářů. Vygenerujte: php cli/tool.php key
    'app_key' => '',
    // Tajný klíč pro spouštění plánovaných úloh: https://…/registrace/cron?key=…
    'cron_key' => '',

    'db' => [
        'host' => 'md123.wedos.net',
        'port' => 3306,
        'name' => 'd286112_xxxx',
        'user' => 'a286112_xxxx',
        'password' => '',
    ],

    'mail' => [
        'transport' => 'smtp',             // smtp | file (uloží do storage/mail) | null
        'from' => 'registrace@bkludgerovice.cz',
        'from_name' => 'Běžecký klub Ludgeřovice – Registrace',
        'reply_to' => 'registrace@bkludgerovice.cz',
        'copy_to' => 'registrace@bkludgerovice.cz',   // skrytá kopie každého potvrzení (druhá stopa)
        'smtp_host' => 'smtp.gmail.com',
        'smtp_port' => 587,
        'smtp_user' => 'registrace@bkludgerovice.cz',
        'smtp_password' => '',             // heslo aplikace Google (16 znaků)
        'test_whitelist' => [],            // jen pro env=test
        'test_redirect' => '',             // jen pro env=test: kam přesměrovat ostatní e-maily
    ],

    // Fio API tokeny „jen pro sledování“. Klíč (např. 'klub') je uveden u bankovního účtu v administraci.
    'fio' => [
        'klub' => '',
    ],

    'alerts' => [
        'admin_email' => 'david.bibrlik@bkludgerovice.cz',  // denní souhrn a vážné chyby
    ],

    'backup' => [
        'email' => '',                     // kam posílat denní šifrovanou zálohu (např. samostatná schránka)
        'passphrase' => '',                // heslo k šifrování záloh – uschovejte ho i mimo server!
        'keep_days' => 14,
    ],

    // Setup prvního administrátora: /registrace/admin/setup?key=… (funguje jen dokud žádný admin neexistuje)
    'setup_key' => '',
];
