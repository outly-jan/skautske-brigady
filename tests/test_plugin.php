<?php
// Automatické testy pluginu na atrapách WordPressu.  Spuštění:  php tests/test_plugin.php
require __DIR__ . '/wp_stubs.php';
require __DIR__ . '/../skautske-brigady.php';

$fail = 0; $pass = 0;
function ok($cond, $name) { global $fail, $pass; if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name\n"; } }
function post($id, $typ, $titulek, $datum) { $GLOBALS['posts'][$id] = (object) ['ID' => $id, 'post_type' => $typ, 'post_title' => $titulek, 'post_date' => $datum, 'post_status' => 'publish']; }
function user($id, $f, $l, $mail = '') { $GLOBALS['users'][$id] = (object) ['ID' => $id, 'first_name' => $f, 'last_name' => $l, 'display_name' => "$f $l", 'user_email' => $mail]; }
function reset_data() {
    $GLOBALS['posts'] = []; $GLOBALS['meta'] = []; $GLOBALS['opts'] = []; $GLOBALS['users'] = [];
    $GLOBALS['deleted'] = []; $GLOBALS['maily'] = []; $_POST = []; $GLOBALS['roles'] = ['author']; $GLOBALS['user_id'] = 0; $GLOBALS['logged'] = false;
    user(11, 'Jana', 'Nováková', 'jana@example.cz'); user(12, 'Petr', 'Novák', 'petr@example.cz'); user(13, 'Eva', 'Svobodová');
    post(100, 'rodina', 'Novákovi', '2024-09-15 10:00:00');
    update_post_meta(100, 'rodice_rodiny', [11, 12]);
    update_post_meta(100, 'deti_rodiny', [['jmeno' => 'Nováková Klára', 'oddil' => 'Lišky (světlušky)'], ['jmeno' => 'Novák Tomáš', 'oddil' => 'Skauti']]);
    post(200, 'rodina', 'Svobodovi', '2025-01-10 10:00:00');
    update_post_meta(200, 'rodice_rodiny', [13]);
    update_post_meta(200, 'deti_rodiny', [['jmeno' => 'Svobodová Anna', 'oddil' => 'Benjamínci']]);
    post(900, 'page', 'Rezervace', '2024-01-01 10:00:00');
}
function brigada($id, $datum, $rodina = null, $osob = 1, $hodin = 0) {
    post($id, 'brigada', 'Brigáda ' . $id, $datum . ' 08:00:00');
    update_post_meta($id, 'datum_brigady', $datum);
    if ($rodina) { update_post_meta($id, 'ucastnici_' . $rodina, $osob); update_post_meta($id, 'odpracovano_' . $rodina, $hodin); }
}
$cil = intval(date('Y')) + 1; $tato = $cil - 1;
$ulozit = function($rows, $extra = []) {
    $_POST = array_merge(['edit_rodina_id' => '100', 'ulozit_rodinu' => '1', '_wpnonce_ulozit_rodinu' => 'tok', 'novy_nazev' => 'Novákovi', 'rodice' => ['11', '12'], 'deti' => $rows], $extra);
    return sb_prehled_rodin();
};
$oba = [0 => ['puvodni' => 'Nováková Klára', 'jmeno' => 'Nováková Klára', 'oddil' => 'Lišky (světlušky)'], 1 => ['puvodni' => 'Novák Tomáš', 'jmeno' => 'Novák Tomáš', 'oddil' => 'Skauti']];

echo "== 1. Počet dětí, skautský rok, archiv ==\n";
reset_data();
ok(sb_pocet_deti_pro_rok(100, $cil) === 2, 'bez archivu a snímku = aktuální počet');
update_post_meta(100, 'deti_archiv', [['jmeno' => 'X', 'oddil' => 'Skauti', 'od_roku' => $cil, 'poznamka' => '', 'datum' => 'x']]);
ok(sb_pocet_deti_pro_rok(100, $tato) === 3 && sb_pocet_deti_pro_rok(100, $cil) === 2, 'dítě odešlé od příštího roku se letos ještě počítá');
update_post_meta(100, 'pocet_deti_' . $tato, 5);
ok(sb_pocet_deti_pro_rok(100, $tato) === 5, 'snímek má přednost');
post(300, 'rodina', 'Nová', '2026-10-05 10:00:00');
ok(!sb_rodina_v_roce(get_post(300), 2026) && sb_rodina_v_roce(get_post(300), 2027), 'rodina vzniklá po 31. 8. patří až do dalšího roku');
update_post_meta(200, 'rodina_archiv', ['od_roku' => $cil, 'poznamka' => '', 'datum' => 'x']);
ok(sb_rodina_v_roce(get_post(200), $tato) && !sb_rodina_v_roce(get_post(200), $cil), 'archivovaná rodina se od roku archivace nepočítá');

echo "== 2. Oprávnění a Správa rodin ==\n";
reset_data();
ok(sb_muze_videt_rodiny() && !sb_muze_upravovat_rodiny(), 'vedoucí bez revize: jen prohlížení');
update_option('sb_revize', ['otevrena' => true, 'rok' => $cil]);
ok(sb_muze_upravovat_rodiny(), 'vedoucí při revizi upravuje');
$GLOBALS['roles'] = ['rodic'];
ok(strpos(sb_prehled_rodin(), 'Nemáš oprávnění') !== false, 'rodič do Správy rodin nesmí');
$GLOBALS['roles'] = ['author'];
$_POST = ['edit_rodina_id' => '900', 'ulozit_rodinu' => '1', '_wpnonce_ulozit_rodinu' => 'tok', 'novy_nazev' => 'HACK'];
sb_prehled_rodin();
ok(get_post(900)->post_title === 'Rezervace', 'cizí příspěvek (stránku) nejde upravit');
$o = $oba; $o[1]['nechodi'] = '1'; $o[1]['poznamka'] = 'přestěhoval se';
$ulozit($o);
ok(count(get_post_meta(100, 'deti_rodiny')) === 1 && get_post_meta(100, 'deti_archiv')[0]['od_roku'] === $cil, 'dítě „Už nechodí" přejde do archivu od cílového roku');
$h = $ulozit($o);
ok(count(get_post_meta(100, 'deti_rodiny')) === 1 && strpos($h, 'odeslán znovu') !== false, 'opakované odeslání formuláře se odhalí');
$_POST = ['edit_rodina_id' => '100', 'confirm_delete_rodina' => '100', '_wpnonce_smazat_rodinu' => 'tok'];
sb_prehled_rodin();
ok(get_post(100) !== null, 'vedoucí nesmaže rodinu');
reset_data(); update_option('sb_revize', ['otevrena' => true, 'rok' => $cil]);
$_POST = ['edit_rodina_id' => '200', 'archivovat_rodinu' => '1', '_wpnonce_archivovat_rodinu' => 'tok', 'archiv_poznamka' => ''];
sb_prehled_rodin();
post(400, 'rodina', 'Další', '2026-10-10 10:00:00');
$_POST = []; $h = sb_prehled_rodin();
$k = substr($h, strpos($h, "id='sb-rodina-400'"), 4000);
ok(strpos($k, "name='rodice[]' value='13'") === false, 'rodič archivované rodiny se nenabízí jako volný');
brigada(700, '2026-05-01'); update_post_meta(700, 'walkin_ucastnici', [['jmeno' => 'Novákovi', 'pocet' => 2, 'hodiny' => 4]]);
$ulozit($oba, ['novy_nazev' => 'Novákovi-Dvořákovi']);
ok(get_post_meta(700, 'walkin_ucastnici')[0]['jmeno'] === 'Novákovi-Dvořákovi', 'přejmenování rodiny převede walk-in záznamy');

echo "== 3. Revize a převod hodin ==\n";
reset_data(); $GLOBALS['roles'] = ['administrator'];
$_POST = ['sb_revize_akce' => 'zahajit', '_wpnonce_revize' => 'tok']; sb_upravit_pozadavky_dle_roku();
ok(sb_revize_stav()['otevrena'] && get_post_meta(100, 'pocet_deti_' . $tato) === 2, 'zahájení revize zmrazí letošní rok');
$_POST = ['sb_revize_akce' => 'ukoncit', '_wpnonce_revize' => 'tok']; sb_upravit_pozadavky_dle_roku();
ok(!sb_revize_stav()['otevrena'] && get_post_meta(100, 'pocet_deti_' . $cil) === 2, 'ukončení revize vytvoří snímek příštího roku');
$o = $oba; $o[0]['nechodi'] = '1';
$ulozit($o);
ok(sb_pocet_deti_pro_rok(100, $cil) === 1, 'odchod po ukončení revize upraví snímek příštího roku');
reset_data();
post(300, 'rodina', 'Nováčkovi', '2026-10-05 10:00:00');
brigada(800, '2026-11-10', 300, 2, 3);
ok(sb_prevod_hodin(get_post(300), 2027) === 6 && sb_prevod_hodin(get_post(100), 2027) === 0, 'nová rodina si hodiny převede do dalšího roku, stávající ne');

echo "== 4. Členský příspěvek – výpočet ==\n";
reset_data();
update_option('pozadavky_dle_roku', [2026 => ['1' => 16, '2' => 20, '3' => 24, 'sazba' => 50]]);
update_option('sb_prispevky', [2027 => ['1' => 1200, '2' => 1100, '3' => 1000, 'ucet' => '2300221146/2010', 'od' => date('Y-m-d', strtotime('-1 day')), 'do' => date('Y-m-d', strtotime('+30 days'))]]);
brigada(801, '2026-06-01', 100, 2, 8);   // Novákovi 16 h z 20 → chybí 4 h
brigada(802, '2026-06-02', 200, 1, 20);  // Svobodovi splnili
$i = sb_prispevek_rodiny(get_post(100), 2027);
ok($i['deti'] === 2 && $i['za_dite'] === 1100 && $i['zaklad'] === 2200, 'rodina se 2 dětmi: 2 × 1 100 = 2 200 Kč');
ok($i['brigady']['chybi'] === 4 && $i['brigady']['castka'] === 200, 'doplatek: 4 neodpracované hodiny × 50 = 200 Kč (jednou za rodinu)');
ok($i['celkem'] === 2400 && $i['stav'] === 'nezaplaceno' && $i['zbyva'] === 2400, 'celkem 2 400 Kč, nezaplaceno');
$s = sb_prispevek_rodiny(get_post(200), 2027);
ok($s['zaklad'] === 1200 && $s['brigady']['castka'] === 0 && $s['celkem'] === 1200, 'rodina s 1 dítětem a splněnými brigádami: 1 200 Kč');
update_post_meta(200, 'deti_rodiny', [['jmeno' => 'A', 'oddil' => 'Skauti'], ['jmeno' => 'B', 'oddil' => 'Skauti'], ['jmeno' => 'C', 'oddil' => 'Skauti']]);
ok(sb_prispevek_rodiny(get_post(200), 2027)['zaklad'] === 3000, 'rodina se 3 dětmi: 3 × 1 000 Kč');
post(300, 'rodina', 'Nováčkovi', '2026-10-05 10:00:00');
update_post_meta(300, 'deti_rodiny', [['jmeno' => 'Eda', 'oddil' => 'Skauti']]);
$n = sb_prispevek_rodiny(get_post(300), 2027);
ok($n && $n['brigady']['castka'] === 0 && $n['celkem'] === 1200, 'nová rodina (od září 2026) platí příspěvek, doplatek za 2026 nemá');
update_post_meta(100, 'rodina_archiv', ['od_roku' => 2027, 'poznamka' => '', 'datum' => 'x']);
ok(sb_prispevek_rodiny(get_post(100), 2027) === null, 'rodina, která odešla (archiv od 2027), neplatí nic – ani doplatek');
delete_post_meta(100, 'rodina_archiv');
update_post_meta(100, 'deti_rodiny', [['jmeno' => 'Nováková Klára', 'oddil' => 'Skauti']]);
update_post_meta(100, 'deti_archiv', [['jmeno' => 'Novák Tomáš', 'oddil' => 'Skauti', 'od_roku' => 2027, 'poznamka' => '', 'datum' => 'x']]);
$i = sb_prispevek_rodiny(get_post(100), 2027);
ok($i['deti'] === 1 && $i['zaklad'] === 1200 && $i['jmena'] === ['Nováková Klára'], 'dítě, které odešlo, se do příspěvku 2027 nepočítá');
ok($i['brigady']['pozadavek'] === 20, '…ale doplatek 2026 se počítá podle dětí v roce 2026 (2 děti → 20 h)');
update_post_meta(100, 'deti_rodiny', [['jmeno' => 'Nováková Klára', 'oddil' => 'Lišky (světlušky)'], ['jmeno' => 'Novák Tomáš', 'oddil' => 'Skauti']]);
delete_post_meta(100, 'deti_archiv');
update_option('sb_prispevky', []);
ok(sb_prispevek_rodiny(get_post(100), 2027) === null, 'bez nastavení příspěvku se nic nepočítá');

echo "== 5. Platební údaje a QR ==\n";
update_option('sb_prispevky', [2027 => ['1' => 1200, '2' => 1100, '3' => 1000, 'ucet' => '2300221146/2010', 'od' => date('Y-m-d', strtotime('-1 day')), 'do' => date('Y-m-d', strtotime('+30 days'))]]);
ok(sb_cz_iban('2300221146/2010') === 'CZ1020100000002300221146', 'IBAN z čísla účtu');
ok(sb_cz_iban('19-2000145399/0800') === 'CZ6508000000192000145399', 'IBAN s předčíslím (vzor ČNB)');
ok(sb_cz_iban('nesmysl') === '', 'neplatné číslo účtu');
$i = sb_prispevek_rodiny(get_post(100), 2027);
ok(sb_platba_zprava($i) === 'Poplatek 2027 Nováková Klára, Novák Tomáš', 'zpráva pro příjemce s diakritikou');
ok(sb_platba_zprava($i, true) === 'Poplatek 2027 Novakova Klara, Novak Tomas', 'zpráva v QR bez diakritiky');
update_post_meta(100, 'sb_dar_2027', 500);
$i = sb_prispevek_rodiny(get_post(100), 2027);
ok($i['celkem'] === 2900 && sb_platba_zprava($i) === 'Poplatek 2027 Nováková Klára, Novák Tomáš, dar 500', 'dar se přičte a zpráva má dovětek „, dar 500"');
ok(sb_platba_spd($i) === 'SPD*1.0*ACC:CZ1020100000002300221146*AM:2900.00*CC:CZK*MSG:Poplatek 2027 Novakova Klara, Novak Tomas, dar 500', 'řetězec QR Platby');
update_post_meta(100, 'deti_rodiny', [['jmeno' => 'Nováková Kateřina Anežka (Kačenka)', 'oddil' => 'Skauti'], ['jmeno' => 'Novák Tomáš Bartoloměj (Bartík)', 'oddil' => 'Skauti'], ['jmeno' => 'Nováková Žofie Marie (Žofka)', 'oddil' => 'Skauti']]);
$i = sb_prispevek_rodiny(get_post(100), 2027);
$q = sb_platba_zprava($i, true);
ok(strlen($q) <= 60 && substr($q, -9) === ', dar 500' && strpos($q, 'Poplatek') === 0, 'dlouhá jména: zpráva v QR zkrácena na 60 znaků, dovětek s darem zůstane');
update_post_meta(100, 'deti_rodiny', [['jmeno' => 'Nováková Klára', 'oddil' => 'Lišky (světlušky)'], ['jmeno' => 'Novák Tomáš', 'oddil' => 'Skauti']]);
delete_post_meta(100, 'sb_dar_2027');
ok(strpos(sb_qr_svg('SPD*1.0*ACC:CZ1020100000002300221146*AM:1.00*CC:CZK'), '<svg') === 0, 'QR jako SVG');
ok(substr(sb_qr_png('test'), 1, 3) === 'PNG', 'QR jako PNG');
ok(sb_qr_matice(str_repeat('x', 214)) === null, 'příliš dlouhý text pro QR se odmítne');

echo "== 6. Platby a splátky ==\n";
update_post_meta(100, 'sb_platby_2027', [1000]);
$i = sb_prispevek_rodiny(get_post(100), 2027);
ok($i['stav'] === 'splatky' && $i['zbyva'] === 1400, 'částečná platba = splátky, zbývá 1 400 Kč');
ok(strpos(sb_platba_spd($i), '*AM:1400.00*') !== false, 'QR je jen na zbývající částku');
update_post_meta(100, 'sb_platby_2027', [1000, 1400]);
$i = sb_prispevek_rodiny(get_post(100), 2027);
ok($i['stav'] === 'zaplaceno' && sb_platba_spd($i) === '', 'uhrazeno celé: stav zaplaceno, žádný QR');
ok(strpos(sb_prispevek_html($i, ''), 'Uhrazeno – děkujeme') !== false, 'rodina vidí „Uhrazeno – děkujeme"');
delete_post_meta(100, 'sb_platby_2027');

$GLOBALS['roles'] = ['administrator'];
$_POST = ['sb_platby_rok' => '2027', 'sb_platby_ulozit' => '1', '_wpnonce_platby' => 'tok', 'sb_platby_rodiny' => ['100', '200'], 'platby' => ['100' => ['1000'], '200' => ['']], 'uhrazeno' => ['200' => '1']];
$h = sb_platby_tab();
ok(sb_platby(100, 2027) === [1000] && sb_platby(200, 2027) === [3200] && sb_prispevek_rodiny(get_post(200), 2027)['stav'] === 'zaplaceno', 'uložení: částečně 1 000 Kč; „uhrazeno" zapíše celou částku 3 200 Kč');
$_POST = ['sb_platby_rok' => '2027'];
$h = sb_platby_tab();
$radek = substr($h, strpos($h, "data-hledat='novákovi"), 2000);
ok(substr_count($radek, "name='platby[100][]'") === 2 && strpos($radek, 'doplaceno') !== false && strpos($radek, 'další splátka') !== false, 'u splátek: zapsaná splátka + volby „doplaceno" / „další splátka"');
$_POST = ['sb_platby_rok' => '2027', 'sb_platby_ulozit' => '1', '_wpnonce_platby' => 'tok', 'sb_platby_rodiny' => ['100'], 'platby' => ['100' => ['1000', '500']]];
sb_platby_tab();
ok(sb_platby(100, 2027) === [1000, 500], 'další splátka se přidá k předchozí');
$_POST = ['sb_platby_rok' => '2027', 'sb_platby_ulozit' => '1', '_wpnonce_platby' => 'tok', 'sb_platby_rodiny' => ['100'], 'platby' => ['100' => ['1000', '500', '']], 'uhrazeno' => ['100' => '1']];
sb_platby_tab();
$i100 = sb_prispevek_rodiny(get_post(100), 2027);
ok($i100['stav'] === 'zaplaceno' && $i100['zaplaceno'] == $i100['celkem'] && count(sb_platby(100, 2027)) === 3, '„doplaceno" zapíše zbytek a rodina je zaplacená');
$_POST = ['sb_platby_rok' => '2027', 'sb_platby_ulozit' => '1', '_wpnonce_platby' => 'tok', 'sb_platby_rodiny' => ['100'], 'platby' => ['100' => ['1000']]];
sb_platby_tab();
$_POST = ['sb_platby_rok' => '2027']; $h = sb_platby_tab();
$radek = substr($h, strpos($h, "data-hledat='novákovi"), 2000);
ok(strpos($h, 'Příspěvky za děti: <strong>') !== false && strpos($h, 'Doplatky za brigády 2026: <strong>') !== false && strpos($h, 'Celkem k úhradě') !== false, 'souhrn odděluje příspěvky, doplatky za brigády a celkovou částku');
$radek2 = substr($h, strpos($h, "data-hledat='svobodovi"), 2000);
ok(substr_count($radek2, "name='platby[200][]'") === 1 && strpos($radek2, 'Zaplaceno') !== false, 'u uhrazené rodiny už další pole není');
$_POST = ['sb_platby_rok' => '2027', 'sb_platby_ulozit' => '1', '_wpnonce_platby' => 'tok', 'sb_platby_rodiny' => ['100'], 'platby' => ['100' => ['', '']]];
sb_platby_tab();
ok(sb_platby(100, 2027) === [], 'smazáním čísla se chybná platba odebere');

$GLOBALS['roles'] = ['author'];
$_POST = ['sb_platby_rok' => '2027', 'sb_platby_ulozit' => '1', '_wpnonce_platby' => 'tok', 'sb_platby_rodiny' => ['100'], 'platby' => ['100' => ['999']]];
$h = sb_platby_tab();
ok(sb_platby(100, 2027) === [] && strpos($h, 'jen správce') !== false, 'vedoucí platby vidí, ale nezapíše');
ok(strpos($h, "name='platby[") === false && strpos($h, 'Novákovi') !== false && strpos($h, 'sb_mail_odeslat') === false, 'vedoucí: přehled bez polí a bez rozesílání e-mailů');
$GLOBALS['roles'] = ['rodic'];
ok(strpos(sb_platby_tab(), 'Nemáš oprávnění') !== false, 'rodič kartu Platby nevidí');

echo "== 7. E-maily ==\n";
$GLOBALS['roles'] = ['administrator']; $GLOBALS['maily'] = [];
$_POST = ['sb_platby_rok' => '2027', 'sb_mail_test' => '1', '_wpnonce_platby_mail' => 'tok', 'sb_mail_test_rodina' => '100'];
sb_platby_tab();
$m = $GLOBALS['maily'][0] ?? null;
ok($m && $m['to'] === 'spravce@example.cz' && strpos($m['subject'], '[ZKOUŠKA]') === 0, 'zkušební e-mail jde správci');
$GLOBALS['maily'] = [];
$_POST = ['sb_platby_rok' => '2027', 'sb_mail_odeslat' => '1', '_wpnonce_platby_mail' => 'tok', 'sb_mail_davka' => 'd' . uniqid(), 'sb_mail_rodiny' => ['100', '200']];
$h = sb_platby_tab();
$m = $GLOBALS['maily'][0] ?? null;
ok(count($GLOBALS['maily']) === 1 && $m['to'] === ['jana@example.cz', 'petr@example.cz'], 'e-mail jde oběma rodičům; rodina bez e-mailu se přeskočí');
ok(strpos($h, 'Bez e-mailové adresy') !== false && strpos($h, 'Svobodovi') !== false, 'správce vidí, kdo e-mail nedostal');
ok(in_array('Reply-To: info@skautchlumec.cz', $m['headers'], true), 'Reply-To: info@skautchlumec.cz');
ok(strpos($m['body'], "cid:sbqrplatba") !== false && $m['obrazky'] && $m['obrazky'][0][0] === 'sbqrplatba', 'e-mail obsahuje QR kód jako vložený obrázek');
ok(strpos($m['body'], '2300221146/2010') !== false && strpos($m['body'], 'Poplatek 2027 Nováková Klára, Novák Tomáš') !== false && strpos($m['body'], '2 400 Kč') !== false, 'e-mail obsahuje účet, zprávu a částku');
ok(get_post_meta(100, 'sb_prispevek_mail_2027') !== '', 'zaznamená se datum odeslání');
$GLOBALS['maily'] = []; $ids = [];
for ($k = 0; $k < 25; $k++) { $id = 1000 + $k; post($id, 'rodina', 'R' . $k, '2024-01-01'); update_post_meta($id, 'deti_rodiny', [['jmeno' => 'D', 'oddil' => 'Skauti']]); user(5000 + $k, 'X', 'Y' . $k, "r$k@example.cz"); update_post_meta($id, 'rodice_rodiny', [5000 + $k]); $ids[] = (string) $id; }
$_POST = ['sb_platby_rok' => '2027', 'sb_mail_odeslat' => '1', '_wpnonce_platby_mail' => 'tok', 'sb_mail_davka' => 'd' . uniqid(), 'sb_mail_rodiny' => $ids];
$h = sb_platby_tab();
ok(count($GLOBALS['maily']) === 20 && strpos($h, 'Zbývá ještě 5') !== false && substr_count($h, "class='sb-mail-cb'") > 0 && substr_count($h, ' checked') >= 5, 'posílá se po 20, zbytek zůstane zaškrtnutý');
$GLOBALS['roles'] = ['author']; $GLOBALS['maily'] = [];
$_POST = ['sb_platby_rok' => '2027', 'sb_mail_odeslat' => '1', '_wpnonce_platby_mail' => 'tok', 'sb_mail_davka' => 'd' . uniqid(), 'sb_mail_rodiny' => ['100']];
sb_platby_tab();
ok(count($GLOBALS['maily']) === 0, 'vedoucí e-maily rozeslat nemůže');
foreach ($ids as $id) unset($GLOBALS['posts'][intval($id)]);

echo "== 8. Moje brigády – box s příspěvkem a dar ==\n";
$GLOBALS['roles'] = ['rodic']; $GLOBALS['user_id'] = 11; $GLOBALS['logged'] = true;
$_POST = [];
$h = sb_moje_brigady_shortcode();
ok(strpos($h, 'Členský příspěvek 2027') !== false && strpos($h, '<svg') !== false && strpos($h, '2300221146/2010') !== false, 'rodina vidí box s příspěvkem, účtem a QR kódem');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['_wpnonce_sb_moje_brigady' => 'tok', 'sb_ulozit_dar' => '1', 'sb_dar_rok' => '2027', 'sb_dar' => '300'];
$h = sb_moje_brigady_shortcode();
ok(intval(get_post_meta(100, 'sb_dar_2027')) === 300 && strpos($h, '2 700 Kč') !== false && strpos($h, ', dar 300') !== false, 'zadaný dar se uloží, přičte do rozpisu i zprávy');
$_POST = ['_wpnonce_sb_moje_brigady' => 'tok', 'sb_ulozit_dar' => '1', 'sb_dar_rok' => '2027', 'sb_dar' => '0'];
sb_moje_brigady_shortcode();
ok(get_post_meta(100, 'sb_dar_2027') === '', 'dar 0 = dar odebrán');
$_POST = ['_wpnonce_sb_moje_brigady' => 'tok', 'sb_ulozit_dar' => '1', 'sb_dar_rok' => '2030', 'sb_dar' => '999'];
sb_moje_brigady_shortcode();
ok(get_post_meta(100, 'sb_dar_2030') === '', 'dar na rok mimo zobrazované období se neuloží');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = [];
update_post_meta(100, 'sb_platby_2027', [2400]);
$h = sb_moje_brigady_shortcode();
ok(strpos($h, 'Uhrazeno – děkujeme') !== false && strpos($h, '<svg') === false, 'po zaplacení vidí rodina „Uhrazeno" a žádný QR');
delete_post_meta(100, 'sb_platby_2027');
update_option('sb_prispevky', [2027 => ['1' => 1200, '2' => 1100, '3' => 1000, 'ucet' => '2300221146/2010', 'od' => date('Y-m-d', strtotime('+5 days')), 'do' => date('Y-m-d', strtotime('+30 days'))]]);
ok(strpos(sb_moje_brigady_shortcode(), 'Členský příspěvek') === false, 'mimo nastavené období se box nezobrazuje');

echo "== 9. Nastavení příspěvku ==\n";
reset_data(); $GLOBALS['roles'] = ['administrator'];
$_POST = ['vybrany_rok' => '2027', 'sb_prispevky_ulozit' => '2027', '_wpnonce_prispevky' => 'tok', 'prispevek_1' => '1200', 'prispevek_2' => '1100', 'prispevek_3' => '1000', 'prispevek_ucet' => '2300221146/2010', 'prispevek_od' => '2026-11-15', 'prispevek_do' => '2027-02-28'];
sb_upravit_pozadavky_dle_roku();
$n = get_option('sb_prispevky')[2027] ?? null;
ok($n && $n['1'] === 1200 && $n['ucet'] === '2300221146/2010' && $n['od'] === '2026-11-15' && $n['do'] === '2027-02-28', 'správce uloží částky, účet a období');
$_POST['prispevek_ucet'] = '12345'; $_POST['prispevek_1'] = '1';
$h = sb_upravit_pozadavky_dle_roku();
ok(get_option('sb_prispevky')[2027]['1'] === 1200 && strpos($h, 'Číslo účtu není platné') !== false, 'neplatné číslo účtu se neuloží');
$GLOBALS['roles'] = ['author'];
$_POST = ['vybrany_rok' => '2027', 'sb_prispevky_ulozit' => '2027', '_wpnonce_prispevky' => 'tok', 'prispevek_1' => '1', 'prispevek_ucet' => '2300221146/2010'];
sb_upravit_pozadavky_dle_roku();
ok(get_option('sb_prispevky')[2027]['1'] === 1200, 'vedoucí nastavení příspěvku nezmění');

echo "== 10. Ochrany z review ==\n";
reset_data(); $GLOBALS['roles'] = ['administrator'];
update_option('pozadavky_dle_roku', [2026 => ['1' => 16, '2' => 20, '3' => 24, 'sazba' => 50]]);
update_option('sb_prispevky', [2027 => ['1' => 1200, '2' => 1100, '3' => 1000, 'ucet' => '2300221146/2010', 'od' => date('Y-m-d', strtotime('-1 day')), 'do' => date('Y-m-d', strtotime('+30 days'))]]);
$GLOBALS['maily'] = [];
$post_mail = ['sb_platby_rok' => '2027', 'sb_mail_odeslat' => '1', '_wpnonce_platby_mail' => 'tok', 'sb_mail_davka' => 'abc123', 'sb_mail_rodiny' => ['100']];
$_POST = $post_mail; sb_platby_tab();
$_POST = $post_mail; $h = sb_platby_tab();   // F5 / dvojklik se stejnou dávkou
ok(count($GLOBALS['maily']) === 1 && strpos($h, 'už byla odeslána') !== false, 'obnovení stránky po odeslání nepošle e-maily podruhé');
$_POST = ['sb_platby_rok' => '2027', 'sb_mail_odeslat' => '1', '_wpnonce_platby_mail' => 'tok', 'sb_mail_rodiny' => ['100']];
sb_platby_tab();
ok(count($GLOBALS['maily']) === 1, 'bez tokenu dávky se nic neodešle');
$_POST = ['sb_platby_rok' => '2027']; $h = sb_platby_tab();
preg_match("/name='sb_mail_davka' value='([0-9a-f]+)'/", $h, $m1); $h2 = sb_platby_tab(); preg_match("/name='sb_mail_davka' value='([0-9a-f]+)'/", $h2, $m2);
ok(!empty($m1[1]) && $m1[1] !== $m2[1], 'každé zobrazení formuláře má novou dávku');
update_post_meta(100, 'deti_rodiny', [['jmeno' => 'Nováková Kateřina Anežka (Kačenka)', 'oddil' => 'Skauti'], ['jmeno' => 'Novák Tomáš Bartoloměj (Bartík)', 'oddil' => 'Skauti']]);
update_post_meta(100, 'sb_dar_2027', 500);
$q = sb_platba_zprava(sb_prispevek_rodiny(get_post(100), 2027), true);
ok(strpos($q, 'Poplatek 2027 ') === 0 && strlen($q) <= 60 && substr($q, -9) === ', dar 500', 'zkrácená zpráva v QR si ponechá rok: ' . $q);
delete_post_meta(100, 'sb_dar_2027');
update_option('pozadavky_dle_roku', [2026 => ['1' => 16, '2' => 20, '3' => 24, 'sazba' => 37.5]]);
brigada(801, '2026-06-01', 100, 1, 17);   // chybí 3 h × 37,50
$i = sb_prispevek_rodiny(get_post(100), 2027);
ok($i['brigady']['castka'] == 112.5 && strpos(sb_prispevek_html($i, ''), '112,50 Kč') !== false && strpos(sb_platba_spd($i), '*AM:2312.50*') !== false, 'doplatek s haléři (3 × 37,50 = 112,50 Kč) stejně jako Roční výpis');
update_post_meta(100, 'sb_platby_2027', [2312.5]);
ok(sb_prispevek_rodiny(get_post(100), 2027)['stav'] === 'zaplaceno', 'platba s haléři = zaplaceno');
delete_post_meta(100, 'sb_platby_2027');
update_option('pozadavky_dle_roku', [2026 => ['1' => 16, '2' => 20, '3' => 24, 'sazba' => 50]]);
update_option('sb_prispevky', [2027 => ['1' => 1200, '2' => 0, '3' => 1000, 'ucet' => '2300221146/2010', 'od' => date('Y-m-d', strtotime('-1 day')), 'do' => date('Y-m-d', strtotime('+30 days'))]]);
brigada(801, '2026-06-01', 100, 2, 10);   // splněno
ok(sb_prispevek_rodiny(get_post(100), 2027) === null, 'rodina s nulovou částkou neplatí (není „nezaplaceno")');
update_option('sb_prispevky', [2027 => ['1' => 1200, '2' => 1100, '3' => 1000, 'ucet' => '2300221146/2010', 'od' => date('Y-m-d', strtotime('-1 day')), 'do' => date('Y-m-d', strtotime('+30 days'))]]);
update_option('pozadavky_dle_roku', []);
ok(strpos(sb_prispevek_html(sb_prispevek_rodiny(get_post(100), 2027), ''), 'brigády splněny') === false, 'bez požadavků na předchozí rok se netvrdí „brigády splněny"');
ok(sb_ucet_platny('2300221146/2010') && sb_ucet_platny('19-2000145399/0800') && !sb_ucet_platny('2300221147/2010'), 'kontrolní součet čísla účtu (překlep se odhalí)');
$GLOBALS['roles'] = ['rodic']; $GLOBALS['user_id'] = 11; $GLOBALS['logged'] = true;
update_post_meta(100, 'sb_platby_2027', [2200]);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['_wpnonce_sb_moje_brigady' => 'tok', 'sb_ulozit_dar' => '1', 'sb_dar_rok' => '2027', 'sb_dar' => '0'];
update_post_meta(100, 'sb_dar_2027', 300); update_post_meta(100, 'sb_platby_2027', [2500]);
$h = sb_moje_brigady_shortcode();
ok(intval(get_post_meta(100, 'sb_dar_2027')) === 300 && strpos($h, 'už uhrazený') !== false, 'po úplném zaplacení nejde dar změnit ani odebrat');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = [];

echo "== 11. Zpráva pro příjemce: přezdívky, čárka, celá jména ==\n";
$b = ['rok' => 2027, 'dar' => 500, 'rodina' => 'Outlí', 'jmena' => ['Outlý Matěj (Uhlík)', 'Outlá Nela (Klíček)']];
ok(sb_platba_zprava($b) === 'Poplatek 2027 Outlý Matěj, Outlá Nela, dar 500', 'přezdívky se vynechají, dar oddělen čárkou');
ok(strpos(sb_platba_zprava($b, true), '+') === false, 've zprávě v QR není znak „+"');
$b = ['rok' => 2027, 'dar' => 1000, 'rodina' => 'Godoyovi Očkayovi', 'jmena' => ['Godoy Očkay Sebastián (Sebík)', 'Godoy Očkay Valentýna Marie', 'Godoy Očkay Maximilián']];
$q = sb_platba_zprava($b, true);
ok(strlen($q) <= 60 && strpos($q, 'Sebastian') !== false && strpos($q, 'Valentyna') !== false && strpos($q, 'Maximilian') !== false && substr($q, -10) === ', dar 1000', 'dlouhá jména: v QR zůstane každé dítě celé (' . $q . ')');
ok(sb_ascii(sb_platba_zprava($b)) === $q, 'zpráva na webu/v e-mailu odpovídá QR (jen s diakritikou)');
$b = ['rok' => 2027, 'dar' => 500, 'rodina' => 'Applovi', 'jmena' => ['Applová Julie (Rákosníček)', 'Appl Josef', 'Applová Kateřina Anežka']];
ok(sb_platba_zprava($b) === 'Poplatek 2027 Applovi: Julie, Josef, Kateřina, dar 500', 'nevejdou-li se plná jména: příjmení rodiny + křestní jména');
$b = ['rok' => 2027, 'dar' => 500, 'rodina' => 'Testerovi', 'jmena' => ['Matěj Tester', 'Nela Testerová', 'Jan Outlý-Tester']];
ok(sb_platba_zprava($b) === 'Poplatek 2027 Testerovi: Matěj, Nela, Jan, dar 500', 'pořadí „Jméno Příjmení" i dvojité příjmení s pomlčkou: ' . sb_platba_zprava($b));
$b = ['rok' => 2027, 'dar' => 0, 'rodina' => 'Novákovi', 'jmena' => ['Nováková Svobodová Jana Marie', 'Novák Bartoloměj Tadeáš', 'Nováková Kristýna Valentýna']];
$q = sb_platba_zprava($b, true);
ok(strpos($q, 'Poplatek 2027 Novakovi:') === 0 && strlen($q) <= 60 && substr_count($q, ',') === 2, 'tři děti s dlouhými jmény: každé jedním křestním jménem (' . $q . ')');

echo "== 12. Rodina vedoucího bez doplatku za brigády ==\n";
reset_data();
update_option('pozadavky_dle_roku', [2026 => ['1' => 16, '2' => 20, '3' => 24, 'sazba' => 50]]);
update_option('sb_prispevky', [2027 => ['1' => 1200, '2' => 1100, '3' => 1000, 'ucet' => '2300221146/2010', 'od' => date('Y-m-d', strtotime('-1 day')), 'do' => date('Y-m-d', strtotime('+30 days'))]]);
brigada(801, '2026-06-01', 100, 2, 8);   // Novákovi 16 z 20 h
brigada(802, '2026-06-02', 200, 1, 10);  // Svobodovi 10 z 16 h
$GLOBALS['users'][12]->roles = ['rodic', 'author'];   // Petr Novák je i vedoucí
ok(sb_rodina_vedouci(100) && !sb_rodina_vedouci(200), 'rodina s rodičem-vedoucím se pozná');
$i = sb_prispevek_rodiny(get_post(100), 2027);
ok($i['brigady']['chybi'] === 4 && $i['brigady']['castka'] === 0 && $i['celkem'] === 2200, 'příspěvek 2027: hodiny se počítají (chybí 4 h), doplatek 0, platí jen 2 × 1 100 Kč');
ok(strpos(sb_prispevek_html($i, ''), 'rodina vedoucího, bez doplatku') !== false, 'v rozpisu je vysvětlení „rodina vedoucího, bez doplatku"');
$s = sb_prispevek_rodiny(get_post(200), 2027);
ok($s['brigady']['castka'] === 300 && $s['celkem'] === 1500, 'ostatní rodiny doplatek platí dál (6 h × 50 = 300 Kč)');
$GLOBALS['roles'] = ['administrator'];
$_POST = ['zobraz_rocni_vypis' => '1', 'vybrany_rok' => '2026'];
$h = sb_rocni_vypis();
$rn = substr($h, strpos($h, '<td>Novákovi</td>'), 1200);
ok(strpos($rn, "data-v='16'>16") !== false && strpos($rn, "data-v='0'>0,00<br><small style='color:#777;'>vedoucí</small>") !== false, 'Roční výpis: skutečné hodiny (16) a poplatek 0 s poznámkou „vedoucí"');
$rs = substr($h, strpos($h, '<td>Svobodovi</td>'), 1200);
ok(strpos($rs, "data-v='300'>300,00") !== false, 'Roční výpis: ostatní rodina má poplatek 300 Kč');
ok(strpos($h, 'Celkem k inkasu: <strong>300,00 Kč') !== false, 'souhrn inkasa nezapočítává rodinu vedoucího');
$GLOBALS['roles'] = ['rodic']; $GLOBALS['user_id'] = 11; $GLOBALS['logged'] = true; $_POST = [];
$h = sb_moje_brigady_shortcode();
$GLOBALS['logged'] = false; $GLOBALS['user_id'] = 0;
ok(strpos($h, 'Odpracováno:</td><td><strong>16 h') !== false && strpos($h, 'Poplatek:</td><td><strong>0 Kč</strong> <span') !== false && strpos($h, 'rodina vedoucího – bez doplatku') !== false, 'Moje brigády: hodiny 16, poplatek 0 Kč s vysvětlením');

echo "== 13. Kontaktní e-maily rodin bez účtu ==\n";
reset_data(); $GLOBALS['roles'] = ['administrator']; $GLOBALS['maily'] = [];
update_option('sb_prispevky', [2027 => ['1' => 1200, '2' => 1100, '3' => 1000, 'ucet' => '2300221146/2010', 'od' => date('Y-m-d', strtotime('-1 day')), 'do' => date('Y-m-d', strtotime('+30 days'))]]);
$_POST = ['sb_platby_rok' => '2027']; $h = sb_platby_tab();
ok(strpos($h, "name='kontakt[200]'") !== false && strpos($h, "name='kontakt[100]'") === false, 'pole pro kontaktní e-mail je jen u rodiny bez účtu');
ok(preg_match("/name='sb_mail_rodiny\[\]' value='200'[^>]*disabled/", $h) === 1, 'bez e-mailu nejde rodinu zaškrtnout');
$_POST = ['sb_platby_rok' => '2027', 'sb_kontakt_ulozit' => '1', '_wpnonce_platby_mail' => 'tok', 'kontakt' => ['200' => 'eva.svobodova@example.cz; spatna-adresa']];
$h = sb_platby_tab();
ok(get_post_meta(200, 'sb_kontakt_email') === 'eva.svobodova@example.cz' && strpos($h, 'Neplatné e-mailové adresy') !== false && strpos($h, 'spatna-adresa') !== false, 'platný e-mail se uloží, neplatný se odmítne s hláškou');
ok(preg_match("/name='sb_mail_rodiny\[\]' value='200'[^>]*disabled/", $h) === 0 && strpos($h, 'kontaktní e-mail (rodiče nemají účet)') !== false, 'po uložení jde rodinu zaškrtnout, u adresy je poznámka');
$_POST = ['sb_platby_rok' => '2027', 'sb_mail_odeslat' => '1', '_wpnonce_platby_mail' => 'tok', 'sb_mail_davka' => 'k1', 'sb_mail_rodiny' => ['200']];
sb_platby_tab();
ok(($GLOBALS['maily'][0]['to'] ?? null) === ['eva.svobodova@example.cz'], 'e-mail o příspěvku odejde na kontaktní adresu');
$GLOBALS['maily'] = [];
update_post_meta(100, 'sb_kontakt_email', 'stara@example.cz');
$_POST = ['sb_platby_rok' => '2027', 'sb_mail_odeslat' => '1', '_wpnonce_platby_mail' => 'tok', 'sb_mail_davka' => 'k2', 'sb_mail_rodiny' => ['100']];
sb_platby_tab();
ok(($GLOBALS['maily'][0]['to'] ?? null) === ['jana@example.cz', 'petr@example.cz'], 'má-li rodič účet, kontaktní e-mail se nepoužije');
delete_post_meta(200, 'sb_kontakt_email'); $GLOBALS['maily'] = [];
$_POST = ['sb_platby_rok' => '2027', 'sb_mail_odeslat' => '1', '_wpnonce_platby_mail' => 'tok', 'sb_mail_davka' => 'k3', 'sb_mail_rodiny' => ['200'], 'kontakt' => ['200' => 'eva@example.cz']];
sb_platby_tab();
ok(get_post_meta(200, 'sb_kontakt_email') === 'eva@example.cz' && ($GLOBALS['maily'][0]['to'] ?? null) === ['eva@example.cz'], 'zadaný e-mail se uloží i při odeslání a rovnou se použije');
$_POST = ['sb_platby_rok' => '2027', 'sb_kontakt_ulozit' => '1', '_wpnonce_platby_mail' => 'tok', 'kontakt' => ['200' => '']];
sb_platby_tab();
ok(get_post_meta(200, 'sb_kontakt_email') === '', 'smazáním pole se kontaktní e-mail odebere');
$GLOBALS['roles'] = ['author'];
$_POST = ['sb_platby_rok' => '2027', 'sb_kontakt_ulozit' => '1', '_wpnonce_platby_mail' => 'tok', 'kontakt' => ['200' => 'x@example.cz']];
sb_platby_tab();
ok(get_post_meta(200, 'sb_kontakt_email') === '', 'vedoucí kontaktní e-mail nezmění');

echo "== 14. Dospělí členové ==\n";
reset_data(); $GLOBALS['roles'] = ['author'];
user(14, 'Eva', 'Dvořáková', 'eva.d@example.cz');
update_option('pozadavky_dle_roku', [$tato => ['1' => 16, '2' => 20, '3' => 24, 'dosp' => 10, 'sazba' => 50], $cil => ['1' => 16, '2' => 20, '3' => 24, 'dosp' => 10, 'sazba' => 50]]);
update_option('sb_prispevky', [$cil => ['1' => 1200, '2' => 1100, '3' => 1000, 'dosp' => 1500, 'ucet' => '2300221146/2010', 'od' => date('Y-m-d', strtotime('-1 day')), 'do' => date('Y-m-d', strtotime('+30 days'))]]);
update_option('sb_revize', ['otevrena' => true, 'rok' => $cil]);
brigada(801, $tato . '-06-01', 100, 2, 8);   // Novákovi 16 z 20 h
// B: rodič, který je členem
$ulozit($oba, ['novy_dospely' => 'Novák Petr']);
ok(sb_dospeli_rodiny(100)[0]['jmeno'] === 'Novák Petr' && sb_dospeli_rodiny(100)[0]['od_roku'] === $cil, 'rodiči lze v rodině vyznačit, že je členem (od příštího roku)');
$i = sb_prispevek_rodiny(get_post(100), $cil);
ok($i['zaklad_deti'] === 2200 && $i['zaklad_dospeli'] === 1500 && $i['brigady']['castka'] === 200 && $i['celkem'] === 3900, "příspěvek $cil: děti 2 × 1 100 + rodič 1 500 + doplatek 200 = 3 900 Kč (jedna částka)");
ok(strpos(sb_prispevek_html($i, ''), 'Příspěvek dospělých členů (Novák Petr)') !== false && strpos(sb_platba_zprava($i), 'Novák Petr') !== false, 'v rozpisu i zprávě pro příjemce je i dospělý člen');
ok(sb_pozadavek_hodin(100, $cil, get_option('pozadavky_dle_roku')[$cil]) === 20, 'brigádní požadavek rodiny se dospělým členem nemění');
// C: dítě dospěje
$o = $oba; $o[1]['dospely'] = '1';
$h = $ulozit($o);
$dosp = null; foreach ($GLOBALS['posts'] as $p) if ($p->post_type === 'rodina' && $p->post_title === 'Novák Tomáš') $dosp = $p;
ok($dosp && sb_je_dospely($dosp->ID) && count(get_post_meta(100, 'deti_rodiny')) === 1, 'dítě označené „Dospělý člen" odejde z rodiny a vznikne samostatný dospělý člen');
ok(strpos($h, 'samostatnými dospělými členy: Novák Tomáš') !== false, 'správce vidí, kdo se osamostatnil');
ok(sb_pocet_deti_pro_rok(100, $tato) === 2 && sb_pocet_deti_pro_rok(100, $cil) === 1, "v roce $tato rodina platí za 2 děti, v roce $cil za 1");
ok(!sb_rodina_v_roce($dosp, $tato) && sb_rodina_v_roce($dosp, $cil), "dospělý člen se počítá až od roku $cil (žádné dvojí placení)");
$d = sb_prispevek_rodiny($dosp, $cil);
ok($d && $d['je_dospely'] && $d['zaklad'] === 1500 && $d['celkem'] === 1500 && sb_platba_zprava($d) === "Poplatek $cil Novák Tomáš", 'dospělý člen platí příspěvek dospělého člena (1 500 Kč)');
ok(sb_pozadavek_hodin($dosp->ID, $cil, get_option('pozadavky_dle_roku')[$cil]) === 10, 'dospělý člen má osobní brigádní požadavek (10 h)');
brigada(802, $cil . '-03-01', $dosp->ID, 1, 4);
$bp = sb_brigadni_poplatek($dosp, $cil);
ok($bp['odpracovano'] === 4 && $bp['chybi'] === 6 && $bp['castka'] === 0, 'hodiny se dospělému evidují (4 z 10), doplatek se neúčtuje');
// D: poslední dítě dospěje → rodina do archivu, rodič-člen se osamostatní
$h = $ulozit([0 => ['puvodni' => 'Nováková Klára', 'jmeno' => 'Nováková Klára', 'oddil' => 'Lišky (světlušky)', 'dospely' => '1']]);
$a = sb_rodina_archiv(100);
ok($a && $a['od_roku'] === $cil && strpos($h, 'přesunuta do archivu') !== false, 'když rodině nezůstane dítě, rodina se archivuje');
$petr = null; $klara = null;
foreach ($GLOBALS['posts'] as $p) { if ($p->post_title === 'Novák Petr') $petr = $p; if ($p->post_title === 'Nováková Klára') $klara = $p; }
ok($petr && sb_je_dospely($petr->ID) && $klara && sb_je_dospely($klara->ID), 'rodič-člen i dospělé dítě jsou nyní samostatní dospělí členové');
ok(sb_dospeli_pro_rok(100, $tato) === [] && sb_dospeli_pro_rok(100, $cil) === [] && sb_prispevek_rodiny(get_post(100), $cil) === null, 'archivovaná rodina už v dalším roce nic neplatí (rodič platí sám za sebe)');
ok(sb_prispevek_rodiny($petr, $cil)['celkem'] === 1500, 'rodič platí jako samostatný dospělý člen 1 500 Kč');
// E/F: automatická archivace jen při odchodu dětí
reset_data(); update_option('sb_revize', ['otevrena' => true, 'rok' => $cil]);
$o = $oba; $o[0]['nechodi'] = '1'; $o[1]['nechodi'] = '1';
$ulozit($o);
ok(sb_rodina_archiv(100) !== null, 'odejdou-li všechny děti, rodina se archivuje');
post(400, 'rodina', 'Nová', '2026-10-10 10:00:00');
$_POST = ['edit_rodina_id' => '400', 'ulozit_rodinu' => '1', '_wpnonce_ulozit_rodinu' => 'tok', 'novy_nazev' => 'Nová', 'rodice' => [], 'deti' => []];
sb_prehled_rodin();
ok(sb_rodina_archiv(400) === null, 'nová rodina bez dětí se při uložení nearchivuje');
// G: Správa dospělých – nový člen
reset_data(); user(14, 'Eva', 'Dvořáková', 'eva.d@example.cz'); update_option('sb_revize', ['otevrena' => true, 'rok' => $cil]);
$_POST = ['sb_dosp_novy' => '1', '_wpnonce_dospeli' => 'tok', 'dosp_jmeno' => 'Dvořáková Eva', 'dosp_ucet' => '14', 'dosp_kontakt' => ''];
$h = sb_sprava_dospelych();
$eva = null; foreach ($GLOBALS['posts'] as $p) if ($p->post_title === 'Dvořáková Eva') $eva = $p;
ok($eva && sb_je_dospely($eva->ID) && get_post_meta($eva->ID, 'rodice_rodiny') === [14], 'nový dospělý člen s účtem na webu');
$_POST = ['sb_dosp_novy' => '1', '_wpnonce_dospeli' => 'tok', 'dosp_jmeno' => 'Dvořáková Eva', 'dosp_ucet' => '0'];
ok(strpos(sb_sprava_dospelych(), 'už existuje') !== false, 'duplicitní jméno se odmítne');
$_POST = ['sb_dosp_novy' => '1', '_wpnonce_dospeli' => 'tok', 'dosp_jmeno' => 'Někdo Jiný', 'dosp_ucet' => '11'];
ok(strpos(sb_sprava_dospelych(), 'nelze přiřadit') !== false, 'účet rodiče z jiné rodiny nelze přiřadit');
$_POST = ['sb_dosp_novy' => '1', '_wpnonce_dospeli' => 'tok', 'dosp_jmeno' => 'Bezúčtový Karel', 'dosp_ucet' => '0', 'dosp_kontakt' => 'karel@example.cz'];
sb_sprava_dospelych();
$karel = null; foreach ($GLOBALS['posts'] as $p) if ($p->post_title === 'Bezúčtový Karel') $karel = $p;
ok($karel && sb_emaily_prispevku($karel->ID) === ['karel@example.cz'], 'člen bez účtu s kontaktním e-mailem');
$_POST = []; $h = sb_prehled_rodin();
ok(strpos($h, 'Dvořáková Eva') === false && strpos(sb_sprava_dospelych(), 'Dvořáková Eva') !== false, 'dospělí jsou ve Správě dospělých, ne ve Správě rodin');
// H: Moje brigády dospělého
update_option('pozadavky_dle_roku', [intval(date('Y')) => ['1' => 16, '2' => 20, '3' => 24, 'dosp' => 10, 'sazba' => 50]]);
brigada(803, date('Y') . '-01-05', $eva->ID, 1, 3);
$eva->post_date = (intval(date('Y')) - 1) . '-01-01 10:00:00';
$GLOBALS['roles'] = ['rodic']; $GLOBALS['user_id'] = 14; $GLOBALS['logged'] = true; $_POST = [];
$h = sb_moje_brigady_shortcode();
$GLOBALS['logged'] = false; $GLOBALS['user_id'] = 0; $GLOBALS['roles'] = ['author'];
ok(strpos($h, 'Dospělý člen: <strong>Dvořáková Eva') !== false && strpos($h, 'Požadavek:</td><td><strong>10 h') !== false && strpos($h, 'dospělý člen – bez doplatku') !== false, 'Moje brigády dospělého: osobní požadavek 10 h, bez doplatku');
// I: stal se rodičem
$_POST = ['sb_dosp_rodic' => '1', '_wpnonce_dospeli' => 'tok', 'edit_dospely_id' => (string) $eva->ID, 'dosp_cil' => '0', 'dosp_nova_rodina' => 'Dvořákovi'];
$h = sb_sprava_dospelych();
$dr = null; foreach ($GLOBALS['posts'] as $p) if ($p->post_title === 'Dvořákovi') $dr = $p;
ok($dr && get_post_meta($dr->ID, 'rodice_rodiny') === [14] && sb_dospeli_rodiny($dr->ID)[0]['jmeno'] === 'Dvořáková Eva', '„Stal se rodičem": vznikne rodina, účet i členství přejdou do ní');
ok(sb_rodina_archiv($eva->ID)['od_roku'] === $cil && get_post_meta($eva->ID, 'rodice_rodiny') === [], 'samostatný záznam se archivuje od příštího roku');
$_POST = ['sb_dosp_ukoncit' => '1', '_wpnonce_dospeli' => 'tok', 'edit_dospely_id' => (string) $karel->ID, 'dosp_poznamka' => 'odstěhoval se'];
sb_sprava_dospelych();
ok(sb_rodina_archiv($karel->ID)['od_roku'] === $cil && sb_prispevek_rodiny($karel, $cil) === null, 'ukončení členství: od příštího roku nic neplatí');
// J: oprávnění
update_option('sb_revize', ['otevrena' => false, 'rok' => $cil]);
$_POST = ['sb_dosp_novy' => '1', '_wpnonce_dospeli' => 'tok', 'dosp_jmeno' => 'Mimo Revizi'];
$h = sb_sprava_dospelych();
$mimo = false; foreach ($GLOBALS['posts'] as $p) if ($p->post_title === 'Mimo Revizi') $mimo = true;
ok(!$mimo && strpos($h, 'uzavřené') !== false, 'vedoucí mimo revizi dospělé nepřidá (jen prohlíží)');
$GLOBALS['roles'] = ['rodic'];
ok(strpos(sb_sprava_dospelych(), 'Nemáš oprávnění') !== false, 'rodič do Správy dospělých nesmí');

echo "== 15. Vedoucí jako členové a opravy z review ==\n";
reset_data(); update_option('sb_revize', ['otevrena' => true, 'rok' => $cil]);
update_option('sb_prispevky', [$cil => ['1' => 1200, '2' => 1100, '3' => 1000, 'dosp' => 1500, 'ucet' => '2300221146/2010', 'od' => date('Y-m-d', strtotime('-1 day')), 'do' => date('Y-m-d', strtotime('+30 days'))]]);
$GLOBALS['users'][12]->roles = ['rodic', 'author'];   // Petr Novák je vedoucí
ok(sb_dospeli_pro_rok(100, $cil) === ['Novák Petr'], 'rodič-vedoucí je automaticky dospělým členem rodiny');
ok(sb_prispevek_rodiny(get_post(100), $cil)['zaklad_dospeli'] === 1500, 'jeho příspěvek se rodině přičte');
update_post_meta(100, 'dospeli_clenove', [['jmeno' => 'Petr Novák', 'od_roku' => $cil]]);
ok(count(sb_dospeli_pro_rok(100, $cil)) === 1, 'zadaný ručně i automaticky (jiné pořadí slov) = počítá se jen jednou');
delete_post_meta(100, 'dospeli_clenove');
$_POST = []; $h = sb_prehled_rodin();
ok(strpos($h, 'vedoucí – člen automaticky') !== false, 'na kartě rodiny je vidět „vedoucí – člen automaticky"');
// osamostatnění vedoucího s účtem po odchodu dětí
$o = $oba; $o[0]['nechodi'] = '1'; $o[1]['nechodi'] = '1';
$ulozit($o);
$petr = null; foreach ($GLOBALS['posts'] as $p) if ($p->post_title === 'Novák Petr' && sb_je_dospely($p->ID)) $petr = $p;
ok($petr && get_post_meta($petr->ID, 'rodice_rodiny') === [12] && !in_array(12, get_post_meta(100, 'rodice_rodiny')), 'vedoucí se osamostatní i s účtem na webu (uvidí své Moje brigády)');
// review 3: ručně zadaný člen převezme účet podle jména
reset_data(); update_option('sb_revize', ['otevrena' => true, 'rok' => $cil]);
update_post_meta(100, 'dospeli_clenove', [['jmeno' => 'Nováková Jana', 'od_roku' => $cil]]);
$o = $oba; $o[0]['nechodi'] = '1'; $o[1]['nechodi'] = '1';
$ulozit($o);
$jana = null; foreach ($GLOBALS['posts'] as $p) if ($p->post_title === 'Nováková Jana' && sb_je_dospely($p->ID)) $jana = $p;
ok($jana && get_post_meta($jana->ID, 'rodice_rodiny') === [11] && sb_emaily_prispevku($jana->ID) === ['jana@example.cz'], 'rodič-člen po archivaci rodiny převezme svůj účet i e-mail');
// review 2: rodič s vlastním záznamem nejde přidat do rodiny
reset_data(); update_option('sb_revize', ['otevrena' => true, 'rok' => $cil]);
$x = sb_vytvorit_dospeleho('Novák Petr', [], null);
$h = $ulozit($oba, ['novy_dospely' => 'Novák Petr']);
ok(sb_dospeli_rodiny(100) === [] && strpos($h, 'Stal se rodičem') !== false, 'člena s vlastním záznamem nelze přidat do rodiny (jinak by platil dvakrát)');
// review 1: obnovení po „Stal se rodičem"
$GLOBALS['roles'] = ['administrator'];
$_POST = ['sb_dosp_rodic' => '1', '_wpnonce_dospeli' => 'tok', 'edit_dospely_id' => (string) $x, 'dosp_cil' => '100'];
sb_sprava_dospelych();
$_POST = ['sb_dosp_obnovit' => '1', '_wpnonce_dospeli' => 'tok', 'edit_dospely_id' => (string) $x];
$h = sb_sprava_dospelych();
ok(sb_rodina_archiv($x) !== null && strpos($h, 'počítal(a) dvakrát') !== false, 'obnovení po „Stal se rodičem" se zablokuje, dokud je člověk členem rodiny');
// review 4: omylem „Dospělý člen" → vrátit
reset_data(); update_option('sb_revize', ['otevrena' => true, 'rok' => $cil]);
$o = $oba; $o[1]['dospely'] = '1';
$ulozit($o);
$tom = sb_najdi_dospeleho_dle_jmena('Novák Tomáš');
ok($tom !== null, 'omylem vznikl samostatný člen');
$h = $ulozit([0 => $oba[0]], ['obnovit' => ['0']]);
ok(sb_najdi_dospeleho_dle_jmena('Novák Tomáš') === null && get_post($tom->ID) === null && count(get_post_meta(100, 'deti_rodiny')) === 2, 'omyl jde vrátit: dítě zpět v rodině, záznam dospělého zrušen');
// review 5: Správa rodin neupraví dospělého
$y = sb_vytvorit_dospeleho('Samostatný Karel', [], null);
$_POST = ['edit_rodina_id' => (string) $y, 'ulozit_rodinu' => '1', '_wpnonce_ulozit_rodinu' => 'tok', 'novy_nazev' => 'X', 'deti' => [0 => ['jmeno' => 'Dítě', 'oddil' => 'Skauti']]];
$h = sb_prehled_rodin();
ok(get_post_meta($y, 'deti_rodiny') === '' && strpos($h, 'nebyla nalezena') !== false, 'Správa rodin odmítne záznam dospělého člena');
ok(strpos(sb_prispevek_email(get_post($y), ['rok' => $cil, 'je_dospely' => true, 'deti' => 0, 'dospeli' => 1, 'dospeli_jmena' => ['Samostatný Karel'], 'za_dospeleho' => 1500, 'zaklad_dospeli' => 1500, 'zaklad' => 1500, 'brigady' => ['v_roce' => false, 'pozadavek' => 0, 'castka' => 0], 'dar' => 0, 'celkem' => 1500, 'stav' => 'nezaplaceno', 'zaplaceno' => 0, 'zbyva' => 1500, 'jmena' => [], 'jmena_qr' => ['Samostatný Karel'], 'rodina' => '', 'ucet' => '2300221146/2010', 'platby' => []])['html'], '<p>Ahoj,</p>') !== false, 'e-mail dospělému začíná „Ahoj,"');

echo "== 16. Admini jako vedoucí, převod rodiny bez dětí ==\n";
reset_data(); update_option('sb_revize', ['otevrena' => true, 'rok' => $cil]);
update_option('sb_prispevky', [$cil => ['1' => 1200, '2' => 1100, '3' => 1000, 'dosp' => 620, 'ucet' => '2300221146/2010', 'od' => date('Y-m-d', strtotime('-1 day')), 'do' => date('Y-m-d', strtotime('+30 days'))]]);
$GLOBALS['users'][12]->roles = ['administrator'];
ok(sb_dospeli_pro_rok(100, $cil) === ['Novák Petr'] && sb_rodina_vedouci(100), 'admin (role vyšší než vedoucí) je dospělým členem a rodina je bez doplatku');
post(600, 'rodina', 'Petrovičtí', '2024-01-01 10:00:00');
user(21, 'Petra', 'Petrovická', 'petra@example.cz'); $GLOBALS['users'][21]->roles = ['rodic', 'author'];
user(22, 'Pavlína', 'Petrovická', 'pavlina@example.cz'); $GLOBALS['users'][22]->roles = ['rodic', 'author'];
update_post_meta(600, 'rodice_rodiny', [21, 22]);
$_POST = []; $h = sb_prehled_rodin();
ok(strpos(substr($h, strpos($h, "id='sb-rodina-600'"), 6000), 'Převést na dospělé členy') !== false, 'rodina bez dětí s dospělými členy má tlačítko „Převést na dospělé členy"');
ok(strpos(substr($h, strpos($h, "id='sb-rodina-100'"), 6000), 'prevest_na_dospele') === false, 'rodina s dětmi tlačítko nemá');
$_POST = ['edit_rodina_id' => '600', 'prevest_na_dospele' => '1', '_wpnonce_prevest' => 'tok'];
$h = sb_prehled_rodin();
$pa = sb_najdi_dospeleho_dle_jmena('Petrovická Petra'); $pv = sb_najdi_dospeleho_dle_jmena('Petrovická Pavlína');
ok(sb_rodina_archiv(600)['od_roku'] === $cil && $pa && $pv && get_post_meta($pa->ID, 'rodice_rodiny') === [21] && get_post_meta($pv->ID, 'rodice_rodiny') === [22], 'převod: rodina do archivu, obě jsou samostatné dospělé členky i se svými účty');
ok(sb_prispevek_rodiny($pa, $cil)['celkem'] === 620 && sb_prispevek_rodiny(get_post(600), $cil) === null, "od roku $cil platí každá zvlášť 620 Kč, archivovaná rodina nic");
$_POST = ['edit_rodina_id' => '100', 'prevest_na_dospele' => '1', '_wpnonce_prevest' => 'tok'];
$h = sb_prehled_rodin();
ok(sb_rodina_archiv(100) === null && strpos($h, 'jen rodinu bez dětí') !== false, 'rodinu s dětmi převést nelze');

$_POST = ['sb_dosp_novy' => '1', '_wpnonce_dospeli' => 'tok', 'dosp_jmeno' => 'Bez Účtu', 'dosp_ucet' => '0'];
$GLOBALS['roles'] = ['administrator']; sb_sprava_dospelych();
$bu = sb_najdi_dospeleho_dle_jmena('Bez Účtu');
$_POST = ['sb_dosp_rodic' => '1', '_wpnonce_dospeli' => 'tok', 'edit_dospely_id' => (string) $bu->ID, 'dosp_cil' => '0', 'dosp_nova_rodina' => 'Bezúčtovi'];
sb_sprava_dospelych();
$bur = null; foreach ($GLOBALS['posts'] as $p) if ($p->post_title === 'Bezúčtovi') $bur = $p;
ok($bur && get_post_meta($bur->ID, 'rodice_rodiny') === [], 'člen bez účtu přejde do nové rodiny bez „prázdného" účtu');

$b = ['rok' => 2027, 'dar' => 500, 'rodina' => 'Podlesní / Hořákovi / Kubátovi', 'jmena' => ['Podlesný Petr Maxmilián', 'Kubát Josef Bartoloměj', 'Hořák Vojtěch Kryštof', 'Hořáková Jana Marie']];
ok(sb_platba_zprava($b) === 'Poplatek 2027 Petr, Josef, Vojtěch, Jana, dar 500', 'různá příjmení v rodině: křestní jména (' . sb_platba_zprava($b) . ')');
$b = ['rok' => 2027, 'dar' => 1000, 'rodina' => 'Novákovi', 'jmena' => ['Svobodová Anna Kristýna', 'Dvořák Tadeáš Bartoloměj', 'Novák Maximilián Ondřej']];
ok(sb_platba_zprava($b) === 'Poplatek 2027 Novákovi: Anna, Tadeáš, Maximilián, dar 1000', 'příjmení mimo název rodiny: první slovo po příjmení (' . sb_platba_zprava($b) . ')');

echo "== 17. Oprava a odebrání dospělého člena v rodině ==\n";
reset_data(); update_option('sb_revize', ['otevrena' => true, 'rok' => $cil]);
$ulozit($oba, ['novy_dospely' => 'Malíková Lenka']);
$_POST = []; $h = sb_prehled_rodin();
ok(strpos($h, "name='dospely_jmeno[0]' value='Malíková Lenka'") !== false, 'jméno dospělého člena je v kartě rodiny editovatelné');
$ulozit($oba, ['dospely_jmeno' => ['0' => 'Masopustová Lenka (Lenka)']]);
ok(sb_dospeli_rodiny(100)[0]['jmeno'] === 'Masopustová Lenka' && sb_dospeli_rodiny(100)[0]['od_roku'] === $cil, 'překlep ve jméně jde opravit (přezdívka se odstraní, rok zůstává)');
$ulozit($oba, ['dospely_konci' => ['0']]);
ok(sb_dospeli_rodiny(100) === [] && sb_dospeli_archiv(100) === [], 'člen zadaný omylem v téže revizi se odebere úplně (bez archivu)');
update_post_meta(100, 'dospeli_clenove', [['jmeno' => 'Starý Člen', 'od_roku' => $tato - 1]]);
$ulozit($oba, ['dospely_konci' => ['0']]);
ok(sb_dospeli_archiv(100)[0]['do_roku'] === $cil && sb_dospeli_pro_rok(100, $tato) === ['Starý Člen'] && sb_dospeli_pro_rok(100, $cil) === [], 'dřívější člen se ukončí archivací (letos platí, od příštího roku ne)');

$ulozit($oba, ['novy_dospely' => 'Malíková Lenka']);
$ulozit($oba, ['novy_dospely' => 'Malíková Lenka']);   // F5 – stejný formulář znovu
ok(count(sb_dospeli_rodiny(100)) === 1, 'obnovení stránky nepřidá stejného dospělého člena podruhé');
update_post_meta(100, 'dospeli_clenove', [['jmeno' => 'Malíková Lenka', 'od_roku' => $cil], ['jmeno' => 'Malíková Lenka', 'od_roku' => $cil]]);
$ulozit($oba, ['dospely_jmeno' => ['0' => 'Masopustová Lenka', '1' => '']]);
ok(sb_dospeli_rodiny(100) === [['jmeno' => 'Masopustová Lenka', 'od_roku' => $cil]] && sb_dospeli_archiv(100) !== null && count(array_filter(sb_dospeli_archiv(100), fn($a) => $a['jmeno'] === 'Malíková Lenka')) === 0, 'vymazané jméno u nového člena = odebrán, nic v archivu');
$_POST = []; $h = sb_prehled_rodin();
ok(strpos($h, "name='dospely_jmeno[0]' value='Masopustová Lenka' maxlength='100' required") === false, 'pole jména není povinné (jde vymazat)');

echo "\n" . ($fail === 0 ? "VŠE V POŘÁDKU ($pass kontrol)" : "SELHALO: $fail (prošlo $pass)") . "\n";
exit($fail === 0 ? 0 : 1);
