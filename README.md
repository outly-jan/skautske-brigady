# Skautské brigády

WordPress plugin pro evidenci brigádnických hodin rodičů ve skautském středisku.

## Co plugin dělá

Plugin umožňuje skautskému středisku sledovat, zda rodiče splnili roční povinnost odpracovat stanovený počet hodin na brigádách (údržba táborů, úklid kluboven apod.). Poskytuje oddělená rozhraní pro správce i rodiče a vše řeší přímo v administraci WordPressu bez nutnosti externích nástrojů.

### Pro správce

- Zakládání a správa brigád (datum, čas, místo, délka, vhodnost)
- Správa rodin — přehled karet (rodina, rodiče, děti a oddíl), úpravy přímo na kartě, vyhledávání, vhodné i pro mobil
- Archiv: děti, které už skauta nenavštěvují, i celé rodiny se přesouvají do archivu (data se nemažou) a od příštího roku se nepočítají do požadavků
- Revize rodin: správce zahájí/ukončí revizi, během níž mohou rodiny upravovat i vedoucí (role `author`)
- Nastavení ročních požadavků na hodiny dle počtu dětí v rodině (1, 2, 3+ děti) a hodinové sazby za nesplnění
- **Skautský rok**: požadavky se vztahují jen na rodiny vzniklé do 31. 8. daného roku; počet dětí se snímkuje ke konci sezóny (tlačítkem nebo ručně)
- **Převod hodin nových rodin**: hodiny odpracované v roce vzniku rodiny (po 31. 8.) se převedou do prvního roku s požadavky
- Evidence skutečně odpracovaných hodin (registrovaní i neregistrovaní walk-in účastníci)
- Roční výpis plnění povinností s řazením dle odpracovaných hodin, požadavku, rozdílu nebo poplatku a číslováním řádků
- Export do CSV (roční přehled vč. sloupce s převodem hodin, účastníci konkrétní brigády, prezenční listina)
- Admin přehled brigád dle rodiny (read-only)
- **Členské příspěvky**: výpočet příspěvku rodiny (děti × sazba + doplatek za neodpracované brigády předchozího roku), QR platba, sponzorský dar, evidence plateb a splátek, rozesílání e-mailů
- 24hodinové upomínky správcům před nadcházející brigádou (WP-Cron)

### Pro rodiče

- Přehled nadcházejících brigád a přihlašování na ně (včetně počtu osob)
- Změna nebo zrušení přihlášky
- Průběžný přehled odpracovaných hodin vůči ročnímu požadavku, zbývajícím hodinám a případnému poplatku
- E-mailové potvrzení při každé změně přihlášky
- Rodiny vzniklé po 31. 8. aktuálního roku vidí nulový požadavek s vysvětlením, do které sezóny patří; v přehledu příštího roku pak uvidí řádek „Převedeno z roku …" s hodinami odpracovanými ještě v roce vzniku
- Archivovaná rodina vidí místo požadavku vysvětlení, že je od daného roku v archivu

### Pro vedoucí (role `author`)

- Záložka **Platby** (jen pro čtení) – kdo zaplatil členský příspěvek, komu platbu připomenout
- Záložka **Nápověda** s návodem krok za krokem (revize, úprava rodiny, archiv, zakládání rodiny, chybové hlášky)
- Prohlížení rodin, přehled účastníků brigád, rodiny dle brigád a roční výpis
- Úpravy a zakládání rodin jen během otevřené revize

## Skautský rok

Skautská sezóna X probíhá od 1. 9. roku X−1 do 31. 8. roku X. Požadavky na rok X se vztahují pouze na rodiny, které vznikly (datum vytvoření WordPress příspěvku) nejpozději 31. 8. roku X.

