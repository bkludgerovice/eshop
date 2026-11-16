# BKL Registrace – provozní návod (verze 1a)

Návod pro správce bez programátorských znalostí. Postupujte krok za krokem.
Technické podrobnosti jsou v `docs/registrace/analyza.md`, stav vývoje v `docs/registrace/stav.md`.

---

## A. Jednorázové nastavení před spuštěním

Celkem cca 1–2 hodiny. Hesla a tokeny **nikdy neposílejte do chatu ani e-mailem** – vkládají se jen do souboru na serveru nebo do GitHubu (Secrets).

### A1. Wedos – plánované úlohy a databáze
1. Přihlaste se na **client.wedos.com** → Webhosting → **behotoulani.cz**.
2. **Doplňkové služby → CRON+ → aktivovat** (8 Kč/měs.).
3. **Databáze → vytvořit novou** – dvakrát:
   - produkční (např. popis „registrace“),
   - testovací (např. „registrace-test“).
   Wedos pošle e-mailem: server (md123.wedos.net), název databáze, uživatele a heslo. Uschovejte.
4. **PHP**: nastavení PHP na hostingu se **nemění** (platí i pro WordPress klubu a pobl.cz). Registrace běží na nastavené verzi (8.5; automaticky testováno na 8.1, 8.3 a 8.5) a samy si vypínají výpis chybových hlášek, i když je na hostingu `display_errors` zapnuté.

### A2. Fio – token jen pro čtení
1. Internetové bankovnictví Fio → Nastavení → **API** → Přidat nový token.
2. Účet 2502518589, oprávnění **„Pouze sledovat účet“**, platnost co nejdelší.
3. Token si zkopírujte – vložíte ho jen do souboru `config.php` na serveru (krok A4).
4. Token pro ples nechte beze změny – registrace mají **vlastní** token.

### A3. Google – heslo aplikace pro registrace@bkludgerovice.cz
1. Přihlaste se do Google jako **registrace@bkludgerovice.cz**.
2. Účet Google → Zabezpečení → zapněte **Dvoufázové ověření** (pokud ještě není).
3. Účet Google → Zabezpečení → **Hesla aplikací** → vytvořit (název „BKL Registrace“). Dostanete 16 znaků.
   - Pokud „Hesla aplikací“ nevidíte, správce Google Workspace je musí povolit (Admin konzole → Zabezpečení).
4. Heslo aplikace vložíte jen do `config.php` (krok A4).

### A4. Konfigurační soubor na serveru (`config.php`)
Soubor obsahuje všechna hesla. Je jen na serveru, není v GitHubu a nasazení ho nikdy nepřepíše.

1. Vymyslete (nebo nechte vygenerovat správcem hesel) tyto náhodné řetězce:
   - `app_key` – alespoň 40 náhodných znaků,
   - `cron_key` – alespoň 30 náhodných znaků (stejný uložíte i do GitHubu jako `REG_CRON_KEY`),
   - `setup_key` – 16+ znaků (jen pro založení prvního administrátora),
   - `backup.passphrase` – heslo k zálohám, 20+ znaků. **Uložte ho i mimo server** (trezor hesel, papír v klubu) – bez něj nejde zálohu obnovit.
2. Otevřete vzor `registrace/config/config.example.php`, zkopírujte obsah a vyplňte hodnoty (databáze z A1, Fio token z A2, heslo aplikace z A3).
3. Nahrajte soubor jako **`/www/domains/eshop.bkludgerovice.cz/registrace/config/config.php`**
   (FTP program, např. WinSCP nebo FileZilla, účet `w286112_eshop`; složky `registrace/config` případně vytvořte).
4. Totéž pro test: složka **`registrace-test/config/config.php`** s testovací databází, `'env' => 'test'`, `'base_url' => 'https://eshop.bkludgerovice.cz/registrace-test'`, jiným `cron_key`, a `'mail' => ['test_whitelist' => ['vas@email.cz'], 'test_redirect' => 'vas@email.cz', …]` – testovací prostředí pak nepíše skutečným lidem a nepoužívá Fio (token nechte prázdný).

### A5. GitHub – tajné údaje pro automatiku
GitHub → repozitář **eshop** → Settings → Secrets and variables → Actions → **New repository secret**:
| Název | Hodnota |
|---|---|
| `REG_CRON_KEY` | stejný `cron_key` jako v produkčním config.php |
| `REG_TEST_CRON_KEY` | `cron_key` z testovacího config.php |
| `REG_BACKUP_PASSPHRASE` | heslo k zálohám (pro denní test obnovy) |

