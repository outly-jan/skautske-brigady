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

echo "\n" . ($fail === 0 ? "VŠE V POŘÁDKU ($pass kontrol)" : "SELHALO: $fail (prošlo $pass)") . "\n";
exit($fail === 0 ? 0 : 1);