Počet dětí rozhodný pro výpočet požadavku se ukládá jako **snímek** ke konci sezóny (post meta `pocet_deti_{rok}`). Pokud snímek neexistuje, použije se aktuální stav (aktivní děti + děti archivované až od pozdějšího roku). Snímky spravuje správce v záložce *Nastavit požadavky dle roku* — hromadně tlačítkem nebo ručně po rodinách.

### Převod hodin nových rodin

Rodina vzniklá po 31. 8. roku X nemá na rok X požadavky. Hodiny, které v roce X přesto odpracuje (registrace i walk-in), se jí převedou jako **počáteční stav do roku X+1**:

- *Moje brigády* a *Rodiny dle brigád*: v boxu roku X+1 řádek „Převedeno z roku X: N h — odpracováno v roce X, kdy rodina ještě brigády plnit nemusela"; převedené hodiny snižují zbývající hodiny i poplatek.
- *Roční výpis* X+1: odpracováno včetně převodu (s poznámkou „vč. N h z X") a položka „Převod z roku X" v seznamu brigád; statistika člověkohodin brigád převod nezapočítává.
- *CSV export*: převod je součástí odpracovaných hodin, navíc sloupec „Z toho převod z předchozího roku (h)".

Převod se týká jen nových rodin (stávající ani archivované rodiny nic nepřevádějí). Počítá se vždy z aktuálních záznamů brigád, nic se neukládá.

## Členské příspěvky a platby

Správce v záložce *Nastavit požadavky dle roku* u daného roku (např. 2027) vyplní částku za dítě při 1 / 2 / 3+ dětech v rodině, číslo účtu a období zobrazení (od – do). Uloženo v option `sb_prispevky`.

- **Výpočet** (`sb_prispevek_rodiny`): počet dětí v roce X (snímek / stav) × sazba + doplatek za brigády roku X−1 (neodpracované hodiny × hodinová sazba, jednou za rodinu, stejně jako v Ročním výpisu) + případný dar. Rodiny archivované od roku X ani rodiny bez dětí neplatí nic; nová rodina (vznik po 31. 8. X−1) platí jen příspěvek za děti.
- **Rodina vedoucího** (některý z rodičů má kromě role `rodic` i roli `author`): doplatek za neodpracované brigády je vždy 0 – v příspěvku, Ročním výpisu, CSV, Moje brigády i Rodinách dle brigád. Odpracované hodiny a požadavek se zobrazují normálně. Rozhoduje aktuální role uživatele.
- **Moje brigády**: v nastaveném období box s rozpisem, číslem účtu, částkou, zprávou pro příjemce („Poplatek 2027 jména dětí, dar N" – bez přezdívek; když se plná jména nevejdou, použije se příjmení rodiny a křestní jména) a QR kódem. Rodina může zadat sponzorský dar (post meta `sb_dar_{rok}`), který se přičte k částce i do QR kódu. Po zaplacení vidí „Uhrazeno".
- **QR Platba**: formát SPD (IBAN se odvodí z čísla účtu), zpráva bez diakritiky max. 60 znaků, částka = zbývající částka. QR kód se generuje přímo v pluginu (SVG na webu, PNG v e-mailu přes GD) – údaje rodin se neposílají žádné externí službě.
- **Karta Platby**: přehled rodin se stavem nezaplaceno / splátky / zaplaceno, vyhledávání a filtr stavu, souhrn (příspěvky za děti, doplatky za brigády, dary, celkem k úhradě, uhrazeno, zbývá) a export CSV. Platbu správce zapíše zaškrtnutím **uhrazeno** (celá částka) nebo **částečně uhrazeno** + přijatá částka; u splátek **doplaceno** / **další splátka**. Zapsané platby lze opravit přepsáním nebo smazáním čísla. Stav se počítá ze součtu plateb (≥ celkové částky = zaplaceno). Platby (splátky) jsou v post meta `sb_platby_{rok}`. Vedoucí kartu vidí (připomínají platby rodičům), zapisuje jen správce.
- **E-maily**: rozesílání vybraným rodinám (všem rodičům s účtem) po 20 na kliknutí, zkušební e-mail sobě, Reply-To `info@skautchlumec.cz`, datum posledního odeslání (`sb_prispevek_mail_{rok}`). Rodinám, jejichž rodiče nemají účet, může správce zadat **kontaktní e-mail** (post meta `sb_kontakt_email`, uloží se natrvalo); použije se jen tehdy, když žádný z rodičů nemá účet.

## Dospělí členové

Kromě rodin s dětmi plugin eviduje i **dospělé členy**:

- **Samostatný dospělý člen** je záznam typu `rodina` s post meta `sb_typ = dospely` (název = příjmení a jméno). Platí příspěvek dospělého člena (nastavení `sb_prispevky[rok]['dosp']`), má osobní brigádní požadavek (`pozadavky_dle_roku[rok]['dosp']`), hodiny se mu evidují, ale doplatek za neodpracované hodiny neplatí. Spravuje se v záložce **Správa dospělých členů** (stejná oprávnění jako rodiny): nový člen, přezdívka (`sb_prezdivka`, zobrazuje se u jména, ne ve zprávě pro příjemce), účet na webu (role `rodic`), kontaktní e-mail, *Ukončil členství*, *Stal se rodičem* (přesun do existující / nové rodiny).
- **Dospělý člen v rodině** (rodič, který je sám členem): u účtu rodiče zaškrtávátko *dospělý člen skauta (platí příspěvek)* (identita podle účtu – `uid`, jméno z aktuálního profilu; funguje i pro dva lidi se stejným jménem), u rodiče bez účtu ruční jméno. Post meta `dospeli_clenove` (`jmeno`, `uid`, `od_roku`), ukončení v `dospeli_archiv` (`do_roku`). Rodina pak platí v jedné částce i jeho příspěvek; hodiny a požadavek zůstávají rodinné. **Rodič s rolí `author`, `spravce_brigad` nebo `administrator` (vedoucí) je dospělým členem automaticky** (a rodina neplatí doplatek za brigády) (bez zadání; duplicitní ruční zadání se pozná podle jména). Člena, který už má samostatný záznam, nelze do rodiny přidat ručně – slouží k tomu „Stal se rodičem“.
- **Dítě dospělo**: ve Správě rodin volba *Dospělý člen* – dítě se archivuje (`dospely: true`) a vznikne samostatný dospělý člen s `sb_od_roku` = rok, od kterého se počítá (žádné dvojí placení).
- **Rodině nezůstalo dítě** (všechny odešly nebo dospěly): rodina se archivuje a její dospělí členové se osamostatní (i s účty na webu).
- **Rodina, která děti nemá** (např. dva vedoucí): tlačítko *Převést na dospělé členy* v kartě rodiny udělá totéž.

## Revize rodin a archiv

Členství vedoucí evidují po skautských sezónách (1. 9.–31. 8.), brigádnické požadavky se ale počítají na kalendářní rok. Proto se používá **revize**:

1. Správce v záložce *Nastavit požadavky dle roku* **zahájí revizi** (doporučeno v říjnu). Tím se zmrazí snímek počtu dětí pro aktuální rok a vedoucí (role `author`) získají možnost rodiny upravovat a zakládat. Změny se projeví od **příštího roku**.
2. Vedoucí u dětí, které už nechodí, zaškrtnou *Už nechodí* (volitelně s poznámkou), případně přesunou celou rodinu do archivu, upraví oddíly a přidají nové děti.
3. Správce revizi **ukončí** (nejpozději do konce listopadu — v prosinci rodiče vidí požadavky na příští rok). Vytvoří se snímek počtu dětí pro příští rok pouze z aktivních dětí a vedoucí už rodiny upravovat nemohou.

Rodiče archivované rodiny v ní zůstávají (`rodice_rodiny` se nemění), nenabízejí se k přiřazení do jiných rodin a při obnovení rodiny se vrátí s ní.

Mimo revizi mohou rodiny upravovat jen správce. Trvalé smazání dítěte nebo rodiny je vyhrazeno správcům. Pokud správce po ukončení revize změní počet dětí (označí dítě „Už nechodí", obnoví ho nebo odebere), existující snímek příštího roku se automaticky upraví o rozdíl — ruční úpravy snímku zůstanou zachovány.

Přejmenování rodiny automaticky převede i záznamy „walk-in" účastníků (párují se podle názvu rodiny), takže se rodině hodiny neztratí. Název nelze změnit na název jiné rodiny.

Datový model: archivované děti jsou v post meta `deti_archiv` (`jmeno`, `oddil`, `od_roku`, `poznamka`, `datum`), archivovaná rodina má `rodina_archiv` (`od_roku`, `poznamka`, `datum`), stav revize je v option `sb_revize`. Pole `deti_rodiny` obsahuje vždy jen aktivní děti.

## Požadavky

- WordPress 6.0 nebo novější
- PHP 8.0 nebo novější
- Žádné externí závislosti — plugin používá pouze funkce WordPress core

## Instalace

1. Zkopíruj složku `skautske-brigady` do `/wp-content/plugins/`.
2. V administraci WordPressu přejdi na **Pluginy → Nainstalované pluginy** a plugin aktivuj.
3. Brigády a rodiny se ukládají jako příspěvky typu `brigada` a `rodina`. Plugin tyto typy neregistruje (WordPress je umí uložit i bez registrace), proto se nezobrazují v menu wp-adminu a spravují se jen přes stránky pluginu.

## Použití

Plugin funguje prostřednictvím shortcodes vložených na libovolné stránky WordPressu:

| Shortcode | Určeno pro | Popis |
|---|---|---|
| `[spravce_brigad]` | správci / administrátoři | Plný správcovský panel |
| `[moje_brigady]` | rodiče (role `rodic`) | Panel rodiče — přihlašování a přehled plnění |
| `[prehled_brigad]` nebo `[sb_prehled_brigad]` | veřejnost | Seznam nadcházejících brigád (jen pro čtení) |

### Uživatelské role

Plugin respektuje tyto role:

- **administrator** — plný přístup
- **spravce_brigad** — správce brigád, plný přístup ke správcovskému panelu
- **author** (vedoucí) — přehled účastníků, roční výpis, prohlížení rodin; úpravy a zakládání rodin jen při otevřené revizi
- **rodic** — panel rodiče

## Struktura projektu

```
skautske-brigady/
├── skautske-brigady.php   # celý plugin (jediný PHP soubor)
├── deploy-webhook.php     # webhook pro invalidaci PHP OPcache po nasazení
└── tests/                 # automatické testy na atrapách WordPressu (na server se nenahrávají)
    ├── test_plugin.php    # php tests/test_plugin.php
    ├── wp_stubs.php
    ├── qr_generuj.php     # zkušební QR kódy …
    └── qr_over.py         # … a jejich ověření nezávislou čtečkou (pip install zxing-cpp pillow)
```

## Nasazení

Plugin se nasazuje automaticky přes GitHub Actions při každém pushu do větve `main`:

1. **Kontrola syntaxe** — `php -l` na obou PHP souborech; při chybě se nic nenahraje
2. **FTP Deploy** — soubory se nahrají na server do `public_html/wp-content/plugins/skautske-brigady/`
3. **OPcache invalidace** — po nahrání se zavolá `deploy-webhook.php`, který zajistí, že PHP server načte novou verzi pluginu okamžitě

Token webhooku (`DEPLOY_SECRET`) musí být nastaven v GitHub Secrets a shodovat se s hodnotou definovanou přímo na serveru v `deploy-webhook.php`. Soubor v repozitáři obsahuje pouze zástupný token `CHANGE_ME`.

## Autor

Honza Outlý — outly@skaut.cz