(`FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD` už existují pro e-shop.)

### A6. První nasazení – nejdřív test
1. GitHub → **Actions** → „Registrace – nasazení (ručně)“ → **Run workflow**:
   cíl **test**, zaškrtnout **První nasazení**.
2. Po dokončení (zelená fajfka) otevřete
   `https://eshop.bkludgerovice.cz/registrace-test/admin/setup?key=VÁŠ_SETUP_KEY`
   a založte svůj administrátorský účet.
3. V administraci založte zkušební akci a vyzkoušejte registraci, export, ruční platbu.
4. Pak totéž pro **produkce** (do pole potvrzení napište `NASADIT`, při úplně prvním nasazení zaškrtněte „První nasazení“).
5. Produkční admin: `https://eshop.bkludgerovice.cz/registrace/admin/setup?key=…` (funguje jen do založení prvního účtu).

### A7. Plánovaná úloha (CRON+)
client.wedos.com → Webhosting → behotoulani.cz → **CRON** → nová úloha:
- adresa: `https://eshop.bkludgerovice.cz/registrace/cron?key=VÁŠ_CRON_KEY`
- interval: **každých 5 minut**

Úloha každých 5 min stáhne platby z Fio, odešle e-maily, pošle upomínky; jednou denně (3:00) udělá zálohu a v 7:00 pošle souhrn.
V administraci → Přehled musí za pár minut zmizet červené upozornění „Plánované úlohy zatím nikdy neběžely“.

---

## B. Založení závodu (Zimní běh)

Administrace: `https://eshop.bkludgerovice.cz/registrace/admin`

1. **Akce → + Nová akce** – název, datum a čas startu.
2. V nastavení akce vyplňte: místo, **společnou kapacitu (170)**, registrace od/do, splatnost (5 dní), co dělat po splatnosti, kontaktní e-mail, odkazy na **podmínky závodu** a **zpracování osobních údajů** (stránky na webu klubu).
3. **Tratě**: 10 km, 3 km (kapacitu tratě nechte prázdnou – platí společná kapacita).
4. **Ceny**: 170 Kč online (bez data = platí stále); případně druhá hladina „od 1. 1.“ s datem od – od toho data se automaticky použije. Volitelně hladina „na místě“.
5. **Věkové slevy**: „Děti do 15 let“ věk do 15 → 50 %; „Senioři od 60 let“ věk od 60 → 50 %.
6. **Kupony**: členský – typ „členský (společný)“, kód např. `CLEN2027`, 50 %, max. použití prázdné. Kód lze kdykoli změnit. Partnerské: „Vygenerovat 20 kódů … 100 %“.
7. **Pole formuláře**: např. „Teplé jídlo“ (výběr z možností) → možnosti s příplatkem.
8. **Charita**: zaškrtnout, účel „Příspěvek pro Šimona a Matěje Rýmlovy“, částky 50,100,200.
9. **Kategorie** (pro časomíru) – volitelné.
10. Zkontrolujte veřejnou stránku a zkušebně se zaregistrujte (pak registraci v administraci zrušte).
11. Stav akce přepněte na **Registrace otevřené** → Uložit.

### Publikace
- **Odkaz pro sdílení** (Facebook, QR kód): `https://eshop.bkludgerovice.cz/registrace/zimni-beh-2027`
- **WordPress / Elementor**: v nastavení akce → „Vložení na web“ → **Zkopírovat kód pro vložení** → v Elementoru přidejte widget **HTML** a kód vložte. Stejně lze vložit startovní listinu.
- Oba způsoby používají stejnou databázi a kapacitu.

---

## C. Běžný provoz

- **Přehled**: počty registrací, zaplaceno / čeká, vybrané peníze (startovné, produkty, charita), upozornění.
- **Platby**: platby z Fio se párují samy podle VS. Pokud někdo zadá špatný VS (začínající 8), objeví se v **Platby → Nespárované** – zadejte správný VS objednávky a „Spárovat“.
- **Registrace → klik na jméno** (detail objednávky):
  - úprava údajů, změna tratě, startovní číslo,
  - **Zaevidovat platbu** (hotovost / ručně potvrzený převod),
  - **Vrácení peněz** (jen evidence – peníze pošlete z banky ručně), e-mail závodníkovi,
  - **Zrušit objednávku**, **Znovu poslat e-mail**, úprava ceny s důvodem,
  - **Výmaz osobních údajů** na žádost (GDPR) – napsat SMAZAT.
