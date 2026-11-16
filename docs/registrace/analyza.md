# BKL Registrace 3.0 – Fáze 1: technická analýza

Stav k 16. 11. 2026 · podklady: zadání „BKL Registrace 3.0“ (16. 10. 2026) a „Doplněk k zadání“ (15. 11. 2026). Kde se liší, platí doplněk.
Doplněno 16. 11. 2026 o rozbor kódu Apps Scriptů e-shopu a plesu.
Tento dokument je jen analýza – nic se neprogramuje ani nenasazuje.

## 1. Shrnutí v jednom odstavci
Doporučuji postavit registrace jako PHP aplikaci s databází MariaDB přímo na stávajícím hostingu Wedos, ve složce `/registrace/` vedle e-shopu a plesu. Stávající e-shop a ples se tím nezmění (jsou to statické stránky napojené na Google Apps Script). Je potřeba ověřit tarif Wedos a dokoupit doplněk **CRON+** (plánované úlohy každých 5 minut pro párování plateb z Fio). Provozní náklad zůstane v řádu desítek až nízkých stovek Kč měsíčně – pod hranicí 300 Kč. Verze 1 pro Zimní běh je na dva týdny velmi napjatá, proto ji navrhuji rozdělit na **1a – spuštění registrací** (do ~30. 11.) a **1b – provoz před závodem** (do ~10. 1. 2027).

## 2. Jak funguje stávající aplikace eshop.bkludgerovice.cz

| Část | Soubor | Co dělá |
|---|---|---|
| E-shop oblečení | `index.html` | katalog, košík, objednávka, „mám zájem“; žádná online platba, vyzvednutí v klubu |
| Rezervace plesu | `ples/index.html` | plánek sálu, rezervace míst, QR platba (formát SPD), variabilní symbol |
| Vstup na ples | `ples/vstup.html` | kontrola vstupenky a odbavení s PINem |
| Ostatní | `logo.png`, `fotky/`, `.htaccess` | obrázky; `.htaccess` vypíná cache HTML |

**Technologie:** čisté HTML + JavaScript bez serverové části. V repozitáři není PHP ani databáze.

**„Databáze“ a logika:** dvě Google Apps Script aplikace (adresy `script.google.com/macros/s/…/exec`) nad Google Sheety – jedna pro e-shop (akce `catalog`, `order`, `interest`), druhá pro ples (`seats`, `reserve`, `ticket`, `checkin`, testovací režim `?test=`). Kód skriptů v repozitáři není.

**Odesílání e-mailů (ověřeno z kódu Apps Scriptů, dodán 16. 11. 2026):**
- **E-shop** posílá přes `MailApp.sendEmail` pod Google účtem vlastníka tabulky (david.bibrlik@bkludgerovice.cz), jméno odesílatele „BK Ludgeřovice – klubové oblečení“, odpovědi na admina. Vlastní adresa odesílatele se nenastavuje.
- **Ples** posílá přes `GmailApp.sendEmail` pod stejným účtem. Pokud je „Odesílatel e-mailů“ nastavený jako **alias v Gmailu** („Odesílat poštu jako“), použije ho jako adresu odesílatele, jinak jen jako adresu pro odpovědi. Přikládá QR kód (obrázek v e-mailu) a PDF vstupenku.
- Tedy: **„stejné řešení jako e-shop“ = Google Workspace (Gmail) klubu.** Sender.net se nepoužívá. Denní limit Apps Scriptu ve Workspace je cca 1 500 příjemců.
- E-shop i ples mají ochranu: skryté pole proti robotům, limit počtu požadavků, zámek proti souběhu (LockService).

**Platby plesu (ověřeno z kódu):** QR platba (SPD) na účet **2502518589/2010 (Fio)**. Apps Script plesu **už používá Fio API**: token je ve vlastnostech skriptu (`FIO_TOKEN`), **každých 5 minut** stahuje pohyby za posledních 30 dní (`/periods/`), ukládá je do listu Platby podle ID pohybu (bez duplicit), páruje podle VS, posílá vstupenku, hlásí nedoplatky, platby ke zrušeným rezervacím a neznámé VS. Nezaplacené ruší po splatnosti + toleranci, předtím pošle připomínku.
- **VS plesu: 7 číslic od 2701001, prefix `2701`.** Platby s jiným VS ples ignoruje; platbu s VS začínajícím `2701` bez rezervace hlásí adminovi.
- Prodej plesu běží od 15. 11. 2026 – Fio token plesu je teď v aktivním provozu.

