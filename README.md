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
- Evidence skutečně odpracovaných hodin (registrovaní i neregistrovaní walk-in účastníci)
- Roční výpis plnění povinností s řazením dle odpracovaných hodin, požadavku, rozdílu nebo poplatku
- Export do CSV (roční přehled, účastníci konkrétní brigády, prezenční listina)
- Admin přehled brigád dle rodiny (read-only)
- 24hodinové upomínky správcům před nadcházející brigádou (WP-Cron)

### Pro rodiče

- Přehled nadcházejících brigád a přihlašování na ně (včetně počtu osob)
- Změna nebo zrušení přihlášky
- Průběžný přehled odpracovaných hodin vůči ročnímu požadavku, zbývajícím hodinám a případnému poplatku
- E-mailové potvrzení při každé změně přihlášky
- Rodiny vzniklé po 31. 8. aktuálního roku vidí nulový požadavek s vysvětlením, do které sezóny patří

## Skautský rok

Skautská sezóna X probíhá od 1. 9. roku X−1 do 31. 8. roku X. Požadavky na rok X se vztahují pouze na rodiny, které vznikly (datum vytvoření WordPress příspěvku) nejpozději 31. 8. roku X.

Počet dětí rozhodný pro výpočet požadavku se ukládá jako **snímek** ke konci sezóny (post meta `pocet_deti_{rok}`). Pokud snímek neexistuje, použije se aktuální počet dětí jako záložní hodnota. Snímky spravuje správce v záložce *Nastavit požadavky dle roku* — hromadně tlačítkem nebo ručně po rodinách.

## Revize rodin a archiv

Členství vedoucí evidují po skautských sezónách (1. 9.–31. 8.), brigádnické požadavky se ale počítají na kalendářní rok. Proto se používá **revize**:

1. Správce v záložce *Nastavit požadavky dle roku* **zahájí revizi** (doporučeno v říjnu). Tím se zmrazí snímek počtu dětí pro aktuální rok a vedoucí (role `author`) získají možnost rodiny upravovat a zakládat. Změny se projeví od **příštího roku**.
2. Vedoucí u dětí, které už nechodí, zaškrtnou *Už nechodí* (volitelně s poznámkou), případně přesunou celou rodinu do archivu, upraví oddíly a přidají nové děti.
3. Správce revizi **ukončí** (nejpozději do konce listopadu — v prosinci rodiče vidí požadavky na příští rok). Vytvoří se snímek počtu dětí pro příští rok pouze z aktivních dětí a vedoucí už rodiny upravovat nemohou.

Mimo revizi mohou rodiny upravovat jen správce. Trvalé smazání dítěte nebo rodiny je vyhrazeno správcům.

Přejmenování rodiny automaticky převede i záznamy „walk-in" účastníků (párují se podle názvu rodiny), takže se rodině hodiny neztratí. Název nelze změnit na název jiné rodiny.

Datový model: archivované děti jsou v post meta `deti_archiv` (`jmeno`, `oddil`, `od_roku`, `poznamka`, `datum`), archivovaná rodina má `rodina_archiv` (`od_roku`, `poznamka`, `datum`), stav revize je v option `sb_revize`. Pole `deti_rodiny` obsahuje vždy jen aktivní děti.

## Požadavky

- WordPress 6.0 nebo novější
- PHP 8.0 nebo novější
- Žádné externí závislosti — plugin používá pouze funkce WordPress core

## Instalace

1. Zkopíruj složku `skautske-brigady` do `/wp-content/plugins/`.
2. V administraci WordPressu přejdi na **Pluginy → Nainstalované pluginy** a plugin aktivuj.
3. Po aktivaci jsou automaticky zaregistrovány vlastní typy příspěvků `brigada` a `rodina`.

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
├── skautske-brigady.php   # celý plugin (jediný PHP soubor, ~4 100 řádků)
└── deploy-webhook.php     # webhook pro invalidaci PHP OPcache po nasazení
```

## Nasazení

Plugin se nasazuje automaticky přes GitHub Actions při každém pushu do větve `main`:

1. **Kontrola syntaxe** — `php -l` na obou PHP souborech; při chybě se nic nenahraje
2. **FTP Deploy** — soubory se nahrají na server do `public_html/wp-content/plugins/skautske-brigady/`
3. **OPcache invalidace** — po nahrání se zavolá `deploy-webhook.php`, který zajistí, že PHP server načte novou verzi pluginu okamžitě

Token webhooku (`DEPLOY_SECRET`) musí být nastaven v GitHub Secrets a shodovat se s hodnotou definovanou přímo na serveru v `deploy-webhook.php`. Soubor v repozitáři obsahuje pouze zástupný token `CHANGE_ME`.

## Autor

Honza Outlý — outly@skaut.cz
