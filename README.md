# Skautské brigády

WordPress plugin pro evidenci brigádnických hodin rodičů ve skautském středisku.

## Co plugin dělá

Plugin umožňuje skautskému středisku sledovat, zda rodiče splnili roční povinnost odpracovat stanovený počet hodin na brigádách (údržba táborů, úklid kluboven apod.). Poskytuje oddělená rozhraní pro správce i rodiče a vše řeší přímo v administraci WordPressu bez nutnosti externích nástrojů.

### Pro správce

- Zakládání a správa brigád (datum, čas, místo, délka, vhodnost)
- Správa rodin — eviduje rodiče i děti včetně oddílové příslušnosti
- Nastavení ročních požadavků na hodiny dle počtu dětí v rodině a hodinové sazby za nesplnění
- Evidence skutečně odpracovaných hodin (registrovaní i neregistrovaní účastníci)
- Roční výpis plnění povinností pro každou rodinu
- Export do CSV (roční přehled, účastníci konkrétní brigády)
- 24hodinové upomínky správcům před nadcházející brigádou (WP-Cron)

### Pro rodiče

- Přehled nadcházejících brigád a přihlašování na ně
- Změna nebo zrušení přihlášky
- Průběžný přehled odpracovaných hodin vůči ročnímu požadavku
- E-mailové potvrzení při každé změně přihlášky

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
| `[sb_prehled_brigad]` | veřejnost | Seznam nadcházejících brigád (jen pro čtení) |

### Uživatelské role

Plugin respektuje tyto role:

- **administrator** — plný přístup
- **spravce_brigad** — správce brigád, plný přístup ke správcovskému panelu
- **author** — omezený přístup (jen přehled účastníků a roční výpis)
- **rodic** — panel rodiče

## Struktura projektu

```
skautske-brigady/
└── skautske-brigady.php   # celý plugin (jediný soubor, ~2 750 řádků)
```

## Autor

Honza Outlý — outly@skaut.cz