**Co z plesu převezmeme (osvědčené postupy):** stahování Fio „za období“ s ukládáním podle ID pohybu, formát QR (SPD), stavy Čeká na platbu / Zaplaceno / Zrušeno, připomínka a tolerance před zrušením, upozornění adminovi na sporné platby, kontrola vstupu s PINem a vyhledáváním podle jména (`ples/vstup.html` je dobrý vzor pro prezenci).

**Nasazení:** GitHub Actions `.github/workflows/deploy.yml` – každý push do větve `main` nahraje celý repozitář přes FTP na Wedos (kromě `.git*`, `.github/`, `README.md`). Nástroj FTP-Deploy-Action si pamatuje, co nahrál, a soubory vytvořené na serveru ručně nemaže.

**Důležitá zjištění pro registrace:**
- Složka `docs/` (včetně této analýzy) by se po sloučení do `main` **nahrála veřejně na web** (eshop.bkludgerovice.cz/docs/registrace/analyza.md). Při implementaci je nutné přidat `docs/**` do výjimek nasazení. Do té doby tuto větev neslučovat do `main`.
- Dnes jde každá změna v `main` rovnou do produkce. Zadání ale vyžaduje schválení nasazení – viz kap. 8.
- Žádné testovací prostředí neexistuje.

## 3. Hosting – co potřebujeme od Wedos