- **Export**: Registrace → Export do Excelu → šablona „Startovka pro časomíru“ nebo vlastní výběr sloupců (lze uložit jako šablonu).
- **Denní souhrn** chodí e-mailem v 7:00 (s CSV přehledem všech registrací jako záložní kopií).
- **Kopie každého potvrzení** jde skrytě do schránky registrace@bkludgerovice.cz.
- Okamžité upozornění e-mailem přijde jen při vážném problému (Fio nefunguje 3× po sobě, záloha neproběhla, e-maily se neodesílají).

---

## D. Zálohy

| Kde | Jak často | Jak dlouho |
|---|---|---|
| Server Wedos `registrace/storage/backups` (šifrováno) | denně 3:00 + před každým nasazením | 14 dní |
| E-mail na adresu `backup.email` z config.php (šifrovaná příloha) | denně | dle schránky |
| GitHub → Actions → „Registrace – denní záloha mimo hosting“ → Artifacts | denně 5:17 | 90 dní |
| Denní souhrn s CSV (čitelný seznam registrací) | denně 7:00 | dle schránky |

GitHub každý den zálohu zároveň **zkušebně obnoví** do prázdné databáze – když se to nepovede, workflow zčervená a GitHub pošle e-mail.

### Obnova ze zálohy (jen při havárii)
**Nikdy nepřepisujte produkční databázi bez rozmyslu.** Postup:
1. Ve Wedosu vytvořte **novou prázdnou databázi**.
2. Stáhněte poslední zálohu (GitHub Actions → Artifacts, nebo z e-mailu).
3. Obnovu provede programátor / Claude Code příkazem
   `php cli/tool.php restore zaloha.sql.gz.enc NAZEV_NOVE_DB --potvrzuji` (zeptá se na heslo k záloze).
4. V `config.php` přepněte databázi na novou, spusťte v administraci **Systém → Stáhnout platby z Fio** – doplní se platby z doby výpadku (stahuje se vždy posledních 30 dní).

---

## E. Aktualizace a návrat k předchozí verzi

1. Změny se připravují na samostatné větvi v GitHubu; automatické testy běží samy.
2. Nasazení vždy **ručně**: Actions → „Registrace – nasazení (ručně)“ → nejdřív **test**, vyzkoušet, pak **produkce** + `NASADIT`.
3. Před nasazením se automaticky udělá záloha, po nasazení proběhnou databázové změny a kontrola, že běží registrace, e-shop i ples.
4. **Návrat**: spusťte nasazení z předchozí verze (v Run workflow vyberte starší větev/tag). Databáze se nevrací – změny databáze jsou vždy jen přidávací, takže nové registrace zůstanou.
5. V týdnu otevření registrací a týden před závodem nasazujte jen opravy chyb.

E-shop a ples se nasazují dál jako dosud (změna v `main` → automaticky). Jejich nasazení složku registrací nikdy nepřepisuje a naopak.

---

## F. Co dělat při výpadku

| Příznak | Co udělat |
|---|---|
| Na webu klubu se místo formuláře zobrazí „Registrační systém je momentálně nedostupný“ | Otevřete `…/registrace/health`. Pokud neodpovídá: client.wedos.com → stav služeb / odstávky; jinak kontaktujte podporu Wedos. Web klubu běží dál. |
| `health` vrací `"db": false` | Problém s databází Wedos – podpora Wedos. Data jsou v zálohách. |
| Platby se nepárují | Administrace → Systém → chyby. Nejčastěji vypršel Fio token → vytvořit nový a vložit do config.php. |
| E-maily neodcházejí | Systém → E-maily (chyba). Nejčastěji změněné heslo aplikace Google → nové do config.php → „Znovu odeslat neodeslané e-maily“. |
| Chyba po aktualizaci | Nasaďte předchozí verzi (kap. E4). |

Diagnostiku pro Claude Code: Administrace → Systém (chyby, úlohy, e-maily) – hesla ani tokeny se do záznamů neukládají.
