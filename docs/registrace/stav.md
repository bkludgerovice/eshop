# BKL Registrace – stav vývoje

Dokument pro navázání v nové relaci Claude Code. Aktualizovat po každé etapě.

## Stav k 16. 11. 2026 – verze 1a (kód hotový, nenasazeno)

Větev: `claude/happy-newton-6erwk1`. Kód: `registrace/`. Nic není nasazeno na Wedos.

### Hotovo (1a)
- PHP 8.1+ aplikace bez frameworku (`registrace/app`), MariaDB, Composer (PHPMailer, chillerlan/php-qrcode, openspout).
- Databáze pro celé zadání: `registrace/migrations/001_init.sql` (V2 tabulky připravené: stripe_events, email_campaigns, měny, jazyky v JSON).
- Veřejná část: formulář (více závodníků, datum narození, souhlas rodiče < 18 let, vlastní pole/produkty, charita, kupony, živý výpočet ceny), stránka „moje registrace“ s QR platbou (SPD) a QR pro prezenci, veřejná startovka s hledáním a filtrem, vložení do WordPressu (`embed.js` + postMessage výška + náhradní hlášení při výpadku, CSP frame-ancestors).
- Ceny: hladiny podle data a kanálu, věkové slevy podle ročníku, kupony (členský společný, jednorázové generované), nesčítání slev, zmrazená cena.
- Kapacita: zámek akce `SELECT … FOR UPDATE`, test souběhu 4 procesů.
- VS `8RRAAPPPP` (ples používá `2701…`).
- Fio: stažení za 30 dní, unikátní ID pohybu, párování, nedoplatek/přeplatek, nespárované, pozdní platba (obnovit / ke kontrole), limit 30 s.
- E-maily: fronta s ochranou proti duplicitě, opakování, SMTP Google (registrace@), skrytá kopie každého potvrzení, testovací prostředí přesměrovává.
- Plánované úlohy (`/cron?key=` nebo `cli/tool.php cron`): Fio, e-maily, upomínky + zrušení po splatnosti (dle akce), denní záloha, denní souhrn s CSV, hlídání.
- Zálohy: šifrovaný výpis (libsodium), server 14 dní, e-mail, GitHub Actions artefakt 90 dní + denní test obnovy (`cli/restore-check.php`).
- Administrace: přihlášení (zámek po 5 chybách), role, CSRF, přehled, akce (nastavení, tratě, ceny, věkové slevy, kupony, pole + možnosti, charita, kategorie, kód pro vložení), registrace (filtr, hledání), detail objednávky (úpravy, stav, ruční platba, vrácení, storno, cena, znovu poslat, poznámka, výmaz GDPR, historie, e-maily), platby (nespárované), export XLSX s výběrem sloupců a šablonami, uživatelé, systém (logy, úlohy, e-maily, audit).
- Testy: `vendor/bin/phpunit` – 37 testů (jednotkové + proti MariaDB). CI: `.github/workflows/registrace-testy.yml` na PHP 8.1, 8.3, 8.5.
- Hosting: PHP na Wedosu se nemění (předseda nechce zasahovat; platí i pro WordPress) – je tam 8.5 a `display_errors` zapnuto. Aplikace si výpis chyb vypíná sama (`index.php`, `App::installErrorHandler`: deprecated jen do logu, ostatní varování → výjimka → obecná chybová stránka). Composer `platform-check` vypnut.
- Nasazení: `.github/workflows/registrace-nasazeni.yml` (ručně, test/produkce, záloha → FTP → migrace → kontrola). `deploy.yml` e-shopu nyní vynechává `docs/`, `registrace/`, `registrace-test/`.

### Zbývá na 1b (do ~10. 1. 2027)
- Prezence na místě: PIN přístup pomocníků, QR čtečka (kód `BKL:<checkin_code>`), našeptávač podle 3 písmen příjmení, přidělení čísla z hromádky s kontrolou duplicity (DB už má unikátní `(event_id, bib)`), registrace na místě s hotovostí (kanál `na_miste`, cenová hladina na místě už funguje).
- Hromadné přidělení startovních čísel (řady `bib_from/bib_to` u tratí jsou v DB).
- Převody registrací (samoobslužně do `transfer_deadline`, pak žádost) – tabulka `transfer_requests` připravena.
- Editor e-mailových šablon (tabulka `email_templates`; nyní texty v `app/Mail/Notifier.php`).
- Zátěžový test, generálka prezence.

### Verze 2 (Ludgeřovická 15)
Stripe (karta, BLIK) s webhooky, PLN/EUR, platební metody podle země, polština a angličtina, hromadné e-maily, více účtů pro charitu, role po akcích, statistiky. Zvážit tarif Extra.

### Odchylky od analýzy
- Záloha na Google Disk není – místo ní GitHub Actions archiv (90 dní) s denním testem obnovy + e-mail. Google Disk lze doplnit (servisní účet).
- Texty e-mailů zatím v kódu (editor v 1b).

### Otevřené otázky na předsedu
- Texty podmínek závodu a GDPR (odkazy se zadávají u akce).
- Sleva i na produkty? (výchozí: ne; přepínač u akce).
- Kategorie a rozsahy startovních čísel Zimního běhu.
- Druhá cenová hladina po 31. 12. / na místě?

## Vývoj lokálně
```
cd registrace && composer install
cp config/config.example.php config/config.php   # vyplnit dev DB, 'mail' => ['transport' => 'file']
php cli/tool.php migrate && php cli/tool.php demo && php cli/tool.php admin "Jméno" email@x.cz
php -S 127.0.0.1:8080 -t .. cli/devserver.php     # http://127.0.0.1:8080/registrace/
vendor/bin/phpunit
```