### 3.1 Co Wedos webhosting umí (veřejné informace, ověřit u vašeho tarifu)
| Potřeba | Wedos webhosting | Poznámka |
|---|---|---|
| Backend | PHP (aktuálně 8.x) | Node.js/Python na webhostingu nejsou → stack PHP |
| Databáze | MariaDB, u NoLimit cca 2 GB, NoLimit Extra cca 5 GB | registrace zaberou jednotky MB |
| HTTPS | ano (Let's Encrypt) | už funguje pro eshop.bkludgerovice.cz |
| Plánované úlohy | **základ: 3 úlohy, nejčastěji 1× za hodinu**; doplněk **CRON+: 10 úloh, každých 5 minut** | pro Fio je hodina málo → CRON+ |
| Výkon | NoLimit: 25 PHP procesů, 256 MB; Extra: více | 150–400 závodníků bez problému |
| Zálohy Wedos | Wedos zálohuje sám, ale **na stejné infrastruktuře** | nestačí → vlastní záloha mimo hosting |
| Proměnné prostředí / trezor klíčů | webhosting nemá UI pro tajné klíče | tajné údaje do konfiguračního souboru vytvořeného jen na serveru (kap. 3.4) |
| SSH, Composer na serveru | spíš ne | knihovny se připraví v GitHub Actions a nahrají hotové |

Zdroje: kb.vedos.cz (webhosting-cron, webhosting-php, webhosting-databaze), blog.wedos.com (NoLimit Extra). Údaje se mezi zdroji mírně liší → ověřit v administraci.

### 3.2 Jak zjistit svůj tarif (krok za krokem)
1. Přihlaste se do **client.wedos.com** (zákaznická administrace).
2. Menu **Služby → Webhosting**. V seznamu najděte řádek s doménou `bkludgerovice.cz` / `eshop.bkludgerovice.cz`.
3. Ve sloupci / v detailu je název tarifu: **LowCost**, **NoLimit**, **NoLimit Extra** (příp. WMS). Poznamenejte si také datum expirace.
4. V detailu webhostingu otevřete:
   - **Databáze** – zda lze založit novou databázi MariaDB (a kolik jich už existuje),
   - **CRON** – kolik úloh lze nastavit a jaký je nejkratší interval,
   - **Doplňkové služby** – zda je dostupný/aktivní **CRON+** a jeho cena,
   - **PHP** – verze PHP.
5. Pošlete mi: název tarifu, verzi PHP, zda máte CRON+, a zda je eshop.bkludgerovice.cz na stejném webhostingu jako bkludgerovice.cz (WordPress).

### 3.3 Doporučení hostingu
- **Doporučuji Wedos** (NoLimit, nebo NoLimit Extra, pokud je WordPress na stejném hostingu – oddělí se výkon) + **CRON+**. Odhad: NoLimit ~40–80 Kč/měs., Extra +90 Kč/měs., CRON+ desítky Kč/měs. (ověřit ceník). Celkem výrazně pod 300 Kč.
- Pokud na webhostingu běží i WordPress, výpadek registrací WordPress neshodí (jiná aplikace), ale **zátěž sdílí** – proto Extra nebo oddělený webhosting pro subdoménu `eshop`.
- **Záložní varianta Render** (~300 Kč/měs.: webová služba ~7 USD + PostgreSQL ~7 USD) – stabilnější provoz aplikací, ale vlastní nasazení a vyšší cena. Railway podobně (platba podle spotřeby, ~5–15 USD). Aplikaci navrhnu tak, aby šla později přesunout (standardní PHP + SQL, žádné zvláštnosti Wedos v kódu).
- Pokud CRON+ nebude k dispozici: Fio párování spouštět zvenku (GitHub Actions plánovač nebo cron-job.org volá chráněnou adresu každých 5–10 minut). Funguje, ale je méně spolehlivé → jen jako záloha.

### 3.4 Tajné údaje (Fio token, heslo SMTP, šifrovací klíč záloh)
- Soubor `registrace/config/secrets.php` se vytvoří **jen na serveru** (Správce souborů Wedos / FTP), není v GitHubu a nasazení ho nepřepíše (výjimka v deploy.yml).
- Složka `config/` je zakázaná pro web (`.htaccess: Require all denied`).
- Fio token vkládáte vy sám přímo do tohoto souboru (nebo do formuláře v administraci, který ho uloží šifrovaně) – nikdy do chatu ani do kódu. Token typu **jen pro sledování účtu** (bez práva zadávat platby).

## 4. Navržená architektura

- **Umístění:** `eshop.bkludgerovice.cz/registrace/…` (doplněk má přednost před `registrace.bkludgerovice.cz` z původního zadání). Subdoménu lze doplnit kdykoli později přesměrováním.
  - veřejná stránka: `/registrace/zimni-beh-2027`
  - pro iframe: `/registrace/zimni-beh-2027?embed=1` (bez hlavičky)
  - startovka: `/registrace/zimni-beh-2027/startovka` (i pro iframe)
  - správa vlastní registrace: `/registrace/moje/<tajný-odkaz>`
  - prezence: `/registrace/prezence/<akce>` + PIN
  - administrace: `/registrace/admin`
- **Stack:** PHP 8.2+ (bez velkého frameworku, malé ověřené knihovny přes Composer: PHPMailer – e-maily, PhpSpreadsheet – Excel, knihovna pro QR, PHPUnit – testy), MariaDB, šablony HTML + malý JavaScript. Volba kvůli Wedos, nízké ceně a dlouhodobé podpoře.
- **Oddělení od e-shopu a plesu:** vše v jedné složce `registrace/` s vlastním `.htaccess`; kořen a `ples/` se nemění. Žádné sdílené soubory.
- **iframe do WordPressu:** tlačítko „Zkopírovat kód pro vložení“ vydá HTML s iframe + malým skriptem: výšku nastavuje přes `postMessage` s ověřením původu (`https://eshop.bkludgerovice.cz` ↔ `https://www.bkludgerovice.cz`). Pokud do ~8 s nepřijde zpráva „formulář načten“, zobrazí se náhradní hlášení „Registrační systém je momentálně nedostupný…“ – funguje i když server vůbec neodpovídá. Server posílá hlavičku `Content-Security-Policy: frame-ancestors` jen pro povolené domény.
- **Ve formuláři nepoužívat cookies** (Safari je v iframe blokuje) – vše se odešle jedním požadavkem s ochranným tokenem.
- **Kapacita a souběh:** registrace se zapisuje v databázové transakci se zamčením řádku akce (`SELECT … FOR UPDATE`) → poslední místo nemůže získat dva lidé. Místo je „rezervované“ od odeslání formuláře (stav Čeká na platbu) do zaplacení / zrušení po splatnosti.
- **Plánované úlohy (CRON+):** každých 5 min `fio-sync` (stažení plateb, párování) + odeslání fronty e-mailů; 1× denně záloha, upomínky/zrušení po splatnosti, denní souhrn správci; kontrola „úloha neběžela > 1 h“ → okamžité upozornění.
- **E-maily:** odesílatel `registrace@bkludgerovice.cz` přes **Google Workspace** – stejný poskytovatel jako e-shop a ples (ověřeno z kódu). Doporučené provedení: `registrace@` jako **alias** existujícího účtu (bez další licence) nastavený v Gmailu jako „Odesílat poštu jako“; aplikace na Wedosu posílá přes SMTP Google (`smtp.gmail.com`, port 587) s **heslem aplikace** (vyžaduje dvoufázové ověření účtu; heslo jen v `secrets.php`). Alternativa bez SMTP: malý Apps Script „pošťák“, kterému aplikace předá e-mail k odeslání (přesně jako ples, ale přidává závislost na Googlu). E-maily odcházejí přes servery Google → **pravděpodobně žádná změna DNS** (ověřit, že SPF obsahuje `include:_spf.google.com` a je zapnutý DKIM ve Workspace). Limit Gmailu ~2 000 zpráv/den – na 400 závodníků stačí. Sender.net zůstává jen pro newslettery (doplněk). Každý e-mail jde přes frontu s historií a ochranou proti duplicitě; kopie potvrzení každé registrace jde do schránky registrace@ (druhá stopa).
- **Platba převodem:** jeden QR kód (SPD) na klubový účet Fio, částka = startovné + produkty − sleva + charita; v databázi rozepsáno odděleně.
- **Fio párování:** stejně jako ples – stahovat pohyby za posledních ~30 dní (`/periods/`, ne „od poslední zarážky“) a ukládat podle jedinečného ID pohybu → opakované stažení nic nezduplikuje a po výpadku se nic neztratí. **Samostatný token jen pro registrace** (jen pro sledování účtu): Fio povoluje 1 dotaz / 30 s **na token** a ples se svým tokenem dotazuje každých 5 minut – sdílený token by způsoboval odmítnuté dotazy (chyba 409) v obou systémech. V internetovém bankovnictví Fio lze k jednomu účtu vytvořit více tokenů. Párování podle VS a částky: přesně → Zaplaceno; méně → Částečně (ke kontrole); více → Přeplaceno (ke kontrole); neznámý VS → Nespárováno (ruční párování v administraci). Platby plesu/e-shopu na stejném účtu systém ignoruje podle rozsahu VS.
- **Variabilní symbol:** ples používá `2701001`, `2701002`, … (prefix `2701`). Registrace proto **nesmí začínat na `2701`** (ples by je hlásil jako neznámé platby). Návrh: 9 číslic `8` + rok (2) + číslo akce (2) + pořadí (4), např. Zimní běh 2027 = akce 01 → `827010001`, `827010002`, … Registrace ignorují platby mimo svůj rozsah (`8…`), ples ignoruje platby mimo `2701…` → systémy se nepletou.
- **Vícejazyčnost:** texty v databázi i v šablonách ukládány po jazycích od začátku; ve verzi 1 vyplněna jen čeština.

## 5. Návrh databáze (pro celé zadání)

Značky: **V1** = implementuje se pro Zimní běh, **V2** = připraveno ve struktuře, implementace pro Ludgeřovickou 15, **(budoucnost)** = jen sloupec/tabulka připravená, nic se neprogramuje.

### 5.1 Organizace, uživatelé, nastavení
| Tabulka | Hlavní sloupce | Etapa |
|---|---|---|
| `organizers` | id, název, … (jeden záznam BKL) | V1 struktura, víc pořadatelů POBL budoucnost |
| `admin_users` | jméno, e-mail, hash hesla, role (`hlavni_admin`, `spravce_akce`, `jen_cteni`), aktivní, poslední přihlášení | V1 (role), V2 (přiřazení k akcím) |
| `admin_user_events` | admin_user_id, event_id | V2 |
| `settings` | klíč, hodnota (obecná nastavení) | V1 |
| `bank_accounts` | interní název, majitel, číslo účtu, kód banky, IBAN, měna, klíč tokenu Fio (odkaz do secrets, ne token), aktivní | V1 (1 účet Fio), víc účtů V2 |

### 5.2 Akce a tratě
| Tabulka | Hlavní sloupce | Etapa |
|---|---|---|
| `events` | organizer_id, slug (měnitelný), název*, popis*, datum a čas, místo, logo/obrázek, kontakt, web, otevření/uzavření registrací, výchozí jazyk, stav (připravovaná / otevřeno / pozastaveno / uzavřeno / vyprodáno / ukončená / archivovaná), režim stavu (auto/ruční), **společná kapacita**, bank_account_id, splatnost dní (5), upomínka po X dnech, po splatnosti (`zrusit` / `rucne`), termín převodu registrace, registrace na místě ano/ne, barvy, povolené domény pro iframe, jméno odesílatele, reply-to, PIN prezence (hash) + platnost do, povolené měny (V2), platební metody podle země (V2) | V1 |
| `races` (tratě) | event_id, název*, délka, vlastní kapacita (nepovinná), otevření/uzavření, věkové omezení, rozsah startovních čísel, pořadí | V1 |
| `categories` | event_id, race_id, název, pohlaví, ročník od–do | V1 (automatické přiřazení pro startovku časomíry) |
| `price_tiers` (cenové hladiny) | event_id, race_id (nebo všechny tratě), platí od–do, částka, měna, kanál (`online` / `na_miste`) | V1 (CZK), V2 (PLN, EUR) |
| `age_discounts` | event_id, ročník/věk do (např. ≤ 15) nebo od (≥ 60), sleva %, výpočet podle ročníku | V1 |
| `coupons` | event_id, kód, typ (`clensky_spolecny` / `jednorazovy`), sleva % nebo pevná, max. použití (prázdné = neomezeně), platnost od–do, aktivní, dávka (hromadné generování) | V1 |
| `charity_campaigns` | event_id, účel*, příjemce, bank_account_id (V2 jiný účet), přednastavené částky, vlastní částka ano/ne | V1 |
| `form_fields` | event_id, race_id (nepovinně), typ (text, číslo, datum, výběr, vícenásobný výběr, zaškrtávátko, produkt), popisek*, povinné, pořadí | V1 |
| `form_field_options` | field_id, popisek*, příplatek, pořadí, aktivní | V1 |

\* = text uložený pro více jazyků (cs / pl / en).

### 5.3 Objednávky, registrace, platby
| Tabulka | Hlavní sloupce | Etapa |
|---|---|---|
| `orders` | event_id, **VS (unikátní)**, tajný odkaz (hash), kontaktní jméno/e-mail/telefon, jazyk, měna, kanál (online / na místě / admin), **snímek bankovního účtu a IBAN** (změna účtu akce nemění staré objednávky), částky zvlášť: startovné, produkty, sleva, charita, celkem; splatnost, stav platby (nezaplaceno / částečně / zaplaceno / přeplaceno / vráceno / ke kontrole), vytvořeno, IP | V1 |
| `registrations` | order_id, event_id, race_id, jméno, příjmení, datum narození (ročník), pohlaví, e-mail, telefon, klub/město, země, jméno rodiče (nezletilí), **zmrazená cena**: hladina, základ, věková sleva, kupon, sleva kuponu, výsledná cena; kategorie, **startovní číslo (unikátní v rámci akce)**, stav (rozpracovaná / čeká na platbu / potvrzená / zrušená / expirovaná / ke kontrole), prezence (čas, kým), tajný odkaz pro závodníka (hash), převedeno z (id), anonymizováno (čas) | V1 |
| `registration_field_values` | registration_id, field_id, hodnota / option_id, příplatek | V1 |
| `consents` | registration_id, typ (podmínky, osobní údaje + veřejná startovka, souhlas rodiče), verze textu, čas, IP | V1 |
| `payments` | bank_account_id, zdroj (`fio`, `hotovost`, `rucne`, `stripe` V2), **externí ID (unikátní – ochrana proti duplicitám)**, částka, měna, VS, protiúčet, zpráva, datum přijetí, surová data, order_id (nebo prázdné = nespárováno), stav párování | V1 |
| `refunds` | order_id, částka, důvod, kdo, kdy | V1 (ručně z administrace) |
| `transfer_requests` (převody) | registration_id, nové údaje, stav (automaticky / čeká na schválení / schváleno / zamítnuto), kdo rozhodl | V1 |
| `stripe_events` | ID události Stripe (unikátní), typ, zpracováno | V2 |

### 5.4 E-maily, exporty, provoz
| Tabulka | Hlavní sloupce | Etapa |
|---|---|---|
| `email_templates` | event_id (prázdné = výchozí), událost (registrace, platba přijata, upomínka, expirace, zrušení, změna, převod, blížící se závod), jazyk, předmět, text, zapnuto | V1 (cs), V2 (pl, en) |
| `email_queue` | příjemce, šablona, vazba na objednávku/registraci, předmět, text, stav, počet pokusů, poslední chyba, **klíč proti duplicitě (unikátní)**, odesláno | V1 |
| `email_campaigns` | hromadné e-maily: filtr příjemců, náhled, počet, stav | V2 |
| `export_templates` | název, sloupce, filtry | V1 |
| `audit_log` | entita, id, stará → nová hodnota, kdo (admin / systém / pomocník / závodník), kdy | V1 – každá změna stavu |
| `system_log` | závažnost, zdroj (fio, e-mail, aplikace, záloha), zpráva (bez hesel a tokenů), čas, upozorněno | V1 |
| `job_runs` | úloha, začátek, konec, výsledek (hlídání, že cron běží) | V1 |
| `schema_migrations` | verze databázových změn | V1 |

### 5.5 Pravidla výpočtu ceny (V1)
1. Cena = hladina platná v okamžiku registrace podle kanálu (online / na místě).
2. Slevy se **nesčítají**: z věkové slevy a kuponu se uplatní ta vyšší (100 % partnerský > 50 % člen = 50 % věk).
3. \+ příplatky produktů z vlastních polí (sleva se na produkty nevztahuje – ověřit), + charitativní příspěvek (bez slevy).
4. Výsledek se uloží do registrace i objednávky a později se nemění změnou ceníku.

### 5.6 Rozdělení na etapy
**Verze 1a – spuštění registrací (cíl ~30. 11. 2026):** akce, tratě, společná kapacita, formulář (jeden i více závodníků), cenové hladiny, věkové slevy, kupony (členský + generování partnerských), vlastní pole, charita, souhlasy + rodič, QR platba, potvrzovací e-mail s QR pro prezenci, Fio párování, veřejná startovka, základní administrace (seznam, detail, úpravy, ruční platba, zrušení), export do Excelu, denní záloha mimo hosting, testy.
**Verze 1b – před závodem (cíl ~10. 1. 2027):** upomínky a zrušení po splatnosti, obnova po pozdní platbě, převody registrací, vrácení peněz, dashboard + denní souhrn, šablony exportů, startovka pro časomíru s kategoriemi, prezence (PIN, QR, našeptávač), startovní čísla na místě i hromadně, registrace na místě s hotovostí, výmaz údajů na žádost, upozornění na chyby. Generálka prezence nanečisto před 24. 1.
**Verze 2 – Ludgeřovická 15:** Stripe (karta, BLIK, Apple/Google Pay) s webhooky, PLN a EUR, metody podle země, polština a angličtina, hromadné e-maily, více účtů pro charitu, role po akcích, statistiky, zátěžový test 400+ současných registrací.

## 6. Testy (požadavek doplňku: každá etapa s automatickými testy)
- **PHPUnit** testy výpočtů a pravidel: cenové hladiny, věk podle ročníku, nesčítání slev, kupony (neomezený / jednorázový), poslední volné místo při souběhu, párování Fio (přesně, nedoplatek, přeplatek, duplicita, neznámý VS), splatnost a obnova.
- **Prohlížečové testy** (Playwright) formuláře na mobilu i v iframe.
- Běží automaticky v GitHub Actions při každé změně proti testovací databázi MariaDB; nasazení je možné jen při zelených testech.

## 7. Zálohování
**Cíl: nikdy neztratit přehled o registrovaných.**
1. **Denní automatická záloha (CRON 1× denně, např. 3:00):** PHP skript vytvoří výpis databáze, zkomprimuje a **zašifruje** (heslo zná jen klub) a nahraje:
   - na **Google Disk** klubu (servisní účet Google, sdílená složka „Zálohy registrace“), uchování 60 dní + vždy první záloha každého měsíce,
   - druhá kopie: e-mailem jako příloha do schránky (velikost pro stovky registrací < 1 MB).
2. **V dny s otevřenými registracemi** navíc záloha každé 1–2 hodiny (stačí na Google Disk).
3. **Druhá stopa:** každé potvrzení registrace jde v kopii do schránky registrace@ + denní souhrnný e-mail obsahuje CSV přehled registrací (v příloze).
4. **Ruční záloha** tlačítkem v administraci a **automaticky před každou aktualizací**.
5. **Test obnovy:** 1× měsíčně GitHub Actions stáhne poslední zálohu, obnoví ji do dočasné databáze a ověří počty → výsledek e-mailem. Správce dostane okamžité upozornění, pokud záloha nevznikla.
6. Wedos vlastní zálohy hostingu zůstávají jako třetí vrstva.
7. Postup obnovy pro neprogramátora bude v provozní dokumentaci (fáze 5). Po obnově se znovu spustí Fio synchronizace za posledních 10+ dní → platby z doby výpadku se doplní.

## 8. Bezpečné aktualizace
1. **Testovací prostředí zdarma na Wedos:** subdoména např. `test-eshop.bkludgerovice.cz` (nebo složka `/registrace-test/`) s vlastní databází; e-maily jdou jen na povolené adresy, Fio vypnuté / testovací data, výrazný nápis TEST.
2. **Postup změny:** vývoj na vlastní větvi → automatické testy → nasazení na test → vy si to proklikáte → **vaše schválení** → nasazení do produkce.
3. **Schválení v GitHubu:** produkční nasazení registrací se spustí jen ručně tlačítkem (Actions → „Run workflow“) nebo vytvořením verze (tagu), ne každým pushem. (Pozn.: e-shop a ples se dnes nasazují pushem do `main`; registrace dostanou vlastní workflow s výjimkami, aby se navzájem nepřepisovaly.)
4. **Před nasazením automaticky záloha** databáze.
5. **Databázové změny jen přidávací** (nové tabulky/sloupce, nikdy mazání v téže verzi) → návrat na předchozí verzi kódu funguje bez obnovy databáze a bez ztráty nových registrací. Návrat = znovu nasadit předchozí verzi (tag).
6. **Kontrola po nasazení:** automatický test, že stránka akce, e-shop i ples odpovídají.
7. **Ochrana e-shopu a plesu:** nasazení registrací smí měnit jen složku `registrace/`; testy po nasazení ověří, že `index.html` a `ples/` fungují.
8. Žádné aktualizace v dny s velkým náporem (otevření registrací, týden před závodem) kromě oprav chyb.

## 9. Rizika
| # | Riziko | Dopad | Opatření |
|---|---|---|---|
| 1 | **Termín 2 týdny** pro rozsah V1 | vysoký | rozdělení 1a/1b, rychlá rozhodnutí, bez grafických extra |
| 2 | Wedos tarif bez CRON+ / bez možnosti nové DB | střední | dokoupit CRON+; záložně externí spouštění; varianta Render |
| 3 | Sdílený webhosting s WordPressem – výkon při náporu | střední | NoLimit Extra nebo oddělený webhosting; zátěžový test před L15 |
| 4 | `docs/` a citlivé soubory se nasazením zveřejní | vysoký | výjimky v deploy.yml, `secrets.php` jen na serveru, `.htaccess` zákazy |
| 5 | Automatické nasazení z `main` bez schválení | střední | oddělený workflow registrací s ručním spuštěním |
| 6 | Kolize VS s plesem na stejném účtu Fio (ples: prefix `2701`) | střední | registrace v rozsahu `8YYAA####`, nikdy `2701…` |
| 7 | Sdílení Fio tokenu s plesem (ples volá Fio každých 5 min, limit 1 dotaz/30 s na token) | střední | samostatný token „jen sledování“ pro registrace |
| 7b | Denní limit Gmailu sdílený s e-shopem a plesem (stejný účet) | nízký | odhad < 1 000 zpráv/den i při L15; hlídat chyby odeslání, případně samostatný účet registrace@ |
| 8 | Výpadek cronu → nepárují se platby, neodcházejí e-maily | střední | `job_runs` + upozornění; párování lze spustit ručně tlačítkem |
| 9 | E-maily do spamu / limity Google | střední | Workspace SMTP, ověřit SPF/DKIM/DMARC před spuštěním, limit 2 000/den |
| 10 | Safari/iOS blokuje cookies v iframe | střední | formulář bez cookies; odkaz „otevřít v novém okně“ |
| 11 | GDPR: veřejná startovka bez možnosti skrytí, nezletilí | střední | jasná informace u souhlasu, u nezletilých souhlas rodiče; konzultace právně |
| 12 | Stávající logika e-shopu/plesu je mimo repozitář (Apps Script) | nízký | registrace na ní nezávisí; kód prověřen 16. 11. 2026, do skriptů se nezasahuje |
| 13 | Prezence v hale bez signálu | střední | ověřit signál/Wi-Fi; offline seznam (PDF/Excel) jako záloha |
| 14 | Jeden člověk zná přístupy | střední | trezor hesel, druhý správce s přístupem |

## 10. Nejasnosti a otázky pro předsedu
**Hosting a přístupy**
1. Jaký je tarif Wedos (návod v kap. 3.2)? Je WordPress bkludgerovice.cz na stejném webhostingu jako eshop.bkludgerovice.cz?
2. Souhlasíte s dokoupením CRON+ (a případně NoLimit Extra), pokud vyjde pod 300 Kč/měs.?
3. Je repozitář `bkludgerovice/eshop` soukromý? (Zadání požaduje soukromý repozitář.)

**Stávající systém**
4. ~~Kód Apps Scriptů~~ – dodán a prověřen (kap. 2).
5. Existuje schránka/alias `registrace@bkludgerovice.cz` v Google Workspace? Souhlasíte, aby byl alias vašeho účtu a e-maily šly přes SMTP Google s heslem aplikace (máte zapnuté dvoufázové ověření)? Kdo spravuje DNS?
6. Mají registrace chodit na stejný účet 2502518589/2010 jako ples? Můžete pro registrace vytvořit **druhý Fio token jen pro sledování** (token plesu ponechat jen plesu)?

**Pravidla závodu**
7. Formulář: ptát se na celé **datum narození**, nebo jen na **ročník**? (Věk i slevy se počítají podle ročníku.)
8. Věková sleva do 15 let = ročník 2012 a mladší pro rok 2027? A od 60 let = ročník 1967 a starší?
9. Vztahuje se sleva (věková/kupon) i na doplňkové produkty (jídlo), nebo jen na startovné?
10. Cenové hladiny Zimního běhu: kromě 170 Kč bude jiná cena po 31. 12. nebo na místě?
11. Jaká vlastní pole a produkty pro Zimní běh (např. teplé jídlo – za kolik)? Přednastavené částky příspěvku pro Šimona a Matěje?
12. Po splatnosti u Zimního běhu: automaticky zrušit, nebo k ručnímu rozhodnutí? Do kdy lze registraci převést samoobslužně?
13. Kategorie pro časomíru – máte seznam kategorií Zimního běhu (věk/pohlaví)?
14. Startovní čísla: rozsahy pro 10 km a 3 km?
15. Texty podmínek závodu a informace o zpracování osobních údajů – dodáte je, nebo připravím návrh ke schválení?

**Termíny**
16. Souhlasíte s rozdělením na 1a (spuštění registrací ~30. 11.) a 1b (prezence, čísla, upomínky do ~10. 1.)?
17. Kdo kromě vás bude administrátor (role)?
