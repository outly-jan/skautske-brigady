<?php
/*
Plugin Name: Skautské brigády
Description: Evidence brigádnických hodin pro rodiče ve skautském středisku.
Version: 3.0
Author: Honza & Copilot
*/

if (!defined('ABSPATH')) exit;

// Po deployi: každý PHP-FPM proces invaliduje svůj OPcache záznam
$_sb_flag = __DIR__ . '/.sb_deploy_flag';
if (file_exists($_sb_flag)) {
    @unlink($_sb_flag);
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate(__FILE__, true);
    }
}
unset($_sb_flag);

// CSV Export: roční výpis
function sb_export_rocni_vypis_csv() {
    if (!isset($_GET['sb_export']) || $_GET['sb_export'] !== 'rocni_vypis') return;
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'sb_export_rocni_vypis')) {
        wp_die('Neplatný bezpečnostní token.');
    }
    $roles = wp_get_current_user()->roles;
    if (!array_intersect($roles, ['administrator', 'spravce_brigad', 'author'])) {
        wp_die('Nemáš oprávnění.');
    }

    $rok       = isset($_GET['rok']) ? intval($_GET['rok']) : intval(date('Y'));
    $rodiny    = get_posts(['post_type' => 'rodina', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);
    $brigady   = get_posts(['post_type' => 'brigada', 'numberposts' => -1]);
    $pozadavky = get_option('pozadavky_dle_roku', []);
    $poz_rok   = $pozadavky[$rok] ?? null;
    $sazba     = isset($poz_rok['sazba']) ? floatval($poz_rok['sazba']) : 0;

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="brigady-' . $rok . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    // BOM pro správné zobrazení UTF-8 v Excelu
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Rodina', 'Děti', 'Počet dětí', 'Odpracováno (h)', 'Požadavek (h)', 'Rozdíl (h)', 'Poplatek (Kč)', 'Z toho převod z předchozího roku (h)'], ';');

    foreach ($rodiny as $r) {
        if (!sb_rodina_v_roce($r, $rok)) continue;

        $deti_meta  = get_post_meta($r->ID, 'deti_rodiny', true);
        $pocet_deti = sb_pocet_deti_pro_rok($r->ID, $rok);

        $pozadavek = $poz_rok ? sb_pozadavek_hodin($r->ID, $rok, $poz_rok) : 0;

        $odpracovano = 0;
        foreach ($brigady as $b) {
            $datum_raw = get_post_meta($b->ID, 'datum_brigady', true);
            if ($datum_raw && preg_match('/\b(19|20)\d{2}\b/', $datum_raw, $m) && intval($m[0]) === $rok) {
                $celkem = sb_celkem_hodin($b->ID, $r->ID);
                if ($celkem !== null) {
                    $odpracovano += $celkem;
                } else {
                    $walkins = get_post_meta($b->ID, 'walkin_ucastnici', true);
                    if (is_array($walkins)) {
                        foreach ($walkins as $w) {
                            if (($w['jmeno'] ?? '') === $r->post_title) {
                                $odpracovano += max(1, intval($w['pocet'] ?? 1)) * intval($w['hodiny'] ?? 0);
                                break;
                            }
                        }
                    }
                }
            }
        }
        $prevod       = sb_prevod_hodin($r, $rok);
        $odpracovano += $prevod;

        $rozdil   = $odpracovano - $pozadavek;
        $poplatek = sb_bez_doplatku($r->ID) ? 0 : max(0, $pozadavek - $odpracovano) * $sazba;

        $jmena = [];
        if (is_array($deti_meta)) {
            foreach ($deti_meta as $dite) {
                $jmena[] = ($dite['jmeno'] ?? '') . ' – ' . ($dite['oddil'] ?? '');
            }
        }
        foreach (sb_deti_archiv($r->ID) as $dite) {
            if (is_array($dite) && isset($dite['od_roku']) && intval($dite['od_roku']) > intval($rok)) {
                $jmena[] = ($dite['jmeno'] ?? '') . ' – ' . ($dite['oddil'] ?? '');
            }
        }

        fputcsv($output, [
            $r->post_title,
            implode(', ', $jmena),
            $pocet_deti,
            $odpracovano,
            $pozadavek,
            $rozdil,
            number_format($poplatek, 2, ',', ' '),
            $prevod
        ], ';');
    }

    fclose($output);
    exit;
}
add_action('init', 'sb_export_rocni_vypis_csv');

// CSV Export: přehled účastníků konkrétní brigády
function sb_export_brigada_csv() {
    if (!isset($_GET['sb_export']) || $_GET['sb_export'] !== 'brigada_ucastnici') return;
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'sb_export_brigada')) {
        wp_die('Neplatný bezpečnostní token.');
    }
    $roles = wp_get_current_user()->roles;
    if (!array_intersect($roles, ['administrator', 'spravce_brigad', 'author'])) {
        wp_die('Nemáš oprávnění.');
    }
    $brigada_id = isset($_GET['brigada_id']) ? intval($_GET['brigada_id']) : 0;
    if (!$brigada_id) wp_die('Neplatné ID brigády.');
    $brigada_post = get_post($brigada_id);
    if (!$brigada_post || $brigada_post->post_type !== 'brigada') wp_die('Brigáda nenalezena.');

    $nazev  = $brigada_post->post_title;
    $datum  = get_post_meta($brigada_id, 'datum_brigady', true);
    $rodiny = get_posts(['post_type' => 'rodina', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);
    $file   = 'brigada-' . sanitize_title($nazev) . ($datum ? '-' . $datum : '') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Sestavení řádků pro CSV
    $walkins_csv     = get_post_meta($brigada_id, 'walkin_ucastnici', true);
    if (!is_array($walkins_csv)) $walkins_csv = [];
    $walkin_jmena_csv = array_column($walkins_csv, 'jmeno');

    $csv_radky          = [];
    $jmena_prihlasenych = [];
    foreach ($rodiny as $r) {
        $pocet = intval(get_post_meta($brigada_id, 'ucastnici_' . $r->ID, true));
        if (!$pocet) continue;
        $hodiny_osob_csv = get_post_meta($brigada_id, 'hodiny_osob_' . $r->ID, true);
        $odp             = get_post_meta($brigada_id, 'odpracovano_' . $r->ID, true);
        $typ             = in_array($r->post_title, $walkin_jmena_csv, true) ? 'Walk-in' : 'Přihlášena';
        if (is_array($hodiny_osob_csv) && !empty($hodiny_osob_csv)) {
            $csv_radky[] = [$r->post_title, $pocet, implode(', ', $hodiny_osob_csv) . ' h (ind.)', array_sum($hodiny_osob_csv), $typ];
        } else {
            $h_os = ($odp !== '' && $odp !== null) ? intval($odp) : '';
            $csv_radky[] = [$r->post_title, $pocet, $h_os, ($h_os !== '') ? $h_os * $pocet : '', $typ];
        }
        $jmena_prihlasenych[] = $r->post_title;
    }
    foreach ($walkins_csv as $w) {
        if (in_array($w['jmeno'], $jmena_prihlasenych, true)) continue;
        $pocet  = intval($w['pocet'] ?? 1);
        $hodiny = intval($w['hodiny']);
        $csv_radky[] = [$w['jmeno'], $pocet, $hodiny, $pocet * $hodiny, 'Walk-in'];
    }
    usort($csv_radky, fn($a, $b) => strcmp(mb_strtolower($a[0]), mb_strtolower($b[0])));

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Brigáda', $nazev], ';');
    fputcsv($output, ['Datum', $datum ?: ''], ';');
    fputcsv($output, [], ';');
    fputcsv($output, ['Rodina / jméno', 'Osob', 'Hod./os.', 'Celkem odpracováno (h)', 'Způsob přihlášení'], ';');
    foreach ($csv_radky as $radek) {
        fputcsv($output, $radek, ';');
    }
    fclose($output);
    exit;
}
add_action('init', 'sb_export_brigada_csv');

function sb_export_prezencni_listina() {
    if (!isset($_GET['sb_export']) || $_GET['sb_export'] !== 'prezencni_listina') return;
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'sb_prezencni_listina')) {
        wp_die('Neplatný bezpečnostní token.');
    }
    $roles = wp_get_current_user()->roles;
    if (!array_intersect($roles, ['administrator', 'spravce_brigad', 'author'])) {
        wp_die('Nemáš oprávnění.');
    }
    $brigada_id = isset($_GET['brigada_id']) ? intval($_GET['brigada_id']) : 0;
    if (!$brigada_id) wp_die('Neplatné ID brigády.');
    $brigada_post = get_post($brigada_id);
    if (!$brigada_post || $brigada_post->post_type !== 'brigada') wp_die('Brigáda nenalezena.');

    $nazev     = $brigada_post->post_title;
    $datum_raw = get_post_meta($brigada_id, 'datum_brigady', true);
    $datum_fmt = $datum_raw ? date_i18n('j. n. Y', strtotime($datum_raw)) : '';
    $misto     = get_post_meta($brigada_id, 'misto_konani', true);
    $cas       = get_post_meta($brigada_id, 'cas_brigady', true);

    $rodiny  = get_posts(['post_type' => 'rodina', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);
    $walkins = get_post_meta($brigada_id, 'walkin_ucastnici', true);
    if (!is_array($walkins)) $walkins = [];
    $walkin_jmena = array_column($walkins, 'jmeno');

    $radky = [];
    $jmena_prihlasenych = [];
    foreach ($rodiny as $r) {
        $pocet = intval(get_post_meta($brigada_id, 'ucastnici_' . $r->ID, true));
        if (!$pocet) continue;
        $radky[] = ['jmeno' => $r->post_title, 'osob' => $pocet];
        $jmena_prihlasenych[] = $r->post_title;
    }
    foreach ($walkins as $w) {
        if (in_array($w['jmeno'], $jmena_prihlasenych, true)) continue;
        $radky[] = ['jmeno' => $w['jmeno'], 'osob' => max(1, intval($w['pocet'] ?? 1))];
    }
    usort($radky, fn($a, $b) => strcmp(mb_strtolower($a['jmeno']), mb_strtolower($b['jmeno'])));

    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Prezenční listina – <?php echo esc_html($nazev); ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, sans-serif; font-size: 13px; color: #000; padding: 20px 30px; max-width: 900px; }
h1 { font-size: 20px; margin-bottom: 6px; }
.meta { font-size: 13px; color: #444; margin-bottom: 20px; line-height: 1.8; }
table { width: 100%; border-collapse: collapse; margin-top: 4px; }
th, td { border: 1px solid #555; padding: 7px 10px; vertical-align: middle; }
th { background: #e8e8e8; font-weight: bold; text-align: center; font-size: 12px; }
td.rodina { font-weight: 600; }
td.ucastnik { text-align: center; color: #555; font-size: 12px; width: 70px; }
td.cas { width: 120px; height: 30px; }
td.podpis { width: 200px; }
.btn-row { margin-bottom: 18px; }
.btn-print { background: #1565c0; color: #fff; border: none; padding: 9px 22px; font-size: 14px; border-radius: 4px; cursor: pointer; }
@media print {
    .btn-row { display: none; }
    body { padding: 8px 12px; }
    th { background: #e0e0e0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
</head>
<body>
<div class="btn-row">
    <button class="btn-print" onclick="window.print()">🖨️ Tisk / Uložit jako PDF</button>
</div>
<h1><?php echo esc_html($nazev); ?></h1>
<div class="meta">
    <?php if ($datum_fmt): ?>📅 <?php echo esc_html($datum_fmt); ?><br><?php endif; ?>
    <?php if ($misto): ?>📍 <?php echo esc_html($misto); ?><br><?php endif; ?>
    <?php if ($cas): ?>⏰ <?php echo esc_html($cas); ?><?php endif; ?>
</div>
<table>
    <thead>
    <tr>
        <th style="width:200px; text-align:left;">Rodina</th>
        <th style="width:70px;">Účastník</th>
        <th style="width:120px;">Čas příchodu</th>
        <th style="width:120px;">Čas odchodu</th>
        <th style="width:200px;">Podpis</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($radky as $rl): ?>
        <?php for ($i = 1; $i <= $rl['osob']; $i++): ?>
        <tr>
            <?php if ($i === 1): ?>
            <td class="rodina" rowspan="<?php echo intval($rl['osob']); ?>"><?php echo esc_html($rl['jmeno']); ?></td>
            <?php endif; ?>
            <td class="ucastnik">č.&nbsp;<?php echo $i; ?></td>
            <td class="cas"></td>
            <td class="cas"></td>
            <?php if ($i === 1): ?>
            <td class="podpis" rowspan="<?php echo intval($rl['osob']); ?>"></td>
            <?php endif; ?>
        </tr>
        <?php endfor; ?>
    <?php endforeach; ?>
    <?php for ($w = 0; $w < 10; $w++): ?>
    <tr>
        <td class="cas"></td>
        <td class="cas"></td>
        <td class="cas"></td>
        <td class="cas"></td>
        <td class="podpis"></td>
    </tr>
    <?php endfor; ?>
    </tbody>
</table>
</body>
</html>
    <?php
    exit;
}
add_action('init', 'sb_export_prezencni_listina');

// Vrátí celkový počet odpracovaných hodin rodiny na brigádě.
// Podporuje starý formát (h/os. × počet) i nový (individuální hodiny na osobu).
function sb_celkem_hodin($brigada_id, $rodina_id) {
    $hodiny_osob = get_post_meta($brigada_id, 'hodiny_osob_' . $rodina_id, true);
    if (is_array($hodiny_osob) && !empty($hodiny_osob)) {
        return array_sum($hodiny_osob);
    }
    $h = get_post_meta($brigada_id, 'odpracovano_' . $rodina_id, true);
    if ($h === '' || $h === null) return null;
    $n = max(1, intval(get_post_meta($brigada_id, 'ucastnici_' . $rodina_id, true)));
    return intval($h) * $n;
}

// Archivované děti rodiny (děti, které už skauta nenavštěvují) – vždy pole.
function sb_deti_archiv($rodina_id) {
    $archiv = get_post_meta($rodina_id, 'deti_archiv', true);
    return is_array($archiv) ? $archiv : [];
}

// Údaje o archivaci rodiny (['od_roku', 'poznamka', 'datum']) nebo null, pokud rodina archivovaná není.
function sb_rodina_archiv($rodina_id) {
    $a = get_post_meta($rodina_id, 'rodina_archiv', true);
    return (is_array($a) && !empty($a['od_roku'])) ? $a : null;
}

// Počet dětí rodiny pro daný rok ze současného stavu (bez snímku):
// aktivní děti + děti, které odešly až od pozdějšího roku, než je $rok.
function sb_pocet_deti_ze_stavu($rodina_id, $rok) {
    $deti  = get_post_meta($rodina_id, 'deti_rodiny', true);
    $pocet = is_array($deti) ? count($deti) : 0;
    foreach (sb_deti_archiv($rodina_id) as $d) {
        if (is_array($d) && isset($d['od_roku']) && intval($d['od_roku']) > intval($rok)) {
            $pocet++;
        }
    }
    return $pocet;
}

// Vrátí počet dětí rodiny pro daný rok — ze snímku, nebo ze současného stavu jako záložní hodnotu.
function sb_pocet_deti_pro_rok($rodina_id, $rok) {
    $snapshot = get_post_meta($rodina_id, 'pocet_deti_' . intval($rok), true);
    if ($snapshot !== '' && $snapshot !== false && $snapshot !== null) {
        return intval($snapshot);
    }
    return sb_pocet_deti_ze_stavu($rodina_id, $rok);
}

// Vrátí true, pokud rodina vznikla před koncem skautského roku X (tj. do 31. 8. roku X)
// a zároveň není od roku X (nebo dříve) v archivu.
function sb_rodina_v_roce($rodina, $rok) {
    $archiv = sb_rodina_archiv($rodina->ID);
    if ($archiv && intval($archiv['od_roku']) <= intval($rok)) {
        return false;
    }
    // Jednotka vzniklá převodem (dospělý člen) platí až od stanoveného roku
    $od = intval(get_post_meta($rodina->ID, 'sb_od_roku', true));
    if ($od && intval($rok) < $od) return false;
    $konec_roku = new DateTime(intval($rok) . '-08-31 23:59:59');
    $vznik      = new DateTime($rodina->post_date);
    return $vznik <= $konec_roku;
}

// Vytvoří samostatnou jednotku „dospělý člen" (od roku $od_roku, nebo dle data vzniku, je-li null).
function sb_vytvorit_dospeleho($jmeno, $rodice = [], $od_roku = null, $z_rodiny = 0) {
    $jmeno = trim(preg_replace('/\s*\([^)]*\)/u', '', (string) $jmeno));
    if ($jmeno === '') return 0;
    $nazev = $jmeno; $i = 2;
    while (sb_post_exists_by_title('rodina', $nazev)) { $nazev = $jmeno . ' (' . $i . ')'; $i++; }
    $id = wp_insert_post(['post_title' => $nazev, 'post_type' => 'rodina', 'post_status' => 'publish']);
    if ($id && !is_wp_error($id)) {
        update_post_meta($id, 'sb_typ', 'dospely');
        update_post_meta($id, 'rodice_rodiny', array_values(array_map('intval', (array) $rodice)));
        if ($od_roku) update_post_meta($id, 'sb_od_roku', intval($od_roku));
        if ($z_rodiny) update_post_meta($id, 'sb_z_rodiny', intval($z_rodiny));
        return intval($id);
    }
    return 0;
}

// Aktivní samostatný dospělý člen se stejným jménem (pro kontrolu dvojí evidence), nebo null.
function sb_najdi_dospeleho_dle_jmena($jmeno) {
    $k = sb_jmeno_klic($jmeno);
    if ($k === '') return null;
    foreach (get_posts(['post_type' => 'rodina', 'numberposts' => -1]) as $r) {
        if (sb_je_dospely($r->ID) && !sb_rodina_archiv($r->ID) && sb_jmeno_klic($r->post_title) === $k) return $r;
    }
    return null;
}

// Archivuje rodinu od roku $od_roku a z jejích dospělých členů (vč. vedoucích) udělá samostatné dospělé členy
// i s jejich účty na webu. Vrací jména osamostatněných.
function sb_osamostatnit_rodinu($id, $od_roku, $poznamka) {
    $dnes = wp_date('Y-m-d');
    update_post_meta($id, 'rodina_archiv', ['od_roku' => $od_roku, 'poznamka' => $poznamka, 'datum' => $dnes]);
    $osamostatneni = [];
    $dosp_zbytek = sb_dospeli_rodiny($id);
    $dosp_archiv = sb_dospeli_archiv($id);
    // Vedoucí rodiče: osamostatní se i s účtem na webu (aby dál viděli Moje brigády)
    $vedouci_r = sb_vedouci_rodice($id);
    $klice_rucni = array_map(function($d) { return sb_jmeno_klic($d['jmeno']); }, $dosp_zbytek);
    $rodice_zbytek = array_map('intval', sb_ucty_jednotky($id));
    foreach ($vedouci_r as $uid => $jm) {
        $k = array_search(sb_jmeno_klic($jm), $klice_rucni, true);
        if ($k !== false) unset($dosp_zbytek[$k]); // ručně zadaný = tatáž osoba
        if (sb_vytvorit_dospeleho($jm, [$uid], $od_roku)) {
            $osamostatneni[] = $jm;
            $rodice_zbytek = array_values(array_diff($rodice_zbytek, [$uid]));
        }
    }
    update_post_meta($id, 'rodice_rodiny', $rodice_zbytek);
    // Ostatní rodiče-členové: účet podle shody jména s rodičem rodiny
    $rodice_jmena = [];
    foreach ($rodice_zbytek as $uid) {
        $u = get_userdata($uid);
        if ($u) $rodice_jmena[$uid] = sb_jmeno_klic(trim(($u->last_name ?? '') . ' ' . ($u->first_name ?? '')) ?: $u->display_name);
    }
    foreach ($dosp_zbytek as $d) {
        $uid = array_search(sb_jmeno_klic($d['jmeno']), $rodice_jmena, true);
        $ucet = $uid !== false ? [intval($uid)] : [];
        if (sb_vytvorit_dospeleho($d['jmeno'], $ucet, $od_roku)) {
            $osamostatneni[] = $d['jmeno'];
            if ($ucet) { $rodice_zbytek = array_values(array_diff($rodice_zbytek, $ucet)); unset($rodice_jmena[$uid]); }
        }
    }
    update_post_meta($id, 'rodice_rodiny', $rodice_zbytek);
    foreach (sb_dospeli_rodiny($id) as $d) {
        $dosp_archiv[] = ['jmeno' => $d['jmeno'], 'od_roku' => intval($d['od_roku'] ?? 0), 'do_roku' => $od_roku, 'datum' => $dnes];
    }
    delete_post_meta($id, 'dospeli_clenove');
    if ($dosp_archiv) update_post_meta($id, 'dospeli_archiv', array_values($dosp_archiv));
    return $osamostatneni;
}

// Vysvětlující věta pro rodinu, na kterou se požadavky v daném roce nevztahují (HTML).
function sb_poznamka_mimo_rok($rodina, $rok) {
    $rok    = intval($rok);
    $archiv = sb_rodina_archiv($rodina->ID);
    if ($archiv && intval($archiv['od_roku']) <= $rok) {
        return 'Rodina je od roku ' . intval($archiv['od_roku']) . ' vedena v archivu (děti už skauta nenavštěvují). Požadavky se pro ni od tohoto roku nepočítají.';
    }
    $od = intval(get_post_meta($rodina->ID, 'sb_od_roku', true));
    if (sb_je_dospely($rodina->ID) && $od > $rok) {
        return 'Jako samostatný dospělý člen se počítáte od roku ' . $od . '.';
    }
    return 'Rodina vznikla po 31. 8. ' . $rok . ' — patří do sezóny ' . $rok . '/' . ($rok + 1) . '. Požadavky platí od roku ' . ($rok + 1) . ' a hodiny odpracované v roce ' . $rok . ' se do něj převedou.';
}

// Odpracované hodiny rodiny na brigádách konaných v roce $rok (stejný výpočet jako Roční výpis, vč. walk-in).
function sb_odpracovano_v_roce($rodina, $rok) {
    static $brigady = null;
    $celkem  = 0;
    if ($brigady === null || !empty($GLOBALS['sb_test_bez_cache'])) $brigady = get_posts(['post_type' => 'brigada', 'numberposts' => -1]);
    foreach ($brigady as $b) {
        $datum_raw = get_post_meta($b->ID, 'datum_brigady', true);
        if (!$datum_raw || !preg_match('/\b(19|20)\d{2}\b/', $datum_raw, $m) || intval($m[0]) !== intval($rok)) continue;
        $h = sb_celkem_hodin($b->ID, $rodina->ID);
        if ($h !== null) {
            $celkem += $h;
            continue;
        }
        $walkins = get_post_meta($b->ID, 'walkin_ucastnici', true);
        if (is_array($walkins)) {
            foreach ($walkins as $w) {
                if (is_array($w) && ($w['jmeno'] ?? '') === $rodina->post_title) {
                    $celkem += max(1, intval($w['pocet'] ?? 1)) * intval($w['hodiny'] ?? 0);
                    break;
                }
            }
        }
    }
    return $celkem;
}

// Převod hodin pro novou rodinu: rodina, která do roku $rok-1 nepatřila (vznikla po 31. 8.),
// ale do roku $rok ano, dostane do roku $rok hodiny odpracované v roce $rok-1.
function sb_prevod_hodin($rodina, $rok) {
    $rok = intval($rok);
    if (sb_rodina_v_roce($rodina, $rok - 1) || !sb_rodina_v_roce($rodina, $rok)) return 0;
    return sb_odpracovano_v_roce($rodina, $rok - 1);
}

// Řádek s převodem hodin do tabulky požadavků (Moje brigády, Rodiny dle brigád).
function sb_prevod_radek_html($prevod, $rok) {
    if ($prevod <= 0) return '';
    $z = intval($rok) - 1;
    return "<tr><td style='padding-right:16px; color:#555;'>Převedeno z roku $z:</td><td><strong>" . intval($prevod) . " h</strong></td></tr>"
         . "<tr><td colspan='2' style='font-size:11px; color:#777;'>Odpracováno v roce $z, kdy rodina ještě brigády plnit nemusela.</td></tr>";
}

// Stav revize rodin: ['otevrena' => bool, 'rok' => cílový rok revize].
function sb_revize_stav() {
    $r = get_option('sb_revize', []);
    if (!is_array($r)) $r = [];
    return [
        'otevrena' => !empty($r['otevrena']),
        'rok'      => isset($r['rok']) ? intval($r['rok']) : 0,
    ];
}

// Rok, od kterého se archivované dítě/rodina přestane počítat do požadavků.
function sb_rok_archivace() {
    $revize = sb_revize_stav();
    if ($revize['otevrena'] && $revize['rok'] > 0) {
        return $revize['rok'];
    }
    return intval(wp_date('Y')) + 1;
}

// Oprávnění: správce/administrátor (plný přístup).
function sb_je_spravce() {
    $u = wp_get_current_user();
    return (bool) array_intersect((array) $u->roles, ['administrator', 'spravce_brigad']);
}

// Oprávnění: smí vidět přehled rodin (správce i vedoucí = role author).
function sb_muze_videt_rodiny() {
    $u = wp_get_current_user();
    return (bool) array_intersect((array) $u->roles, ['administrator', 'spravce_brigad', 'author']);
}

// Oprávnění: smí rodiny upravovat (správce kdykoli, vedoucí jen při otevřené revizi).
function sb_muze_upravovat_rodiny() {
    if (sb_je_spravce()) return true;
    if (!sb_muze_videt_rodiny()) return false;
    $revize = sb_revize_stav();
    return $revize['otevrena'];
}

// Pokud pro $rok už existuje snímek počtu dětí, upraví ho o změnu počtu dětí ve stavu rodiny
// ($pred = počet ze stavu před úpravou). Ruční úpravy snímku tím zůstanou zachovány.
function sb_uprav_snimek_o_rozdil($rodina_id, $rok, $pred) {
    $klic = 'pocet_deti_' . intval($rok);
    $snap = get_post_meta($rodina_id, $klic, true);
    if ($snap === '' || $snap === false || $snap === null) return;
    $rozdil = sb_pocet_deti_ze_stavu($rodina_id, $rok) - intval($pred);
    if ($rozdil !== 0) {
        update_post_meta($rodina_id, $klic, max(0, intval($snap) + $rozdil));
    }
}

// Vrátí post rodiny podle ID, nebo null, pokud ID nepatří rodině.
function sb_get_rodina($id) {
    $p = get_post(intval($id));
    return ($p && $p->post_type === 'rodina') ? $p : null;
}

// Walk-in záznamy se s rodinou párují podle názvu rodiny. Při přejmenování rodiny proto
// přepíšeme jméno ve všech walk-in záznamech všech brigád, aby se hodiny rodině neztratily.
// Vrací počet upravených brigád.
function sb_prejmenovat_walkin($stary, $novy) {
    if ($stary === '' || $novy === '' || $stary === $novy) return 0;
    $upraveno = 0;
    $brigady  = get_posts(['post_type' => 'brigada', 'numberposts' => -1, 'post_status' => 'any']);
    foreach ($brigady as $b) {
        $walkins = get_post_meta($b->ID, 'walkin_ucastnici', true);
        if (!is_array($walkins)) continue;
        $zmena = false;
        foreach ($walkins as $i => $w) {
            if (is_array($w) && isset($w['jmeno']) && $w['jmeno'] === $stary) {
                $walkins[$i]['jmeno'] = $novy;
                $zmena = true;
            }
        }
        if ($zmena) {
            update_post_meta($b->ID, 'walkin_ucastnici', wp_slash($walkins));
            wp_cache_delete($b->ID, 'post_meta');
            $upraveno++;
        }
    }
    return $upraveno;
}

// Hook: kdykoli se změní název rodiny (Správa rodin i editace ve wp-adminu), převedeme walk-in záznamy.
function sb_rodina_prejmenovana($post_id, $po, $pred) {
    if (!$po || !$pred || $po->post_type !== 'rodina') return;
    if ($pred->post_title !== $po->post_title) {
        sb_prejmenovat_walkin($pred->post_title, $po->post_title);
    }
}
add_action('post_updated', 'sb_rodina_prejmenovana', 10, 3);
// =====================================================================
// ČLENSKÉ PŘÍSPĚVKY: výpočet, QR Platba, platby
// =====================================================================

// --- QR kód (byte mode, úroveň opravy M, verze 1–10), bez externích služeb ---

function sb_qr_gf_mul($x, $y) {
    $z = 0;
    for ($i = 7; $i >= 0; $i--) {
        $z = ($z << 1) ^ (($z >> 7) * 0x11D);
        $z ^= (($y >> $i) & 1) * $x;
    }
    return $z & 0xFF;
}

function sb_qr_rs_divisor($degree) {
    $result = array_fill(0, $degree, 0);
    $result[$degree - 1] = 1;
    $root = 1;
    for ($i = 0; $i < $degree; $i++) {
        for ($j = 0; $j < $degree; $j++) {
            $result[$j] = sb_qr_gf_mul($result[$j], $root);
            if ($j + 1 < $degree) $result[$j] ^= $result[$j + 1];
        }
        $root = sb_qr_gf_mul($root, 0x02);
    }
    return $result;
}

function sb_qr_rs_remainder($data, $divisor) {
    $result = array_fill(0, count($divisor), 0);
    foreach ($data as $b) {
        $factor = $b ^ array_shift($result);
        $result[] = 0;
        foreach ($divisor as $i => $coef) {
            $result[$i] ^= sb_qr_gf_mul($coef, $factor);
        }
    }
    return $result;
}

// Vrátí matici modulů [y][x] (true = tmavý) nebo null, pokud je text příliš dlouhý.
function sb_qr_matice($text) {
    // verze => [EC kódových slov na blok, [[počet bloků, datových slov v bloku], ...]] pro úroveň M
    $tab = [
        1 => [10, [[1, 16]]], 2 => [16, [[1, 28]]], 3 => [26, [[1, 44]]], 4 => [18, [[2, 32]]],
        5 => [24, [[2, 43]]], 6 => [16, [[4, 27]]], 7 => [18, [[4, 31]]], 8 => [22, [[2, 38], [2, 39]]],
        9 => [22, [[3, 36], [2, 37]]], 10 => [26, [[4, 43], [1, 44]]],
    ];
    $zarovnani = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
                  7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]];
    $bytes = array_values(unpack('C*', (string) $text) ?: []);
    $n = count($bytes);

    $verze = 0;
    foreach ($tab as $v => $t) {
        $datovych = 0;
        foreach ($t[1] as $g) $datovych += $g[0] * $g[1];
        $delka_poctu = $v <= 9 ? 8 : 16;
        if (4 + $delka_poctu + 8 * $n <= $datovych * 8) { $verze = $v; break; }
    }
    if (!$verze) return null;

    list($ec_len, $skupiny) = $tab[$verze];
    $datovych = 0;
    foreach ($skupiny as $g) $datovych += $g[0] * $g[1];

    // Bitový proud
    $bity = [];
    $pridej = function($hodnota, $delka) use (&$bity) {
        for ($i = $delka - 1; $i >= 0; $i--) $bity[] = ($hodnota >> $i) & 1;
    };
    $pridej(0x4, 4);
    $pridej($n, $verze <= 9 ? 8 : 16);
    foreach ($bytes as $b) $pridej($b, 8);
    $kapacita = $datovych * 8;
    $pridej(0, min(4, $kapacita - count($bity)));
    $pridej(0, (8 - count($bity) % 8) % 8);
    for ($pad = 0xEC; count($bity) < $kapacita; $pad ^= 0xEC ^ 0x11) $pridej($pad, 8);
    $data = [];
    for ($i = 0; $i < count($bity); $i += 8) {
        $b = 0;
        for ($j = 0; $j < 8; $j++) $b = ($b << 1) | $bity[$i + $j];
        $data[] = $b;
    }

    // Bloky + Reed-Solomon, prokládání
    $divisor = sb_qr_rs_divisor($ec_len);
    $bloky = []; $ecc = []; $pos = 0;
    foreach ($skupiny as $g) {
        for ($k = 0; $k < $g[0]; $k++) {
            $blok = array_slice($data, $pos, $g[1]);
            $pos += $g[1];
            $bloky[] = $blok;
            $ecc[] = sb_qr_rs_remainder($blok, $divisor);
        }
    }
    $slova = [];
    $max = 0;
    foreach ($bloky as $b) $max = max($max, count($b));
    for ($i = 0; $i < $max; $i++) foreach ($bloky as $b) if ($i < count($b)) $slova[] = $b[$i];
    for ($i = 0; $i < $ec_len; $i++) foreach ($ecc as $e) $slova[] = $e[$i];

    // Matice a funkční vzory
    $size = $verze * 4 + 17;
    $m = array_fill(0, $size, array_fill(0, $size, false));
    $f = array_fill(0, $size, array_fill(0, $size, false));
    $set = function($x, $y, $dark) use (&$m, &$f) { $m[$y][$x] = $dark; $f[$y][$x] = true; };

    for ($i = 0; $i < $size; $i++) { $set(6, $i, $i % 2 == 0); $set($i, 6, $i % 2 == 0); }
    foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as $c) {
        for ($dy = -4; $dy <= 4; $dy++) for ($dx = -4; $dx <= 4; $dx++) {
            $x = $c[0] + $dx; $y = $c[1] + $dy;
            if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) continue;
            $d = max(abs($dx), abs($dy));
            $set($x, $y, $d != 2 && $d != 4);
        }
    }
    $al = $zarovnani[$verze];
    $na = count($al);
    for ($i = 0; $i < $na; $i++) for ($j = 0; $j < $na; $j++) {
        if (($i == 0 && $j == 0) || ($i == 0 && $j == $na - 1) || ($i == $na - 1 && $j == 0)) continue;
        for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) {
            $set($al[$i] + $dx, $al[$j] + $dy, max(abs($dx), abs($dy)) != 1);
        }
    }
    $format = function($mask) use ($set, $size) {
        $data = (0 << 3) | $mask; // úroveň M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = function($i) use ($bits) { return (($bits >> $i) & 1) != 0; };
        for ($i = 0; $i <= 5; $i++) $set(8, $i, $bit($i));
        $set(8, 7, $bit(6)); $set(8, 8, $bit(7)); $set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) $set(14 - $i, 8, $bit($i));
        for ($i = 0; $i < 8; $i++) $set($size - 1 - $i, 8, $bit($i));
        for ($i = 8; $i < 15; $i++) $set(8, $size - 15 + $i, $bit($i));
        $set(8, $size - 8, true);
    };
    $format(0);
    if ($verze >= 7) {
        $rem = $verze;
        for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        $bits = ($verze << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $bit = (($bits >> $i) & 1) != 0;
            $a = $size - 11 + $i % 3; $b = intdiv($i, 3);
            $set($a, $b, $bit); $set($b, $a, $bit);
        }
    }

    // Data (cik-cak)
    $i = 0; $celkem_bitu = count($slova) * 8;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right == 6) $right = 5;
        for ($vert = 0; $vert < $size; $vert++) {
            for ($j = 0; $j < 2; $j++) {
                $x = $right - $j;
                $upward = (($right + 1) & 2) == 0;
                $y = $upward ? $size - 1 - $vert : $vert;
                if (!$f[$y][$x] && $i < $celkem_bitu) {
                    $m[$y][$x] = (($slova[$i >> 3] >> (7 - ($i & 7))) & 1) != 0;
                    $i++;
                }
            }
        }
    }

    // Maska s nejnižší penalizací
    $maskuj = function($mat, $mask) use ($f, $size) {
        for ($y = 0; $y < $size; $y++) for ($x = 0; $x < $size; $x++) {
            if ($f[$y][$x]) continue;
            switch ($mask) {
                case 0: $inv = ($x + $y) % 2 == 0; break;
                case 1: $inv = $y % 2 == 0; break;
                case 2: $inv = $x % 3 == 0; break;
                case 3: $inv = ($x + $y) % 3 == 0; break;
                case 4: $inv = (intdiv($x, 3) + intdiv($y, 2)) % 2 == 0; break;
                case 5: $inv = $x * $y % 2 + $x * $y % 3 == 0; break;
                case 6: $inv = ($x * $y % 2 + $x * $y % 3) % 2 == 0; break;
                default: $inv = (($x + $y) % 2 + $x * $y % 3) % 2 == 0;
            }
            if ($inv) $mat[$y][$x] = !$mat[$y][$x];
        }
        return $mat;
    };
    $penalizace = function($mat) use ($size) {
        $p = 0; $tmavych = 0;
        $vzor1 = [true, false, true, true, true, false, true, false, false, false, false];
        $vzor2 = [false, false, false, false, true, false, true, true, true, false, true];
        for ($smer = 0; $smer < 2; $smer++) {
            for ($a = 0; $a < $size; $a++) {
                $run = 0; $prev = null; $radek = [];
                for ($b = 0; $b < $size; $b++) {
                    $v = $smer ? $mat[$b][$a] : $mat[$a][$b];
                    $radek[] = $v;
                    if ($v === $prev) { $run++; } else { if ($run >= 5) $p += 3 + $run - 5; $run = 1; $prev = $v; }
                }
                if ($run >= 5) $p += 3 + $run - 5;
                for ($b = 0; $b + 11 <= $size; $b++) {
                    $s = array_slice($radek, $b, 11);
                    if ($s === $vzor1 || $s === $vzor2) $p += 40;
                }
            }
        }
        for ($y = 0; $y < $size; $y++) for ($x = 0; $x < $size; $x++) {
            if ($mat[$y][$x]) $tmavych++;
            if ($x + 1 < $size && $y + 1 < $size) {
                $c = $mat[$y][$x];
                if ($mat[$y][$x + 1] === $c && $mat[$y + 1][$x] === $c && $mat[$y + 1][$x + 1] === $c) $p += 3;
            }
        }
        $p += 10 * (int) floor(abs($tmavych * 100 / ($size * $size) - 50) / 5);
        return $p;
    };
    $nejlepsi = null; $nejlepsi_p = PHP_INT_MAX; $nejlepsi_mask = 0;
    for ($mask = 0; $mask < 8; $mask++) {
        $format($mask);
        $kandidat = $maskuj($m, $mask);
        // formátové bity jsou ve funkčních modulech – je třeba je zkopírovat i do kandidáta
        $pen = $penalizace($kandidat);
        if ($pen < $nejlepsi_p) { $nejlepsi_p = $pen; $nejlepsi = $kandidat; $nejlepsi_mask = $mask; }
    }
    $format($nejlepsi_mask);
    $vysledek = $maskuj($m, $nejlepsi_mask);
    return $vysledek;
}

// QR kód jako inline SVG (pro web).
function sb_qr_svg($text, $px = 200) {
    $mat = sb_qr_matice($text);
    if (!$mat) return '';
    $size = count($mat);
    $q = 4;
    $cesta = '';
    for ($y = 0; $y < $size; $y++) for ($x = 0; $x < $size; $x++) {
        if ($mat[$y][$x]) $cesta .= 'M' . ($x + $q) . ',' . ($y + $q) . 'h1v1h-1z';
    }
    $rozmer = $size + 2 * $q;
    return "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 $rozmer $rozmer' width='" . intval($px) . "' height='" . intval($px) . "' shape-rendering='crispEdges' role='img' aria-label='QR kód pro platbu'>"
         . "<rect width='100%' height='100%' fill='#fff'/><path d='$cesta' fill='#000'/></svg>";
}

// QR kód jako PNG (pro e-mail); vyžaduje rozšíření GD. Vrací binární data nebo ''.
function sb_qr_png($text, $modul = 6) {
    if (!function_exists('imagecreate')) return '';
    $mat = sb_qr_matice($text);
    if (!$mat) return '';
    $size = count($mat);
    $q = 4;
    $px = ($size + 2 * $q) * $modul;
    $img = imagecreate($px, $px);
    $bila = imagecolorallocate($img, 255, 255, 255);
    $cerna = imagecolorallocate($img, 0, 0, 0);
    imagefill($img, 0, 0, $bila);
    for ($y = 0; $y < $size; $y++) for ($x = 0; $x < $size; $x++) {
        if ($mat[$y][$x]) {
            imagefilledrectangle($img, ($x + $q) * $modul, ($y + $q) * $modul, ($x + $q + 1) * $modul - 1, ($y + $q + 1) * $modul - 1, $cerna);
        }
    }
    ob_start();
    imagepng($img);
    $png = ob_get_clean();
    imagedestroy($img);
    return $png;
}

// Převod českého čísla účtu (např. 2300221146/2010 nebo 19-123/0800) na IBAN; '' při chybě.
function sb_cz_iban($ucet) {
    if (!preg_match('/^\s*(?:(\d{1,6})-)?(\d{1,10})\s*\/\s*(\d{4})\s*$/', (string) $ucet, $m)) return '';
    $bban = $m[3] . str_pad($m[1], 6, '0', STR_PAD_LEFT) . str_pad($m[2], 10, '0', STR_PAD_LEFT);
    $cislo = $bban . '123500'; // C=12, Z=35, 00
    $zbytek = 0;
    foreach (str_split($cislo) as $c) $zbytek = ($zbytek * 10 + intval($c)) % 97;
    return 'CZ' . str_pad((string) (98 - $zbytek), 2, '0', STR_PAD_LEFT) . $bban;
}

// Kontrola čísla účtu podle váhového součtu (modulo 11) pro předčíslí i číslo.
function sb_ucet_platny($ucet) {
    if (!preg_match('/^\s*(?:(\d{1,6})-)?(\d{1,10})\s*\/\s*(\d{4})\s*$/', (string) $ucet, $m)) return false;
    $kontrola = function($cislo, $vahy) {
        $cislo = str_pad($cislo, count($vahy), '0', STR_PAD_LEFT);
        $s = 0;
        foreach ($vahy as $i => $v) $s += intval($cislo[$i]) * $v;
        return $s % 11 === 0;
    };
    return $kontrola($m[1], [10, 5, 8, 4, 2, 1]) && $kontrola($m[2], [6, 3, 7, 9, 10, 5, 8, 4, 2, 1]);
}

// Bez diakritiky a jen tisknutelné ASCII (pro QR Platbu).
function sb_ascii($text) {
    $text = remove_accents((string) $text);
    return trim(preg_replace('/[^\x20-\x7E]/', '', $text));
}

// --- Nastavení a výpočet příspěvku ---

// Nastavení příspěvku pro rok: ['1','2','3' (Kč za dítě), 'ucet', 'od', 'do'] nebo null.
function sb_prispevek_nastaveni($rok) {
    $vse = get_option('sb_prispevky', []);
    $n = (is_array($vse) && isset($vse[intval($rok)]) && is_array($vse[intval($rok)])) ? $vse[intval($rok)] : null;
    if (!$n || (intval($n['1'] ?? 0) + intval($n['2'] ?? 0) + intval($n['3'] ?? 0) + intval($n['dosp'] ?? 0)) <= 0) return null;
    return $n;
}

// Jména dětí, které se v roce $rok počítají (aktivní + odešlé až od pozdějšího roku).
function sb_deti_jmena_pro_rok($rodina_id, $rok) {
    $jmena = [];
    $deti = get_post_meta($rodina_id, 'deti_rodiny', true);
    if (is_array($deti)) foreach ($deti as $d) if (is_array($d) && !empty($d['jmeno'])) $jmena[] = $d['jmeno'];
    foreach (sb_deti_archiv($rodina_id) as $d) {
        if (is_array($d) && !empty($d['jmeno']) && isset($d['od_roku']) && intval($d['od_roku']) > intval($rok)) $jmena[] = $d['jmeno'];
    }
    return $jmena;
}

// Dospělý člen: samostatná jednotka bez dětí – příspěvek i brigádní požadavek se vztahují na něj osobně.
function sb_je_dospely($rodina_id) {
    return get_post_meta($rodina_id, 'sb_typ', true) === 'dospely';
}

// Dospělí členové v rodině (rodiče, kteří jsou sami členy) – aktivní: [['jmeno', 'od_roku'], ...]
function sb_dospeli_rodiny($rodina_id) {
    $d = get_post_meta($rodina_id, 'dospeli_clenove', true);
    return is_array($d) ? array_values(array_filter($d, 'is_array')) : [];
}
function sb_dospeli_archiv($rodina_id) {
    $d = get_post_meta($rodina_id, 'dospeli_archiv', true);
    return is_array($d) ? array_values(array_filter($d, 'is_array')) : [];
}

// Rodiče s účtem, kteří jsou vedoucí (role author) – jsou automaticky i dospělými členy: [user_id => 'Příjmení Jméno']
function sb_vedouci_rodice($rodina_id) {
    $vysl = [];
    $rodice = get_post_meta($rodina_id, 'rodice_rodiny', true);
    if (!is_array($rodice)) return $vysl;
    foreach ($rodice as $uid) {
        $u = get_userdata(intval($uid));
        if ($u && array_intersect(['author', 'administrator', 'spravce_brigad'], (array) ($u->roles ?? []))) {
            $j = trim(($u->last_name ?? '') . ' ' . ($u->first_name ?? ''));
            $vysl[intval($uid)] = $j !== '' ? $j : (string) $u->display_name;
        }
    }
    return $vysl;
}

// Porovnatelný tvar jména (bez ohledu na velikost písmen a pořadí slov).
function sb_jmeno_klic($j) {
    $w = preg_split('/\s+/u', mb_strtolower(trim(preg_replace('/\s*\([^)]*\)/u', '', (string) $j))), -1, PREG_SPLIT_NO_EMPTY);
    sort($w);
    return implode(' ', $w);
}

// Jména dospělých členů rodiny, za které se v roce $rok platí příspěvek
// (zadaní ručně + automaticky rodiče, kteří jsou vedoucí).
function sb_dospeli_pro_rok($rodina_id, $rok) {
    $rok = intval($rok); $jmena = [];
    foreach (sb_dospeli_rodiny($rodina_id) as $d) {
        if (!empty($d['jmeno']) && intval($d['od_roku'] ?? 0) <= $rok) $jmena[] = $d['jmeno'];
    }
    foreach (sb_dospeli_archiv($rodina_id) as $d) {
        if (!empty($d['jmeno']) && intval($d['od_roku'] ?? 0) <= $rok && intval($d['do_roku'] ?? 0) > $rok) $jmena[] = $d['jmeno'];
    }
    $klice = array_map('sb_jmeno_klic', $jmena);
    foreach (sb_vedouci_rodice($rodina_id) as $j) {
        if (!in_array(sb_jmeno_klic($j), $klice, true)) { $jmena[] = $j; $klice[] = sb_jmeno_klic($j); }
    }
    return $jmena;
}

// Brigádní požadavek (h) jednotky pro rok podle nastavení roku $p.
function sb_pozadavek_hodin($rodina_id, $rok, $p) {
    if (!is_array($p)) return 0;
    if (sb_je_dospely($rodina_id)) return intval($p['dosp'] ?? 0);
    $n = sb_pocet_deti_pro_rok($rodina_id, $rok);
    if ($n == 1) return intval($p['1'] ?? 0);
    if ($n == 2) return intval($p['2'] ?? 0);
    if ($n >= 3) return intval($p['3'] ?? 0);
    return 0;
}

// Účty (user ID) přiřazené k jednotce – vždy pole kladných čísel.
function sb_ucty_jednotky($rodina_id) {
    $r = get_post_meta($rodina_id, 'rodice_rodiny', true);
    return is_array($r) ? array_values(array_filter(array_map('intval', $r), function($x) { return $x > 0; })) : [];
}

// Rodina vedoucího: některý z rodičů má i roli author (vedoucí) – doplatek za neodpracované brigády se neplatí.
function sb_rodina_vedouci($rodina_id) {
    $rodice = get_post_meta($rodina_id, 'rodice_rodiny', true);
    if (!is_array($rodice)) return false;
    foreach ($rodice as $uid) {
        $u = get_userdata(intval($uid));
        if ($u && array_intersect(['author', 'administrator', 'spravce_brigad'], (array) ($u->roles ?? []))) return true;
    }
    return false;
}

// Bez doplatku za neodpracované brigády: dospělý člen nebo rodina vedoucího (hodiny se jen evidují).
function sb_bez_doplatku($rodina_id) {
    return sb_je_dospely($rodina_id) || sb_rodina_vedouci($rodina_id);
}

// Popis důvodu, proč jednotka doplatek neplatí.
function sb_bez_doplatku_popis($rodina_id) {
    return sb_je_dospely($rodina_id) ? 'dospělý člen' : 'rodina vedoucího';
}

// Brigádnický poplatek rodiny za rok (stejný výpočet jako Roční výpis).
function sb_brigadni_poplatek($rodina, $rok) {
    $vysl = ['pozadavek' => 0, 'odpracovano' => 0, 'chybi' => 0, 'sazba' => 0, 'castka' => 0, 'v_roce' => false, 'vedouci' => false];
    if (!sb_rodina_v_roce($rodina, $rok)) return $vysl;
    $vysl['v_roce'] = true;
    $pozadavky = get_option('pozadavky_dle_roku', []);
    $p = (is_array($pozadavky) && isset($pozadavky[intval($rok)])) ? $pozadavky[intval($rok)] : null;
    if (!$p) return $vysl;
    $poz  = sb_pozadavek_hodin($rodina->ID, $rok, $p);
    $odpr = sb_odpracovano_v_roce($rodina, $rok) + sb_prevod_hodin($rodina, $rok);
    $vysl['pozadavek']   = $poz;
    $vysl['odpracovano'] = $odpr;
    $vysl['chybi']       = max(0, $poz - $odpr);
    $vysl['sazba']       = floatval($p['sazba'] ?? 0);
    $vysl['vedouci']     = sb_bez_doplatku($rodina->ID);
    $vysl['duvod']       = sb_bez_doplatku_popis($rodina->ID);
    $vysl['castka']      = $vysl['vedouci'] ? 0 : sb_castka($vysl['chybi'] * $vysl['sazba']);
    return $vysl;
}

// Uložené platby (splátky) rodiny za rok – pole kladných částek v Kč.
function sb_castka($v) {
    $v = round(floatval(is_scalar($v) ? str_replace([' ', ','], ['', '.'], (string) $v) : 0), 2);
    return ($v == floor($v)) ? (int) $v : $v;
}

function sb_platby($rodina_id, $rok) {
    $p = get_post_meta($rodina_id, 'sb_platby_' . intval($rok), true);
    if (!is_array($p)) return [];
    return array_values(array_filter(array_map('sb_castka', $p), function($x) { return $x > 0; }));
}

// Kompletní příspěvek rodiny na rok, nebo null, pokud rodina v daném roce neplatí.
function sb_prispevek_rodiny($rodina, $rok) {
    $rok  = intval($rok);
    $nast = sb_prispevek_nastaveni($rok);
    if (!$nast || !sb_rodina_v_roce($rodina, $rok)) return null;
    $je_dospely = sb_je_dospely($rodina->ID);
    $deti       = $je_dospely ? 0 : sb_pocet_deti_pro_rok($rodina->ID, $rok);
    $dospeli    = $je_dospely ? [$rodina->post_title] : sb_dospeli_pro_rok($rodina->ID, $rok);
    if ($deti <= 0 && !$dospeli) return null;
    $za_dite      = $deti > 0 ? intval($deti == 1 ? ($nast['1'] ?? 0) : ($deti == 2 ? ($nast['2'] ?? 0) : ($nast['3'] ?? 0))) : 0;
    $za_dospeleho = intval($nast['dosp'] ?? 0);
    $zaklad_deti  = $deti * $za_dite;
    $zaklad_dosp  = count($dospeli) * $za_dospeleho;
    $zaklad    = $zaklad_deti + $zaklad_dosp;
    $brigady   = sb_brigadni_poplatek($rodina, $rok - 1);
    $dar       = max(0, intval(get_post_meta($rodina->ID, 'sb_dar_' . $rok, true)));
    $celkem    = sb_castka($zaklad + $brigady['castka'] + $dar);
    if ($celkem <= 0) return null; // nulová částka – rodina nic neplatí
    $platby    = sb_platby($rodina->ID, $rok);
    $zaplaceno = sb_castka(array_sum($platby));
    $stav      = $zaplaceno <= 0 ? 'nezaplaceno' : ($zaplaceno >= $celkem - 0.004 ? 'zaplaceno' : 'splatky');
    $jmena_deti = $je_dospely ? [] : sb_deti_jmena_pro_rok($rodina->ID, $rok);
    return [
        'rok' => $rok, 'deti' => $deti, 'za_dite' => $za_dite, 'zaklad' => $zaklad,
        'zaklad_deti' => $zaklad_deti, 'dospeli' => count($dospeli), 'dospeli_jmena' => $dospeli,
        'za_dospeleho' => $za_dospeleho, 'zaklad_dospeli' => $zaklad_dosp, 'je_dospely' => $je_dospely,
        'brigady' => $brigady, 'dar' => $dar, 'celkem' => $celkem,
        'platby' => $platby, 'zaplaceno' => $zaplaceno, 'zbyva' => sb_castka(max(0, $celkem - $zaplaceno)),
        'stav' => $stav, 'jmena' => array_merge($jmena_deti, $je_dospely ? [] : $dospeli),
        'rodina' => $je_dospely ? '' : $rodina->post_title,
        'jmena_qr' => $je_dospely ? [$rodina->post_title] : null,
        'ucet' => (string) ($nast['ucet'] ?? ''),
    ];
}

// Zpráva pro příjemce, např. „Poplatek 2027 Outlý Matěj, Outlá Nela, dar 500" (bez přezdívek).
// Musí se vejít do 60 znaků QR Platby bez diakritiky, a přitom obsahovat každé dítě:
// 1) plná jména, 2) příjmení rodiny + křestní jména, 3) totéž bez roku, 4) nouzově zkrátit.
// Na webu a v e-mailu se zobrazí stejná varianta jako v QR kódu, jen s diakritikou.
function sb_platba_zprava($info, $pro_qr = false) {
    $rok    = intval($info['rok']);
    $plna   = array_map(function($j) { return trim(preg_replace('/\s*\([^)]*\)/u', '', (string) $j)); }, !empty($info['jmena_qr']) ? $info['jmena_qr'] : $info['jmena']);
    $plna   = array_values(array_filter($plna, 'strlen'));
    // Křestní jméno: za příjmení se považují slova shodná začátkem s názvem rodiny nebo se slovy ostatních
    // členů (Outlý/Outlá/Outlí). Standardně „Příjmení Jméno" → první slovo po příjmení; je-li příjmení
    // na konci („Jméno Příjmení"), vezme se první slovo. Slova bez písmen (např. „-") se ignorují.
    $kmen = function($w) { return substr(strtolower(sb_ascii($w)), 0, 4); };
    $slova = array_map(function($j) {
        return array_values(array_filter(preg_split('/[\s\-–]+/u', $j), function($w) { return preg_match('/\p{L}/u', $w); }));
    }, $plna);
    $kmeny_rodiny = array_map($kmen, array_filter(preg_split('/[\s\/\-–]+/u', (string) ($info['rodina'] ?? '')), function($w) { return mb_strlen($w) >= 3; }));
    $krestni = [];
    foreach ($slova as $i => $w) {
        $ostatni = [];
        foreach ($slova as $k => $w2) if ($k !== $i) foreach ($w2 as $x) $ostatni[] = $kmen($x);
        $prijmeni = array_merge($kmeny_rodiny, $ostatni);
        $je_prijmeni = function($x) use ($kmen, $prijmeni) { return in_array($kmen($x), $prijmeni, true); };
        if (!$w) { $krestni[] = ''; continue; }
        if (count($w) >= 2 && $je_prijmeni(end($w)) && !$je_prijmeni($w[0])) {
            $krestni[] = $w[0];                         // „Jméno Příjmení"
            continue;
        }
        $z = $w;                                         // „Příjmení Jméno [Jméno2]"
        while (count($z) > 1 && $je_prijmeni($z[0])) array_shift($z);
        $krestni[] = ($z === $w && count($w) >= 2) ? $w[1] : $z[0];
    }
    $rodina = trim((string) ($info['rodina'] ?? ''));
    $dar    = $info['dar'] > 0 ? ', dar ' . intval($info['dar']) : '';
    $k_txt  = implode(', ', $krestni);
    $kandidati = ['Poplatek ' . $rok . ' ' . implode(', ', $plna) . $dar];
    if ($rodina !== '') $kandidati[] = 'Poplatek ' . $rok . ' ' . $rodina . ': ' . $k_txt . $dar;
    $kandidati[] = 'Poplatek ' . $rok . ' ' . $k_txt . $dar;
    if ($rodina !== '') $kandidati[] = 'Poplatek ' . $rodina . ': ' . $k_txt . $dar;
    $kandidati[] = 'Poplatek ' . $k_txt . $dar;
    foreach ($kandidati as $k) {
        $k = trim($k);
        if (strlen(sb_ascii($k)) <= 60) return $pro_qr ? sb_ascii($k) : $k;
    }
    // Nouzově: poslední varianta zkrácená tak, aby zůstal dovětek s darem
    $posledni = sb_ascii(end($kandidati));
    $bez_daru = $dar !== '' ? substr($posledni, 0, strlen($posledni) - strlen($dar)) : $posledni;
    return rtrim(substr($bez_daru, 0, 60 - strlen($dar)), ' ,:') . $dar;
}

// Řetězec QR Platby (SPD) na zbývající částku, nebo '' (zaplaceno / chybí účet).
function sb_platba_spd($info) {
    $iban = sb_cz_iban($info['ucet']);
    if ($iban === '' || $info['zbyva'] <= 0) return '';
    $msg = str_replace('*', '', sb_platba_zprava($info, true));
    return 'SPD*1.0*ACC:' . $iban . '*AM:' . number_format($info['zbyva'], 2, '.', '') . '*CC:CZK*MSG:' . $msg;
}

// Je dnes v období, kdy se má příspěvek rodičům zobrazovat?
function sb_prispevek_aktivni($rok) {
    $nast = sb_prispevek_nastaveni($rok);
    if (!$nast || empty($nast['od']) || empty($nast['do'])) return false;
    $dnes = wp_date('Y-m-d');
    return $dnes >= $nast['od'] && $dnes <= $nast['do'];
}

function sb_kc($castka) {
    $c = round((float) $castka, 2);
    return number_format($c, $c == floor($c) ? 0 : 2, ',', ' ') . ' Kč';
}

// Adresa stránky se shortcodem [moje_brigady] (pro odkaz v e-mailu).
function sb_moje_brigady_url() {
    global $wpdb;
    $id = $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'page' AND post_content LIKE '%[moje_brigady%' LIMIT 1");
    return $id ? get_permalink($id) : home_url('/');
}

// Rozpis příspěvku jako HTML tabulka (web i e-mail). $qr_html = obrázek QR (SVG nebo <img>).
function sb_prispevek_html($info, $qr_html, $pro_email = false) {
    $rok = intval($info['rok']);
    $td  = "style='padding:6px 18px 6px 8px; color:#555; vertical-align:top;'";
    $tdv = "style='padding:6px 8px; vertical-align:top;'";
    $h  = "<table style='border-collapse:collapse; font-size:14px; line-height:1.5;'>";
    if (!empty($info['deti'])) {
        $h .= "<tr><td $td>Příspěvek za děti:</td><td $tdv>" . intval($info['deti']) . " × " . sb_kc($info['za_dite']) . " = <strong>" . sb_kc($info['zaklad_deti'] ?? $info['zaklad']) . "</strong></td></tr>";
    }
    if (!empty($info['dospeli'])) {
        $popis = !empty($info['je_dospely']) ? 'Příspěvek dospělého člena:' : 'Příspěvek dospělých členů (' . esc_html(implode(', ', $info['dospeli_jmena'])) . '):';
        $h .= "<tr><td $td>$popis</td><td $tdv>" . intval($info['dospeli']) . " × " . sb_kc($info['za_dospeleho']) . " = <strong>" . sb_kc($info['zaklad_dospeli']) . "</strong></td></tr>";
    }
    $b = $info['brigady'];
    if ($b['v_roce'] && $b['pozadavek'] > 0) {
        if ($b['vedouci'] && $b['chybi'] > 0) {
            $h .= "<tr><td $td>Doplatek za brigády " . ($rok - 1) . ":</td><td $tdv><strong>0 Kč</strong> – " . esc_html($b['duvod'] ?? 'rodina vedoucího') . ", bez doplatku</td></tr>";
        } elseif ($b['castka'] > 0) {
            $h .= "<tr><td $td>Doplatek za brigády " . ($rok - 1) . ":</td><td $tdv>" . intval($b['chybi']) . " h z " . intval($b['pozadavek']) . " h neodpracováno × " . sb_kc($b['sazba']) . " = <strong>" . sb_kc($b['castka']) . "</strong></td></tr>";
        } else {
            $h .= "<tr><td $td>Doplatek za brigády " . ($rok - 1) . ":</td><td $tdv><strong>0 Kč</strong> – brigády splněny, děkujeme!</td></tr>";
        }
    }
    if ($info['dar'] > 0) {
        $h .= "<tr><td $td>Sponzorský dar:</td><td $tdv><strong>" . sb_kc($info['dar']) . "</strong></td></tr>";
    }
    $h .= "<tr><td $td><strong>Celkem:</strong></td><td $tdv><strong style='font-size:16px;'>" . sb_kc($info['celkem']) . "</strong></td></tr>";
    if ($info['stav'] === 'splatky') {
        $h .= "<tr><td $td>Uhrazeno:</td><td $tdv>" . sb_kc($info['zaplaceno']) . "</td></tr>";
        $h .= "<tr><td $td><strong>Zbývá uhradit:</strong></td><td $tdv><strong>" . sb_kc($info['zbyva']) . "</strong></td></tr>";
    }
    $h .= "</table>";

    if ($info['stav'] === 'zaplaceno') {
        $h .= "<p style='margin:12px 0 0; padding:8px 12px; background:#e8f5e9; border-left:4px solid #2e7d32; color:#1b5e20; font-weight:600;'>✅ Uhrazeno – děkujeme!</p>";
        return $h;
    }

    $h .= "<div style='display:flex; flex-wrap:wrap; gap:16px; align-items:flex-start; margin-top:12px;'>";
    $h .= "<table style='border-collapse:collapse; font-size:14px; line-height:1.5;'>";
    $h .= "<tr><td $td>Číslo účtu:</td><td $tdv><strong>" . esc_html($info['ucet']) . "</strong></td></tr>";
    $h .= "<tr><td $td>Částka:</td><td $tdv><strong>" . sb_kc($info['zbyva']) . "</strong></td></tr>";
    $h .= "<tr><td $td>Zpráva pro příjemce:</td><td $tdv><strong>" . esc_html(sb_platba_zprava($info)) . "</strong></td></tr>";
    $h .= "</table>";
    if ($qr_html) {
        $h .= "<div style='text-align:center;'>" . $qr_html . "<div style='font-size:12px; color:#666;'>QR platba – naskenujte v bankovní aplikaci</div></div>";
    }
    $h .= "</div>";
    return $h;
}

// E-mail s příspěvkem pro rodinu: ['predmet', 'html', 'png'].
function sb_prispevek_email($rodina, $info) {
    $rok = intval($info['rok']);
    $spd = sb_platba_spd($info);
    $png = $spd ? sb_qr_png($spd) : '';
    $qr  = $png ? "<img src='cid:sbqrplatba' alt='QR platba' width='200' height='200' style='display:block; margin:0 auto;'>" : '';
    $url = esc_url(sb_moje_brigady_url());
    $h  = "<div style='font-family:Arial,Helvetica,sans-serif; font-size:14px; color:#222; max-width:640px;'>";
    if (!empty($info['je_dospely'])) {
        $h .= "<p>Ahoj,</p>";
        $h .= "<p>posíláme podklady pro úhradu <strong>členského příspěvku na rok $rok</strong> – <strong>" . esc_html($rodina->post_title) . "</strong>.</p>";
    } else {
        $h .= "<p>Milí rodiče,</p>";
        $h .= "<p>posíláme podklady pro úhradu <strong>členského příspěvku na rok $rok</strong> za rodinu <strong>" . esc_html($rodina->post_title) . "</strong>.</p>";
    }
    $h .= sb_prispevek_html($info, $qr, true);
    if ($info['stav'] !== 'zaplaceno') {
        $h .= "<p style='margin-top:16px;'>Pokud vám to přijde smysluplné a váš rodinný rozpočet to dovolí, můžete nás podpořit i formou sponzorského daru – částku stačí k příspěvku jednoduše přidat. Na dar vám rádi vystavíme potvrzení. Dar můžete zadat i po přihlášení na webu v sekci <a href='$url'>Moje brigády</a>, kde se vám rovnou upraví částka i QR kód.</p>";
    }
    $h .= "<p>Přehled odpracovaných hodin najdete po přihlášení na webu v sekci <a href='$url'>Moje brigády</a>.</p>";
    $h .= "<p>V případě dotazů stačí odpovědět na tento e-mail.</p>";
    $h .= "<p>Děkujeme,<br>Skautské středisko Černého havrana, Chlumec nad Cidlinou</p>";
    $h .= "</div>";
    return ['predmet' => 'Členský příspěvek ' . $rok . ' – ' . $rodina->post_title, 'html' => $h, 'png' => $png];
}

// Odeslání e-mailu s příspěvkem; vrací true/false.
function sb_odeslat_prispevek_email($to, $email) {
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: Skaut Chlumec nad Cidlinou <outly@skaut.cz>',
        'Reply-To: info@skautchlumec.cz',
    ];
    $png = $email['png'];
    $vloz = function($phpmailer) use ($png) {
        if ($png) $phpmailer->addStringEmbeddedImage($png, 'sbqrplatba', 'qr-platba.png', 'base64', 'image/png');
    };
    add_action('phpmailer_init', $vloz);
    $ok = wp_mail($to, $email['predmet'], $email['html'], $headers);
    remove_action('phpmailer_init', $vloz);
    return (bool) $ok;
}

// =====================================================================
// KARTA PLATBY – evidence členských příspěvků, rozesílání e-mailů
// =====================================================================

// Roky s nastaveným příspěvkem (sestupně).
function sb_roky_prispevku() {
    $vse = get_option('sb_prispevky', []);
    $roky = [];
    if (is_array($vse)) foreach (array_keys($vse) as $r) if (sb_prispevek_nastaveni($r)) $roky[] = intval($r);
    rsort($roky);
    return $roky;
}

// Kontaktní e-maily rodiny zadané správcem (pro rodiny, jejichž rodiče nemají účet na webu).
function sb_kontakt_emaily($rodina_id) {
    $s = (string) get_post_meta($rodina_id, 'sb_kontakt_email', true);
    $vysl = [];
    foreach (preg_split('/[\s,;]+/', $s, -1, PREG_SPLIT_NO_EMPTY) as $e) if (is_email($e)) $vysl[] = $e;
    return array_values(array_unique($vysl));
}

// Adresy pro e-mail s příspěvkem: e-maily z účtů rodičů, a jen když žádný nemá účet, kontaktní e-maily.
function sb_emaily_prispevku($rodina_id) {
    $ucty = sb_get_rodic_emails($rodina_id);
    return $ucty ? array_values($ucty) : sb_kontakt_emaily($rodina_id);
}

// Platící rodiny pro rok: [['rodina' => WP_Post, 'info' => [...]], ...] (abecedně)
function sb_platici_rodiny($rok) {
    $vysl = [];
    foreach (get_posts(['post_type' => 'rodina', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']) as $r) {
        $info = sb_prispevek_rodiny($r, $rok);
        if ($info) $vysl[] = ['rodina' => $r, 'info' => $info];
    }
    return $vysl;
}

function sb_stav_platby_html($stav) {
    $m = [
        'zaplaceno'   => ['Zaplaceno', '#1b5e20', '#e8f5e9', '#a5d6a7'],
        'splatky'     => ['Splátky', '#7a4100', '#fff8e1', '#ffcc80'],
        'nezaplaceno' => ['Nezaplaceno', '#7b1c1e', '#fff0f0', '#ef9a9a'],
    ];
    $s = $m[$stav] ?? $m['nezaplaceno'];
    return "<span style='display:inline-block; white-space:nowrap; font-size:12px; font-weight:600; color:{$s[1]}; background:{$s[2]}; border:1px solid {$s[3]}; border-radius:10px; padding:1px 8px;'>{$s[0]}</span>";
}

// CSV export plateb
function sb_export_platby_csv() {
    if (!isset($_GET['sb_export']) || $_GET['sb_export'] !== 'platby') return;
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'sb_export_platby')) {
        wp_die('Neplatný bezpečnostní token.');
    }
    if (!sb_muze_videt_rodiny()) wp_die('Nemáš oprávnění.');
    $rok = isset($_GET['rok']) ? intval($_GET['rok']) : intval(wp_date('Y')) + 1;
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="platby-' . $rok . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Rodina / člen', 'Děti a dospělí členové', 'Počet dětí', 'Příspěvek celkem bez doplatku (Kč)', 'Doplatek za brigády ' . ($rok - 1) . ' (Kč)', 'Dar (Kč)', 'Celkem (Kč)', 'Uhrazeno (Kč)', 'Zbývá (Kč)', 'Stav', 'Splátky (Kč)', 'Zpráva pro příjemce'], ';');
    $stavy = ['zaplaceno' => 'Zaplaceno', 'splatky' => 'Splátky', 'nezaplaceno' => 'Nezaplaceno'];
    $txt = function($s) { $s = (string) $s; return ($s !== '' && strpos('=+-@', $s[0]) !== false) ? "'" . $s : $s; };
    foreach (sb_platici_rodiny($rok) as $z) {
        $i = $z['info'];
        fputcsv($out, [
            $txt($z['rodina']->post_title), $txt(implode(', ', $i['jmena'])), $i['deti'], $i['zaklad'], $i['brigady']['castka'],
            $i['dar'], $i['celkem'], $i['zaplaceno'], $i['zbyva'], $stavy[$i['stav']], implode(' + ', $i['platby']),
            $txt(sb_platba_zprava($i)),
        ], ';');
    }
    fclose($out);
    exit;
}
add_action('init', 'sb_export_platby_csv');

function sb_platby_tab() {
    if (!sb_muze_videt_rodiny()) {
        return '<p>Nemáš oprávnění pro zobrazení plateb.</p>';
    }
    $je_spravce = sb_je_spravce();
    $roky = sb_roky_prispevku();
    $rok = isset($_POST['sb_platby_rok']) ? intval($_POST['sb_platby_rok']) : 0;
    if (!$rok) {
        foreach ($roky as $r) if (sb_prispevek_aktivni($r)) { $rok = $r; break; }
        if (!$rok) $rok = $roky ? $roky[0] : intval(wp_date('Y')) + 1;
    }
    $zprava = '';
    $mail_vybrane = null; // ID rodin, které mají zůstat zaškrtnuté (další dávka)
    $davka = 20;

    // --- Uložení plateb (jen správce) ---
    if (isset($_POST['sb_platby_ulozit'])) {
        if (!$je_spravce) {
            $zprava .= sb_alert('Platby může zapisovat jen správce.', 'error');
        } elseif (!isset($_POST['_wpnonce_platby']) || !wp_verify_nonce($_POST['_wpnonce_platby'], 'sb_platby')) {
            $zprava .= sb_alert('Neplatný bezpečnostní token.', 'error');
        } else {
            $ids = (isset($_POST['sb_platby_rodiny']) && is_array($_POST['sb_platby_rodiny'])) ? array_map('intval', $_POST['sb_platby_rodiny']) : [];
            $zmen = 0;
            foreach ($ids as $id) {
                $r = sb_get_rodina($id);
                if (!$r) continue;
                $castky = (isset($_POST['platby'][$id]) && is_array($_POST['platby'][$id])) ? $_POST['platby'][$id] : [];
                $nove = [];
                foreach ($castky as $c) {
                    $c = sb_castka($c);
                    if ($c > 0) $nove[] = $c;
                }
                if (!empty($_POST['uhrazeno'][$id])) {
                    // „uhrazeno" / „doplaceno": zapíše se celá zbývající částka
                    $info = sb_prispevek_rodiny($r, $rok);
                    $zbytek = $info ? sb_castka($info['celkem'] - array_sum($nove)) : 0;
                    if ($zbytek > 0) $nove[] = $zbytek;
                }
                if ($nove !== sb_platby($id, $rok)) {
                    $zmen++;
                    if ($nove) update_post_meta($id, 'sb_platby_' . $rok, $nove);
                    else delete_post_meta($id, 'sb_platby_' . $rok);
                }
            }
            $zprava .= sb_alert($zmen ? 'Platby byly uloženy (změněno rodin: ' . $zmen . ').' : 'Žádná změna plateb.');
        }
    }

    // --- Kontaktní e-maily rodin bez účtu (ukládají se i při odeslání) ---
    if ((isset($_POST['sb_kontakt_ulozit']) || isset($_POST['sb_mail_odeslat'])) && isset($_POST['kontakt']) && is_array($_POST['kontakt'])) {
        if (!$je_spravce || !isset($_POST['_wpnonce_platby_mail']) || !wp_verify_nonce($_POST['_wpnonce_platby_mail'], 'sb_platby_mail')) {
            $zprava .= sb_alert('Neplatný požadavek.', 'error');
        } else {
            $ulozeno = 0; $spatne = [];
            foreach ($_POST['kontakt'] as $kid => $hodnota) {
                $r = sb_get_rodina(intval($kid));
                if (!$r) continue;
                $platne = [];
                foreach (preg_split('/[\s,;]+/', sanitize_text_field(wp_unslash((string) $hodnota)), -1, PREG_SPLIT_NO_EMPTY) as $e) {
                    if (is_email($e)) $platne[] = $e; else $spatne[] = $r->post_title . ': ' . $e;
                }
                $nova = implode(', ', array_unique($platne));
                if ($nova !== (string) get_post_meta($r->ID, 'sb_kontakt_email', true)) {
                    if ($nova !== '') update_post_meta($r->ID, 'sb_kontakt_email', $nova);
                    else delete_post_meta($r->ID, 'sb_kontakt_email');
                    $ulozeno++;
                }
            }
            if (isset($_POST['sb_kontakt_ulozit'])) $zprava .= sb_alert($ulozeno ? 'Kontaktní e-maily byly uloženy (změněno rodin: ' . $ulozeno . ').' : 'Kontaktní e-maily beze změny.');
            if ($spatne) $zprava .= sb_alert('Neplatné e-mailové adresy (neuloženy): ' . implode(', ', $spatne) . '.', 'error');
        }
    }

    // --- Zkušební e-mail správci ---
    if (isset($_POST['sb_mail_test'])) {
        if (!$je_spravce || !isset($_POST['_wpnonce_platby_mail']) || !wp_verify_nonce($_POST['_wpnonce_platby_mail'], 'sb_platby_mail')) {
            $zprava .= sb_alert('Neplatný požadavek.', 'error');
        } else {
            $r = sb_get_rodina(intval($_POST['sb_mail_test_rodina'] ?? 0));
            $info = $r ? sb_prispevek_rodiny($r, $rok) : null;
            $komu = wp_get_current_user()->user_email;
            if (!$info) {
                $zprava .= sb_alert('Vyberte platící rodinu.', 'error');
            } elseif (!is_email($komu)) {
                $zprava .= sb_alert('Váš účet nemá platnou e-mailovou adresu.', 'error');
            } else {
                $email = sb_prispevek_email($r, $info);
                $email['predmet'] = '[ZKOUŠKA] ' . $email['predmet'];
                $ok = sb_odeslat_prispevek_email($komu, $email);
                $zprava .= $ok ? sb_alert('Zkušební e-mail pro rodinu ' . $r->post_title . ' byl odeslán na ' . $komu . '.')
                               : sb_alert('Zkušební e-mail se nepodařilo odeslat.', 'error');
            }
        }
    }

    // --- Rozeslání e-mailů vybraným rodinám (po dávkách) ---
    if (isset($_POST['sb_mail_odeslat'])) {
        if (!$je_spravce || !isset($_POST['_wpnonce_platby_mail']) || !wp_verify_nonce($_POST['_wpnonce_platby_mail'], 'sb_platby_mail')) {
            $zprava .= sb_alert('Neplatný požadavek.', 'error');
        } else {
            $ids = (isset($_POST['sb_mail_rodiny']) && is_array($_POST['sb_mail_rodiny'])) ? array_values(array_unique(array_map('intval', $_POST['sb_mail_rodiny']))) : [];
            $token = sanitize_key($_POST['sb_mail_davka'] ?? '');
            $pouzite = get_option('sb_mail_davky', []);
            if (!is_array($pouzite)) $pouzite = [];
            if ($token === '' || in_array($token, $pouzite, true)) {
                $ids = [];
                $zprava .= sb_alert('Tato dávka e-mailů už byla odeslána (stránka byla nejspíš obnovena nebo tlačítko stisknuto dvakrát). Nic dalšího se neodeslalo – zkontrolujte sloupec „Naposledy odesláno".', 'warning');
            } else {
                $pouzite[] = $token;
                update_option('sb_mail_davky', array_slice($pouzite, -100), false);
            }
            if (function_exists('set_time_limit')) @set_time_limit(180);
            $odeslano = []; $bez_emailu = []; $chyby = [];
            foreach (array_slice($ids, 0, $davka) as $id) {
                $r = sb_get_rodina($id);
                $info = $r ? sb_prispevek_rodiny($r, $rok) : null;
                if (!$info) continue;
                $adresy = sb_emaily_prispevku($id);
                if (!$adresy) { $bez_emailu[] = $r->post_title; continue; }
                if (sb_odeslat_prispevek_email($adresy, sb_prispevek_email($r, $info))) {
                    update_post_meta($id, 'sb_prispevek_mail_' . $rok, wp_date('j. n. Y H:i'));
                    $odeslano[] = $r->post_title;
                } else {
                    $chyby[] = $r->post_title;
                }
            }
            $mail_vybrane = array_slice($ids, $davka);
            if (!$ids) {
                if ($token !== '' && !in_array($token, array_slice($pouzite, 0, -1), true)) $zprava .= sb_alert('Nevybrali jste žádnou rodinu.', 'error');
            } else {
                $zprava .= sb_alert('Odesláno rodinám: ' . count($odeslano) . '.');
                if ($bez_emailu) $zprava .= sb_alert('Bez e-mailové adresy (rodiče nemají účet ani kontaktní e-mail): ' . implode(', ', $bez_emailu) . '.', 'warning');
                if ($chyby) $zprava .= sb_alert('Nepodařilo se odeslat: ' . implode(', ', $chyby) . '.', 'error');
                if ($mail_vybrane) $zprava .= sb_alert('E-maily se posílají po ' . $davka . '. Zbývá ještě ' . count($mail_vybrane) . ' vybraných rodin – jsou zaškrtnuté níže, klikněte znovu na Odeslat.', 'info');
            }
        }
    }

    ob_start();
    echo "<h3 class='sb-section-title'>💳 Platby členských příspěvků</h3>";
    echo $zprava;

    echo "<div class='sb-card'><form method='post' class='sb-form-row'>";
    echo "<div class='sb-form-group sb-fg-inline'><label>Rok:</label><select name='sb_platby_rok'>";
    $nabidka = $roky ?: [$rok];
    if (!in_array($rok, $nabidka, true)) $nabidka[] = $rok;
    foreach ($nabidka as $r) echo "<option value='" . intval($r) . "'" . ($r === $rok ? ' selected' : '') . ">" . intval($r) . "</option>";
    echo "</select></div><input type='submit' name='sb_platby_zobraz' value='Zobrazit' class='sb-btn sb-btn-primary'></form></div>";

    if (!sb_prispevek_nastaveni($rok)) {
        echo sb_alert('Pro rok ' . $rok . ' není nastaven členský příspěvek. Správce ho nastaví v záložce Nastavit požadavky dle roku.', 'info');
        return ob_get_clean();
    }

    $seznam = sb_platici_rodiny($rok);
    $suma = ['zaklad' => 0, 'zaklad_deti' => 0, 'zaklad_dospeli' => 0, 'brigady' => 0, 'dar' => 0, 'celkem' => 0, 'zaplaceno' => 0, 'zbyva' => 0, 'zaplaceno_n' => 0, 'splatky_n' => 0, 'nezaplaceno_n' => 0];
    foreach ($seznam as $z) {
        $suma['zaklad'] += $z['info']['zaklad'];
        $suma['zaklad_deti'] += $z['info']['zaklad_deti'];
        $suma['zaklad_dospeli'] += $z['info']['zaklad_dospeli'];
        $suma['brigady'] += $z['info']['brigady']['castka'];
        $suma['dar'] += $z['info']['dar'];
        $suma['celkem'] += $z['info']['celkem'];
        $suma['zaplaceno'] += $z['info']['zaplaceno'];
        $suma['zbyva'] += $z['info']['zbyva'];
        $suma[$z['info']['stav'] . '_n']++;
    }
    $nast = sb_prispevek_nastaveni($rok);
    echo "<div class='sb-card'>";
    $polozka = function($popis, $castka, $tucne = false) {
        return "<div style='white-space:nowrap;'>" . $popis . ": " . ($tucne ? "<strong style='font-size:15px;'>" : "<strong>") . sb_kc($castka) . "</strong></div>";
    };
    echo "<div style='font-size:14px; margin-bottom:6px;'>Platících rodin: <strong>" . count($seznam) . "</strong></div>";
    echo "<div style='display:flex; flex-wrap:wrap; gap:6px 22px; font-size:14px;'>";
    echo $polozka('Příspěvky za děti', $suma['zaklad_deti']);
    if ($suma['zaklad_dospeli'] > 0) echo $polozka('Příspěvky dospělých členů', $suma['zaklad_dospeli']);
    echo $polozka('Doplatky za brigády ' . ($rok - 1), $suma['brigady']);
    if ($suma['dar'] > 0) echo $polozka('Dary', $suma['dar']);
    echo $polozka('Celkem k úhradě', $suma['celkem'], true);
    echo "</div><div style='display:flex; flex-wrap:wrap; gap:6px 22px; font-size:14px; margin-top:6px;'>";
    echo $polozka('Uhrazeno', $suma['zaplaceno']);
    echo $polozka('Zbývá', $suma['zbyva']);
    echo "</div><div style='margin-top:8px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; font-size:13px;'>";
    echo sb_stav_platby_html('zaplaceno') . " " . $suma['zaplaceno_n'] . " &nbsp; " . sb_stav_platby_html('splatky') . " " . $suma['splatky_n'] . " &nbsp; " . sb_stav_platby_html('nezaplaceno') . " " . $suma['nezaplaceno_n'];
    echo "</div>";
    echo "<p class='sb-text-hint' style='margin-top:8px;'>Rodičům se příspěvek v Moje brigády zobrazuje " . (!empty($nast['od']) && !empty($nast['do']) ? "od " . esc_html(date('j. n. Y', strtotime($nast['od']))) . " do " . esc_html(date('j. n. Y', strtotime($nast['do']))) : "– období zatím není nastaveno") . ". Účet: " . esc_html($nast['ucet'] ?? '') . "</p>";
    $export_url = add_query_arg(['sb_export' => 'platby', 'rok' => $rok, '_wpnonce' => wp_create_nonce('sb_export_platby')], get_permalink());
    echo "<a href='" . esc_url($export_url) . "' class='sb-btn sb-btn-secondary sb-btn-sm'>📥 Exportovat CSV</a>";
    echo "</div>";

    // Filtr
    echo "<div class='sb-card'><div class='sb-form-row'>";
    echo "<input type='text' id='sb-platby-hledat' placeholder='🔍 Hledat rodinu nebo dítě…' autocomplete='off' style='flex:1 1 220px; max-width:360px;'>";
    echo "<select id='sb-platby-stav' style='width:auto; max-width:260px;'><option value=''>Všechny stavy</option><option value='nezaplaceno'>Nezaplaceno</option><option value='splatky'>Splátky</option><option value='zaplaceno'>Zaplaceno</option><option value='nedoplaceno'>Nezaplaceno + splátky</option></select>";
    echo "</div></div>";

    if ($je_spravce) {
        echo "<form method='post'>";
        wp_nonce_field('sb_platby', '_wpnonce_platby');
        echo "<input type='hidden' name='sb_platby_rok' value='" . intval($rok) . "'>";
        echo "<div class='sb-btn-row' style='margin:0 0 8px;'><input type='submit' name='sb_platby_ulozit' value='💾 Uložit platby' class='sb-btn sb-btn-primary'></div>";
    }
    echo "<style>#sb-platby-tabulka td.center { white-space:nowrap; }</style>";
    echo "<div style='overflow-x:auto; max-width:100%;'><table class='sb-table' id='sb-platby-tabulka' style='min-width:640px;'>";
    echo "<thead><tr><th>Rodina</th><th class='center'>Příspěvek</th><th class='center'>Doplatek " . ($rok - 1) . "</th><th class='center'>Dar</th><th class='center'>Celkem</th><th>" . ($je_spravce ? 'Platby (Kč)' : 'Splátky') . "</th><th class='center'>Zbývá</th><th class='center'>Stav</th></tr></thead><tbody>";
    foreach ($seznam as $z) {
        $r = $z['rodina']; $i = $z['info']; $id = intval($r->ID);
        $hledat = mb_strtolower($r->post_title . ' ' . implode(' ', $i['jmena']));
        echo "<tr data-hledat='" . esc_attr($hledat) . "' data-stav='" . esc_attr($i['stav']) . "'>";
        echo "<td><strong>" . esc_html($r->post_title) . "</strong><br><span class='sb-text-hint'>" . ($i['je_dospely'] ? 'dospělý člen' : esc_html(implode(', ', $i['jmena']))) . "</span></td>";
        echo "<td class='center'>" . sb_kc($i['zaklad']) . "</td>";
        echo "<td class='center'>" . ($i['brigady']['castka'] > 0 ? sb_kc($i['brigady']['castka']) . "<br><span class='sb-text-hint'>" . intval($i['brigady']['chybi']) . " h</span>" : '–') . "</td>";
        echo "<td class='center'>" . ($i['dar'] > 0 ? sb_kc($i['dar']) : '–') . "</td>";
        echo "<td class='center'><strong>" . sb_kc($i['celkem']) . "</strong></td>";
        echo "<td>";
        if ($je_spravce) {
            echo "<input type='hidden' name='sb_platby_rodiny[]' value='$id'>";
            echo "<div style='display:flex; flex-wrap:wrap; gap:4px; align-items:center;'>";
            foreach ($i['platby'] as $c) {
                echo "<input type='number' name='platby[$id][]' value='" . esc_attr($c) . "' min='0' step='0.01' style='width:80px;'>";
            }
            if ($i['stav'] !== 'zaplaceno') {
                $splatky = !empty($i['platby']);
                echo "<label style='font-size:13px; white-space:nowrap;'><input type='checkbox' name='uhrazeno[$id]' value='1' class='sb-pl-cele'> " . ($splatky ? 'doplaceno' : 'uhrazeno') . "</label>";
                echo "<label style='font-size:13px; white-space:nowrap;'><input type='checkbox' class='sb-pl-cast'> " . ($splatky ? 'další splátka' : 'částečně uhrazeno') . "</label>";
                echo "<input type='number' name='platby[$id][]' value='' min='0' step='0.01' placeholder='částka' class='sb-pl-pole' style='width:90px; display:none;'>";
            }
            echo "</div>";
        } else {
            echo $i['platby'] ? esc_html(implode(' + ', array_map('sb_kc', $i['platby']))) : '–';
        }
        echo "</td>";
        echo "<td class='center'>" . ($i['zbyva'] > 0 ? sb_kc($i['zbyva']) : '–') . "</td>";
        echo "<td class='center'>" . sb_stav_platby_html($i['stav']) . "</td>";
        echo "</tr>";
    }
    if (!$seznam) echo "<tr><td colspan='8' class='center sb-text-muted'>Žádná rodina zatím neplatí.</td></tr>";
    echo "</tbody></table></div>";
    if ($je_spravce) {
        echo "<p class='sb-text-hint'>Zaškrtněte „uhrazeno“ (zapíše se celá částka), nebo „částečně uhrazeno“ a vyplňte přijatou částku; pak uložte. U splátek zaškrtněte „doplaceno“ nebo „další splátka“. Zapsané platby opravíte přepsáním nebo smazáním čísla.</p>";
        echo "<div class='sb-btn-row'><input type='submit' name='sb_platby_ulozit' value='💾 Uložit platby' class='sb-btn sb-btn-primary'></div>";
        echo "</form>";
    } else {
        echo "<p class='sb-text-hint'>Platby zapisuje správce. Pokud platba některé rodiny nedorazí včas, prosíme, připomeňte ji rodičům.</p>";
    }

    // --- Rozeslání e-mailů (jen správce) ---
    if ($je_spravce) {
        echo "<div class='sb-card' style='margin-top:24px;'>";
        echo "<h4 class='sb-card-title'>📧 Rozeslat e-maily s příspěvkem " . intval($rok) . "</h4>";
        echo "<p class='sb-text-muted' style='margin-bottom:10px;'>E-mail obsahuje rozpis příspěvku, platební údaje a QR kód. Odpovědi chodí na info@skautchlumec.cz. Posílá se všem rodičům rodiny, kteří mají na webu účet.</p>";
        echo "<form method='post' id='sb-mail-form'>";
        wp_nonce_field('sb_platby_mail', '_wpnonce_platby_mail');
        echo "<input type='hidden' name='sb_platby_rok' value='" . intval($rok) . "'>";
        echo "<input type='hidden' name='sb_mail_davka' value='" . esc_attr(bin2hex(random_bytes(8))) . "'>";
        if (!sb_prispevek_aktivni($rok)) {
            echo sb_alert('Pozor: rodiče teď box s příspěvkem v Moje brigády nevidí (mimo nastavené období zobrazení). E-mail na něj odkazuje kvůli zadání daru – zvažte úpravu období.', 'warning');
        }
        echo "<div class='sb-form-row' style='margin-bottom:12px;'>";
        echo "<select name='sb_mail_test_rodina' style='max-width:260px;'>";
        foreach ($seznam as $z) echo "<option value='" . intval($z['rodina']->ID) . "'>" . esc_html($z['rodina']->post_title) . "</option>";
        echo "</select>";
        echo "<input type='submit' name='sb_mail_test' value='Poslat zkušební e-mail mně' class='sb-btn sb-btn-secondary'>";
        echo "</div>";
        echo "<div class='sb-btn-row' style='margin:0 0 8px;'>";
        echo "<button type='button' class='sb-btn sb-btn-secondary sb-btn-sm' onclick=\"sbMailVyber('vse')\">Označit vše</button>";
        echo "<button type='button' class='sb-btn sb-btn-secondary sb-btn-sm' onclick=\"sbMailVyber('nic')\">Odznačit vše</button>";
        echo "<button type='button' class='sb-btn sb-btn-secondary sb-btn-sm' onclick=\"sbMailVyber('nezaplacene')\">Jen nezaplacené a splátky</button>";
        echo "<button type='button' class='sb-btn sb-btn-secondary sb-btn-sm' onclick=\"sbMailVyber('neodeslane')\">Jen dosud neodeslané</button>";
        echo "</div>";
        echo "<div style='overflow-x:auto;'><table class='sb-table' style='min-width:520px;'><thead><tr><th class='center'>✉</th><th>Rodina</th><th>E-maily</th><th class='center'>Stav</th><th>Naposledy odesláno</th></tr></thead><tbody>";
        foreach ($seznam as $z) {
            $id = intval($z['rodina']->ID);
            $ucty = sb_get_rodic_emails($id);
            $adresy = $ucty ?: sb_kontakt_emaily($id);
            $odeslano = get_post_meta($id, 'sb_prispevek_mail_' . $rok, true);
            $zaskrt = $mail_vybrane !== null && in_array($id, $mail_vybrane, true);
            echo "<tr>";
            echo "<td class='center'><input type='checkbox' name='sb_mail_rodiny[]' value='$id' class='sb-mail-cb' data-stav='" . esc_attr($z['info']['stav']) . "' data-odeslano='" . ($odeslano ? '1' : '0') . "'" . ($zaskrt ? ' checked' : '') . ($adresy ? '' : ' disabled') . "></td>";
            echo "<td>" . esc_html($z['rodina']->post_title) . "</td>";
            if ($ucty) {
                echo "<td class='sb-small'>" . esc_html(implode(', ', $ucty)) . "</td>";
            } else {
                echo "<td class='sb-small'>";
                echo "<input type='text' name='kontakt[$id]' value='" . esc_attr((string) get_post_meta($id, 'sb_kontakt_email', true)) . "' placeholder='bez účtu – zadejte e-mail' class='sb-kontakt' data-rodina='$id' style='width:100%; min-width:200px; font-size:12px;'>";
                echo $adresy ? "<div class='sb-text-hint'>kontaktní e-mail (rodiče nemají účet)</div>" : "";
                echo "</td>";
            }
            echo "<td class='center'>" . sb_stav_platby_html($z['info']['stav']) . "</td>";
            echo "<td class='sb-small'>" . ($odeslano ? esc_html($odeslano) : '–') . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table></div>";
        echo "<div class='sb-btn-row'><input type='submit' name='sb_mail_odeslat' value='📧 Odeslat vybraným' class='sb-btn sb-btn-primary' onclick=\"return confirm('Odeslat e-mail s příspěvkem vybraným rodinám?');\"> <input type='submit' name='sb_kontakt_ulozit' id='sb-kontakt-ulozit' value='💾 Uložit kontaktní e-maily' class='sb-btn sb-btn-secondary'></div>";
        echo "<p class='sb-text-hint'>E-maily se posílají po $davka rodinách na jedno kliknutí. U rodin, jejichž rodiče nemají na webu účet, zadejte kontaktní e-mail (např. ze skautského informačního systému; více adres oddělte čárkou) – použije se, dokud si některý z rodičů nezaloží účet. Zadaný e-mail se uloží i při odeslání.</p>";
        echo "</form></div>";
    }

    echo "<script>
document.addEventListener('change', function(e) {
    var cb = e.target;
    if (!cb.classList || (!cb.classList.contains('sb-pl-cele') && !cb.classList.contains('sb-pl-cast'))) return;
    var bunka = cb.closest('td');
    var cele = bunka.querySelector('.sb-pl-cele'), cast = bunka.querySelector('.sb-pl-cast'), pole = bunka.querySelector('.sb-pl-pole');
    if (cb === cele && cele.checked) cast.checked = false;
    if (cb === cast && cast.checked) cele.checked = false;
    pole.style.display = cast.checked ? '' : 'none';
    if (!cast.checked) pole.value = '';
    if (cast.checked) pole.focus();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && e.target.classList && e.target.classList.contains('sb-kontakt')) {
        e.preventDefault();
        var b = document.getElementById('sb-kontakt-ulozit'); if (b) b.click();
    }
});
document.addEventListener('input', function(e) {
    if (!e.target.classList || !e.target.classList.contains('sb-kontakt')) return;
    var cb = document.querySelector('.sb-mail-cb[value=\"' + parseInt(e.target.getAttribute('data-rodina'), 10) + '\"]');
    if (cb) { cb.disabled = e.target.value.indexOf('@') < 0; if (cb.disabled) cb.checked = false; }
});
function sbMailVyber(co) {
    document.querySelectorAll('.sb-mail-cb').forEach(function(cb) {
        if (cb.disabled) { cb.checked = false; return; }
        if (co === 'vse') cb.checked = true;
        else if (co === 'nic') cb.checked = false;
        else if (co === 'nezaplacene') cb.checked = cb.getAttribute('data-stav') !== 'zaplaceno';
        else if (co === 'neodeslane') cb.checked = cb.getAttribute('data-odeslano') === '0';
    });
}
(function() {
    var q = document.getElementById('sb-platby-hledat'), s = document.getElementById('sb-platby-stav');
    if (!q || !s) return;
    function filtr() {
        var t = q.value.toLowerCase().trim(), st = s.value;
        document.querySelectorAll('#sb-platby-tabulka tbody tr[data-stav]').forEach(function(tr) {
            var stav = tr.getAttribute('data-stav');
            var okStav = !st || st === stav || (st === 'nedoplaceno' && stav !== 'zaplaceno');
            var okText = !t || tr.getAttribute('data-hledat').indexOf(t) > -1;
            tr.style.display = (okStav && okText) ? '' : 'none';
        });
    }
    q.addEventListener('input', filtr); s.addEventListener('change', filtr);
})();
</script>";

    return ob_get_clean();
}

 function sb_spravce_brigad_shortcode() {
  $current_user = wp_get_current_user();
$roles = $current_user->roles;

$allowed_full = ['administrator', 'spravce_brigad'];
$allowed_limited = ['author'];

if (!array_intersect($roles, array_merge($allowed_full, $allowed_limited))) {
    return '<p>Nemáš oprávnění pro přístup k této části.</p>';
}


    // Výchozí sekce
    $aktivni_sekce = 'nova';
if (array_intersect($roles, $allowed_limited)) {
    $aktivni_sekce = 'ucastnici_dle';
}

    // Aktivace správné sekce po POSTu
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Správa brigád – jen pokud nejde o výběr brigády z jiné sekce
        if (isset($_POST['ulozit_brigadu']) || isset($_POST['smazat_brigadu']) || isset($_POST['nova_brigada']) ||
            (isset($_POST['vybrana_brigada']) && !isset($_POST['zobraz_ucast']) && !isset($_POST['zobraz_ucastniky']))) {
            $aktivni_sekce = 'upravit_brigady';
        }
        // Účastníci dle brigád
        if (isset($_POST['zobraz_ucast'])) {
            $aktivni_sekce = 'ucastnici_dle';
        }
        // Správa účastníků
        if (isset($_POST['zobraz_ucastniky']) || isset($_POST['edit_ucastnik']) || isset($_POST['delete_ucastnik']) || isset($_POST['odhlasit_ucastnika'])) {
            $aktivni_sekce = 'upravit_ucastniky';
        }
        // Vytvořit rodinu
        if (isset($_POST['nova_rodina'])) {
            $aktivni_sekce = 'rodiny';
        }
        // Správa rodin
        if (isset($_POST['edit_rodina_id']) || isset($_POST['confirm_delete_rodina']) || isset($_POST['smazat_dite']) ||
            (isset($_POST['vybrana_rodina']) && isset($_POST['akce']) && sanitize_key($_POST['akce']) === 'vyber')) {
            $aktivni_sekce = 'prehled';
        }
        // Nastavit požadavky
        if (isset($_POST['upravit_pozadavky_dle_roku']) || isset($_POST['ulozit_pozadavky']) || isset($_POST['sb_revize_akce']) || isset($_POST['sb_prispevky_ulozit'])) {
            $aktivni_sekce = 'upravit_pozadavky';
        }
        // Rodiny dle brigád
        if (isset($_POST['zobraz_rodinu_admin'])) {
            $aktivni_sekce = 'rodiny_dle_brigad';
        }
        // Roční výpis
        if (isset($_POST['zobraz_rocni_vypis'])) {
            $aktivni_sekce = 'rocni_vypis';
        }
        // Správa dospělých členů
        if (isset($_POST['sb_dosp_novy']) || isset($_POST['sb_dosp_ulozit']) || isset($_POST['sb_dosp_ukoncit']) || isset($_POST['sb_dosp_obnovit']) || isset($_POST['sb_dosp_rodic'])) {
            $aktivni_sekce = 'dospeli';
        }
        // Platby
        if (isset($_POST['sb_platby_zobraz']) || isset($_POST['sb_platby_ulozit']) || isset($_POST['sb_mail_odeslat']) || isset($_POST['sb_mail_test'])) {
            $aktivni_sekce = 'platby';
        }
    }

    ob_start();
    
        ?>
    <style>
        /* === Skautské brigády – Admin UI === */
        .sb-admin-wrap { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 14px; color: #333; }

        /* Navigační menu */
        .sb-menu-horizontal { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 0; padding-bottom: 0; border-bottom: 3px solid #0073aa; }
        .sb-menu-horizontal button {
            font-size: 12px; padding: 7px 13px; background: #f0f0f0; color: #444;
            border: 1px solid #ccc; border-bottom: none; border-radius: 4px 4px 0 0;
            cursor: pointer; white-space: nowrap; transition: background 0.15s; margin-bottom: -1px;
        }
        .sb-menu-horizontal button:hover { background: #e0e0e0; color: #000; }
        .sb-menu-horizontal button.sb-active { background: #0073aa; color: #fff; border-color: #0073aa; font-weight: 600; }

        /* Obsah sekcí */
        .sb-content { padding: 24px; background: #fff; }
        .sb-content > div { display: none; }
        .sb-content > div.sb-active { display: block; }

        /* Karty */
        .sb-card { background: #fafafa; border: 1px solid #ddd; border-radius: 4px; padding: 18px 20px; margin-bottom: 20px; }
        .sb-card-title { margin: 0 0 14px 0; padding-bottom: 8px; border-bottom: 2px solid #0073aa; color: #0073aa; font-size: 15px; font-weight: 600; }

        /* Nadpisy sekcí */
        .sb-section-title { margin: 0 0 20px 0; font-size: 18px; color: #222; display: flex; align-items: center; gap: 8px; }

        /* Tlačítka */
        .sb-btn { display: inline-block; padding: 6px 14px; font-size: 13px; font-weight: 500; border: none; border-radius: 3px; cursor: pointer; text-decoration: none; line-height: 1.6; transition: filter 0.15s; vertical-align: middle; }
        .sb-btn:hover { filter: brightness(0.88); }
        .sb-btn-primary  { background: #0073aa; color: #fff !important; }
        .sb-btn-danger   { background: #d63638; color: #fff !important; }
        .sb-btn-success  { background: #2e7d32; color: #fff !important; }
        .sb-btn-secondary{ background: #757575; color: #fff !important; }
        .sb-btn-sm       { padding: 3px 9px; font-size: 12px; }
        .sb-btn-row      { display: flex; gap: 8px; margin-top: 16px; flex-wrap: wrap; align-items: center; }

        /* Globální styl polí v celém pluginu */
        .sb-admin-wrap input[type=text],
        .sb-admin-wrap input[type=number],
        .sb-admin-wrap input[type=date],
        .sb-admin-wrap textarea,
        .sb-admin-wrap select {
            padding: 5px 8px; border: 1px solid #8c8f94; border-radius: 3px;
            font-size: 13px; line-height: 1.5; color: #2c3338; background: #fff;
            box-sizing: border-box; vertical-align: middle;
            transition: border-color 0.1s;
        }
        .sb-admin-wrap input[type=text]:focus,
        .sb-admin-wrap input[type=number]:focus,
        .sb-admin-wrap input[type=date]:focus,
        .sb-admin-wrap textarea:focus,
        .sb-admin-wrap select:focus {
            border-color: #0073aa; outline: none; box-shadow: 0 0 0 1px #0073aa;
        }
        .sb-admin-wrap textarea { resize: vertical; }

        /* Formuláře */
        .sb-form-group { margin-bottom: 14px; }
        .sb-form-group > label { display: block; font-weight: 600; margin-bottom: 4px; font-size: 13px; color: #444; }
        .sb-form-group input[type=text], .sb-form-group input[type=number],
        .sb-form-group input[type=date], .sb-form-group textarea, .sb-form-group select {
            width: 100%; max-width: 420px;
        }
        .sb-form-group textarea { max-width: 100%; }
        .sb-form-inline { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

        /* Zprávy */
        .sb-alert { padding: 10px 14px; border-radius: 3px; margin-bottom: 16px; font-size: 13px; border-left: 4px solid; }
        .sb-alert-success { background: #f0faf0; border-color: #2e7d32; color: #1b5e20; }
        .sb-alert-error   { background: #fff0f0; border-color: #d63638; color: #7b1c1e; }
        .sb-alert-info    { background: #e8f4fb; border-color: #0073aa; color: #004e73; }
        .sb-alert-warning { background: #fff8e1; border-color: #f57c00; color: #7a4100; }

        /* Tabulky */
        .sb-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 10px; }
        .sb-table th { background: #e8f0f7; color: #222; border: 1px solid #c0cfe0; padding: 7px 10px; text-align: left; font-weight: 600; }
        .sb-table td { border: 1px solid #ddd; padding: 6px 10px; vertical-align: middle; }
        .sb-table tr:nth-child(even) td { background: #f7f7f7; }
        .sb-table tr:hover td { background: #edf4fa; }
        .sb-table .center { text-align: center; }
        .sb-table .green  { color: #2e7d32; font-weight: 600; }
        .sb-table .red    { color: #d32f2f; font-weight: 600; }

        /* Walk-in účastníci */
        .sb-walkin-item { display: flex; align-items: center; gap: 10px; padding: 6px 10px; background: #fff; border: 1px solid #ddd; border-radius: 3px; margin-bottom: 6px; font-size: 13px; }
        .sb-walkin-item span { flex: 1; }

        /* Brigáda karta (výpis) */
        .sb-brigade-card { border: 1px solid #ddd; border-radius: 4px; padding: 14px 16px; margin-bottom: 14px; background: #fff; }
        .sb-brigade-card h4 { margin: 0 0 10px 0; color: #0073aa; font-size: 14px; }

        /* Pomocné třídy */
        .sb-form-row { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
        .sb-fg-inline { margin: 0 !important; }
        .sb-fg-inline-b0 { margin-bottom: 0 !important; }
        .sb-text-muted { color: #777; font-size: 13px; font-style: italic; }
        .sb-text-hint { color: #999; font-size: 12px; }
        .sb-participant-item { border: 1px solid #e0e0e0; border-radius: 4px; padding: 12px; margin-bottom: 12px; background: #fff; }
        .sb-participant-meta { color: #555; font-size: 13px; }
        .sb-walkin-add { background: #f0f8ff; border: 1px solid #b0d4ea; border-radius: 4px; padding: 14px; margin-top: 12px; }
        .sb-walkin-add strong { font-size: 13px; }
        .sb-divider { margin: 20px 0; border: none; border-top: 1px solid #e0e0e0; }
        .sb-action-section { margin-top: 14px; padding-top: 14px; border-top: 1px solid #eee; }
        .sb-children-row { display: flex; gap: 8px; margin-bottom: 6px; align-items: center; }
        .sb-children-row input[type=text] { flex: 2; }
        .sb-children-row select { flex: 1; }
        .sb-label-normal { display: block; margin-bottom: 5px; font-weight: normal; }
        .sb-table td.sb-small { font-size: 12px; }

        /* Responsivita */
        @media (max-width: 640px) {
            .sb-menu-horizontal { flex-direction: column; border-bottom: none; }
            .sb-menu-horizontal button { border-radius: 3px; border-bottom: 1px solid #ccc; margin-bottom: 2px; }
            .sb-menu-horizontal button.sb-active { border-color: #0073aa; }
            .sb-content { border-top: 1px solid #0073aa; }
            .sb-btn-row { flex-direction: column; align-items: flex-start; }
            .sb-content { padding: 14px 10px; }
            .sb-table th, .sb-table td { padding: 5px 6px; font-size: 12px; }
            .sb-form-group input[type=text], .sb-form-group input[type=number],
            .sb-form-group input[type=date], .sb-form-group textarea, .sb-form-group select { max-width: 100%; }
        }
    </style>

    <script>
        function sbShowSection(id) {
            document.querySelectorAll('.sb-content > div').forEach(div => {
                div.classList.remove('sb-active');
                div.style.display = 'none';
            });
            document.querySelectorAll('.sb-menu-horizontal button').forEach(btn => {
                btn.classList.remove('sb-active');
            });
            const target = document.getElementById('sb-' + id);
            if (target) {
                target.classList.add('sb-active');
                target.style.display = 'block';
                target.scrollIntoView({ behavior: 'smooth' });
                document.querySelectorAll('.sb-menu-horizontal button').forEach(btn => {
                    if (btn.getAttribute('onclick') === "sbShowSection('" + id + "')") {
                        btn.classList.add('sb-active');
                    }
                });
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const hash = window.location.hash;
            if (hash.startsWith('#sb-')) {
                sbShowSection(hash.replace('#sb-', ''));
            }

            // Obnov scroll pozici po POST (uložení formuláře)
            const savedY = sessionStorage.getItem('sb_scroll_y');
            if (savedY !== null) {
                sessionStorage.removeItem('sb_scroll_y');
                setTimeout(() => window.scrollTo({ top: parseInt(savedY), behavior: 'instant' }), 150);
            }
        });

        // Uložení scroll pozice před odesláním formuláře
        document.addEventListener('submit', function() {
            sessionStorage.setItem('sb_scroll_y', window.scrollY);
        });
    </script>
    
    <div class="sb-admin-wrap">
        <div class="sb-menu-horizontal">
        <?php if (array_intersect($roles, $allowed_full)) : ?>
            <button onclick="sbShowSection('nova')">📘 Návod</button>
            <button onclick="sbShowSection('rodiny')">➕ Vytvořit rodinu</button>
            <button onclick="sbShowSection('prehled')">👥 Správa rodin</button>
            <button onclick="sbShowSection('dospeli')">🧑 Správa dospělých členů (bez dětí ve skautu)</button>
            <button onclick="sbShowSection('upravit_pozadavky')">📅 Nastavit požadavky dle roku</button>
            <button onclick="sbShowSection('upravit_brigady')">🛠️ Vytvoření a správa brigád</button>
            <button onclick="sbShowSection('upravit_ucastniky')">🧮 Správa účastníků</button>
            <button onclick="sbShowSection('ucastnici_dle')">🧮 Účastníci dle brigád</button>
            <button onclick="sbShowSection('rodiny_dle_brigad')">👪 Rodiny dle brigád</button>
            <button onclick="sbShowSection('rocni_vypis')">📊 Roční výpis</button>
            <button onclick="sbShowSection('platby')">💳 Platby</button>
        <?php elseif (array_intersect($roles, $allowed_limited)) : ?>
    <button onclick="sbShowSection('napoveda')">📘 Nápověda</button>
    <button onclick="sbShowSection('rodiny')">➕ Vytvořit rodinu</button>
    <button onclick="sbShowSection('prehled')">👥 Správa rodin</button>
    <button onclick="sbShowSection('dospeli')">🧑 Správa dospělých členů (bez dětí ve skautu)</button>
    <button onclick="sbShowSection('ucastnici_dle')">🧮 Účastníci dle brigád</button>
    <button onclick="sbShowSection('rodiny_dle_brigad')">👪 Rodiny dle brigád</button>
    <button onclick="sbShowSection('rocni_vypis')">📊 Roční výpis</button>
    <button onclick="sbShowSection('platby')">💳 Platby</button>
<?php endif; ?>
    </div>
    
    <div class="sb-content">


       <?php if (array_intersect($roles, $allowed_full)) : ?>
    <div id="sb-nova" class="<?php echo ($aktivni_sekce === 'nova') ? 'sb-active' : ''; ?>">



                <br><br>
                <h3>📘 <b>Uživatelský manuál</b></h3>
                <p>Tento plugin slouží k evidenci brigádnických hodin v našem středisku. Umožňuje spravovat brigády, zaznamenávat účast rodin, sledovat odpracované hodiny, nastavovat požadavky na brigádnické hodiny dle počtu dětí a vypočítávat poplatky za neodpracované hodiny.</p>

                <br><br>
                <h5>🧭 <b>Navigace v rozhraní</b></h5>
                <p>Horní horizontální menu obsahuje tlačítka pro přepínání mezi sekcemi. Každé tlačítko aktivuje konkrétní část rozhraní, která se zobrazí níže. Přepínání je plynulé a přehledné – stačí kliknout.</p>

                <br><br>
                <h5>🛠️ <b>Správa brigád</b></h5>
                <ul>
                    <li>Výběr brigády ze seznamu</li>
                    <li>Úprava názvu a data konání</li>
                    <li>Smazání brigády (s potvrzením)</li>
                    <li>Vytvoření nové brigády (formulář se zobrazí po kliknutí na tlačítko)</li>
                </ul>
                <p>Brigády se zobrazují chronologicky podle data.</p>

                <br><br>
                <h5>🧮 <b>Účastníci dle brigád</b></h5>
                <ul>
                    <li>Vyber brigádu</li>
                    <li>Zobrazí se seznam účastníků (přihlášené rodiny i walk-in) s odpracovanými hodinami</li>
                    <li>Odtud stáhneš prezenční listinu, Excel (CSV) a zkopíruješ e-maily přihlášených rodin</li>
                </ul>

                <br><br>
                <h5>🧮 <b>Správa účastníků</b></h5>
                <ul>
                    <li>Vyber brigádu</li>
                    <li>U každé rodiny lze upravit počet hodin nebo ji odebrat</li>
                </ul>

                <br><br>
                <h5>➕ <b>Vytvořit rodinu</b></h5>
                <ul>
                    <li>Zadej název rodiny (musí být jedinečný) a vyber rodiče (uživatelé s rolí „rodic", řazení podle příjmení)</li>
                    <li>Rodiče, kteří už patří do jiné rodiny, se v nabídce nezobrazují; chybějícího rodiče lze doplnit později</li>
                    <li>Děti se přidávají až potom v sekci <strong>Správa rodin</strong> (Upravit)</li>
                    <li>Vedoucí (role author) mohou rodiny zakládat jen při otevřené revizi</li>
                </ul>

                <br><br>
                <h5>👥 <b>Správa rodin</b></h5>
                <ul>
                    <li>Přehled karet: rodina, rodiče, děti a jejich oddíl (děti, které už nechodí, jsou šedě přeškrtnuté)</li>
                    <li>Vyhledávání rodiny, rodiče nebo dítěte v poli nahoře</li>
                    <li>Tlačítkem <strong>Upravit</strong> se karta rozbalí: změna názvu, rodičů, jmen a oddílů dětí, přidání dítěte; po uložení se karta sbalí a krátce zeleně zvýrazní</li>
                    <li>Při přejmenování rodiny se automaticky převedou i její walk-in záznamy, takže hodiny se neztratí; název nesmí být stejný jako u jiné rodiny</li>
                    <li><strong>Už nechodí</strong> – dítě se přesune do archivu (data se nemažou) a od příštího roku se nepočítá do požadavků; poznámka je volitelná</li>
                    <li><strong>Přesunout rodinu do archivu</strong> – celá rodina už skauta nenavštěvuje; v sekci Archiv rodin ji lze kdykoli obnovit. Rodiče v archivované rodině zůstávají, nenabízejí se pro jiné rodiny a při obnovení se vrátí s ní</li>
                    <li>Vedoucí (role author) mohou rodiny upravovat jen při <strong>otevřené revizi</strong>, jinak je vidí jen pro čtení</li>
                    <li>Trvalé smazání dítěte nebo rodiny může provést jen správce</li>
                </ul>

                <br><br>
                <h5>🧑 <b>Dospělí členové</b></h5>
                <ul>
                    <li><strong>Dítě dospělo</strong> – ve Správě rodin u dítěte zaškrtni <strong>Dospělý člen</strong>: od příštího roku odejde z rodiny a vznikne samostatný dospělý člen (najdeš ho v záložce <strong>Správa dospělých členů</strong>)</li>
                    <li><strong>Rodič je sám členem</strong> – ve Správě rodin ho přidej do „Dospělí členové v rodině“: rodina pak platí v jedné částce i jeho příspěvek dospělého člena (hodiny se počítají za rodinu). <strong>Rodič s rolí vedoucí (author) nebo správce/administrátor je dospělým členem automaticky</strong> – nezadávej ho</li>
                    <li><strong>Rodina bez dětí</strong> (např. dva vedoucí) – v rozbalené kartě tlačítko <strong>Převést na dospělé členy</strong>: rodina se archivuje a každý bude veden jako samostatný dospělý člen i se svým účtem</li>
                    <li>Omylem zaškrtnuté „Dospělý člen“ vrátíš v kartě rodiny volbou <em>Vrátit do rodiny (byl to omyl)</em> – samostatný záznam se zruší</li>
                    <li>Když rodině nezůstane žádné dítě, rodina se automaticky archivuje a dospělí členové v ní se stanou samostatnými dospělými členy</li>
                    <li><strong>Správa dospělých členů</strong>: nový člen (jméno, účet na webu s rolí „rodic“, případně kontaktní e-mail), úpravy, <strong>Ukončil členství</strong> a <strong>Stal se rodičem</strong> (přesun do existující nebo nové rodiny)</li>
                    <li>Samostatný dospělý člen platí příspěvek dospělého člena a má osobní brigádní požadavek; hodiny se mu evidují, doplatek za neodpracované hodiny neplatí</li>
                    <li>Sazbu příspěvku i požadavek pro dospělé nastavíš v <em>Nastavit požadavky dle roku</em></li>
                </ul>

                <br><br>
                <h5>🗂️ <b>Revize rodin</b></h5>
                <ul>
                    <li>V sekci <em>Nastavit požadavky dle roku</em> správce <strong>zahájí revizi</strong> (zmrazí se počty dětí pro letošní rok a vedoucí mohou upravovat rodiny)</li>
                    <li>Po dokončení úprav revizi <strong>ukončí</strong> – vytvoří se snímek počtu dětí pro příští rok (přepíše i ručně upravené hodnoty snímku)</li>
                    <li>Když správce po ukončení revize u dítěte zaškrtne „Už nechodí" (nebo dítě obnoví či odebere), snímek příštího roku se automaticky upraví o rozdíl</li>
                    <li>Doporučený termín: zahájit v říjnu, ukončit nejpozději do konce listopadu (v prosinci rodiče vidí požadavky na příští rok)</li>
                </ul>

                <br><br>
                <h5>📅 <b>Nastavit požadavky dle roku</b></h5>
                <ul>
                    <li>Vyber rok ze seznamu</li>
                    <li>Zadej požadavky pro rodiny s 1, 2 nebo 3+ dětmi</li>
                    <li>Zadej sazbu za neodpracovanou hodinu (v Kč)</li>
                    <li>Ulož požadavky</li>
                </ul>
                <p>Na konci stránky se zobrazuje přehledová tabulka všech nastavených let, včetně sazby, a tabulka <strong>snímku počtu dětí</strong> pro vybraný rok (lze vytvořit tlačítkem ze současného stavu nebo upravit ručně).</p>

                <br><br>
                <h5>🗓️ <b>Skautský rok a nové rodiny</b></h5>
                <ul>
                    <li>Požadavky na rok X se vztahují jen na rodiny vzniklé nejpozději <strong>31. 8. roku X</strong></li>
                    <li>Rodina vzniklá později (např. v říjnu 2026) má na rok 2026 nulový požadavek a začíná plnit až od roku 2027</li>
                    <li><strong>Převod hodin:</strong> hodiny, které taková nová rodina odpracuje v roce svého vzniku, se jí převedou do prvního roku s požadavky (zobrazí se jako „Převedeno z roku 2026" v Moje brigády, Rodiny dle brigád i v Ročním výpisu)</li>
                    <li>Archivovaná rodina se od roku archivace do požadavků nepočítá</li>
                </ul>

                <br><br>
                <h5>💳 <b>Členský příspěvek a platby</b></h5>
                <ul>
                    <li>V <em>Nastavit požadavky dle roku</em> vyber rok (např. 2027) a v kartě <strong>Členský příspěvek</strong> vyplň částku za dítě při 1, 2 a 3+ dětech v rodině, číslo účtu a období, kdy se příspěvek rodičům zobrazí (od – do)</li>
                    <li>Příspěvek rodiny = počet dětí × částka + doplatek za neodpracované brigádní hodiny předchozího roku (jednou za rodinu). Rodiny v archivu neplatí nic</li>
                    <li><strong>Rodina vedoucího</strong> (některý z rodičů má i roli author) doplatek za neodpracované brigády neplatí – hodiny se jí zobrazují normálně, poplatek je 0 (i v Ročním výpisu a v Moje brigády)</li>
                    <li>Rodiče v nastaveném období vidí v <strong>Moje brigády</strong> rozpis, platební údaje a QR kód; mohou přidat sponzorský dar (připočte se k částce i do QR kódu)</li>
                    <li>Záložka <strong>Platby</strong>: přehled rodin se stavem <em>nezaplaceno / splátky / zaplaceno</em> a souhrn (příspěvky za děti, doplatky za brigády, dary, celkem, uhrazeno, zbývá). Platbu zapíšeš zaškrtnutím <strong>uhrazeno</strong> (zapíše se celá částka), nebo <strong>částečně uhrazeno</strong> a vyplněním přijaté částky; u splátek <strong>doplaceno</strong> (zbytek) nebo <strong>další splátka</strong>. Nakonec <strong>Uložit platby</strong>. Zapsanou platbu opravíš přepsáním čísla v poli, smazáním čísla ji odebereš. Vedoucí platby vidí, zapisuje jen správce</li>
                    <li>Tamtéž <strong>rozeslání e-mailů</strong>: nejdřív si pošli zkušební e-mail, pak zaškrtni rodiny (Označit vše / Jen nezaplacené / Jen dosud neodeslané) a odešli – posílá se po 20 rodinách na kliknutí; odpovědi chodí na info@skautchlumec.cz</li>
                    <li>Rodině, jejíž rodiče nemají účet na webu, zadej v seznamu pro rozesílání <strong>kontaktní e-mail</strong> (např. ze skautského informačního systému, více adres odděl čárkou) a ulož – adresa se k rodině uloží natrvalo a použije se, dokud si některý z rodičů nezaloží účet</li>
                </ul>

                <br><br>
                <h5>📊 <b>Roční výpis</b></h5>
                <ul>
                    <li>Vyber rok (2025–2035)</li>
                    <li>Zobrazí se tabulka s:
                        <ul>
                            <li>Počtem dětí</li>
                            <li>Odpracovanými hodinami</li>
                            <li>Požadavkem</li>
                            <li>Rozdílem (barevně zvýrazněno)</li>
                            <li>Poplatkem za neodpracované hodiny (v Kč)</li>
                        </ul>
                    </li>
                    <li>Kliknutím na záhlaví sloupců Odpracováno, Požadavek, Rozdíl a Poplatek tabulku seřadíš; sloupec # čísluje řádky</li>
                    <li>U nových rodin obsahuje Odpracováno i převod z předchozího roku (např. „vč. 8 h z 2026")</li>
                    <li>Tlačítkem <strong>Exportovat CSV</strong> stáhneš výpis do Excelu</li>
                </ul>
                <p>Výpis pomáhá sledovat, kdo splnil požadavek a jaký poplatek případně vzniká.</p>
            </div>
        <?php endif; ?>
        
                <?php if (array_intersect($roles, $allowed_full)) : ?>
            <div id="sb-rodiny" class="<?php echo ($aktivni_sekce === 'rodiny') ? 'sb-active' : ''; ?>"><?php echo sb_vytvorit_rodinu(); ?></div>
            <div id="sb-prehled" class="<?php echo ($aktivni_sekce === 'prehled') ? 'sb-active' : ''; ?>"><?php echo sb_prehled_rodin(); ?></div>
            <div id="sb-dospeli" class="<?php echo ($aktivni_sekce === 'dospeli') ? 'sb-active' : ''; ?>"><?php echo sb_sprava_dospelych(); ?></div>
            <div id="sb-upravit_pozadavky" class="<?php echo ($aktivni_sekce === 'upravit_pozadavky') ? 'sb-active' : ''; ?>"><?php echo sb_upravit_pozadavky_dle_roku(); ?></div>
            <div id="sb-upravit_brigady" class="<?php echo ($aktivni_sekce === 'upravit_brigady') ? 'sb-active' : ''; ?>"><?php echo sb_sprava_brigad(); ?></div>
            <div id="sb-upravit_ucastniky" class="<?php echo ($aktivni_sekce === 'upravit_ucastniky') ? 'sb-active' : ''; ?>"><?php echo sb_sprava_ucastniku(); ?></div>
            <div id="sb-ucastnici_dle" class="<?php echo ($aktivni_sekce === 'ucastnici_dle') ? 'sb-active' : ''; ?>"><?php echo sb_ucastnici_dle_brigad(); ?></div>
            <div id="sb-rodiny_dle_brigad" class="<?php echo ($aktivni_sekce === 'rodiny_dle_brigad') ? 'sb-active' : ''; ?>"><?php echo sb_rodiny_dle_brigad_tab(); ?></div>
            <div id="sb-rocni_vypis" class="<?php echo ($aktivni_sekce === 'rocni_vypis') ? 'sb-active' : ''; ?>"><?php echo sb_rocni_vypis(); ?></div>
            <div id="sb-platby" class="<?php echo ($aktivni_sekce === 'platby') ? 'sb-active' : ''; ?>"><?php echo sb_platby_tab(); ?></div>
        <?php elseif (array_intersect($roles, $allowed_limited)) : ?>
            <div id="sb-napoveda" class="<?php echo ($aktivni_sekce === 'napoveda') ? 'sb-active' : ''; ?>"><?php echo sb_napoveda_vedouci(); ?></div>
            <div id="sb-rodiny" class="<?php echo ($aktivni_sekce === 'rodiny') ? 'sb-active' : ''; ?>"><?php echo sb_vytvorit_rodinu(); ?></div>
            <div id="sb-prehled" class="<?php echo ($aktivni_sekce === 'prehled') ? 'sb-active' : ''; ?>"><?php echo sb_prehled_rodin(); ?></div>
            <div id="sb-dospeli" class="<?php echo ($aktivni_sekce === 'dospeli') ? 'sb-active' : ''; ?>"><?php echo sb_sprava_dospelych(); ?></div>
            <div id="sb-ucastnici_dle" class="<?php echo ($aktivni_sekce === 'ucastnici_dle') ? 'sb-active' : ''; ?>"><?php echo sb_ucastnici_dle_brigad(); ?></div>
            <div id="sb-rodiny_dle_brigad" class="<?php echo ($aktivni_sekce === 'rodiny_dle_brigad') ? 'sb-active' : ''; ?>"><?php echo sb_rodiny_dle_brigad_tab(); ?></div>
            <div id="sb-rocni_vypis" class="<?php echo ($aktivni_sekce === 'rocni_vypis') ? 'sb-active' : ''; ?>"><?php echo sb_rocni_vypis(); ?></div>
            <div id="sb-platby" class="<?php echo ($aktivni_sekce === 'platby') ? 'sb-active' : ''; ?>"><?php echo sb_platby_tab(); ?></div>
        <?php endif; ?>
    </div>
    </div><!-- .sb-admin-wrap -->

    <?php
    return ob_get_clean();
}
add_shortcode('spravce_brigad', 'sb_spravce_brigad_shortcode');

// Helper: HTML alert zpráva
function sb_alert($text, $type = 'success') {
    $types = ['success' => 'sb-alert-success', 'error' => 'sb-alert-error', 'info' => 'sb-alert-info', 'warning' => 'sb-alert-warning'];
    $class = $types[$type] ?? 'sb-alert-info';
    return "<div class='sb-alert {$class}'>" . esc_html($text) . "</div>";
}

// Utility: přesná kontrola, zda existuje post s daným názvem
function sb_post_exists_by_title($post_type, $title) {
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM $wpdb->posts WHERE post_type = %s AND post_status != 'trash' AND post_title = %s LIMIT 1",
        $post_type, $title
    ));
}

function sb_ucastnici_dle_brigad() {
    $current_user = wp_get_current_user();
    $roles = $current_user->roles;
    if (!array_intersect($roles, ['administrator', 'spravce_brigad', 'author'])) {
        return '<p>Nemáš oprávnění pro zobrazení účastníků.</p>';
    }

    $brigady = get_posts([
        'post_type' => 'brigada',
        'numberposts' => -1,
        'meta_key' => 'datum_brigady',
        'orderby' => 'meta_value',
        'order' => 'ASC'
    ]);

    $rodiny = get_posts([
        'post_type' => 'rodina',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC'
    ]);

    $zprava = '';
    ob_start();
    echo "<h3 class='sb-section-title'>🧮 Účastníci dle brigád</h3>";

    echo "<div class='sb-card'>";
    echo "<form method='post' class='sb-form-row'>";
    wp_nonce_field('zobraz_ucast', '_wpnonce_zobraz_ucast');
    echo "<div class='sb-form-group sb-fg-inline'><label>Brigáda:</label><select name='vybrana_brigada'>";
    foreach ($brigady as $b) {
        $selected = (isset($_POST['vybrana_brigada']) && intval($_POST['vybrana_brigada']) === $b->ID) ? 'selected' : '';
        echo "<option value='{$b->ID}' $selected>" . esc_html($b->post_title) . "</option>";
    }
    echo "</select></div>";
    echo "<input type='submit' name='zobraz_ucast' value='Zobrazit' class='sb-btn sb-btn-primary'>";
    echo "</form>";
    echo "</div>";

    // Výpis účastníků
if (isset($_POST['zobraz_ucast'])) {
    if (!isset($_POST['_wpnonce_zobraz_ucast']) || !wp_verify_nonce($_POST['_wpnonce_zobraz_ucast'], 'zobraz_ucast')) {
        echo sb_alert('Neplatný bezpečnostní token. Zkuste to prosím znovu.', 'error');
        return ob_get_clean();
    }
    $id = intval($_POST['vybrana_brigada']);

    // --- Získání informací o brigádě ---
    $datum       = get_post_meta($id, 'datum_brigady', true);
    $cas         = get_post_meta($id, 'cas_brigady', true);
    $misto       = get_post_meta($id, 'misto_konani', true);
    $trvani      = get_post_meta($id, 'delka_brigady', true);
    $vhodne      = get_post_meta($id, 'vhodne_pro', true);
    $poznamka    = get_post_meta($id, 'poznamka_brigady', true);
    $potrebujeme = get_post_meta($id, 'pozadovany_pocet', true);

    // --- Sestavení sjednoceného přehledu účastníků ---
    $radky_ucastnici     = [];
    $prihlasenych_celkem = 0;
    $skutecne_celkem     = 0;
    $clovekhodiny_celkem = 0;

    $walkins_dle     = get_post_meta($id, 'walkin_ucastnici', true);
    if (!is_array($walkins_dle)) $walkins_dle = [];
    $walkin_jmena_dle = array_column($walkins_dle, 'jmeno');

    foreach ($rodiny as $r) {
        $pocet = intval(get_post_meta($id, 'ucastnici_' . $r->ID, true));
        if (!$pocet) continue;
        $prihlasenych_celkem += $pocet;
        $hodiny_osob_dle = get_post_meta($id, 'hodiny_osob_' . $r->ID, true);
        $hodiny_osob_dle = (is_array($hodiny_osob_dle) && !empty($hodiny_osob_dle)) ? $hodiny_osob_dle : null;
        $odp      = get_post_meta($id, 'odpracovano_' . $r->ID, true);
        $h_os     = ($hodiny_osob_dle === null && $odp !== '' && $odp !== null) ? intval($odp) : null;
        $celkem_h = $hodiny_osob_dle !== null ? array_sum($hodiny_osob_dle) : ($h_os !== null ? $h_os * $pocet : null);
        if ($celkem_h !== null) {
            $skutecne_celkem     += $pocet;
            $clovekhodiny_celkem += $celkem_h;
        }
        $typ = in_array($r->post_title, $walkin_jmena_dle, true) ? 'Walk-in' : 'Přihlášena';
        $radky_ucastnici[] = ['jmeno' => $r->post_title, 'osob' => $pocet, 'h_os' => $h_os, 'hodiny_osob' => $hodiny_osob_dle, 'celkem_h' => $celkem_h, 'typ' => $typ];
    }

    $jmena_prihlasenych_dle = array_column($radky_ucastnici, 'jmeno');
    foreach ($walkins_dle as $w) {
        if (in_array($w['jmeno'], $jmena_prihlasenych_dle, true)) continue;
        $pocet    = intval($w['pocet'] ?? 1);
        $hodiny   = intval($w['hodiny']);
        $celkem_h = $pocet * $hodiny;
        $skutecne_celkem     += $pocet;
        $clovekhodiny_celkem += $celkem_h;
        $radky_ucastnici[] = ['jmeno' => $w['jmeno'], 'osob' => $pocet, 'h_os' => $hodiny, 'celkem_h' => $celkem_h, 'typ' => 'Walk-in'];
    }
    usort($radky_ucastnici, fn($a, $b) => strcmp(mb_strtolower($a['jmeno']), mb_strtolower($b['jmeno'])));

    // --- Výpis hlavičky brigády ---
    echo "<div class='sb-card'>";
    echo "<h4 class='sb-card-title'>📋 " . esc_html(get_the_title($id)) . "</h4>";
    echo "<table class='sb-table' style='max-width:500px;'>";
    echo "<tr><th>Položka</th><th>Hodnota</th></tr>";
    echo "<tr><td>📅 Datum</td><td>" . esc_html($datum ?: 'neuvedeno') . "</td></tr>";
    echo "<tr><td>⏰ Čas</td><td>" . esc_html($cas ?: 'neuvedeno') . "</td></tr>";
    echo "<tr><td>📍 Místo</td><td>" . esc_html($misto ?: 'neuvedeno') . "</td></tr>";
    echo "<tr><td>⏳ Trvání</td><td>" . esc_html($trvani ?: 'neuvedeno') . "</td></tr>";
    if ($vhodne) echo "<tr><td>👤 Vhodné pro</td><td>" . esc_html($vhodne) . "</td></tr>";
    echo "<tr><td>📝 Poznámka</td><td>" . esc_html($poznamka ?: '–') . "</td></tr>";
    echo "<tr><td>👥 Potřebujeme</td><td>" . esc_html($potrebujeme ?: 'neuvedeno') . " brigádníků</td></tr>";
    echo "<tr><td>✅ Přihlášeno</td><td><strong>" . $prihlasenych_celkem . " osob</strong></td></tr>";
    echo "<tr><td>🎯 Skutečně se zúčastnilo</td><td><strong>" . $skutecne_celkem . " osob</strong></td></tr>";
    echo "<tr><td>⏱️ Odpracovaných člověkohodin</td><td><strong>" . $clovekhodiny_celkem . " h</strong></td></tr>";
    echo "</table>";

    // --- Sjednocená tabulka účastníků ---
    if (!empty($radky_ucastnici)) {
        $export_url = add_query_arg([
            'sb_export'  => 'brigada_ucastnici',
            'brigada_id' => $id,
            '_wpnonce'   => wp_create_nonce('sb_export_brigada'),
        ]);
        $pl_url = add_query_arg([
            'sb_export'  => 'prezencni_listina',
            'brigada_id' => $id,
            '_wpnonce'   => wp_create_nonce('sb_prezencni_listina'),
        ]);
        echo "<div style='display:flex; flex-wrap:wrap; gap:8px; justify-content:space-between; align-items:center; margin-top:20px; margin-bottom:8px;'>";
        echo "<h5 class='sb-card-title' style='margin:0;'>👥 Přehled účastníků</h5>";
        echo "<div style='display:flex; flex-wrap:wrap; gap:8px;'>";
        echo "<a href='" . esc_url($pl_url) . "' target='_blank' class='sb-btn sb-btn-secondary sb-btn-sm'>📋 Prezenční listina</a>";
        echo "<a href='" . esc_url($export_url) . "' class='sb-btn sb-btn-secondary sb-btn-sm'>📥 Stáhnout Excel (CSV)</a>";
        echo "</div>";
        echo "</div>";
        echo "<div style='overflow-x:auto; max-width:100%;'><table class='sb-table'>";
        echo "<tr><th>Rodina / jméno</th><th class='center'>Osob</th><th class='center'>Hod./os.</th><th class='center'>Celkem</th><th>Způsob</th></tr>";
        foreach ($radky_ucastnici as $row) {
            if (!empty($row['hodiny_osob'])) {
                $h_os_str = implode(', ', array_map(fn($h) => $h . ' h', $row['hodiny_osob']));
            } elseif ($row['h_os'] !== null) {
                $h_os_str = $row['h_os'] . ' h/os.';
            } else {
                $h_os_str = '<em style="color:#999;">nezadáno</em>';
            }
            $celkem_str = $row['celkem_h'] !== null ? '<strong>' . $row['celkem_h'] . ' h</strong>' : '–';
            $typ_badge  = $row['typ'] === 'Walk-in'
                ? "<span style='background:#fff3cd;color:#856404;border:1px solid #ffc107;border-radius:3px;padding:1px 6px;font-size:12px; white-space:nowrap;'>Walk-in</span>"
                : "<span style='background:#d1e7dd;color:#0a5b2e;border:1px solid #a3cfbb;border-radius:3px;padding:1px 6px;font-size:12px; white-space:nowrap;'>Přihlášena</span>";
            echo "<tr>";
            echo "<td>" . esc_html($row['jmeno']) . "</td>";
            echo "<td class='center'>" . intval($row['osob']) . "</td>";
            echo "<td class='center'>" . $h_os_str . "</td>";
            echo "<td class='center'>" . $celkem_str . "</td>";
            echo "<td>" . $typ_badge . "</td>";
            echo "</tr>";
        }
        echo "</table></div>";

        // --- Mailové adresy přihlášených rodin ---
        $emails = [];
        foreach ($rodiny as $r) {
            if (!intval(get_post_meta($id, 'ucastnici_' . $r->ID, true))) continue;
            $rodice_ids = get_post_meta($r->ID, 'rodice_rodiny', true);
            if (is_array($rodice_ids)) {
                foreach ($rodice_ids as $rid) {
                    $u = get_user_by('id', $rid);
                    if ($u && $u->user_email) $emails[] = $u->user_email;
                }
            }
        }
        $emails = array_unique($emails);
        if (!empty($emails)) {
            $email_str = esc_attr(implode(';', $emails));
            $field_id  = 'sb-emails-' . intval($id);
            echo "<div style='margin-top:16px; padding:12px 14px; background:#f0f8ff; border:1px solid #b0d4ea; border-radius:4px;'>";
            echo "<strong style='font-size:13px;'>📧 Mailové adresy přihlášených rodin</strong>";
            echo "<p style='font-size:12px; color:#555; margin:4px 0 8px;'>Zkopírujte a vložte do adresního řádku mailového klienta:</p>";
            echo "<div style='display:flex; align-items:center; gap:8px;'>";
            echo "<input type='text' id='{$field_id}' value='{$email_str}' readonly style='flex:1; font-size:12px; color:#333; background:#fff;'>";
            echo "<button type='button' class='sb-btn sb-btn-secondary sb-btn-sm' onclick=\"var el=document.getElementById('{$field_id}');el.select();document.execCommand('copy');this.textContent='✅ Zkopírováno';setTimeout(function(){this.textContent='📋 Kopírovat';}.bind(this),2000);\">📋 Kopírovat</button>";
            echo "</div>";
            echo "</div>";
        }
    } else {
        echo sb_alert('Na tuto brigádu se nikdo nepřihlásil.', 'info');
    }

    echo "</div>"; // sb-card

    $zprava .= "<script>setTimeout(function() { sbShowSection('ucastnici_dle'); }, 100);</script>";
}

    echo $zprava;
    return ob_get_clean();
}

function sb_sprava_brigad() {
   $current_user = wp_get_current_user();
$roles = $current_user->roles;
if (!array_intersect($roles, ['administrator', 'spravce_brigad'])) {
    return '<p>Nemáš oprávnění pro správu brigád.</p>';
}
    
    $brigady = get_posts([
        'post_type' => 'brigada',
        'numberposts' => -1,
        'meta_key' => 'datum_brigady',
        'orderby' => 'meta_value',
        'order' => 'ASC'
    ]);

    $zprava = '';
    $vybrana_id = isset($_POST['vybrana_brigada']) ? intval($_POST['vybrana_brigada']) : 0;
    $duplikat_data = null;

    // Duplikace brigády – předvyplní formulář nové brigády
    if (isset($_POST['duplikovat_brigadu'])) {
        if (!isset($_POST['_wpnonce_duplikovat']) || !wp_verify_nonce($_POST['_wpnonce_duplikovat'], 'duplikovat_brigadu')) {
            $zprava .= sb_alert('Neplatný bezpečnostní token.', 'error');
        } else {
            $zdroj_id = intval($_POST['zdroj_brigada_id']);
            $duplikat_data = [
                'nazev'    => get_the_title($zdroj_id),
                'datum'    => '',
                'cas'      => get_post_meta($zdroj_id, 'cas_brigady', true),
                'misto'    => get_post_meta($zdroj_id, 'misto_konani', true),
                'delka'    => get_post_meta($zdroj_id, 'delka_brigady', true),
                'pocet'    => get_post_meta($zdroj_id, 'pozadovany_pocet', true),
                'vhodne'   => get_post_meta($zdroj_id, 'vhodne_pro', true),
                'poznamka' => get_post_meta($zdroj_id, 'poznamka_brigady', true),
            ];
            $vybrana_id = $zdroj_id;
        }
    }

   // Uložení úprav
if (isset($_POST['ulozit_brigadu'])) {
    if (!isset($_POST['_wpnonce_ulozit_brigadu']) ||
        !wp_verify_nonce($_POST['_wpnonce_ulozit_brigadu'], 'ulozit_brigadu')) {
        $zprava .= sb_alert('Neplatný bezpečnostní token. Zkuste to prosím znovu.', 'error');
    } else {
        $id     = intval($_POST['brigada_id']);
        $nazev  = sanitize_text_field($_POST['nazev_brigady']);
        $datum  = sanitize_text_field($_POST['datum_brigady']);
        $cas    = sanitize_text_field($_POST['cas_brigady']);
        $misto  = sanitize_text_field($_POST['misto_konani']);
        $delka  = sanitize_text_field($_POST['delka_brigady']);
        $pozadovany_pocet = intval($_POST['pozadovany_pocet']);
        $vhodne = sanitize_text_field($_POST['vhodne_pro']);
        $poznamka = sanitize_textarea_field($_POST['poznamka_brigady']);
        $datum_predbezne = isset($_POST['datum_predbezne']) ? '1' : '0';
        $cas_predbezne   = isset($_POST['cas_predbezne']) ? '1' : '0';

        // Validace formátu datumu (YYYY-MM-DD)
        $dt = DateTime::createFromFormat('Y-m-d', $datum);
        $errors = DateTime::getLastErrors();
        if (!$dt || !empty($errors['warning_count']) || !empty($errors['error_count'])) {
            $zprava .= sb_alert('Neplatný formát datumu.', 'error');
        } else {
            wp_update_post(['ID' => $id, 'post_title' => $nazev]);
            update_post_meta($id, 'datum_brigady', $datum);
            update_post_meta($id, 'cas_brigady', $cas);
            update_post_meta($id, 'misto_konani', $misto);
            update_post_meta($id, 'delka_brigady', $delka);
            update_post_meta($id, 'pozadovany_pocet', $pozadovany_pocet);
            update_post_meta($id, 'vhodne_pro', $vhodne);
            update_post_meta($id, 'poznamka_brigady', $poznamka);
            update_post_meta($id, 'datum_predbezne', $datum_predbezne);
            update_post_meta($id, 'cas_predbezne', $cas_predbezne);

            $zprava .= sb_alert('Brigáda byla upravena.');
            $zprava .= "<script>setTimeout(function() { sbShowSection('upravit_brigady'); }, 100);</script>";
        }
    }
}

// Vytvoření nové brigády
if (isset($_POST['nova_brigada'])) {

    // Ověření nonce
    if (!isset($_POST['_wpnonce_nova_brigada']) || !wp_verify_nonce($_POST['_wpnonce_nova_brigada'], 'nova_brigada')) {
        $zprava .= sb_alert('Neplatný bezpečnostní token. Zkuste to prosím znovu.', 'error');
    } else {
        // Sanitizace vstupů
        $nazev = sanitize_text_field($_POST['novy_nazev_brigady']);
        $datum = sanitize_text_field($_POST['novy_datum_brigady']);
        $cas   = sanitize_text_field($_POST['novy_cas_brigady']);
        $misto = sanitize_text_field($_POST['novy_misto_konani']);
        $delka = sanitize_text_field($_POST['novy_delka_brigady']);
        $pozadovany_pocet = intval($_POST['novy_pozadovany_pocet']);
        $vhodne   = sanitize_text_field($_POST['novy_vhodne_pro']);
        $poznamka = sanitize_textarea_field($_POST['novy_poznamka_brigady']);
        $datum_predbezne = isset($_POST['novy_datum_predbezne']) ? '1' : '0';
        $cas_predbezne   = isset($_POST['novy_cas_predbezne']) ? '1' : '0';

        // Validace formátu datumu (YYYY-MM-DD)
        $dt = DateTime::createFromFormat('Y-m-d', $datum);
        $errors = DateTime::getLastErrors();
        if (!$dt || !empty($errors['warning_count']) || !empty($errors['error_count'])) {
            $zprava .= sb_alert('Neplatný formát datumu.', 'error');
        } else {
            // Kontrola duplicity názvu brigády
            $existujici_id = sb_post_exists_by_title('brigada', $nazev);

            if ($existujici_id) {
                $zprava .= sb_alert('Brigáda s tímto názvem už existuje.', 'error');
            } else {
                $id = wp_insert_post([
                    'post_title'  => $nazev,
                    'post_type'   => 'brigada',
                    'post_status' => 'publish'
                ]);

                if ($id) {
                    update_post_meta($id, 'datum_brigady', $datum);
                    update_post_meta($id, 'cas_brigady', $cas);
                    update_post_meta($id, 'misto_konani', $misto);
                    update_post_meta($id, 'delka_brigady', $delka);
                    update_post_meta($id, 'pozadovany_pocet', $pozadovany_pocet);
                    update_post_meta($id, 'vhodne_pro', $vhodne);
                    update_post_meta($id, 'poznamka_brigady', $poznamka);
                    update_post_meta($id, 'datum_predbezne', $datum_predbezne);
                    update_post_meta($id, 'cas_predbezne', $cas_predbezne);

                    $zprava .= sb_alert('Brigáda byla vytvořena.');
                    $vybrana_id = $id;
                } else {
                    $zprava .= sb_alert('Nepodařilo se vytvořit brigádu.', 'error');
                }
            }
        }
    }

    $zprava .= "<script>setTimeout(function() { sbShowSection('upravit_brigady'); }, 100);</script>";
}

    // Smazání brigády
    if (isset($_POST['smazat_brigadu'])) {
    if (!isset($_POST['_wpnonce_smazat_brigadu']) || 
        !wp_verify_nonce($_POST['_wpnonce_smazat_brigadu'], 'smazat_brigadu')) {
        $zprava .= sb_alert('Neplatný bezpečnostní token. Zkuste to prosím znovu.', 'error');
    } else {
        wp_delete_post(intval($_POST['smazat_brigadu']), true);
        $zprava .= sb_alert('Brigáda byla smazána.');
        $vybrana_id = 0;
        $zprava .= "<script>setTimeout(function() { sbShowSection('upravit_brigady'); }, 100);</script>";
    }
}

    ob_start();
    echo "<h3 class='sb-section-title'>🛠️ Správa brigád</h3>";
    echo $zprava;

    // Výběr brigády
    echo "<div class='sb-card'>";
    echo "<form method='post' class='sb-form-row'>";
    echo "<div class='sb-form-group sb-fg-inline'><label>Vyber brigádu:</label><select name='vybrana_brigada'>";
    echo "<option value='0'>-- vyber --</option>";
    foreach ($brigady as $b) {
        $selected = ($b->ID == $vybrana_id) ? 'selected' : '';
        $datum = get_post_meta($b->ID, 'datum_brigady', true);
        $predbezne = get_post_meta($b->ID, 'datum_predbezne', true) === '1' ? ' (předběžně)' : '';
        echo "<option value='{$b->ID}' $selected>" . esc_html($b->post_title) . " – " . esc_html($datum) . esc_html($predbezne) . "</option>";
    }
    echo "</select></div>";
    echo "<input type='submit' value='Zobrazit' class='sb-btn sb-btn-primary'>";
    echo "</form>";
    echo "</div>";
    
        // Formulář pro úpravu vybrané brigády
    if ($vybrana_id) {
        $b = get_post($vybrana_id);
        $nazev = esc_attr($b->post_title);
        $datum = esc_attr(get_post_meta($b->ID, 'datum_brigady', true));
        $cas = esc_attr(get_post_meta($b->ID, 'cas_brigady', true));
        $misto = esc_attr(get_post_meta($b->ID, 'misto_konani', true));
        $delka = esc_attr(get_post_meta($b->ID, 'delka_brigady', true));
        $pozadovany_pocet = esc_attr(get_post_meta($b->ID, 'pozadovany_pocet', true));
        $vhodne = esc_attr(get_post_meta($b->ID, 'vhodne_pro', true));
        $poznamka = esc_textarea(get_post_meta($b->ID, 'poznamka_brigady', true));
        $datum_predbezne = get_post_meta($b->ID, 'datum_predbezne', true) === '1' ? 'checked' : '';
        $cas_predbezne = get_post_meta($b->ID, 'cas_predbezne', true) === '1' ? 'checked' : '';

        echo "<div class='sb-card'>";
        echo "<h4 class='sb-card-title'>✏️ Upravit brigádu</h4>";
        echo "<form method='post'>";
        wp_nonce_field('ulozit_brigadu', '_wpnonce_ulozit_brigadu');
        echo "<input type='hidden' name='brigada_id' value='{$b->ID}'>";

        echo "<div class='sb-form-group'><label>Název brigády:</label><input type='text' name='nazev_brigady' value='$nazev' required></div>";
        echo "<div class='sb-form-group'><label>Datum brigády:</label>";
        echo "<span class='sb-form-inline'><input type='date' name='datum_brigady' value='$datum' required style='width:160px;'> <label style='font-weight:normal;'><input type='checkbox' name='datum_predbezne' $datum_predbezne> předběžně</label></span></div>";
        echo "<div class='sb-form-group'><label>Čas konání:</label>";
        echo "<span class='sb-form-inline'><input type='text' name='cas_brigady' value='$cas' style='width:120px;'> <label style='font-weight:normal;'><input type='checkbox' name='cas_predbezne' $cas_predbezne> předběžně</label></span></div>";
        echo "<div class='sb-form-group'><label>Místo konání:</label><input type='text' name='misto_konani' value='$misto'></div>";
        echo "<div class='sb-form-group'><label>Délka brigády:</label><input type='text' name='delka_brigady' value='$delka' placeholder='např. 4 hod.'><div class='sb-text-hint' style='margin-top:3px;'>Napište počet hodin i s jednotkou, např. <em>4 hod.</em> Text se rodičům zobrazí přesně tak, jak ho napíšete. Pro statistiku se použije první číslo v textu, proto nezačínejte časem (např. „8:30–12:30“).</div></div>";
        echo "<div class='sb-form-group'><label>Potřebný počet brigádníků:</label><input type='number' name='pozadovany_pocet' value='$pozadovany_pocet' min='1' style='width:90px;'></div>";
        echo "<div class='sb-form-group'><label>Vhodné pro:</label><input type='text' name='vhodne_pro' value='$vhodne'></div>";
        echo "<div class='sb-form-group'><label>Poznámka:</label><textarea name='poznamka_brigady' rows='3'>$poznamka</textarea></div>";

        echo "<div class='sb-btn-row'>";
        echo "<input type='submit' name='ulozit_brigadu' value='💾 Uložit změny' class='sb-btn sb-btn-primary'>";
        echo "</div></form>";

        echo "<div class='sb-btn-row' style='margin-top:14px; gap:8px;'>";
        echo "<form method='post' style='display:inline;'>";
        wp_nonce_field('duplikovat_brigadu', '_wpnonce_duplikovat');
        echo "<input type='hidden' name='zdroj_brigada_id' value='{$b->ID}'>";
        echo "<input type='hidden' name='vybrana_brigada' value='{$b->ID}'>";
        echo "<input type='submit' name='duplikovat_brigadu' value='📋 Duplikovat brigádu' class='sb-btn sb-btn-secondary'>";
        echo "</form>";
        echo "<form method='post' onsubmit='return confirm(\"Opravdu chceš smazat brigádu?\")' style='display:inline;'>";
        wp_nonce_field('smazat_brigadu', '_wpnonce_smazat_brigadu');
        echo "<input type='hidden' name='smazat_brigadu' value='{$b->ID}'>";
        echo "<input type='submit' value='🗑️ Smazat brigádu' class='sb-btn sb-btn-danger'>";
        echo "</form>";
        echo "</div>";
        echo "</div>";
    }

    // Formulář pro vytvoření nové brigády (skrytý, nebo předvyplněný při duplikaci)
    $nova_form_display = $duplikat_data !== null ? 'block' : 'none';
    $nova_btn_display  = $duplikat_data !== null ? 'none' : 'inline-block';
    $d = $duplikat_data ?? ['nazev' => '', 'datum' => '', 'cas' => '', 'misto' => '', 'delka' => '', 'pocet' => '', 'vhodne' => '', 'poznamka' => ''];
    $nova_titulek = $duplikat_data !== null ? '📋 Duplikát brigády – upravte údaje a uložte' : '➕ Nová brigáda';

    echo "<button id='btn-nova-brigada' onclick=\"document.getElementById('nova-brigada-form').style.display='block'; this.style.display='none';\" class='sb-btn sb-btn-success' style='margin-top:14px; display:{$nova_btn_display};'>➕ Vytvořit novou brigádu</button>";
    echo "<div id='nova-brigada-form' style='display:{$nova_form_display}; margin-top:16px;'>";
    if ($duplikat_data !== null) {
        echo "<script>document.addEventListener('DOMContentLoaded', function() { document.getElementById('nova-brigada-form').scrollIntoView({behavior:'smooth'}); });</script>";
    }
    echo "<div class='sb-card'>";
    echo "<h4 class='sb-card-title'>" . esc_html($nova_titulek) . "</h4>";
    echo "<form method='post'>";
    wp_nonce_field('nova_brigada', '_wpnonce_nova_brigada');

    echo "<div class='sb-form-group'><label>Název brigády:</label><input type='text' name='novy_nazev_brigady' value='" . esc_attr($d['nazev']) . "' required></div>";
    echo "<div class='sb-form-group'><label>Datum brigády:</label>";
    echo "<span class='sb-form-inline'><input type='date' name='novy_datum_brigady' value='" . esc_attr($d['datum']) . "' required style='width:160px;'> <label style='font-weight:normal;'><input type='checkbox' name='novy_datum_predbezne'> předběžně</label></span></div>";
    echo "<div class='sb-form-group'><label>Čas konání:</label>";
    echo "<span class='sb-form-inline'><input type='text' name='novy_cas_brigady' value='" . esc_attr($d['cas']) . "' style='width:120px;'> <label style='font-weight:normal;'><input type='checkbox' name='novy_cas_predbezne'> předběžně</label></span></div>";
    echo "<div class='sb-form-group'><label>Místo konání:</label><input type='text' name='novy_misto_konani' value='" . esc_attr($d['misto']) . "'></div>";
    echo "<div class='sb-form-group'><label>Délka brigády:</label><input type='text' name='novy_delka_brigady' value='" . esc_attr($d['delka']) . "' placeholder='např. 4 hod.'><div class='sb-text-hint' style='margin-top:3px;'>Napište počet hodin i s jednotkou, např. <em>4 hod.</em> Text se rodičům zobrazí přesně tak, jak ho napíšete. Pro statistiku se použije první číslo v textu, proto nezačínejte časem (např. „8:30–12:30“).</div></div>";
    echo "<div class='sb-form-group'><label>Potřebný počet brigádníků:</label><input type='number' name='novy_pozadovany_pocet' value='" . esc_attr($d['pocet']) . "' min='1' style='width:90px;'></div>";
    echo "<div class='sb-form-group'><label>Vhodné pro:</label><input type='text' name='novy_vhodne_pro' value='" . esc_attr($d['vhodne']) . "'></div>";
    echo "<div class='sb-form-group'><label>Poznámka:</label><textarea name='novy_poznamka_brigady' rows='3'>" . esc_textarea($d['poznamka']) . "</textarea></div>";
    echo "<div class='sb-btn-row'><input type='submit' name='nova_brigada' value='💾 Vytvořit brigádu' class='sb-btn sb-btn-primary'></div>";
    echo "</form>";
    echo "</div></div>";

    return ob_get_clean();
}


function sb_sprava_ucastniku() {
    $current_user = wp_get_current_user();
    $roles = $current_user->roles;
    if (!array_intersect($roles, ['administrator', 'spravce_brigad'])) {
        return '<p>Nemáš oprávnění pro správu účastníků.</p>';
    }

    $brigady = get_posts(['post_type' => 'brigada', 'numberposts' => -1, 'meta_key' => 'datum_brigady', 'orderby' => 'meta_value', 'order' => 'ASC']);
    $rodiny  = get_posts(['post_type' => 'rodina',  'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);

    $zprava = '';
    $vybrana_brigada_id = isset($_POST['vybrana_brigada']) ? intval($_POST['vybrana_brigada']) : 0;

    // Uložení odpracovaných hodin + počtu osob
    if (isset($_POST['edit_ucastnik'])) {
        if (!isset($_POST['_wpnonce_edit_ucastnik']) || !wp_verify_nonce($_POST['_wpnonce_edit_ucastnik'], 'edit_ucastnik')) {
            $zprava .= sb_alert('Neplatný bezpečnostní token.', 'error');
        } else {
            $brigada_id  = intval($_POST['brigada_id']);
            $rodina_id   = intval($_POST['rodina_id']);
            $pocet_osob  = max(1, intval($_POST['novy_pocet_osob']));
            $hodiny_raw  = (isset($_POST['hodiny_osoba']) && is_array($_POST['hodiny_osoba']))
                ? array_map(fn($h) => max(0, intval($h)), $_POST['hodiny_osoba'])
                : [];
            $hodiny_raw  = array_slice($hodiny_raw, 0, $pocet_osob);
            while (count($hodiny_raw) < $pocet_osob) { $hodiny_raw[] = 0; }
            update_post_meta($brigada_id, 'ucastnici_' . $rodina_id, $pocet_osob);
            update_post_meta($brigada_id, 'hodiny_osob_' . $rodina_id, $hodiny_raw);
            delete_post_meta($brigada_id, 'odpracovano_' . $rodina_id);
            wp_cache_delete($brigada_id, 'post_meta');
            $vybrana_brigada_id = $brigada_id;
            $zprava .= sb_alert('Záznam byl uložen.');
            $zprava .= "<script>setTimeout(function() { sbShowSection('upravit_ucastniky'); }, 100);</script>";
        }
    }

    // Smazání odpracovaných hodin rodiny
    if (isset($_POST['delete_ucastnik'])) {
        if (!isset($_POST['_wpnonce_delete_ucastnik']) || !wp_verify_nonce($_POST['_wpnonce_delete_ucastnik'], 'delete_ucastnik')) {
            $zprava .= sb_alert('Neplatný bezpečnostní token.', 'error');
        } else {
            $brigada_id = intval($_POST['brigada_id']);
            $rodina_id  = intval($_POST['rodina_id']);
            delete_post_meta($brigada_id, 'odpracovano_' . $rodina_id);
            delete_post_meta($brigada_id, 'hodiny_osob_' . $rodina_id);
            wp_cache_delete($brigada_id, 'post_meta');
            $vybrana_brigada_id = $brigada_id;
            $zprava .= sb_alert('Záznam o odpracovaných hodinách byl odebrán.');
            $zprava .= "<script>setTimeout(function() { sbShowSection('upravit_ucastniky'); }, 100);</script>";
        }
    }

    // Odhlášení rodiny z brigády (smaže přihlášení i hodiny)
    if (isset($_POST['odhlasit_ucastnika'])) {
        if (!isset($_POST['_wpnonce_odhlasit_ucastnika']) || !wp_verify_nonce($_POST['_wpnonce_odhlasit_ucastnika'], 'odhlasit_ucastnika')) {
            $zprava .= sb_alert('Neplatný bezpečnostní token.', 'error');
        } else {
            $brigada_id = intval($_POST['brigada_id']);
            $rodina_id  = intval($_POST['rodina_id']);
            delete_post_meta($brigada_id, 'ucastnici_' . $rodina_id);
            delete_post_meta($brigada_id, 'odpracovano_' . $rodina_id);
            delete_post_meta($brigada_id, 'hodiny_osob_' . $rodina_id);
            wp_cache_delete($brigada_id, 'post_meta');
            $vybrana_brigada_id = $brigada_id;
            $zprava .= sb_alert('Rodina byla odhlášena z brigády.');
            $zprava .= "<script>setTimeout(function() { sbShowSection('upravit_ucastniky'); }, 100);</script>";
        }
    }

    // Přidání walk-in účastníka
    if (isset($_POST['pridat_walkin'])) {
        if (!isset($_POST['_wpnonce_walkin']) || !wp_verify_nonce($_POST['_wpnonce_walkin'], 'sprava_walkin')) {
            $zprava .= sb_alert('Neplatný bezpečnostní token.', 'error');
        } else {
            $brigada_id    = intval($_POST['brigada_id']);
            $walkin_jmeno  = sanitize_text_field($_POST['walkin_jmeno']);
            $walkin_pocet  = max(1, intval($_POST['walkin_pocet']));
            $walkin_hodiny = max(0, intval($_POST['walkin_hodiny']));
            if ($walkin_jmeno !== '') {
                $walkins   = get_post_meta($brigada_id, 'walkin_ucastnici', true);
                if (!is_array($walkins)) $walkins = [];
                $walkins[] = ['jmeno' => $walkin_jmeno, 'pocet' => $walkin_pocet, 'hodiny' => $walkin_hodiny];
                update_post_meta($brigada_id, 'walkin_ucastnici', $walkins);
                wp_cache_delete($brigada_id, 'post_meta');
                $zprava .= sb_alert('Walk-in účastník byl přidán.');
            } else {
                $zprava .= sb_alert('Zadej prosím jméno účastníka.', 'error');
            }
            $vybrana_brigada_id = $brigada_id;
            $zprava .= "<script>setTimeout(function() { sbShowSection('upravit_ucastniky'); }, 100);</script>";
        }
    }

    // Smazání walk-in účastníka
    if (isset($_POST['smazat_walkin'])) {
        if (!isset($_POST['_wpnonce_walkin']) || !wp_verify_nonce($_POST['_wpnonce_walkin'], 'sprava_walkin')) {
            $zprava .= sb_alert('Neplatný bezpečnostní token.', 'error');
        } else {
            $brigada_id  = intval($_POST['brigada_id']);
            $walkin_idx  = intval($_POST['smazat_walkin']);
            $walkins     = get_post_meta($brigada_id, 'walkin_ucastnici', true);
            if (is_array($walkins) && isset($walkins[$walkin_idx])) {
                unset($walkins[$walkin_idx]);
                update_post_meta($brigada_id, 'walkin_ucastnici', array_values($walkins));
                wp_cache_delete($brigada_id, 'post_meta');
                $zprava .= sb_alert('Walk-in účastník byl odebrán.');
            }
            $vybrana_brigada_id = $brigada_id;
            $zprava .= "<script>setTimeout(function() { sbShowSection('upravit_ucastniky'); }, 100);</script>";
        }
    }

    // Editace walk-in účastníka
    if (isset($_POST['edit_walkin'])) {
        if (!isset($_POST['_wpnonce_walkin']) || !wp_verify_nonce($_POST['_wpnonce_walkin'], 'sprava_walkin')) {
            $zprava .= sb_alert('Neplatný bezpečnostní token.', 'error');
        } else {
            $brigada_id  = intval($_POST['brigada_id']);
            $walkin_idx  = intval($_POST['walkin_idx']);
            $novy_pocet  = max(1, intval($_POST['walkin_pocet']));
            $nove_hodiny = max(0, intval($_POST['walkin_hodiny']));
            $walkins     = get_post_meta($brigada_id, 'walkin_ucastnici', true);
            if (is_array($walkins) && isset($walkins[$walkin_idx])) {
                $walkins[$walkin_idx]['pocet']  = $novy_pocet;
                $walkins[$walkin_idx]['hodiny'] = $nove_hodiny;
                update_post_meta($brigada_id, 'walkin_ucastnici', $walkins);
                wp_cache_delete($brigada_id, 'post_meta');
                $zprava .= sb_alert('Walk-in záznam byl uložen.');
            }
            $vybrana_brigada_id = $brigada_id;
            $zprava .= "<script>setTimeout(function() { sbShowSection('upravit_ucastniky'); }, 100);</script>";
        }
    }

    ob_start();
    echo "<h3 class='sb-section-title'>🧮 Správa účastníků brigád</h3>";
    echo $zprava;

    // Výběr brigády
    echo "<div class='sb-card'>";
    echo "<form method='post' class='sb-form-row'>";
    echo "<div class='sb-form-group sb-fg-inline'><label>Vyber brigádu:</label><select name='vybrana_brigada'>";
    foreach ($brigady as $b) {
        $sel   = ($vybrana_brigada_id && $vybrana_brigada_id === $b->ID) ? 'selected' : '';
        $datum = esc_html(get_post_meta($b->ID, 'datum_brigady', true));
        $predb = get_post_meta($b->ID, 'datum_predbezne', true) === '1' ? ' (předběžně)' : '';
        echo "<option value='{$b->ID}' $sel>" . esc_html($b->post_title) . ($datum ? " – $datum" . esc_html($predb) : "") . "</option>";
    }
    echo "</select></div>";
    echo "<input type='submit' name='zobraz_ucastniky' value='Zobrazit' class='sb-btn sb-btn-primary'>";
    echo "</form>";
    echo "</div>";

    // Výpis účastníků – zobrazit jen při akcích patřících této sekci (ne při 'zobraz_ucast' z jiné sekce)
    $akce_teto_sekce = isset($_POST['zobraz_ucastniky']) || isset($_POST['edit_ucastnik']) ||
                       isset($_POST['delete_ucastnik']) || isset($_POST['pridat_walkin']) || isset($_POST['smazat_walkin']) || isset($_POST['edit_walkin']);
    if ($akce_teto_sekce) {
        $id = $vybrana_brigada_id ?: intval($_POST['vybrana_brigada']);

        echo "<div class='sb-card'>";
        echo "<h4 class='sb-card-title'>Účastníci: " . esc_html(get_the_title($id)) . "</h4>";

        // Sestavit seznam jmen walk-in účastníků pro tuto brigádu
        $walkins_raw      = get_post_meta($id, 'walkin_ucastnici', true);
        $walkin_jmena     = [];
        if (is_array($walkins_raw)) {
            foreach ($walkins_raw as $w) {
                if (!empty($w['jmeno'])) $walkin_jmena[] = $w['jmeno'];
            }
        }

        // Sestavit všechny řádky – přihlášené rodiny + walk-in – seřadit abecedně
        $sprava_radky = [];

        foreach ($rodiny as $r) {
            $prihlaseno    = get_post_meta($id, 'ucastnici_' . $r->ID, true);
            $hodiny_osob_s = get_post_meta($id, 'hodiny_osob_' . $r->ID, true);
            $hodiny_osob_s = (is_array($hodiny_osob_s) && !empty($hodiny_osob_s)) ? $hodiny_osob_s : null;
            $odpracovano   = get_post_meta($id, 'odpracovano_' . $r->ID, true);
            $ma_hodiny     = $hodiny_osob_s !== null || ($odpracovano !== '' && $odpracovano !== null);
            if (!$prihlaseno && !$ma_hodiny) continue;
            if ($hodiny_osob_s !== null) {
                $celkem_h = array_sum($hodiny_osob_s);
            } elseif ($odpracovano !== '' && $odpracovano !== null) {
                $celkem_h = $prihlaseno ? intval($odpracovano) * intval($prihlaseno) : intval($odpracovano);
            } else {
                $celkem_h = '';
            }
            $je_walkin = in_array($r->post_title, $walkin_jmena, true);
            $osirely   = !$prihlaseno && $ma_hodiny && !$je_walkin;
            $sprava_radky[] = [
                'typ'        => 'rodina',
                'jmeno'      => $r->post_title,
                'rodina_id'  => $r->ID,
                'prihlaseno' => $prihlaseno,
                'celkem_h'   => $celkem_h,
                'hodiny_osob' => $hodiny_osob_s,
                'odpracovano' => $odpracovano, // starý formát, pro fallback v edit formuláři
                'osirely'    => $osirely,
            ];
        }

        // Jména rodin již zobrazených z CPT (pro potlačení duplicit)
        $jmena_prihlasen = [];
        foreach ($sprava_radky as $rr) {
            $jmena_prihlasen[] = $rr['jmeno'];
        }

        $walkins_sprava = get_post_meta($id, 'walkin_ucastnici', true);
        if (!is_array($walkins_sprava)) $walkins_sprava = [];
        foreach ($walkins_sprava as $idx => $w) {
            // Pokud rodina se stejným jménem je už v seznamu přihlášených, nezobrazovat znovu jako walk-in
            if (in_array($w['jmeno'], $jmena_prihlasen, true)) continue;
            $pocet_w  = intval($w['pocet'] ?? 1);
            $hodiny_w = intval($w['hodiny']);
            $sprava_radky[] = [
                'typ'    => 'walkin',
                'jmeno'  => $w['jmeno'],
                'idx'    => $idx,
                'pocet'  => $pocet_w,
                'hodiny' => $hodiny_w,
                'celkem' => $hodiny_w * $pocet_w,
            ];
        }

        usort($sprava_radky, fn($a, $b) => strcmp(mb_strtolower($a['jmeno']), mb_strtolower($b['jmeno'])));

        $nekdo_prihlasen = !empty($sprava_radky);

        foreach ($sprava_radky as $radek) {
            if ($radek['typ'] === 'rodina') {
                $osirely     = $radek['osirely'];
                $prihlaseno  = $radek['prihlaseno'];
                $celkem_h    = $radek['celkem_h'];
                $hodiny_osob = $radek['hodiny_osob'];  // array nebo null
                $pocet_v_form = max(1, intval($prihlaseno));

                // Připrav pole hodin pro formulář (jeden vstup na brigádníka)
                if ($hodiny_osob !== null) {
                    $hodiny_form = array_values($hodiny_osob);
                } elseif ($radek['odpracovano'] !== '' && $radek['odpracovano'] !== null) {
                    // Starý formát: vyplň všechny vstupy stejnou hodnotou
                    $hodiny_form = array_fill(0, $pocet_v_form, intval($radek['odpracovano']));
                } else {
                    $hodiny_form = array_fill(0, $pocet_v_form, 0);
                }
                $hodiny_form = array_slice($hodiny_form, 0, $pocet_v_form);
                while (count($hodiny_form) < $pocet_v_form) { $hodiny_form[] = 0; }

                // Zobraz souhrn hodin
                if ($hodiny_osob !== null) {
                    $hodiny_popis = '<strong>' . $celkem_h . ' h</strong> <span style="color:#999;font-size:12px;">(' . implode('+', $hodiny_osob) . ' h)</span>';
                } elseif ($celkem_h !== '') {
                    $hodiny_popis = '<strong>' . $celkem_h . ' h</strong>';
                } else {
                    $hodiny_popis = '<em>nezadáno</em>';
                }

                echo "<div class='sb-participant-item'" . ($osirely ? " style='border-color:#f0ad4e; background:#fffbf0;'" : "") . ">";
                echo "<strong>" . esc_html($radek['jmeno']) . "</strong> &nbsp;";
                if ($osirely) {
                    echo "<span style='font-size:12px; color:#856404; background:#fff3cd; border:1px solid #ffc107; border-radius:3px; padding:1px 6px;'>⚠️ Rodina není přihlášena, ale má záznam hodin</span> &nbsp;";
                } elseif ($je_walkin) {
                    echo "<span style='font-size:12px; color:#4a6fa5; background:#dce8f8; border:1px solid #b0c4de; border-radius:3px; padding:1px 6px;'>👟 walk-in</span> &nbsp;";
                }
                echo "<span class='sb-participant-meta'>✅ Přihlášeno: " . ($prihlaseno ? intval($prihlaseno) . " osob" : "<em>–</em>") . " &nbsp;|&nbsp; ";
                echo "🕒 Odpracováno: " . $hodiny_popis . "</span><br><br>";
                echo "<div class='sb-form-row' style='align-items:center; flex-wrap:wrap; gap:6px;'>";
                echo "<form method='post' style='display:contents;'>";
                wp_nonce_field('edit_ucastnik', '_wpnonce_edit_ucastnik');
                echo "<input type='hidden' name='brigada_id' value='" . esc_attr($id) . "'>";
                echo "<input type='hidden' name='rodina_id'  value='" . esc_attr($radek['rodina_id']) . "'>";
                echo "<span style='font-size:12px; color:#555;'>osob:</span>";
                echo "<input type='number' name='novy_pocet_osob' value='" . intval($prihlaseno) . "' min='1' required style='width:60px; text-align:center;'>";
                for ($i = 0; $i < $pocet_v_form; $i++) {
                    $label = $pocet_v_form > 1 ? "brigádník " . ($i + 1) . ":" : "hodin:";
                    echo "<span style='font-size:12px; color:#555;'>" . esc_html($label) . "</span>";
                    echo "<input type='number' name='hodiny_osoba[]' value='" . intval($hodiny_form[$i]) . "' min='0' required style='width:60px; text-align:center;'>";
                }
                echo "<input type='submit' name='edit_ucastnik' value='💾 Uložit' class='sb-btn sb-btn-primary sb-btn-sm'>";
                echo "</form>";
                if ($osirely) {
                    echo "<form method='post' style='display:contents;' onsubmit='return confirm(\"Odebrat záznam?\")'>";
                    wp_nonce_field('delete_ucastnik', '_wpnonce_delete_ucastnik');
                    echo "<input type='hidden' name='brigada_id' value='" . esc_attr($id) . "'>";
                    echo "<input type='hidden' name='rodina_id'  value='" . esc_attr($radek['rodina_id']) . "'>";
                    echo "<input type='submit' name='delete_ucastnik' value='🗑️ Odebrat záznam' class='sb-btn sb-btn-danger sb-btn-sm'>";
                    echo "</form>";
                }
                if ($prihlaseno && !$osirely) {
                    echo "<form method='post' style='display:contents;' onsubmit='return confirm(\"Odhlásit rodinu z brigády? Smažou se i případné hodiny.\")'>";
                    wp_nonce_field('odhlasit_ucastnika', '_wpnonce_odhlasit_ucastnika');
                    echo "<input type='hidden' name='brigada_id' value='" . esc_attr($id) . "'>";
                    echo "<input type='hidden' name='rodina_id'  value='" . esc_attr($radek['rodina_id']) . "'>";
                    echo "<input type='submit' name='odhlasit_ucastnika' value='🚫 Odhlásit z brigády' class='sb-btn sb-btn-danger sb-btn-sm'>";
                    echo "</form>";
                }
                echo "</div>";
                echo "</div>";
            } else {
                // walk-in řádek
                $celkem_w = $radek['celkem'];
                echo "<div class='sb-participant-item' style='border-color:#b0c4de; background:#f5f8ff;'>";
                echo "<strong>" . esc_html($radek['jmeno']) . "</strong> &nbsp;";
                echo "<span style='font-size:12px; color:#4a6fa5; background:#dce8f8; border:1px solid #b0c4de; border-radius:3px; padding:1px 6px;'>👟 walk-in</span> &nbsp;";
                echo "<span class='sb-participant-meta'>✅ Přihlášeno: " . $radek['pocet'] . " osob &nbsp;|&nbsp; ";
                echo "🕒 Odpracováno: <strong>{$celkem_w} h</strong> <span style='color:#999;font-size:12px;'>(" . $radek['hodiny'] . " h/os.)</span></span><br><br>";
                echo "<div class='sb-form-row' style='align-items:center; flex-wrap:wrap; gap:6px;'>";
                echo "<form method='post' style='display:contents;'>";
                wp_nonce_field('sprava_walkin', '_wpnonce_walkin');
                echo "<input type='hidden' name='brigada_id' value='" . esc_attr($id) . "'>";
                echo "<input type='hidden' name='walkin_idx'  value='" . intval($radek['idx']) . "'>";
                echo "<span style='font-size:12px; color:#555;'>osob:</span>";
                echo "<input type='number' name='walkin_pocet'  value='" . intval($radek['pocet'])  . "' min='1' required style='width:60px; text-align:center;'>";
                echo "<span style='font-size:12px; color:#555;'>h/os.:</span>";
                echo "<input type='number' name='walkin_hodiny' value='" . intval($radek['hodiny']) . "' min='0' required style='width:60px; text-align:center;'>";
                echo "<input type='submit' name='edit_walkin' value='💾 Uložit' class='sb-btn sb-btn-primary sb-btn-sm'>";
                echo "</form>";
                echo "<form method='post' style='display:contents;' onsubmit='return confirm(\"Odebrat?\")'>";
                wp_nonce_field('sprava_walkin', '_wpnonce_walkin');
                echo "<input type='hidden' name='brigada_id' value='" . esc_attr($id) . "'>";
                echo "<input type='hidden' name='smazat_walkin' value='" . intval($radek['idx']) . "'>";
                echo "<input type='submit' value='🗑️ Odebrat' class='sb-btn sb-btn-danger sb-btn-sm'>";
                echo "</form>";
                echo "</div>";
                echo "</div>";
            }
        }

        if (!$nekdo_prihlasen) {
            echo sb_alert('Na tuto brigádu se nepřihlásila žádná rodina ani walk-in účastník.', 'info');
        }

        // Formulář pro přidání walk-in
        // Zjisti rodiny, které NEJSOU přihlášeny na tuto brigádu
        $neprihlasen_rodiny = [];
        foreach ($rodiny as $r) {
            if (!get_post_meta($id, 'ucastnici_' . $r->ID, true)) {
                $neprihlasen_rodiny[] = $r;
            }
        }

        echo "<div class='sb-walkin-add'>";
        echo "<strong>➕ Přidat nepřihlášeného účastníka</strong><br><br>";
        echo "<form method='post' class='sb-form-row'>";
        wp_nonce_field('sprava_walkin', '_wpnonce_walkin');
        echo "<input type='hidden' name='brigada_id' value='" . esc_attr($id) . "'>";
        echo "<div class='sb-form-group sb-fg-inline'><label>Rodina</label>";
        echo "<select name='walkin_jmeno'>";
        foreach ($neprihlasen_rodiny as $r) {
            echo "<option value='" . esc_attr($r->post_title) . "'>" . esc_html($r->post_title) . "</option>";
        }
        echo "</select></div>";
        echo "<div class='sb-form-group sb-fg-inline'><label>Počet osob</label>";
        echo "<input type='number' name='walkin_pocet' value='1' min='1' style='width:60px; text-align:center;'></div>";
        echo "<div class='sb-form-group sb-fg-inline'><label>Počet hodin</label>";
        echo "<input type='number' name='walkin_hodiny' value='0' min='0' style='width:60px; text-align:center;'></div>";
        echo "<input type='submit' name='pridat_walkin' value='➕ Přidat' class='sb-btn sb-btn-success'>";
        echo "</form>";
        echo "</div>";

        echo "</div>"; // sb-card
        echo "<script>setTimeout(function() { sbShowSection('upravit_ucastniky'); }, 100);</script>";
    }

    return ob_get_clean();
}


// Nápověda pro vedoucí (role author) – jen části, které se jich týkají.
function sb_napoveda_vedouci() {
    $revize = sb_revize_stav();
    ob_start();
    echo "<h3 class='sb-section-title'>📘 Nápověda pro vedoucí</h3>";

    if ($revize['otevrena']) {
        echo sb_alert('Revize rodin je teď otevřená – rodiny můžete upravovat a zakládat. Změny se projeví v brigádnických požadavcích od roku ' . intval($revize['rok']) . '.', 'warning');
    } else {
        echo sb_alert('Revize rodin je teď uzavřená – rodiny můžete jen prohlížet. Úpravy se otevřou, až správce revizi zahájí.', 'info');
    }
    ?>
    <div class="sb-card">
        <h4 class="sb-card-title">🗂️ Co je revize rodin</h4>
        <p>Jednou ročně (obvykle v říjnu a listopadu) správce vyhlásí <strong>revizi rodin</strong>. Během ní projdete rodiny svého oddílu a opravíte, co nesedí: kdo už do skauta nechodí, kdo nově přibyl, kdo změnil oddíl.</p>
        <ul>
            <li>Rodiny můžete <strong>upravovat a zakládat jen během revize</strong>. Mimo ni je vidíte jen pro čtení.</li>
            <li>Brigádnické hodiny se počítají za <strong>kalendářní rok</strong>. Vaše změny se proto projeví až <strong>od příštího roku</strong> – letošní požadavky rodin se nezmění.</li>
            <li>Nic se nemaže. Děti a rodiny, které už nechodí, se jen přesunou do archivu a dají se kdykoli vrátit.</li>
        </ul>
    </div>

    <div class="sb-card">
        <h4 class="sb-card-title">👥 Správa rodin – úprava rodiny krok za krokem</h4>
        <ol>
            <li>Rodinu najdete nejrychleji v poli <strong>🔍 Hledat</strong> – stačí napsat část příjmení rodiny, jméno rodiče nebo dítěte.</li>
            <li>Na kartě rodiny klikněte na <strong>✏️ Upravit</strong>. Karta se rozbalí.</li>
            <li>Opravte, co je potřeba:
                <ul>
                    <li><strong>Jméno nebo oddíl dítěte</strong> – přepište jméno nebo vyberte oddíl ze seznamu.</li>
                    <li><strong>Nové dítě</strong> – vyplňte jméno do prázdného řádku „Přidat dítě" a vyberte oddíl. Rodina může mít nejvýše 4 děti.</li>
                    <li><strong>Dítě už nechodí</strong> – zaškrtněte <strong>Už nechodí</strong>. Do poznámky můžete napsat důvod (např. „přešel do jiného střediska").</li>
                    <li><strong>Rodiče</strong> – zaškrtněte rodiče, kteří do rodiny patří.</li>
                </ul>
            </li>
            <li>Klikněte na <strong>💾 Uložit rodinu</strong>. Karta se sbalí a krátce se zeleně orámuje – uloženo.</li>
        </ol>
        <p><strong>Dítěti je 18 a zůstává ve skautu?</strong> Zaškrtněte místo toho <strong>Dospělý člen</strong> – od příštího roku bude vedeno samostatně v záložce <em>Správa dospělých členů</em>.</p>
        <p><strong>Rodič je sám členem skauta?</strong> Napište jeho jméno do pole <em>Přidat dospělého člena</em> v rozbalené kartě rodiny a uložte. Rodiče, kteří jsou vedoucí, se započítají automaticky – ty nezadávejte.</p>
        <p><strong>Rodina bez dětí</strong> (např. dva dospělí členové)? V rozbalené kartě použijte tlačítko <strong>Převést na dospělé členy</strong> – každý pak bude veden samostatně.</p>
        <p><strong>Jak poznám dítě v archivu?</strong> Na kartě je jeho jméno <span style="background:#eee;border:1px solid #ccc;border-radius:12px;padding:0 8px;color:#777;text-decoration:line-through;">šedé a přeškrtnuté</span>.</p>
        <p><strong>Omylem jsem označil dítě, že nechodí.</strong> Rozbalte kartu, v části <em>Děti v archivu</em> zaškrtněte <strong>Obnovit</strong> a uložte.</p>
    </div>

    <div class="sb-card">
        <h4 class="sb-card-title">📦 Celá rodina už do skauta nechodí</h4>
        <ol>
            <li>Rozbalte kartu rodiny (<strong>✏️ Upravit</strong>).</li>
            <li>Úplně dole můžete vyplnit poznámku a kliknout na <strong>📦 Přesunout rodinu do archivu</strong>.</li>
        </ol>
        <p>Rodina pak zmizí z přehledu a najdete ji dole na stránce v sekci <strong>📦 Archiv rodin</strong>. Tam ji lze tlačítkem <strong>↩️ Obnovit z archivu</strong> vrátit zpět. Rodiče zůstávají v archivované rodině – proto je neuvidíte v nabídce rodičů u jiných rodin.</p>
        <p>Smazat rodinu nebo dítě natrvalo může jen správce.</p>
    </div>

    <div class="sb-card">
        <h4 class="sb-card-title">➕ Vytvořit rodinu (nová rodina)</h4>
        <ol>
            <li>Zadejte <strong>název rodiny</strong> – obvykle příjmení v množném čísle, např. „Novákovi". Název nesmí být stejný jako u jiné rodiny.</li>
            <li>Zaškrtněte <strong>rodiče</strong>. Pokud rodič v seznamu chybí, ještě se nezaregistroval na webu – nevadí, rodinu založte bez něj.</li>
            <li>Klikněte na <strong>➕ Vytvořit rodinu</strong>.</li>
            <li><strong>Děti přidáte potom</strong> v záložce <em>Správa rodin</em>: najděte novou rodinu, klikněte na Upravit a vyplňte děti.</li>
        </ol>
        <p>Nová rodina založená v průběhu sezóny (po 31. 8.) bude mít brigádnický požadavek až od příštího roku. Hodiny, které na brigádách odpracuje ještě letos, se jí do příštího roku <strong>převedou</strong> – nic nepropadne. Rodiče to uvidí ve svém přehledu jako „Převedeno z roku …".</p>
    </div>

    <div class="sb-card">
        <h4 class="sb-card-title">⚠️ Když se objeví chyba</h4>
        <ul>
            <li><strong>„Rodina byla mezitím změněna jiným uživatelem…"</strong> – rodinu ve stejnou chvíli upravoval někdo jiný (nebo se formulář odeslal dvakrát). Obnovte stránku a úpravu zopakujte.</li>
            <li><strong>„Někteří vybraní rodiče už patří do jiné rodiny"</strong> – rodič je přiřazený jinde. Napište správci.</li>
            <li><strong>„Úpravy rodin jsou teď uzavřené"</strong> – revize skončila nebo ještě nezačala.</li>
        </ul>
    </div>

    <div class="sb-card">
        <h4 class="sb-card-title">📊 Ostatní záložky (jen pro čtení)</h4>
        <ul>
            <li><strong>🧮 Účastníci dle brigád</strong> – vyberte brigádu a uvidíte, kdo je přihlášený a kolik hodin odpracoval. Odtud si stáhnete <strong>prezenční listinu</strong>, <strong>Excel (CSV)</strong> a zkopírujete <strong>e-maily</strong> přihlášených rodin.</li>
            <li><strong>👪 Rodiny dle brigád</strong> – vyberte rodinu a uvidíte její brigády a kolik hodin jí ještě zbývá.</li>
            <li><strong>Jste zároveň rodič?</strong> Rodina vedoucího doplatek za neodpracované brigády neplatí – odpracované hodiny uvidíte normálně, poplatek bude 0 Kč. Členský příspěvek za děti se platí stejně.</li>
            <li><strong>💳 Platby</strong> – přehled, které rodiny už zaplatily členský příspěvek (zaplaceno / splátky / nezaplaceno). Filtrem „Nezaplaceno + splátky“ rychle najdete rodiny, kterým je potřeba platbu připomenout. Platby zapisuje správce.</li>
            <li><strong>📊 Roční výpis</strong> – vyberte rok a uvidíte přehled všech rodin. Kliknutím na záhlaví sloupců <em>Odpracováno, Požadavek, Rozdíl, Poplatek</em> tabulku seřadíte. Výpis stáhnete tlačítkem <strong>📥 Exportovat CSV</strong> (otevře se v Excelu).</li>
        </ul>
    </div>

    <p class="sb-text-muted">Je něco nejasné nebo nefunguje? Helpdesk: Honza Outlý – outly@skaut.cz, tel. 777 640 504.</p>
    <?php
    return ob_get_clean();
}

// =====================================================================
// SPRÁVA DOSPĚLÝCH ČLENŮ (samostatné jednotky bez dětí)
// =====================================================================
function sb_sprava_dospelych() {
    if (!sb_muze_videt_rodiny()) {
        return '<p>Nemáš oprávnění pro správu dospělých členů.</p>';
    }
    $smi    = sb_muze_upravovat_rodiny();
    $revize = sb_revize_stav();
    $zprava = '';
    $ulozena_id = 0;

    $vsichni = get_users(['role' => 'rodic']);
    usort($vsichni, function($a, $b) {
        $la = $a->last_name ?: array_slice(explode(' ', $a->display_name), -1)[0];
        $lb = $b->last_name ?: array_slice(explode(' ', $b->display_name), -1)[0];
        return strcasecmp($la, $lb) ?: strcasecmp($a->first_name ?: $a->display_name, $b->first_name ?: $b->display_name);
    });
    $povolene_ids = array_map(function($u) { return intval($u->ID); }, $vsichni);

    // Účty už přiřazené k některé jednotce (rodině i dospělému, včetně archivu): [user_id => rodina_id]
    $obsazeni = function() {
        $o = [];
        foreach (get_posts(['post_type' => 'rodina', 'numberposts' => -1]) as $r) {
            $p = get_post_meta($r->ID, 'rodice_rodiny', true);
            if (is_array($p)) foreach ($p as $uid) $o[intval($uid)] = $r->ID;
        }
        return $o;
    };
    $kontakt_z_postu = function($hodnota, &$chyby) {
        $platne = [];
        foreach (preg_split('/[\s,;]+/', sanitize_text_field(wp_unslash((string) $hodnota)), -1, PREG_SPLIT_NO_EMPTY) as $e) {
            if (is_email($e)) $platne[] = $e; else $chyby[] = $e;
        }
        return implode(', ', array_unique($platne));
    };
    $jmeno_z_postu = function($klic) {
        return mb_substr(trim(preg_replace('/\s*\([^)]*\)/u', '', sanitize_text_field(wp_unslash($_POST[$klic] ?? '')))), 0, 100);
    };

    $je_akce = isset($_POST['sb_dosp_novy']) || isset($_POST['sb_dosp_ulozit']) || isset($_POST['sb_dosp_ukoncit'])
            || isset($_POST['sb_dosp_obnovit']) || isset($_POST['sb_dosp_rodic']);
    if ($je_akce) {
        if (!$smi) {
            $zprava .= sb_alert('Úpravy jsou teď uzavřené. Otevřou se, když správce zahájí revizi.', 'error');
        } elseif (!isset($_POST['_wpnonce_dospeli']) || !wp_verify_nonce($_POST['_wpnonce_dospeli'], 'sb_dospeli')) {
            $zprava .= sb_alert('Neplatný bezpečnostní token.', 'error');
        } else {
            $obs = $obsazeni();
            $rok_x = sb_rok_archivace();
            $dnes = wp_date('Y-m-d');

            if (isset($_POST['sb_dosp_novy'])) {
                $jmeno = $jmeno_z_postu('dosp_jmeno');
                $ucet  = intval($_POST['dosp_ucet'] ?? 0);
                $chyby = [];
                $kontakt = $kontakt_z_postu($_POST['dosp_kontakt'] ?? '', $chyby);
                if ($jmeno === '') {
                    $zprava .= sb_alert('Zadejte příjmení a jméno.', 'error');
                } elseif (sb_post_exists_by_title('rodina', $jmeno)) {
                    $zprava .= sb_alert('Člen nebo rodina s tímto jménem už existuje.', 'error');
                } elseif ($ucet && (!in_array($ucet, $povolene_ids, true) || isset($obs[$ucet]))) {
                    $zprava .= sb_alert('Vybraný účet nelze přiřadit (už patří jiné rodině nebo členovi).', 'error');
                } elseif ($chyby) {
                    $zprava .= sb_alert('Neplatný e-mail: ' . implode(', ', $chyby), 'error');
                } else {
                    $id = sb_vytvorit_dospeleho($jmeno, $ucet ? [$ucet] : [], null);
                    if ($id && $kontakt !== '') update_post_meta($id, 'sb_kontakt_email', $kontakt);
                    $zprava .= $id ? sb_alert('Dospělý člen ' . $jmeno . ' byl přidán.') : sb_alert('Člena se nepodařilo přidat.', 'error');
                    $ulozena_id = $id;
                }
            } else {
                $r = sb_get_rodina(intval($_POST['edit_dospely_id'] ?? 0));
                if (!$r || !sb_je_dospely($r->ID)) {
                    $zprava .= sb_alert('Člen nebyl nalezen.', 'error');
                } elseif (isset($_POST['sb_dosp_ulozit'])) {
                    $jmeno = $jmeno_z_postu('dosp_jmeno');
                    $ucet  = intval($_POST['dosp_ucet'] ?? 0);
                    $chyby = [];
                    $kontakt = $kontakt_z_postu($_POST['dosp_kontakt'] ?? '', $chyby);
                    $existuje = ($jmeno !== '' && $jmeno !== $r->post_title) ? sb_post_exists_by_title('rodina', $jmeno) : 0;
                    if ($jmeno === '') {
                        $zprava .= sb_alert('Jméno nesmí být prázdné.', 'error');
                    } elseif ($existuje && intval($existuje) !== $r->ID) {
                        $zprava .= sb_alert('Člen nebo rodina s tímto jménem už existuje.', 'error');
                    } elseif ($ucet && (!in_array($ucet, $povolene_ids, true) || (isset($obs[$ucet]) && $obs[$ucet] != $r->ID))) {
                        $zprava .= sb_alert('Vybraný účet nelze přiřadit (už patří jiné rodině nebo členovi).', 'error');
                    } elseif ($chyby) {
                        $zprava .= sb_alert('Neplatný e-mail: ' . implode(', ', $chyby), 'error');
                    } else {
                        if ($jmeno !== $r->post_title) wp_update_post(['ID' => $r->ID, 'post_title' => $jmeno]);
                        update_post_meta($r->ID, 'rodice_rodiny', $ucet ? [$ucet] : []);
                        if ($kontakt !== '') update_post_meta($r->ID, 'sb_kontakt_email', $kontakt); else delete_post_meta($r->ID, 'sb_kontakt_email');
                        $zprava .= sb_alert('Údaje člena byly uloženy.');
                        $ulozena_id = $r->ID;
                    }
                } elseif (isset($_POST['sb_dosp_ukoncit'])) {
                    if (sb_rodina_archiv($r->ID)) {
                        $zprava .= sb_alert('Člen už je v archivu.', 'info');
                    } else {
                        $pozn = mb_substr(sanitize_text_field(wp_unslash($_POST['dosp_poznamka'] ?? '')), 0, 200);
                        update_post_meta($r->ID, 'rodina_archiv', ['od_roku' => $rok_x, 'poznamka' => $pozn, 'datum' => $dnes]);
                        $zprava .= sb_alert('Členství ' . $r->post_title . ' končí – od roku ' . $rok_x . ' se mu nebude počítat příspěvek ani brigády.');
                    }
                } elseif (isset($_POST['sb_dosp_obnovit']) && !empty(sb_rodina_archiv($r->ID)['presun_do'])
                          && in_array(sb_jmeno_klic($r->post_title), array_map('sb_jmeno_klic', sb_dospeli_pro_rok(intval(sb_rodina_archiv($r->ID)['presun_do']), sb_rok_archivace())), true)) {
                    $cil_r = get_post(intval(sb_rodina_archiv($r->ID)['presun_do']));
                    $zprava .= sb_alert($r->post_title . ' přešel/přešla do rodiny ' . ($cil_r ? $cil_r->post_title : '') . ' – obnovením by se počítal(a) dvakrát. Nejdřív ho/ji v rodině označte „už není členem“.', 'error');
                } elseif (isset($_POST['sb_dosp_obnovit'])) {
                    $konflikt = false;
                    foreach (sb_ucty_jednotky($r->ID) as $uid) {
                        if (isset($obs[intval($uid)]) && $obs[intval($uid)] != $r->ID) $konflikt = true;
                    }
                    if ($konflikt) {
                        $zprava .= sb_alert('Člena nelze obnovit – jeho účet je už přiřazen jinde.', 'error');
                    } else {
                        delete_post_meta($r->ID, 'rodina_archiv');
                        $zprava .= sb_alert('Člen ' . $r->post_title . ' byl obnoven z archivu.');
                        $ulozena_id = $r->ID;
                    }
                } elseif (isset($_POST['sb_dosp_rodic'])) {
                    $cil_id   = intval($_POST['dosp_cil'] ?? 0);
                    $nova     = mb_substr(sanitize_text_field(wp_unslash($_POST['dosp_nova_rodina'] ?? '')), 0, 100);
                    $ucty     = array_values(array_map('intval', sb_ucty_jednotky($r->ID)));
                    $cil      = $cil_id ? sb_get_rodina($cil_id) : null;
                    if (sb_rodina_archiv($r->ID)) {
                        $zprava .= sb_alert('Člen je v archivu.', 'error');
                    } elseif (!$cil && $nova === '') {
                        $zprava .= sb_alert('Vyberte rodinu, nebo zadejte název nové rodiny.', 'error');
                    } elseif ($cil && (sb_je_dospely($cil->ID) || sb_rodina_archiv($cil->ID))) {
                        $zprava .= sb_alert('Vybraná rodina není platná.', 'error');
                    } elseif (!$cil && sb_post_exists_by_title('rodina', $nova)) {
                        $zprava .= sb_alert('Rodina s tímto názvem už existuje – vyberte ji ze seznamu.', 'error');
                    } else {
                        if (!$cil) {
                            $nid = wp_insert_post(['post_title' => $nova, 'post_type' => 'rodina', 'post_status' => 'publish']);
                            $cil = $nid ? sb_get_rodina($nid) : null;
                        }
                        if (!$cil) {
                            $zprava .= sb_alert('Rodinu se nepodařilo vytvořit.', 'error');
                        } else {
                            // účet člena přejde do rodiny, člen se stane dospělým členem rodiny
                            update_post_meta($r->ID, 'rodice_rodiny', []);
                            $rodice = sb_ucty_jednotky($cil->ID);
                            update_post_meta($cil->ID, 'rodice_rodiny', array_values(array_unique(array_merge(array_map('intval', array_filter($rodice, 'is_numeric')), $ucty))));
                            $dosp = sb_dospeli_rodiny($cil->ID);
                            $dosp[] = ['jmeno' => $r->post_title, 'od_roku' => $rok_x];
                            update_post_meta($cil->ID, 'dospeli_clenove', $dosp);
                            update_post_meta($r->ID, 'rodina_archiv', ['od_roku' => $rok_x, 'poznamka' => 'Stal se rodičem – rodina ' . $cil->post_title, 'datum' => $dnes, 'presun_do' => $cil->ID]);
                            $kontakt_dosp = (string) get_post_meta($r->ID, 'sb_kontakt_email', true);
                            if ($kontakt_dosp !== '' && (string) get_post_meta($cil->ID, 'sb_kontakt_email', true) === '') update_post_meta($cil->ID, 'sb_kontakt_email', $kontakt_dosp);
                            $zprava .= sb_alert($r->post_title . ' je od roku ' . $rok_x . ' dospělým členem rodiny ' . $cil->post_title . '. Děti rodiny doplňte v záložce Správa rodin.');
                        }
                    }
                }
            }
        }
        $zprava .= "<script>setTimeout(function() { sbShowSection('dospeli'); }, 100);</script>";
    }

    // --- Zobrazení ---
    $obs = $obsazeni();
    $vsechny = get_posts(['post_type' => 'rodina', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);
    $aktivni = []; $archiv = []; $rodiny_cil = [];
    foreach ($vsechny as $r) {
        if (sb_je_dospely($r->ID)) {
            if (sb_rodina_archiv($r->ID)) $archiv[] = $r; else $aktivni[] = $r;
        } elseif (!sb_rodina_archiv($r->ID)) {
            $rodiny_cil[] = $r;
        }
    }

    ob_start();
    echo "<h3 class='sb-section-title'>🧑 Správa dospělých členů (bez dětí ve skautu)</h3>";
    echo $zprava;
    if ($revize['otevrena']) {
        echo sb_alert('Revize je otevřená. Změny (konec členství, přechod do rodiny) se projeví od roku ' . intval($revize['rok']) . '.', 'warning');
    } elseif (!$smi) {
        echo sb_alert('Dospělé členy můžete teď jen prohlížet. Úpravy se otevřou, když správce zahájí revizi.', 'info');
    }
    echo "<p class='sb-text-muted'>Dospělý člen je samostatná jednotka: platí příspěvek dospělého člena a brigádní hodiny se mu evidují osobně, doplatek za neodpracované hodiny ale neplatí. Rodiče, kteří jsou členy, se zadávají přímo v rodině (Správa rodin). Na web se člen přihlašuje účtem s rolí „rodic“.</p>";

    $vyber_uctu = function($aktualni_id, $jednotka_id) use ($vsichni, $obs) {
        $h = "<select name='dosp_ucet' style='width:100%; max-width:100%;'><option value='0'>— bez účtu na webu —</option>";
        foreach ($vsichni as $u) {
            if (isset($obs[$u->ID]) && $obs[$u->ID] != $jednotka_id) continue;
            $h .= "<option value='" . intval($u->ID) . "'" . ($u->ID == $aktualni_id ? ' selected' : '') . ">" . sb_format_rodic_jmeno($u) . "</option>";
        }
        return $h . "</select>";
    };

    if ($smi) {
        echo "<div class='sb-card'><details><summary style='cursor:pointer; font-weight:600; color:#0073aa;'>➕ Nový dospělý člen</summary>";
        echo "<form method='post' style='margin-top:10px; max-width:460px;'>";
        wp_nonce_field('sb_dospeli', '_wpnonce_dospeli');
        echo "<div class='sb-rod-sekce'>Příjmení a jméno</div><input type='text' name='dosp_jmeno' required maxlength='100' style='width:100%; max-width:100%;'>";
        echo "<div class='sb-rod-sekce'>Účet na webu (pokud ho má)</div>" . $vyber_uctu(0, 0);
        echo "<div class='sb-rod-sekce'>Kontaktní e-mail (když nemá účet)</div><input type='text' name='dosp_kontakt' placeholder='např. ze skautského informačního systému' style='width:100%; max-width:100%;'>";
        echo "<div class='sb-btn-row'><input type='submit' name='sb_dosp_novy' value='➕ Přidat člena' class='sb-btn sb-btn-primary'></div>";
        echo "</form></details></div>";
    }

    echo "<div class='sb-card'><div class='sb-rod-toolbar'><input type='text' id='sb-dosp-hledat' placeholder='🔍 Hledat člena…' autocomplete='off'><span class='sb-text-hint'>Dospělých členů: " . count($aktivni) . "</span></div></div>";

    $karta = function($r, $archivni) use ($smi, $vyber_uctu, $rodiny_cil, $ulozena_id) {
        $ucty = array_map('intval', sb_ucty_jednotky($r->ID));
        $ucet_id = $ucty ? $ucty[0] : 0;
        $u = $ucet_id ? get_userdata($ucet_id) : null;
        $kontakt = (string) get_post_meta($r->ID, 'sb_kontakt_email', true);
        $od = intval(get_post_meta($r->ID, 'sb_od_roku', true));
        $arch = sb_rodina_archiv($r->ID);
        echo "<div id='sb-dospely-" . intval($r->ID) . "' class='sb-rod-card" . ($archivni ? " sb-rod-off" : "") . ($ulozena_id === $r->ID ? " sb-rod-ulozeno" : "") . "' data-hledat='" . esc_attr(mb_strtolower($r->post_title)) . "'>";
        echo "<div class='sb-rod-head'>" . esc_html($r->post_title) . "</div>";
        echo "<div class='sb-rod-line'>👤 " . ($u ? sb_format_rodic_jmeno($u) : "<em>bez účtu na webu</em>") . "</div>";
        if ($kontakt !== '') echo "<div class='sb-rod-line'>✉ " . esc_html($kontakt) . "</div>";
        if ($od) echo "<div class='sb-text-hint'>Dospělý člen od roku $od</div>";
        if ($archivni && $arch) echo "<div class='sb-text-hint'>V archivu od roku " . intval($arch['od_roku']) . (!empty($arch['poznamka']) ? " – " . esc_html($arch['poznamka']) : "") . "</div>";
        if ($smi) {
            if ($archivni) {
                echo "<form method='post' style='margin-top:8px;'>";
                wp_nonce_field('sb_dospeli', '_wpnonce_dospeli');
                echo "<input type='hidden' name='edit_dospely_id' value='" . intval($r->ID) . "'>";
                echo "<input type='submit' name='sb_dosp_obnovit' value='↩️ Obnovit z archivu' class='sb-btn sb-btn-secondary sb-btn-sm'></form>";
            } else {
                echo "<details class='sb-rod-edit'><summary>✏️ Upravit</summary>";
                echo "<form method='post'>";
                wp_nonce_field('sb_dospeli', '_wpnonce_dospeli');
                echo "<input type='hidden' name='edit_dospely_id' value='" . intval($r->ID) . "'>";
                echo "<div class='sb-rod-sekce'>Příjmení a jméno</div><input type='text' name='dosp_jmeno' value='" . esc_attr($r->post_title) . "' required maxlength='100' style='width:100%; max-width:100%;'>";
                echo "<div class='sb-rod-sekce'>Účet na webu</div>" . $vyber_uctu($ucet_id, $r->ID);
                echo "<div class='sb-rod-sekce'>Kontaktní e-mail (když nemá účet)</div><input type='text' name='dosp_kontakt' value='" . esc_attr($kontakt) . "' style='width:100%; max-width:100%;'>";
                echo "<div class='sb-btn-row'><input type='submit' name='sb_dosp_ulozit' value='💾 Uložit' class='sb-btn sb-btn-primary'></div></form>";

                echo "<div class='sb-action-section'><form method='post'>";
                wp_nonce_field('sb_dospeli', '_wpnonce_dospeli');
                echo "<input type='hidden' name='edit_dospely_id' value='" . intval($r->ID) . "'>";
                echo "<div class='sb-rod-sekce' style='margin-top:0;'>Stal se rodičem</div>";
                echo "<select name='dosp_cil' style='width:100%; max-width:100%; margin-bottom:6px;'><option value='0'>— nová rodina (zadejte název níže) —</option>";
                foreach ($rodiny_cil as $rc) echo "<option value='" . intval($rc->ID) . "'>" . esc_html($rc->post_title) . "</option>";
                echo "</select>";
                echo "<input type='text' name='dosp_nova_rodina' maxlength='100' placeholder='Název nové rodiny, např. Novákovi' style='width:100%; max-width:100%; margin-bottom:6px;'>";
                echo "<input type='submit' name='sb_dosp_rodic' value='👪 Přesunout do rodiny' class='sb-btn sb-btn-secondary' onclick=\"return confirm('Přesunout člena do rodiny? Jeho samostatný záznam se archivuje a v rodině bude veden jako dospělý člen.');\">";
                echo "</form></div>";

                echo "<div class='sb-action-section'><form method='post' onsubmit='return confirm(\"Ukončit členství? Od příštího roku se nebude počítat příspěvek ani brigády.\")'>";
                wp_nonce_field('sb_dospeli', '_wpnonce_dospeli');
                echo "<input type='hidden' name='edit_dospely_id' value='" . intval($r->ID) . "'>";
                echo "<div class='sb-rod-sekce' style='margin-top:0;'>Ukončil členství</div>";
                echo "<input type='text' name='dosp_poznamka' maxlength='200' placeholder='Poznámka (volitelné)' style='width:100%; max-width:100%; margin-bottom:6px;'>";
                echo "<input type='submit' name='sb_dosp_ukoncit' value='📦 Ukončit členství' class='sb-btn sb-btn-secondary'>";
                echo "</form></div>";
                echo "</details>";
            }
        }
        echo "</div>";
    };

    echo "<div class='sb-rod-grid'>";
    foreach ($aktivni as $r) $karta($r, false);
    echo "</div>";
    if (!$aktivni) echo "<p class='sb-text-muted'>Zatím žádní dospělí členové.</p>";
    if ($archiv) {
        echo "<details class='sb-archiv' style='margin-top:20px;'><summary>📦 Archiv dospělých členů (" . count($archiv) . ")</summary><div class='sb-rod-grid'>";
        foreach ($archiv as $r) $karta($r, true);
        echo "</div></details>";
    }
    echo "<script>(function(){var i=document.getElementById('sb-dosp-hledat');if(!i)return;i.addEventListener('input',function(){var q=i.value.toLowerCase().trim();document.querySelectorAll('#sb-dospeli .sb-rod-card[data-hledat]').forEach(function(c){c.style.display=(!q||c.getAttribute('data-hledat').indexOf(q)>-1)?'':'none';});});})();</script>";
    return ob_get_clean();
}

function sb_vytvorit_rodinu() {
    if (!sb_muze_videt_rodiny()) {
        return '<p>Nemáš oprávnění pro vytváření rodin.</p>';
    }
    if (!sb_muze_upravovat_rodiny()) {
        return "<h3 class='sb-section-title'>➕ Vytvořit rodinu</h3>" .
               sb_alert('Zakládání rodin je teď uzavřené. Otevře se, když správce zahájí revizi rodin.', 'info');
    }

    $zprava = '';
    $vsichni_rodice = get_users(['role' => 'rodic', 'fields' => ['ID','display_name','first_name','last_name']]);

    // Seřazení podle příjmení, pak podle jména (fallback na display_name)
    usort($vsichni_rodice, function($a, $b) {
        $la = $a->last_name ?: array_slice(explode(' ', $a->display_name), -1)[0];
        $lb = $b->last_name ?: array_slice(explode(' ', $b->display_name), -1)[0];
        $fa = $a->first_name ?: $a->display_name;
        $fb = $b->first_name ?: $b->display_name;
        $cmp = strcasecmp($la, $lb);
        return $cmp === 0 ? strcasecmp($fa, $fb) : $cmp;
    });

    $rodiny = get_posts([
        'post_type'   => 'rodina',
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC'
    ]);

    // Zjištění, kteří rodiče už jsou přiřazeni
    $obsazeni_rodice = [];
    foreach ($rodiny as $r) {
        // I rodiče archivovaných rodin zůstávají v rodině (obsazení); vrátí se s ní při obnovení
        $prirazeni = get_post_meta($r->ID, 'rodice_rodiny', true);
        if (is_array($prirazeni)) {
            foreach ($prirazeni as $rid) {
                $obsazeni_rodice[(int)$rid] = $r->ID; // mapujeme na ID rodiny
            }
        } elseif (is_numeric($prirazeni)) {
            $obsazeni_rodice[(int)$prirazeni] = $r->ID;
        }
    }

    // Zpracování formuláře
    if (isset($_POST['nova_rodina'])) {
        if (!isset($_POST['_wpnonce_nova_rodina']) || !wp_verify_nonce($_POST['_wpnonce_nova_rodina'], 'nova_rodina')) {
            $zprava = sb_alert('Neplatný bezpečnostní token. Zkuste to prosím znovu.', 'error');
        } else {
            $nazev = sanitize_text_field($_POST['nazev_rodiny']);
            $vybrani_rodice = isset($_POST['rodice']) && is_array($_POST['rodice']) ? array_map('intval', $_POST['rodice']) : [];
            $vybrani_rodice = array_values(array_intersect($vybrani_rodice, array_map(function($u) { return intval($u->ID); }, $vsichni_rodice)));

            $existujici_id = sb_post_exists_by_title('rodina', $nazev);

            if ($existujici_id) {
                $zprava = sb_alert('Rodina s tímto názvem už existuje.', 'error');
            } else {
                $konflikt = array_intersect(array_keys($obsazeni_rodice), $vybrani_rodice);
                if (!empty($konflikt)) {
                    $zprava = sb_alert('Někteří vybraní rodiče už jsou přiřazeni k jiné rodině.', 'error');
                } else {
                    $id = wp_insert_post([
                        'post_title'  => $nazev,
                        'post_type'   => 'rodina',
                        'post_status' => 'publish'
                    ]);
                    if ($id) {
                        update_post_meta($id, 'rodice_rodiny', $vybrani_rodice);
                        $zprava = sb_alert('Rodina byla vytvořena: ' . $nazev . '.');
                        foreach ($vybrani_rodice as $rid) {
                            $obsazeni_rodice[$rid] = $id;
                        }
                    } else {
                        $zprava = sb_alert('Nepodařilo se vytvořit rodinu.', 'error');
                    }
                }
            }
        }
        $zprava .= "<script>setTimeout(function() { sbShowSection('rodiny'); }, 100);</script>";
    }

    ob_start();
    echo "<h3 class='sb-section-title'>➕ Vytvořit rodinu</h3>";
    echo $zprava;

    echo "<div class='sb-card' style='max-width:560px;'>";
    echo "<form method='post'>";
    wp_nonce_field('nova_rodina', '_wpnonce_nova_rodina');

    echo "<div class='sb-form-group'><label>Název rodiny:</label><input type='text' name='nazev_rodiny' required></div>";

    echo "<div class='sb-form-group'><label>Rodiče:</label>";
    $volni_rodice = array_filter($vsichni_rodice, fn($u) => !isset($obsazeni_rodice[$u->ID]));
    if (!empty($volni_rodice)) {
        foreach ($volni_rodice as $u) {
            echo "<label class='sb-label-normal'>";
            echo "<input type='checkbox' name='rodice[]' value='" . esc_attr($u->ID) . "'> " . sb_format_rodic_jmeno($u);
            echo "</label>";
        }
    } else {
        echo sb_alert('Všichni registrovaní rodiče jsou již přiřazeni k nějaké rodině.', 'info');
    }
    echo "</div>";
    echo "<div class='sb-form-group'>";
    echo "<p class='sb-text-muted'>Pokud v seznamu chybí rodič, který se ještě nezaregistroval na webu, nevadí — rodinu lze vytvořit i bez něj a rodiče přidat později v sekci <strong>Správa rodin</strong>.</p>";
    echo "</div>";

    echo "<div class='sb-btn-row'><input type='submit' name='nova_rodina' value='➕ Vytvořit rodinu' class='sb-btn sb-btn-primary'></div>";
    echo "</form>";
    echo "</div>";

    return ob_get_clean();
}



function sb_upravit_pozadavky_dle_roku() {
    $zprava = '';
    $pozadavky = get_option('pozadavky_dle_roku', []);
    $vybrany_rok = isset($_POST['vybrany_rok']) ? intval($_POST['vybrany_rok']) : date('Y');

    // Uložení změn
    if (isset($_POST['ulozit_pozadavky'])) {
        if (!isset($_POST['_wpnonce_ulozit_pozadavky']) ||
            !wp_verify_nonce($_POST['_wpnonce_ulozit_pozadavky'], 'ulozit_pozadavky')) {
            $zprava = sb_alert('Neplatný bezpečnostní token. Zkuste to prosím znovu.', 'error');
        } else {
        $rok = intval($_POST['ulozit_pozadavky']);
        if ($rok >= 2000 && $rok <= date('Y') + 5) {
            $hodiny1 = max(0, intval($_POST['hodiny_1']));
            $hodiny2 = max(0, intval($_POST['hodiny_2']));
            $hodiny3 = max(0, intval($_POST['hodiny_3']));
            $hodiny_dosp = max(0, intval($_POST['hodiny_dosp'] ?? 0));
            $sazba = max(0, floatval($_POST['sazba_za_hodinu']));

            $pozadavky[$rok] = [
                '1' => $hodiny1,
                '2' => $hodiny2,
                '3' => $hodiny3,
                'dosp' => $hodiny_dosp,
                'sazba' => $sazba
            ];
            update_option('pozadavky_dle_roku', $pozadavky);
            $zprava = sb_alert('Požadavky pro rok ' . intval($rok) . ' byly uloženy.');
            $vybrany_rok = $rok;
        } else {
            $zprava = sb_alert('Neplatný rok.', 'error');
        }
        } // konec else nonce
    }

    // Členský příspěvek – uložení nastavení (jen správce)
    if (isset($_POST['sb_prispevky_ulozit']) && sb_je_spravce()) {
        if (!isset($_POST['_wpnonce_prispevky']) || !wp_verify_nonce($_POST['_wpnonce_prispevky'], 'sb_prispevky')) {
            $zprava = sb_alert('Neplatný bezpečnostní token. Zkuste to prosím znovu.', 'error');
        } else {
            $rok_p = intval($_POST['sb_prispevky_ulozit']);
            $ucet  = sanitize_text_field(wp_unslash($_POST['prispevek_ucet'] ?? ''));
            $od    = sanitize_text_field(wp_unslash($_POST['prispevek_od'] ?? ''));
            $do    = sanitize_text_field(wp_unslash($_POST['prispevek_do'] ?? ''));
            $datum_ok = function($d) { return $d === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); };
            if ($rok_p < 2000 || $rok_p > intval(date('Y')) + 5) {
                $zprava = sb_alert('Neplatný rok.', 'error');
            } elseif ($ucet !== '' && (sb_cz_iban($ucet) === '' || !sb_ucet_platny($ucet))) {
                $zprava = sb_alert('Číslo účtu není platné. Zadejte ho ve tvaru 123456789/0100 (případně s předčíslím 19-123456789/0100) a zkontrolujte překlep.', 'error');
            } elseif (!$datum_ok($od) || !$datum_ok($do) || ($od !== '' && $do !== '' && $od > $do)) {
                $zprava = sb_alert('Zkontrolujte období zobrazení (datum „od" musí být před datem „do").', 'error');
            } else {
                $vse = get_option('sb_prispevky', []);
                if (!is_array($vse)) $vse = [];
                $vse[$rok_p] = [
                    '1'    => max(0, intval($_POST['prispevek_1'] ?? 0)),
                    '2'    => max(0, intval($_POST['prispevek_2'] ?? 0)),
                    '3'    => max(0, intval($_POST['prispevek_3'] ?? 0)),
                    'dosp' => max(0, intval($_POST['prispevek_dosp'] ?? 0)),
                    'ucet' => $ucet,
                    'od'   => $od,
                    'do'   => $do,
                ];
                update_option('sb_prispevky', $vse, false);
                $zprava = sb_alert('Nastavení členského příspěvku pro rok ' . $rok_p . ' bylo uloženo.');
                $vybrany_rok = $rok_p;
            }
        }
    }

    // Revize rodin – zahájení a ukončení (jen správce)
    if (isset($_POST['sb_revize_akce']) && sb_je_spravce()) {
        if (!isset($_POST['_wpnonce_revize']) || !wp_verify_nonce($_POST['_wpnonce_revize'], 'sb_revize')) {
            $zprava = sb_alert('Neplatný bezpečnostní token. Zkuste to prosím znovu.', 'error');
        } else {
            $akce    = sanitize_key($_POST['sb_revize_akce']);
            $stav    = sb_revize_stav();
            $cil     = intval(wp_date('Y')) + 1;
            $rodiny_rev = get_posts(['post_type' => 'rodina', 'numberposts' => -1]);

            if ($akce === 'zahajit' && !$stav['otevrena']) {
                // Zmrazení aktuálního roku: snímek se vytvoří jen tam, kde ještě není
                $zmrazit = $cil - 1;
                foreach ($rodiny_rev as $rr) {
                    if (!sb_rodina_v_roce($rr, $zmrazit)) continue;
                    $ex = get_post_meta($rr->ID, 'pocet_deti_' . $zmrazit, true);
                    if ($ex === '' || $ex === false || $ex === null) {
                        update_post_meta($rr->ID, 'pocet_deti_' . $zmrazit, sb_pocet_deti_ze_stavu($rr->ID, $zmrazit));
                    }
                }
                update_option('sb_revize', ['otevrena' => true, 'rok' => $cil], false);
                $zprava = sb_alert('Revize rodin byla zahájena. Vedoucí mohou upravovat rodiny. Rok ' . $zmrazit . ' je zmrazen, změny se projeví od roku ' . $cil . '.');
            } elseif ($akce === 'ukoncit' && $stav['otevrena'] && $stav['rok'] > 0) {
                foreach ($rodiny_rev as $rr) {
                    if (!sb_rodina_v_roce($rr, $stav['rok'])) continue;
                    update_post_meta($rr->ID, 'pocet_deti_' . $stav['rok'], sb_pocet_deti_ze_stavu($rr->ID, $stav['rok']));
                }
                update_option('sb_revize', ['otevrena' => false, 'rok' => $stav['rok']], false);
                $zprava = sb_alert('Revize rodin byla ukončena. Snímek počtu dětí pro rok ' . intval($stav['rok']) . ' byl vytvořen.');
            }
        }
    }

    $hodiny = isset($pozadavky[$vybrany_rok]) ? $pozadavky[$vybrany_rok] : ['1' => '', '2' => '', '3' => '', 'sazba' => ''];

    ob_start();
    echo "<h3 class='sb-section-title'>📅 Požadavky dle roku</h3>";
    echo $zprava;

    // Karta revize rodin
    $revize     = sb_revize_stav();
    $cil_rok    = intval(wp_date('Y')) + 1;
    echo "<div class='sb-card'>";
    echo "<h4 class='sb-card-title'>🗂️ Revize rodin</h4>";
    if ($revize['otevrena']) {
        echo sb_alert('Revize je otevřená (změny se projeví od roku ' . intval($revize['rok']) . '). Vedoucí teď mohou upravovat rodiny a archivovat děti.', 'warning');
        echo "<p class='sb-text-muted'>Po dokončení úprav revizi ukončete – vytvoří se snímek počtu dětí pro rok " . intval($revize['rok']) . " a vedoucí už rodiny upravovat nebudou. Ukončete ji nejpozději do konce listopadu, aby rodiče v prosinci viděli správné požadavky na příští rok.</p>";
        echo "<form method='post' onsubmit='return confirm(\"Opravdu ukončit revizi? Vytvoří se snímek pro rok " . intval($revize['rok']) . " (přepíše i ručně upravené hodnoty snímku).\")'>";
        wp_nonce_field('sb_revize', '_wpnonce_revize');
        echo "<input type='hidden' name='sb_revize_akce' value='ukoncit'>";
        echo "<input type='submit' value='✅ Ukončit revizi' class='sb-btn sb-btn-success'>";
        echo "</form>";
    } else {
        echo "<p class='sb-text-muted'>Revize je uzavřená – vedoucí mohou rodiny jen prohlížet. Zahájením revize se zmrazí počty dětí pro rok " . intval($cil_rok - 1) . " a vedoucí budou moci upravovat rodiny (změny se projeví od roku " . intval($cil_rok) . ").</p>";
        echo "<form method='post' onsubmit='return confirm(\"Zahájit revizi rodin? Zmrazí se počty dětí pro rok " . intval($cil_rok - 1) . ".\")'>";
        wp_nonce_field('sb_revize', '_wpnonce_revize');
        echo "<input type='hidden' name='sb_revize_akce' value='zahajit'>";
        echo "<input type='submit' value='▶️ Zahájit revizi' class='sb-btn sb-btn-primary'>";
        echo "</form>";
    }
    echo "</div>";

    // Výběr roku
    echo "<div class='sb-card'>";
    echo "<form method='post' class='sb-form-row'>";
    echo "<div class='sb-form-group sb-fg-inline'><label>Rok:</label><select name='vybrany_rok'>";
    for ($r = 2025; $r <= 2035; $r++) {
        $sel = ($r == $vybrany_rok) ? 'selected' : '';
        echo "<option value='$r' $sel>$r</option>";
    }
    echo "</select></div>";
    echo "<input type='submit' name='upravit_pozadavky_dle_roku' value='Zobrazit' class='sb-btn sb-btn-primary'>";
    echo "</form>";
    echo "</div>";

    // Formulář pro úpravu
    echo "<div class='sb-card' style='max-width:480px;'>";
    echo "<h4 class='sb-card-title'>Požadavky pro rok " . intval($vybrany_rok) . "</h4>";
    echo "<form method='post'>";
    wp_nonce_field('ulozit_pozadavky', '_wpnonce_ulozit_pozadavky');
    echo "<input type='hidden' name='ulozit_pozadavky' value='" . intval($vybrany_rok) . "'>";

    echo "<div class='sb-form-group'><label>Rodiny s 1 dítětem – požadované hodiny:</label><input type='number' name='hodiny_1' value='{$hodiny['1']}' min='0' style='width:90px;'></div>";
    echo "<div class='sb-form-group'><label>Rodiny se 2 dětmi – požadované hodiny:</label><input type='number' name='hodiny_2' value='{$hodiny['2']}' min='0' style='width:90px;'></div>";
    echo "<div class='sb-form-group'><label>Rodiny se 3 a více dětmi – požadované hodiny:</label><input type='number' name='hodiny_3' value='{$hodiny['3']}' min='0' style='width:90px;'></div>";
    echo "<div class='sb-form-group'><label>Dospělý člen – požadované hodiny (osobně, bez doplatku):</label><input type='number' name='hodiny_dosp' value='" . esc_attr($hodiny['dosp'] ?? '') . "' min='0' style='width:90px;'></div>";
    echo "<div class='sb-form-group'><label>Poplatek za neodpracovanou hodinu (Kč):</label><input type='number' step='0.01' name='sazba_za_hodinu' value='{$hodiny['sazba']}' min='0' style='width:110px;'></div>";

    echo "<div class='sb-btn-row'><input type='submit' value='💾 Uložit' class='sb-btn sb-btn-primary'></div>";
    echo "</form>";
    echo "</div>";
    
// Členský příspěvek pro vybraný rok
$prisp_vse = get_option('sb_prispevky', []);
$prisp = (is_array($prisp_vse) && isset($prisp_vse[$vybrany_rok])) ? $prisp_vse[$vybrany_rok] : [];
$prisp_ucet = $prisp['ucet'] ?? '2300221146/2010';
echo "<div class='sb-card' style='max-width:480px;'>";
echo "<h4 class='sb-card-title'>💳 Členský příspěvek " . intval($vybrany_rok) . "</h4>";
echo "<p class='sb-text-muted' style='margin-bottom:10px;'>Příspěvek = počet dětí × částka podle počtu dětí v rodině + doplatek za neodpracované brigádní hodiny roku " . (intval($vybrany_rok) - 1) . ". Rodiny v archivu neplatí.</p>";
echo "<form method='post'>";
wp_nonce_field('sb_prispevky', '_wpnonce_prispevky');
echo "<input type='hidden' name='sb_prispevky_ulozit' value='" . intval($vybrany_rok) . "'>";
echo "<input type='hidden' name='vybrany_rok' value='" . intval($vybrany_rok) . "'>";
echo "<div class='sb-form-group'><label>Za každé dítě, je-li v rodině 1 dítě (Kč):</label><input type='number' name='prispevek_1' value='" . esc_attr($prisp['1'] ?? '') . "' min='0' style='width:110px;'></div>";
echo "<div class='sb-form-group'><label>Za každé dítě, jsou-li v rodině 2 děti (Kč):</label><input type='number' name='prispevek_2' value='" . esc_attr($prisp['2'] ?? '') . "' min='0' style='width:110px;'></div>";
echo "<div class='sb-form-group'><label>Za každé dítě, jsou-li v rodině 3 a více dětí (Kč):</label><input type='number' name='prispevek_3' value='" . esc_attr($prisp['3'] ?? '') . "' min='0' style='width:110px;'></div>";
echo "<div class='sb-form-group'><label>Za dospělého člena (Kč za osobu):</label><input type='number' name='prispevek_dosp' value='" . esc_attr($prisp['dosp'] ?? '') . "' min='0' style='width:110px;'></div>";
echo "<div class='sb-form-group'><label>Číslo účtu:</label><input type='text' name='prispevek_ucet' value='" . esc_attr($prisp_ucet) . "' placeholder='2300221146/2010'></div>";
echo "<div class='sb-form-group'><label>Rodičům zobrazit v Moje brigády od – do:</label>";
echo "<input type='date' name='prispevek_od' value='" . esc_attr($prisp['od'] ?? '') . "' style='width:160px;'> – ";
echo "<input type='date' name='prispevek_do' value='" . esc_attr($prisp['do'] ?? '') . "' style='width:160px;'></div>";
echo "<div class='sb-btn-row'><input type='submit' value='💾 Uložit příspěvek' class='sb-btn sb-btn-primary'></div>";
echo "</form>";
echo "</div>";

// Přehledová tabulka všech uložených požadavků
echo "<div class='sb-card'>";
echo "<h4 class='sb-card-title'>📊 Přehled požadavků dle roku</h4>";
echo "<table class='sb-table' style='max-width:500px;'>";
echo "<tr><th class='center'>Rok</th><th class='center'>1 dítě (h)</th><th class='center'>2 děti (h)</th><th class='center'>3+ dětí (h)</th><th class='center'>Dospělý (h)</th><th class='center'>Sazba (Kč)</th></tr>";

$ma_data = false;
foreach ($pozadavky as $rok => $data) {
    if (!is_array($data)) continue;
    $h1    = isset($data['1']) ? intval($data['1']) : 0;
    $h2    = isset($data['2']) ? intval($data['2']) : 0;
    $h3    = isset($data['3']) ? intval($data['3']) : 0;
    $hd    = isset($data['dosp']) ? intval($data['dosp']) : 0;
    $sazba = isset($data['sazba']) ? number_format(floatval($data['sazba']), 2, ',', ' ') : '';
    if ($h1 === 0 && $h2 === 0 && $h3 === 0 && $sazba === '') continue;
    $ma_data = true;
    echo "<tr><td class='center'><strong>" . intval($rok) . "</strong></td><td class='center'>$h1</td><td class='center'>$h2</td><td class='center'>$h3</td><td class='center'>$hd</td><td class='center'>$sazba</td></tr>";
}
if (!$ma_data) {
    echo "<tr><td colspan='6' class='center sb-text-muted'>Žádné požadavky dosud nebyly nastaveny.</td></tr>";
}
echo "</table>";
echo "</div>";

// === SNÍMEK POČTU DĚTÍ ===
$rodiny_all = get_posts(['post_type' => 'rodina', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);

// Uložení automatického snímku
if (isset($_POST['vytvorit_snapshot']) && isset($_POST['_wpnonce_snapshot']) &&
    wp_verify_nonce($_POST['_wpnonce_snapshot'], 'sb_snapshot_' . $vybrany_rok)) {
    foreach ($rodiny_all as $rr) {
        if (!sb_rodina_v_roce($rr, $vybrany_rok)) continue;
        update_post_meta($rr->ID, 'pocet_deti_' . $vybrany_rok, sb_pocet_deti_ze_stavu($rr->ID, $vybrany_rok));
    }
    echo "<div class='sb-alert sb-alert-success'>Snímek počtu dětí pro rok $vybrany_rok byl vytvořen.</div>";
}

// Uložení ručních úprav
if (isset($_POST['ulozit_snapshot_rucne']) && isset($_POST['_wpnonce_snapshot']) &&
    wp_verify_nonce($_POST['_wpnonce_snapshot'], 'sb_snapshot_' . $vybrany_rok)) {
    foreach ($rodiny_all as $rr) {
        $klic = 'snap_' . $rr->ID;
        if (isset($_POST[$klic])) {
            update_post_meta($rr->ID, 'pocet_deti_' . $vybrany_rok, max(0, intval($_POST[$klic])));
        }
    }
    echo "<div class='sb-alert sb-alert-success'>Snímek byl upraven.</div>";
}

$rodiny_v_roce = array_filter($rodiny_all, function($rr) use ($vybrany_rok) {
    return sb_rodina_v_roce($rr, $vybrany_rok);
});

echo "<div class='sb-card'>";
echo "<h4 class='sb-card-title'>👶 Snímek počtu dětí pro rok " . intval($vybrany_rok) . "</h4>";
echo "<p class='sb-text-muted' style='margin-bottom:10px;'>Počet dětí použitý pro výpočet požadavků. Pokud snímek neexistuje, použije se aktuální stav rodiny.</p>";

echo "<form method='post'>";
wp_nonce_field('sb_snapshot_' . $vybrany_rok, '_wpnonce_snapshot');
echo "<input type='hidden' name='vybrany_rok' value='" . intval($vybrany_rok) . "'>";
echo "<div style='margin-bottom:10px;'><input type='submit' name='vytvorit_snapshot' value='📷 Vytvořit snímek ze současného stavu' class='sb-btn sb-btn-secondary'></div>";

echo "<table class='sb-table' style='max-width:500px;'>";
echo "<thead><tr><th>Rodina</th><th class='center'>Aktuálně dětí</th><th class='center'>Snímek pro " . intval($vybrany_rok) . "</th></tr></thead><tbody>";

foreach ($rodiny_v_roce as $rr) {
    $aktualne   = sb_pocet_deti_ze_stavu($rr->ID, $vybrany_rok);
    $snap_raw   = get_post_meta($rr->ID, 'pocet_deti_' . $vybrany_rok, true);
    $snap       = ($snap_raw !== '' && $snap_raw !== false && $snap_raw !== null) ? intval($snap_raw) : null;
    $snap_zobr  = $snap !== null ? $snap : '<em class="sb-text-muted">– (použije se ' . $aktualne . ')</em>';
    echo "<tr>
        <td>" . esc_html($rr->post_title) . "</td>
        <td class='center'>$aktualne</td>
        <td class='center'><input type='number' name='snap_{$rr->ID}' value='" . ($snap !== null ? $snap : $aktualne) . "' min='0' style='width:60px;'></td>
    </tr>";
}
echo "</tbody></table>";
echo "<div style='margin-top:10px;'><input type='submit' name='ulozit_snapshot_rucne' value='💾 Uložit úpravy snímku' class='sb-btn sb-btn-primary'></div>";
echo "</form>";
echo "</div>";

    return ob_get_clean();
}


function sb_rocni_vypis() {
    $current_user = wp_get_current_user();
    $roles = $current_user->roles;
    if (!array_intersect($roles, ['administrator', 'spravce_brigad', 'author'])) {
        return '<p>Nemáš oprávnění pro roční výpis.</p>';
    }

    $rodiny = get_posts([
        'post_type' => 'rodina',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC'
    ]);
    $brigady = get_posts(['post_type' => 'brigada', 'numberposts' => -1]);
    $pozadavky = get_option('pozadavky_dle_roku', []);
    $vybrany_rok = isset($_POST['vybrany_rok']) ? intval($_POST['vybrany_rok']) : intval(date('Y'));
    $zobrazit_vypis = isset($_POST['zobraz_rocni_vypis']);

    ob_start();
    echo "<h3 class='sb-section-title'>📊 Roční výpis</h3>";

    echo "<div class='sb-card'>";
    echo "<form method='post' class='sb-form-row'>";
    echo "<div class='sb-form-group sb-fg-inline'><label>Rok:</label><select name='vybrany_rok'>";
    for ($r = 2025; $r <= 2035; $r++) {
        $selected = ($r == $vybrany_rok) ? 'selected' : '';
        echo "<option value='$r' $selected>$r</option>";
    }
    echo "</select></div>";
    echo "<input type='submit' name='zobraz_rocni_vypis' value='Zobrazit výpis' class='sb-btn sb-btn-primary'>";
    echo "</form>";
    echo "</div>";

    if ($zobrazit_vypis) {
        $pozadavky_rok = $pozadavky[$vybrany_rok] ?? null;
        $sazba = isset($pozadavky_rok['sazba']) ? floatval($pozadavky_rok['sazba']) : 0;

        // --- Nejprve projdeme všechny rodiny a sestavíme data i celkový součet ---
        $celkem_poplatek = 0;
        $radky = [];
        foreach ($rodiny as $r) {
            if (!sb_rodina_v_roce($r, $vybrany_rok)) continue;

            $rodina_id = $r->ID;
            $nazev = esc_html($r->post_title);
            $deti = get_post_meta($rodina_id, 'deti_rodiny', true);
            $rodice = get_post_meta($rodina_id, 'rodice_rodiny', true);
            $pocet_deti = sb_pocet_deti_pro_rok($rodina_id, $vybrany_rok);

            $jmena_deti = '';
            if (is_array($deti)) {
                foreach ($deti as $dite) {
                    $jmena_deti .= esc_html($dite['jmeno']) . " – " . esc_html($dite['oddil']) . "<br>";
                }
            }
            // Děti, které odešly až od pozdějšího roku, se v tomto roce ještě počítají
            foreach (sb_deti_archiv($rodina_id) as $dite) {
                if (is_array($dite) && isset($dite['od_roku']) && intval($dite['od_roku']) > intval($vybrany_rok)) {
                    $jmena_deti .= esc_html($dite['jmeno']) . " – " . esc_html($dite['oddil']) . "<br>";
                }
            }

            $jmena_rodicu = '';
            if (is_array($rodice)) {
                foreach ($rodice as $rid) {
                    $u = get_user_by('id', $rid);
                    if ($u) $jmena_rodicu .= sb_format_rodic_jmeno($u) . "<br>";
                }
            }

            $pozadavek = $pozadavky_rok ? sb_pozadavek_hodin($rodina_id, $vybrany_rok, $pozadavky_rok) : 0;
            if (sb_je_dospely($rodina_id)) $jmena_deti = '<em>dospělý člen</em>';

            $odpracovano = 0;
            $brigady_seznam = [];
            foreach ($brigady as $b) {
                $datum_raw = get_post_meta($b->ID, 'datum_brigady', true);
                $rok_v_datu = null;
                if ($datum_raw && preg_match('/\b(19|20)\d{2}\b/', $datum_raw, $m)) {
                    $rok_v_datu = intval($m[0]);
                }
                if ($rok_v_datu !== $vybrany_rok) continue;

                $celkem = sb_celkem_hodin($b->ID, $rodina_id);
                if ($celkem !== null) {
                    $odpracovano += $celkem;
                    $d = $datum_raw ? date('j. n.', strtotime($datum_raw)) : '';
                    $brigady_seznam[] = esc_html($b->post_title) . ($d ? ' ' . $d : '') . ' (' . $celkem . ' h)';
                } else {
                    $walkins = get_post_meta($b->ID, 'walkin_ucastnici', true);
                    if (is_array($walkins)) {
                        foreach ($walkins as $w) {
                            if (($w['jmeno'] ?? '') === $r->post_title) {
                                $c = max(1, intval($w['pocet'] ?? 1)) * intval($w['hodiny'] ?? 0);
                                $odpracovano += $c;
                                $d = $datum_raw ? date('j. n.', strtotime($datum_raw)) : '';
                                $brigady_seznam[] = esc_html($b->post_title) . ($d ? ' ' . $d : '') . ' (' . $c . ' h)';
                                break;
                            }
                        }
                    }
                }
            }

            $prevod = sb_prevod_hodin($r, $vybrany_rok);
            if ($prevod > 0) {
                $odpracovano += $prevod;
                array_unshift($brigady_seznam, 'Převod z roku ' . ($vybrany_rok - 1) . ' (' . $prevod . ' h)');
            }

            $rozdil  = $odpracovano - $pozadavek;
            $vedouci = sb_bez_doplatku($rodina_id);
            $vedouci_popis = $vedouci ? (sb_je_dospely($rodina_id) ? 'dospělý' : 'vedoucí') : '';
            $poplatek = $vedouci ? 0 : max(0, $pozadavek - $odpracovano) * $sazba;
            $celkem_poplatek += $poplatek;
            $radky[] = compact('nazev', 'jmena_deti', 'jmena_rodicu', 'pocet_deti', 'odpracovano', 'prevod', 'pozadavek', 'rozdil', 'poplatek', 'brigady_seznam', 'rodina_id', 'vedouci', 'vedouci_popis');
        }

        // --- Agregované statistiky brigád ---
        $minule_clovekoh   = 0;
        foreach ($radky as $z) $minule_clovekoh += $z['odpracovano'] - $z['prevod'];

        $dnes_stat         = new DateTime('today', wp_timezone());
        $minule_brigady_h  = 0;
        $budouci_brigady_h = 0;
        $budouci_clovekoh  = 0;
        $rodina_budouci_h  = [];

        foreach ($brigady as $b) {
            $datum_raw = get_post_meta($b->ID, 'datum_brigady', true);
            $rok_v_datu = null;
            if ($datum_raw && preg_match('/\b(19|20)\d{2}\b/', $datum_raw, $m2)) {
                $rok_v_datu = intval($m2[0]);
            }
            if ($rok_v_datu !== $vybrany_rok) continue;

            $datum_obj_stat = DateTime::createFromFormat('Y-m-d', $datum_raw, wp_timezone());
            preg_match('/\d+/', (string) get_post_meta($b->ID, 'delka_brigady', true), $dm);
            $delka_h = isset($dm[0]) ? intval($dm[0]) : 0;

            if ($datum_obj_stat && $datum_obj_stat < $dnes_stat) {
                $minule_brigady_h += $delka_h;
            } else {
                $budouci_brigady_h += $delka_h;
                $meta_b = get_post_meta($b->ID);
                foreach ($meta_b as $mk => $mv) {
                    if (strpos($mk, 'ucastnici_') === 0 && isset($mv[0]) && $mv[0] !== '') {
                        $pocet_r = intval($mv[0]);
                        $budouci_clovekoh += $pocet_r * $delka_h;
                        $rid = intval(substr($mk, strlen('ucastnici_')));
                        $rodina_budouci_h[$rid] = ($rodina_budouci_h[$rid] ?? 0) + $pocet_r * $delka_h;
                    }
                }
            }
        }

        $predpokladane_inkaso = 0;
        foreach ($radky as $z) {
            if ($z['vedouci']) continue;
            $proj = $z['odpracovano'] + ($rodina_budouci_h[$z['rodina_id']] ?? 0);
            $predpokladane_inkaso += max(0, $z['pozadavek'] - $proj) * $sazba;
        }

        // --- Výpis ---
        echo "<div class='sb-card'>";
        echo "<h4 class='sb-card-title'>Rok " . intval($vybrany_rok) . " — sazba: <strong>" . number_format($sazba, 2, ',', ' ') . " Kč/h</strong></h4>";

        $stat_style      = "flex:1; min-width:200px; padding:12px 16px; border-radius:4px; font-size:13px; line-height:1.7;";
        $hodnota_brigad  = $budouci_clovekoh * $sazba;
        $realne_snizeni  = $celkem_poplatek - $predpokladane_inkaso;

        echo "<div style='display:flex; flex-wrap:wrap; gap:12px; margin-bottom:12px;'>";

        echo "<div style='$stat_style background:#fff3cd; border:1px solid #f0c040;'>"
            . "<div style='font-weight:700; font-size:15px; margin-bottom:4px;'>💰 Aktuální inkaso</div>"
            . "<div>Celkem k inkasu: <strong>" . number_format($celkem_poplatek, 2, ',', ' ') . " Kč</strong></div>"
            . "</div>";

        echo "<div style='$stat_style background:#e8f5e9; border:1px solid #a5d6a7;'>"
            . "<div style='font-weight:700; font-size:15px; margin-bottom:4px;'>✅ Uskutečněné brigády</div>"
            . "<div>Hodin brigád: <strong>$minule_brigady_h h</strong></div>"
            . "<div>Odpracováno: <strong>$minule_clovekoh člověkohodin</strong></div>"
            . "</div>";

        echo "<div style='$stat_style background:#e3f2fd; border:1px solid #90caf9;'>"
            . "<div style='font-weight:700; font-size:15px; margin-bottom:4px;'>📅 Zbývající brigády</div>"
            . "<div>Hodin brigád: <strong>$budouci_brigady_h h</strong></div>"
            . "<div>Předp. člověkohodiny: <strong>$budouci_clovekoh h</strong></div>"
            . "<div>Hodnota brigád: <strong>" . number_format($hodnota_brigad, 2, ',', ' ') . " Kč</strong></div>"
            . "<div>Reálné snížení inkasa: <strong>" . number_format($realne_snizeni, 2, ',', ' ') . " Kč</strong></div>"
            . "</div>";

        echo "</div>";

        echo "<div style='margin-bottom:6px; font-size:13px;'>Předpokládané inkaso na konci roku: <strong style='font-size:15px;'>"
            . number_format($predpokladane_inkaso, 2, ',', ' ') . " Kč</strong></div>";
        echo "<div style='margin-bottom:20px; font-size:12px; color:#666; font-style:italic;'>Část rodin je na brigády přihlášena, přestože svůj požadavek hodin již splnila — jejich hodiny inkaso dále nesnižují.</div>";

        echo "<table class='sb-table' id='sb-rocni-tabulka'>";
        echo "<thead><tr>
            <th class='center'>#</th>
            <th>Rodina</th>
            <th>Děti</th>
            <th>Rodiče</th>
            <th class='center'>Počet dětí</th>
            <th data-col='5' data-type='num' class='center' style='cursor:pointer; user-select:none;'>Odpracováno (h) <span class='sb-si'> ↕</span></th>
            <th data-col='6' data-type='num' class='center' style='cursor:pointer; user-select:none;'>Požadavek (h) <span class='sb-si'> ↕</span></th>
            <th data-col='7' data-type='num' class='center' style='cursor:pointer; user-select:none;'>Rozdíl (h) <span class='sb-si'> ↕</span></th>
            <th data-col='8' data-type='num' class='center' style='cursor:pointer; user-select:none;'>Poplatek (Kč) <span class='sb-si'> ↕</span></th>
            <th>Brigády</th>
        </tr></thead><tbody>";

        $radek_cislo = 1;
        foreach ($radky as $z) {
            $trida_rozdil = $z['rozdil'] >= 0 ? 'green' : 'red';
            $brigady_str  = implode('<br>', $z['brigady_seznam']);
            echo "<tr>
                <td class='center sb-rownum'>" . $radek_cislo++ . "</td>
                <td>" . $z['nazev'] . "</td>
                <td class='sb-small'>" . $z['jmena_deti'] . "</td>
                <td class='sb-small'>" . $z['jmena_rodicu'] . "</td>
                <td class='center'>" . $z['pocet_deti'] . "</td>
                <td class='center' data-v='" . intval($z['odpracovano']) . "'>" . $z['odpracovano'] . ($z['prevod'] > 0 ? "<br><small style='color:#777;'>vč. " . intval($z['prevod']) . " h z " . ($vybrany_rok - 1) . "</small>" : "") . "</td>
                <td class='center' data-v='" . intval($z['pozadavek']) . "'>" . $z['pozadavek'] . "</td>
                <td class='center $trida_rozdil' data-v='" . intval($z['rozdil']) . "'>" . intval($z['rozdil']) . "</td>
                <td class='center' data-v='" . $z['poplatek'] . "'>" . number_format($z['poplatek'], 2, ',', ' ') . ($z['vedouci'] ? "<br><small style='color:#777;'>" . esc_html($z['vedouci_popis']) . "</small>" : "") . "</td>
                <td class='sb-small'>$brigady_str</td>
            </tr>";
        }
        echo "</tbody></table>";
        echo "<script>
(function() {
    var tbl = document.getElementById('sb-rocni-tabulka');
    if (!tbl) return;
    var sortCol = -1, sortAsc = true;
    tbl.querySelectorAll('thead th[data-col]').forEach(function(th) {
        th.addEventListener('click', function() {
            var col = parseInt(th.getAttribute('data-col'));
            var type = th.getAttribute('data-type');
            if (sortCol === col) { sortAsc = !sortAsc; } else { sortCol = col; sortAsc = true; }
            tbl.querySelectorAll('thead th .sb-si').forEach(function(s) { s.textContent = ' ↕'; });
            th.querySelector('.sb-si').textContent = sortAsc ? ' ▲' : ' ▼';
            var tbody = tbl.querySelector('tbody');
            var rows = Array.from(tbody.querySelectorAll('tr'));
            rows.sort(function(a, b) {
                var aCell = a.querySelectorAll('td')[col];
                var bCell = b.querySelectorAll('td')[col];
                if (!aCell || !bCell) return 0;
                var aVal = type === 'num' ? parseFloat(aCell.getAttribute('data-v') || 0) : aCell.textContent.trim().toLowerCase();
                var bVal = type === 'num' ? parseFloat(bCell.getAttribute('data-v') || 0) : bCell.textContent.trim().toLowerCase();
                if (aVal < bVal) return sortAsc ? -1 : 1;
                if (aVal > bVal) return sortAsc ? 1 : -1;
                return 0;
            });
            rows.forEach(function(r) { tbody.appendChild(r); });
            tbody.querySelectorAll('tr').forEach(function(r, i) {
                var nc = r.querySelector('.sb-rownum');
                if (nc) nc.textContent = i + 1;
            });
        });
    });
})();
</script>";

        $export_url = add_query_arg([
            'sb_export' => 'rocni_vypis',
            'rok'       => $vybrany_rok,
            '_wpnonce'  => wp_create_nonce('sb_export_rocni_vypis')
        ], get_permalink());
        echo "<div class='sb-btn-row'><a href='" . esc_url($export_url) . "' class='sb-btn sb-btn-primary'>📥 Exportovat CSV</a></div>";
        echo "</div>"; // sb-card
    }

    return ob_get_clean();
}

function sb_prehled_rodin() {
    if (!sb_muze_videt_rodiny()) {
        return '<p>Nemáš oprávnění pro správu rodin.</p>';
    }
    $smi_upravovat = sb_muze_upravovat_rodiny();
    $je_spravce    = sb_je_spravce();
    $revize        = sb_revize_stav();

    $oddily = [
        'Benjamínci',
        'Lišky (světlušky)',
        'Braši (vlčata)',
        'Sněženky (skautky)',
        'Skauti',
        'Rangers a roveři'
    ];
    $max_deti = 4;

    $nacti_rodiny = function() {
        return get_posts([
            'post_type'   => 'rodina',
            'numberposts' => -1,
            'orderby'     => 'title',
            'order'       => 'ASC'
        ]);
    };

    // Rodiče už přiřazení k rodině (i archivované): [user_id => rodina_id]
    $spocitej_obsazeni = function($rodiny) {
        $obsazeni = [];
        foreach ($rodiny as $r) {
            $prirazeni = get_post_meta($r->ID, 'rodice_rodiny', true);
            if (is_array($prirazeni)) {
                foreach ($prirazeni as $rid) {
                    $obsazeni[intval($rid)] = $r->ID;
                }
            }
        }
        return $obsazeni;
    };

    $vsichni_rodice = get_users(['role' => 'rodic']);
    usort($vsichni_rodice, function($a, $b) {
        $la = $a->last_name ?: array_slice(explode(' ', $a->display_name), -1)[0];
        $lb = $b->last_name ?: array_slice(explode(' ', $b->display_name), -1)[0];
        $fa = $a->first_name ?: $a->display_name;
        $fb = $b->first_name ?: $b->display_name;
        $cmp = strcasecmp($la, $lb);
        return $cmp === 0 ? strcasecmp($fa, $fb) : $cmp;
    });

    $zprava      = '';
    $otevrena_id = 0; // karta, která zůstane rozbalená (chyba při uložení)
    $ulozena_id  = 0; // karta, která se po úspěšném uložení zvýrazní (sbalená)

    // ---------------------------------------------------------------
    // Zpracování akcí
    // ---------------------------------------------------------------
    $je_akce = isset($_POST['ulozit_rodinu']) || isset($_POST['archivovat_rodinu']) || isset($_POST['obnovit_rodinu']) || isset($_POST['prevest_na_dospele'])
            || isset($_POST['smazat_dite']) || isset($_POST['confirm_delete_rodina']);

    if ($je_akce) {
        $post_id     = isset($_POST['edit_rodina_id']) ? intval($_POST['edit_rodina_id']) : 0;
        $post_rodina = $post_id ? sb_get_rodina($post_id) : null;
        if ($post_rodina && sb_je_dospely($post_rodina->ID)) $post_rodina = null; // dospělí mají vlastní správu

        if (!$smi_upravovat) {
            $zprava .= sb_alert('Úpravy rodin jsou teď uzavřené. Otevřou se, když správce zahájí revizi.', 'error');
        } elseif (!$post_rodina) {
            $zprava .= sb_alert('Rodina nebyla nalezena.', 'error');
        } else {
            $id = $post_rodina->ID;
            $rodiny_pred = $nacti_rodiny();
            $obsazeni    = $spocitej_obsazeni($rodiny_pred);

            // --- Uložení rodiny (název, rodiče, děti, odchody dětí) ---
            if (isset($_POST['ulozit_rodinu'])) {
                if (!isset($_POST['_wpnonce_ulozit_rodinu']) || !wp_verify_nonce($_POST['_wpnonce_ulozit_rodinu'], 'ulozit_rodinu')) {
                    $zprava .= sb_alert('Neplatný bezpečnostní token.', 'error');
                } else {
                    $nazev = sanitize_text_field(wp_unslash(isset($_POST['novy_nazev']) ? $_POST['novy_nazev'] : ''));
                    $vybrani_rodice = (isset($_POST['rodice']) && is_array($_POST['rodice'])) ? array_map('intval', $_POST['rodice']) : [];

                    // Přiřadit lze jen uživatele s rolí rodič; stávající členy, kteří v nabídce nejsou, zachováme
                    $povolene_ids = array_map(function($u) { return intval($u->ID); }, $vsichni_rodice);
                    $vybrani_rodice = array_values(array_intersect($vybrani_rodice, $povolene_ids));
                    $stavajici_rodice = get_post_meta($id, 'rodice_rodiny', true);
                    $stavajici_rodice = is_array($stavajici_rodice) ? array_map('intval', $stavajici_rodice) : [];
                    $vybrani_rodice = array_values(array_unique(array_merge($vybrani_rodice, array_diff($stavajici_rodice, $povolene_ids))));

                    $konflikt = false;
                    foreach ($vybrani_rodice as $rid) {
                        if (isset($obsazeni[$rid]) && $obsazeni[$rid] != $id) { $konflikt = true; break; }
                    }

                    $stare_aktivni = get_post_meta($id, 'deti_rodiny', true);
                    if (!is_array($stare_aktivni)) $stare_aktivni = [];
                    $stare_aktivni = array_values($stare_aktivni);
                    $archiv        = array_values(sb_deti_archiv($id));

                    $chyba = '';
                    $existujici_nazev = ($nazev !== '' && $nazev !== $post_rodina->post_title) ? sb_post_exists_by_title('rodina', $nazev) : 0;

                    $novy_dosp_kontrola = trim(sanitize_text_field(wp_unslash($_POST['novy_dospely'] ?? '')));
                    $dvojity = $novy_dosp_kontrola !== '' ? sb_najdi_dospeleho_dle_jmena($novy_dosp_kontrola) : null;

                    if ($dvojity) {
                        $chyba = $dvojity->post_title . ' je už veden(a) jako samostatný dospělý člen. Aby se nepočítal(a) dvakrát, použijte ve Správě dospělých členů „Stal se rodičem“.';
                    } elseif ($nazev === '') {
                        $chyba = 'Název rodiny nesmí být prázdný.';
                    } elseif ($existujici_nazev && intval($existujici_nazev) !== $id) {
                        $chyba = 'Rodina s tímto názvem už existuje.';
                    } elseif ($konflikt) {
                        $chyba = 'Někteří vybraní rodiče už patří do jiné rodiny.';
                    }

                    $aktivni       = [];
                    $novy_archiv   = [];
                    $nove_dospele_deti = [];
                    $vracene_dospele = [];
                    $odchody       = 0;
                    $od_roku       = sb_rok_archivace();
                    $dnes          = wp_date('Y-m-d');
                    $radky         = (isset($_POST['deti']) && is_array($_POST['deti'])) ? $_POST['deti'] : [];
                    $odeslane      = [];
                    $zastarale_msg = 'Rodina byla mezitím změněna jiným uživatelem nebo byl formulář odeslán znovu. Načtěte stránku znovu a úpravy zopakujte.';

                    if ($chyba === '') {
                        foreach ($radky as $idx => $radek) {
                            if (!is_array($radek)) continue;
                            $idx      = intval($idx);
                            $existuje = isset($stare_aktivni[$idx]) && is_array($stare_aktivni[$idx]);
                            if ($existuje) $odeslane[] = $idx;

                            // Řádek existujícího dítěte, které už v rodině není (zastaralý nebo opakovaně odeslaný formulář)
                            if (!$existuje && isset($radek['puvodni']) && $radek['puvodni'] !== '') {
                                $chyba = $zastarale_msg;
                                break;
                            }

                            if ($existuje) {
                                // Ochrana proti souběžné úpravě: řádek musí odpovídat uloženému dítěti
                                $puvodni_form = sanitize_text_field(wp_unslash(isset($radek['puvodni']) ? $radek['puvodni'] : ''));
                                $puvodni_db   = isset($stare_aktivni[$idx]['jmeno']) ? $stare_aktivni[$idx]['jmeno'] : '';
                                if ($puvodni_form !== $puvodni_db) {
                                    $chyba = $zastarale_msg;
                                    break;
                                }
                            }

                            $jmeno = trim(sanitize_text_field(wp_unslash(isset($radek['jmeno']) ? $radek['jmeno'] : '')));
                            $oddil = sanitize_text_field(wp_unslash(isset($radek['oddil']) ? $radek['oddil'] : ''));
                            $puvodni_oddil = $existuje && isset($stare_aktivni[$idx]['oddil']) ? $stare_aktivni[$idx]['oddil'] : '';

                            if ($jmeno === '') {
                                if (!$existuje) continue;
                                $jmeno = $puvodni_db;
                            }
                            if (!in_array($oddil, $oddily, true) && $oddil !== $puvodni_oddil) {
                                $oddil = $puvodni_oddil !== '' ? $puvodni_oddil : $oddily[0];
                            }

                            if ($existuje && (!empty($radek['nechodi']) || !empty($radek['dospely']))) {
                                $poznamka = sanitize_text_field(wp_unslash(isset($radek['poznamka']) ? $radek['poznamka'] : ''));
                                $je_dosp  = !empty($radek['dospely']);
                                $novy_archiv[] = [
                                    'jmeno'    => $jmeno,
                                    'oddil'    => $oddil,
                                    'od_roku'  => $od_roku,
                                    'poznamka' => mb_substr($je_dosp ? trim('Dospělý člen. ' . $poznamka) : $poznamka, 0, 200),
                                    'datum'    => $dnes,
                                    'dospely'  => $je_dosp,
                                ];
                                if ($je_dosp) $nove_dospele_deti[] = $jmeno;
                                $odchody++;
                            } else {
                                $aktivni[] = ['jmeno' => $jmeno, 'oddil' => $oddil];
                            }
                        }
                        // Formulář musí obsahovat všechny stávající děti, jinak by nějaké tiše zmizelo
                        if ($chyba === '') {
                            foreach (array_keys($stare_aktivni) as $k) {
                                if (!in_array($k, $odeslane, true)) { $chyba = $zastarale_msg; break; }
                            }
                        }
                    }

                    // Obnovení dětí z archivu
                    $obnovit = (isset($_POST['obnovit']) && is_array($_POST['obnovit'])) ? array_map('intval', $_POST['obnovit']) : [];
                    $zustava_v_archivu = [];
                    foreach ($archiv as $ai => $ad) {
                        if (in_array($ai, $obnovit, true) && is_array($ad)) {
                            if (!empty($ad['dospely'])) $vracene_dospele[] = $ad['jmeno'] ?? '';
                            $aktivni[] = [
                                'jmeno' => isset($ad['jmeno']) ? $ad['jmeno'] : '',
                                'oddil' => isset($ad['oddil']) ? $ad['oddil'] : $oddily[0],
                            ];
                        } else {
                            $zustava_v_archivu[] = $ad;
                        }
                    }

                    if ($chyba === '' && count($aktivni) > $max_deti) {
                        $chyba = 'Rodina může mít nejvýše ' . $max_deti . ' aktivní děti.';
                    }

                    if ($chyba !== '') {
                        $zprava .= sb_alert($chyba, 'error');
                        $otevrena_id = $id;
                    } else {
                        $ulozena_id  = $id;
                        $rok_snimku  = sb_rok_archivace();
                        $pred_snimek = sb_pocet_deti_ze_stavu($id, $rok_snimku);
                        wp_update_post(['ID' => $id, 'post_title' => $nazev]);
                        update_post_meta($id, 'rodice_rodiny', $vybrani_rodice);
                        update_post_meta($id, 'deti_rodiny', array_values($aktivni));
                        $archiv_final = array_merge($zustava_v_archivu, $novy_archiv);
                        if (empty($archiv_final)) {
                            delete_post_meta($id, 'deti_archiv');
                        } else {
                            update_post_meta($id, 'deti_archiv', array_values($archiv_final));
                        }
                        delete_post_meta($id, 'pocet_deti'); // úklid starého pole, pokud existuje
                        sb_uprav_snimek_o_rozdil($id, $rok_snimku, $pred_snimek);

                        // Dospělí členové rodiny (rodiče, kteří jsou sami členy)
                        $dosp = sb_dospeli_rodiny($id);
                        $dosp_archiv = sb_dospeli_archiv($id);
                        $konci = (isset($_POST['dospely_konci']) && is_array($_POST['dospely_konci'])) ? array_map('intval', $_POST['dospely_konci']) : [];
                        $dosp_nove = [];
                        foreach ($dosp as $di => $d) {
                            if (in_array($di, $konci, true)) {
                                $dosp_archiv[] = ['jmeno' => $d['jmeno'], 'od_roku' => intval($d['od_roku'] ?? 0), 'do_roku' => $od_roku, 'datum' => $dnes];
                            } else {
                                $dosp_nove[] = $d;
                            }
                        }
                        $novy_dosp = trim(preg_replace('/\s*\([^)]*\)/u', '', sanitize_text_field(wp_unslash($_POST['novy_dospely'] ?? ''))));
                        if ($novy_dosp !== '') $dosp_nove[] = ['jmeno' => mb_substr($novy_dosp, 0, 100), 'od_roku' => $od_roku];
                        if ($dosp_nove) update_post_meta($id, 'dospeli_clenove', array_values($dosp_nove)); else delete_post_meta($id, 'dospeli_clenove');
                        if ($dosp_archiv) update_post_meta($id, 'dospeli_archiv', array_values($dosp_archiv));

                        // Dítě, které dospělo, se stává samostatným dospělým členem
                        $vytvoreni = [];
                        foreach ($nove_dospele_deti as $jm) {
                            if (sb_vytvorit_dospeleho($jm, [], $od_roku, $id)) $vytvoreni[] = $jm;
                        }
                        // Vrácení omylu: dítě obnovené z „Dospělý člen" – odstraní se záznam, který z něj vznikl
                        foreach ($vracene_dospele as $jm) {
                            $omyl = sb_najdi_dospeleho_dle_jmena($jm);
                            if ($omyl && intval(get_post_meta($omyl->ID, 'sb_z_rodiny', true)) === $id) {
                                $ma_platby = false;
                                foreach (get_post_meta($omyl->ID) as $mk => $mv) if (strpos($mk, 'sb_platby_') === 0) $ma_platby = true;
                                if ($ma_platby) {
                                    update_post_meta($omyl->ID, 'rodina_archiv', ['od_roku' => $od_roku, 'poznamka' => 'Vráceno do rodiny', 'datum' => $dnes]);
                                } else {
                                    wp_delete_post($omyl->ID, true);
                                }
                                $zprava .= sb_alert(esc_html($jm) . ' bylo vráceno do rodiny, samostatný záznam dospělého člena byl zrušen.', 'info');
                            }
                        }
                        $zprava .= sb_alert('Rodina byla upravena.');
                        if ($vytvoreni) {
                            $zprava .= sb_alert('Od roku ' . $od_roku . ' jsou samostatnými dospělými členy: ' . implode(', ', $vytvoreni) . ' (najdete je v záložce Správa dospělých členů).');
                        }

                        // Rodině nezůstalo žádné dítě → archivace; dospělí členové rodiny se osamostatní
                        if (empty($aktivni) && $odchody > 0) {
                            $osamostatneni = sb_osamostatnit_rodinu($id, $od_roku, 'Rodině nezůstalo žádné dítě.');
                            $zprava .= sb_alert('Rodině nezůstalo žádné dítě, proto byla od roku ' . $od_roku . ' přesunuta do archivu.' . ($osamostatneni ? ' Samostatnými dospělými členy se stávají: ' . implode(', ', $osamostatneni) . '.' : ''), 'warning');
                        } elseif (empty($aktivni)) {
                            $zprava .= sb_alert('Rodina nemá žádné aktivní dítě. Pokud už skauta nenavštěvuje nikdo z rodiny, přesuňte celou rodinu do archivu.', 'warning');
                        }
                    }
                }
            }

            // --- Rodina bez dětí → samostatní dospělí členové ---
            if (isset($_POST['prevest_na_dospele'])) {
                if (!isset($_POST['_wpnonce_prevest']) || !wp_verify_nonce($_POST['_wpnonce_prevest'], 'prevest_na_dospele')) {
                    $zprava .= sb_alert('Neplatný bezpečnostní token.', 'error');
                } elseif (sb_rodina_archiv($id)) {
                    $zprava .= sb_alert('Rodina už je v archivu.', 'info');
                } elseif (is_array($dd = get_post_meta($id, 'deti_rodiny', true)) && count($dd) > 0) {
                    $zprava .= sb_alert('Rodina má děti – převést lze jen rodinu bez dětí.', 'error');
                } else {
                    $rx = sb_rok_archivace();
                    $jm = sb_osamostatnit_rodinu($id, $rx, 'Převedeno na dospělé členy.');
                    $zprava .= sb_alert('Rodina byla od roku ' . $rx . ' převedena na samostatné dospělé členy' . ($jm ? ': ' . implode(', ', $jm) : '') . '. Najdete je v záložce Správa dospělých členů.');
                }
            }

            // --- Archivace celé rodiny ---
            if (isset($_POST['archivovat_rodinu'])) {
                if (!isset($_POST['_wpnonce_archivovat_rodinu']) || !wp_verify_nonce($_POST['_wpnonce_archivovat_rodinu'], 'archivovat_rodinu')) {
                    $zprava .= sb_alert('Neplatný bezpečnostní token.', 'error');
                } elseif (sb_rodina_archiv($id)) {
                    $zprava .= sb_alert('Rodina už je v archivu.', 'info');
                } else {
                    $poznamka = sanitize_text_field(wp_unslash(isset($_POST['archiv_poznamka']) ? $_POST['archiv_poznamka'] : ''));
                    update_post_meta($id, 'rodina_archiv', [
                        'od_roku'  => sb_rok_archivace(),
                        'poznamka' => mb_substr($poznamka, 0, 200),
                        'datum'    => wp_date('Y-m-d'),
                    ]);
                    $zprava .= sb_alert('Rodina byla přesunuta do archivu. Od roku ' . sb_rok_archivace() . ' se jí nebudou počítat požadavky na brigády.');
                }
            }

            // --- Obnovení rodiny z archivu ---
            if (isset($_POST['obnovit_rodinu'])) {
                if (!isset($_POST['_wpnonce_obnovit_rodinu']) || !wp_verify_nonce($_POST['_wpnonce_obnovit_rodinu'], 'obnovit_rodinu')) {
                    $zprava .= sb_alert('Neplatný bezpečnostní token.', 'error');
                } else {
                    $rodice_rodiny = get_post_meta($id, 'rodice_rodiny', true);
                    $konflikt = false;
                    if (is_array($rodice_rodiny)) {
                        foreach ($rodice_rodiny as $rid) {
                            if (isset($obsazeni[intval($rid)]) && $obsazeni[intval($rid)] != $id) { $konflikt = true; break; }
                        }
                    }
                    if ($konflikt) {
                        $zprava .= sb_alert('Rodinu nelze obnovit – někteří její rodiče jsou už přiřazeni k jiné rodině.', 'error');
                    } else {
                        delete_post_meta($id, 'rodina_archiv');
                        $zprava .= sb_alert('Rodina byla obnovena z archivu.');
                        $ulozena_id = $id;
                    }
                }
            }

            // --- Trvalé odebrání dítěte (jen správce) ---
            if (isset($_POST['smazat_dite'])) {
                if (!$je_spravce) {
                    $zprava .= sb_alert('Trvalé odebrání dítěte může provést jen správce.', 'error');
                } elseif (!isset($_POST['_wpnonce_smazat_dite']) || !wp_verify_nonce($_POST['_wpnonce_smazat_dite'], 'smazat_dite')) {
                    $zprava .= sb_alert('Neplatný bezpečnostní token.', 'error');
                } else {
                    $index = intval($_POST['smazat_dite']);
                    $deti  = get_post_meta($id, 'deti_rodiny', true);
                    if (is_array($deti) && isset($deti[$index])) {
                        $rok_snimku  = sb_rok_archivace();
                        $pred_snimek = sb_pocet_deti_ze_stavu($id, $rok_snimku);
                        unset($deti[$index]);
                        update_post_meta($id, 'deti_rodiny', array_values($deti));
                        sb_uprav_snimek_o_rozdil($id, $rok_snimku, $pred_snimek);
                        $zprava .= sb_alert('Dítě bylo odebráno.');
                        $otevrena_id = $id;
                    }
                }
            }

            // --- Smazání rodiny (jen správce) ---
            if (isset($_POST['confirm_delete_rodina'])) {
                if (!$je_spravce) {
                    $zprava .= sb_alert('Smazat rodinu může jen správce.', 'error');
                } elseif (!isset($_POST['_wpnonce_smazat_rodinu']) || !wp_verify_nonce($_POST['_wpnonce_smazat_rodinu'], 'smazat_rodinu')
                          || intval($_POST['confirm_delete_rodina']) !== $id) {
                    $zprava .= sb_alert('Neplatný bezpečnostní token.', 'error');
                } else {
                    wp_delete_post($id, true);
                    $zprava .= sb_alert('Rodina byla smazána.');
                }
            }
        }
        $zprava .= "<script>setTimeout(function() { sbShowSection('prehled'); }, 100);</script>";
    }

    // ---------------------------------------------------------------
    // Zobrazení
    // ---------------------------------------------------------------
    $rodiny   = $nacti_rodiny();
    $obsazeni = $spocitej_obsazeni($rodiny);

    $plne_jmeno = function($u) {
        $j = trim($u->last_name . ' ' . $u->first_name);
        return $j !== '' ? $j : $u->display_name;
    };

    $aktivni_rodiny   = [];
    $archivovane      = [];
    $deti_celkem      = 0;
    foreach ($rodiny as $r) {
        if (sb_je_dospely($r->ID)) continue; // dospělí členové mají vlastní záložku
        if (sb_rodina_archiv($r->ID)) { $archivovane[] = $r; continue; }
        $aktivni_rodiny[] = $r;
        $d = get_post_meta($r->ID, 'deti_rodiny', true);
        $deti_celkem += is_array($d) ? count($d) : 0;
    }

    ob_start();
    ?>
    <style>
        .sb-rod-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:12px; margin-top:12px; }
        .sb-rod-card { box-sizing:border-box; min-width:0; max-width:100%; background:#fff; border:1px solid #d5dde5; border-radius:6px; padding:12px 14px; overflow-wrap:anywhere; }
        .sb-rod-card.sb-rod-off { background:#f6f6f6; border-style:dashed; }
        .sb-rod-card.sb-rod-ulozeno { border-color:#2e7d32; box-shadow:0 0 0 2px #c8e6c9; transition:box-shadow 1s, border-color 1s; }
        .sb-rod-head { font-size:15px; font-weight:700; color:#0073aa; margin-bottom:6px; }
        .sb-rod-line { font-size:13px; color:#444; margin-bottom:6px; }
        .sb-chips { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:8px; }
        .sb-chip { display:inline-block; background:#e8f4fb; border:1px solid #b0d4ea; border-radius:14px; padding:2px 10px; font-size:12px; line-height:1.6; }
        .sb-chip small { color:#555; }
        .sb-chip-off { background:#eee; border-color:#ccc; color:#777; text-decoration:line-through; }
        .sb-rod-edit { margin-top:8px; }
        .sb-rod-edit > summary, .sb-archiv > summary { cursor:pointer; font-size:13px; font-weight:600; color:#0073aa; padding:4px 0; }
        .sb-rod-sekce { margin:12px 0 4px; font-weight:600; font-size:13px; color:#444; }
        .sb-dite-row { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin-bottom:8px; padding:8px; border:1px solid #e5e5e5; border-radius:4px; background:#fafafa; box-sizing:border-box; max-width:100%; }
        .sb-dite-row input[type=text], .sb-dite-row select { flex:1 1 140px; min-width:0; max-width:100%; }
        .sb-dite-row .sb-nechodi { flex:0 0 auto; font-size:12px; color:#7a1c1e; white-space:nowrap; }
        .sb-rod-check { display:block; font-weight:normal; font-size:13px; margin-bottom:4px; }
        .sb-rod-toolbar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
        .sb-rod-toolbar input[type=text] { flex:1 1 220px; min-width:0; max-width:420px; }
        .sb-rod-edit form, .sb-rod-edit .sb-rod-inline { max-width:100%; }
        @media (max-width: 640px) {
            .sb-rod-grid { grid-template-columns:minmax(0, 1fr); }
        }
    </style>
    <?php
    echo "<h3 class='sb-section-title'>👥 Správa rodin</h3>";
    echo $zprava;

    if ($revize['otevrena']) {
        echo sb_alert('Revize rodin je otevřená. Změny (odchody dětí, archivace rodin) se projeví od roku ' . intval($revize['rok']) . '.', 'warning');
    } elseif (!$smi_upravovat) {
        echo sb_alert('Rodiny můžete teď jen prohlížet. Úpravy se otevřou, když správce zahájí revizi rodin.', 'info');
    }

    echo "<div class='sb-card'>";
    echo "<div class='sb-rod-toolbar'>";
    echo "<input type='text' id='sb-rod-hledat' placeholder='🔍 Hledat rodinu, rodiče nebo dítě…' autocomplete='off'>";
    echo "<span class='sb-text-hint'>Rodin: " . count($aktivni_rodiny) . " · aktivních dětí: " . intval($deti_celkem) . "</span>";
    echo "</div>";
    echo "</div>";

    $vykresli_kartu = function($r, $archivni) use ($oddily, $max_deti, $smi_upravovat, $je_spravce, $vsichni_rodice, $obsazeni, $otevrena_id, $ulozena_id, $plne_jmeno) {
        $deti = get_post_meta($r->ID, 'deti_rodiny', true);
        if (!is_array($deti)) $deti = [];
        $deti = array_values($deti);
        $archiv_deti = array_values(sb_deti_archiv($r->ID));
        $prirazeni = get_post_meta($r->ID, 'rodice_rodiny', true);
        if (!is_array($prirazeni)) $prirazeni = [];
        $info_archiv = sb_rodina_archiv($r->ID);

        $rodice_html = [];
        $hledat      = [mb_strtolower($r->post_title)];
        foreach ($prirazeni as $rid) {
            $u = get_userdata(intval($rid));
            if ($u) {
                $rodice_html[] = sb_format_rodic_jmeno($u);
                $hledat[]      = mb_strtolower($plne_jmeno($u));
            }
        }
        foreach ($deti as $d) {
            if (is_array($d) && isset($d['jmeno'])) $hledat[] = mb_strtolower($d['jmeno']);
        }
        foreach ($archiv_deti as $d) {
            if (is_array($d) && isset($d['jmeno'])) $hledat[] = mb_strtolower($d['jmeno']);
        }

        echo "<div id='sb-rodina-" . intval($r->ID) . "' class='sb-rod-card" . ($archivni ? " sb-rod-off" : "") . ($ulozena_id === $r->ID ? " sb-rod-ulozeno" : "") . "' data-hledat='" . esc_attr(implode(' ', $hledat)) . "'>";
        echo "<div class='sb-rod-head'>" . esc_html($r->post_title) . "</div>";
        echo "<div class='sb-rod-line'>👤 " . (!empty($rodice_html) ? implode(', ', $rodice_html) : "<em>bez přiřazených rodičů</em>") . "</div>";

        echo "<div class='sb-chips'>";
        if (empty($deti) && empty($archiv_deti)) {
            echo "<span class='sb-text-hint'>Žádné děti</span>";
        }
        foreach ($deti as $d) {
            if (!is_array($d)) continue;
            echo "<span class='sb-chip'>" . esc_html(isset($d['jmeno']) ? $d['jmeno'] : '') . " <small>(" . esc_html(isset($d['oddil']) ? $d['oddil'] : '') . ")</small></span>";
        }
        $klice_rucni = [];
        foreach (sb_dospeli_rodiny($r->ID) as $d) {
            $klice_rucni[] = sb_jmeno_klic($d['jmeno']);
            echo "<span class='sb-chip' style='background:#eef3ff; border-color:#b6c6ee;'>" . esc_html($d['jmeno']) . " <small>(dospělý člen)</small></span>";
        }
        foreach (sb_vedouci_rodice($r->ID) as $j) {
            if (in_array(sb_jmeno_klic($j), $klice_rucni, true)) continue;
            echo "<span class='sb-chip' style='background:#eef3ff; border-color:#b6c6ee;'>" . esc_html($j) . " <small>(vedoucí – člen automaticky)</small></span>";
        }
        foreach ($archiv_deti as $d) {
            if (!is_array($d)) continue;
            $titulek = 'Od roku ' . intval(isset($d['od_roku']) ? $d['od_roku'] : 0) . (!empty($d['poznamka']) ? ' – ' . $d['poznamka'] : '');
            echo "<span class='sb-chip sb-chip-off' title='" . esc_attr($titulek) . "'>" . esc_html(isset($d['jmeno']) ? $d['jmeno'] : '') . "</span>";
        }
        echo "</div>";

        if ($archivni && $info_archiv) {
            echo "<div class='sb-text-hint'>V archivu od roku " . intval($info_archiv['od_roku']) . (!empty($info_archiv['poznamka']) ? " – " . esc_html($info_archiv['poznamka']) : "") . "</div>";
        }

        if ($smi_upravovat) {
            if ($archivni) {
                echo "<form method='post' class='sb-rod-inline' style='margin-top:8px;'>";
                wp_nonce_field('obnovit_rodinu', '_wpnonce_obnovit_rodinu');
                echo "<input type='hidden' name='edit_rodina_id' value='" . intval($r->ID) . "'>";
                echo "<input type='submit' name='obnovit_rodinu' value='↩️ Obnovit z archivu' class='sb-btn sb-btn-secondary sb-btn-sm'>";
                echo "</form>";
            } else {
                echo "<details class='sb-rod-edit'" . ($otevrena_id === $r->ID ? " open" : "") . "><summary>✏️ Upravit</summary>";
                echo "<form method='post'>";
                wp_nonce_field('ulozit_rodinu', '_wpnonce_ulozit_rodinu');
                echo "<input type='hidden' name='edit_rodina_id' value='" . intval($r->ID) . "'>";

                echo "<div class='sb-rod-sekce'>Název rodiny</div>";
                echo "<input type='text' name='novy_nazev' value='" . esc_attr($r->post_title) . "' required style='width:100%; max-width:100%;'>";

                echo "<div class='sb-rod-sekce'>Rodiče</div>";
                foreach ($vsichni_rodice as $u) {
                    $je_clen  = in_array($u->ID, $prirazeni);
                    $je_jinde = isset($obsazeni[$u->ID]) && $obsazeni[$u->ID] != $r->ID;
                    if ($je_jinde) continue;
                    echo "<label class='sb-rod-check'><input type='checkbox' name='rodice[]' value='" . esc_attr($u->ID) . "'" . ($je_clen ? " checked" : "") . "> " . sb_format_rodic_jmeno($u) . "</label>";
                }

                echo "<div class='sb-rod-sekce'>Děti (nejvýše " . intval($max_deti) . ")</div>";
                $radku = max(count($deti), 0);
                for ($i = 0; $i < $radku; $i++) {
                    $jm = isset($deti[$i]['jmeno']) ? $deti[$i]['jmeno'] : '';
                    $od = isset($deti[$i]['oddil']) ? $deti[$i]['oddil'] : '';
                    echo "<div class='sb-dite-row'>";
                    echo "<input type='hidden' name='deti[$i][puvodni]' value='" . esc_attr($jm) . "'>";
                    echo "<input type='text' name='deti[$i][jmeno]' value='" . esc_attr($jm) . "' placeholder='Jméno dítěte' required>";
                    echo "<select name='deti[$i][oddil]'>";
                    $moznosti = $oddily;
                    if ($od !== '' && !in_array($od, $moznosti, true)) array_unshift($moznosti, $od);
                    foreach ($moznosti as $o) {
                        echo "<option value='" . esc_attr($o) . "'" . ($o === $od ? " selected" : "") . ">" . esc_html($o) . "</option>";
                    }
                    echo "</select>";
                    echo "<label class='sb-nechodi'><input type='checkbox' name='deti[$i][nechodi]' value='1' class='sb-volba-ditete'> Už nechodí</label>";
                    echo "<label class='sb-nechodi' style='color:#0b5394;'><input type='checkbox' name='deti[$i][dospely]' value='1' class='sb-volba-ditete'> Dospělý člen</label>";
                    echo "<input type='text' name='deti[$i][poznamka]' maxlength='200' placeholder='Poznámka k odchodu (volitelné)'>";
                    echo "</div>";
                }
                for ($i = $radku; $i < $max_deti; $i++) {
                    echo "<div class='sb-dite-row'>";
                    echo "<input type='text' name='deti[$i][jmeno]' value='' placeholder='Přidat dítě – jméno'>";
                    echo "<select name='deti[$i][oddil]'>";
                    foreach ($oddily as $o) {
                        echo "<option value='" . esc_attr($o) . "'>" . esc_html($o) . "</option>";
                    }
                    echo "</select></div>";
                }

                if (!empty($archiv_deti)) {
                    echo "<div class='sb-rod-sekce'>Děti v archivu</div>";
                    foreach ($archiv_deti as $ai => $d) {
                        if (!is_array($d)) continue;
                        $popis = esc_html(isset($d['jmeno']) ? $d['jmeno'] : '') . " (" . esc_html(isset($d['oddil']) ? $d['oddil'] : '') . ") – od roku " . intval(isset($d['od_roku']) ? $d['od_roku'] : 0);
                        if (!empty($d['poznamka'])) $popis .= " – " . esc_html($d['poznamka']);
                        if (!empty($d['dospely'])) {
                            echo "<label class='sb-rod-check'><input type='checkbox' name='obnovit[]' value='" . intval($ai) . "'> Vrátit do rodiny (byl to omyl): $popis <span class='sb-text-hint'>(teď samostatný dospělý člen)</span></label>";
                        } else {
                            echo "<label class='sb-rod-check'><input type='checkbox' name='obnovit[]' value='" . intval($ai) . "'> Obnovit: $popis</label>";
                        }
                    }
                }

                // Dospělí členové rodiny (rodiče, kteří jsou sami členy skauta)
                echo "<div class='sb-rod-sekce'>Dospělí členové v rodině (rodič, který je sám členem skauta)</div>";
                foreach (sb_dospeli_rodiny($r->ID) as $di => $d) {
                    echo "<label class='sb-rod-check'>" . esc_html($d['jmeno']) . " <span class='sb-text-hint'>(člen od roku " . intval($d['od_roku'] ?? 0) . ")</span> &nbsp; <input type='checkbox' name='dospely_konci[]' value='" . intval($di) . "'> už není členem</label>";
                }
                foreach (sb_vedouci_rodice($r->ID) as $j) {
                    echo "<div class='sb-rod-check'>" . esc_html($j) . " <span class='sb-text-hint'>(vedoucí – člen automaticky, nezadávejte)</span></div>";
                }
                echo "<input type='text' name='novy_dospely' maxlength='100' placeholder='Přidat dospělého člena – příjmení a jméno' style='width:100%; max-width:100%;'>";

                echo "<div class='sb-btn-row'><input type='submit' name='ulozit_rodinu' value='💾 Uložit rodinu' class='sb-btn sb-btn-primary'></div>";
                echo "</form>";

                if (empty($deti) && (sb_dospeli_rodiny($r->ID) || sb_vedouci_rodice($r->ID))) {
                    echo "<div class='sb-action-section'><form method='post' class='sb-rod-inline' onsubmit='return confirm(\"Převést rodinu bez dětí na samostatné dospělé členy? Rodina se archivuje a každý dospělý člen bude veden zvlášť (i se svým účtem).\")'>";
                    wp_nonce_field('prevest_na_dospele', '_wpnonce_prevest');
                    echo "<input type='hidden' name='edit_rodina_id' value='" . intval($r->ID) . "'>";
                    echo "<div class='sb-rod-sekce' style='margin-top:0;'>Rodina nemá děti</div>";
                    echo "<input type='submit' name='prevest_na_dospele' value='🧑 Převést na dospělé členy' class='sb-btn sb-btn-primary'>";
                    echo "</form></div>";
                }
                echo "<div class='sb-action-section'>";
                echo "<form method='post' class='sb-rod-inline' onsubmit='return confirm(\"Přesunout celou rodinu do archivu? Od příštího roku se jí nebudou počítat požadavky na brigády.\")'>";
                wp_nonce_field('archivovat_rodinu', '_wpnonce_archivovat_rodinu');
                echo "<input type='hidden' name='edit_rodina_id' value='" . intval($r->ID) . "'>";
                echo "<div class='sb-rod-sekce' style='margin-top:0;'>Celá rodina už skauta nenavštěvuje</div>";
                echo "<input type='text' name='archiv_poznamka' maxlength='200' placeholder='Poznámka (volitelné)' style='width:100%; max-width:100%; margin-bottom:6px;'>";
                echo "<input type='submit' name='archivovat_rodinu' value='📦 Přesunout rodinu do archivu' class='sb-btn sb-btn-secondary'>";
                echo "</form></div>";

                if ($je_spravce) {
                    echo "<div class='sb-action-section'><div class='sb-rod-sekce' style='margin-top:0;'>Jen pro správce – trvalé smazání</div>";
                    foreach ($deti as $idx => $dite) {
                        $popis = esc_html((isset($dite['jmeno']) ? $dite['jmeno'] : '') . ' – ' . (isset($dite['oddil']) ? $dite['oddil'] : ''));
                        echo "<form method='post' onsubmit='return confirm(\"Trvale odebrat dítě? Data se smažou.\")' style='display:inline-block; margin:0 6px 6px 0;'>";
                        wp_nonce_field('smazat_dite', '_wpnonce_smazat_dite');
                        echo "<input type='hidden' name='edit_rodina_id' value='" . intval($r->ID) . "'>";
                        echo "<input type='hidden' name='smazat_dite' value='" . intval($idx) . "'>";
                        echo "<input type='submit' value='🗑️ {$popis}' class='sb-btn sb-btn-danger sb-btn-sm'>";
                        echo "</form>";
                    }
                    echo "<form method='post' onsubmit='return confirm(\"Opravdu trvale smazat celou rodinu?\")' style='margin-top:6px;'>";
                    wp_nonce_field('smazat_rodinu', '_wpnonce_smazat_rodinu');
                    echo "<input type='hidden' name='edit_rodina_id' value='" . intval($r->ID) . "'>";
                    echo "<input type='hidden' name='confirm_delete_rodina' value='" . intval($r->ID) . "'>";
                    echo "<input type='submit' value='🗑️ Smazat rodinu' class='sb-btn sb-btn-danger sb-btn-sm'>";
                    echo "</form></div>";
                }
                echo "</details>";
            }
        }
        echo "</div>"; // .sb-rod-card
    };

    echo "<div class='sb-rod-grid'>";
    foreach ($aktivni_rodiny as $r) {
        $vykresli_kartu($r, false);
    }
    echo "</div>";
    if (empty($aktivni_rodiny)) {
        echo "<p class='sb-text-muted'>Zatím nejsou žádné rodiny.</p>";
    }

    if (!empty($archivovane)) {
        echo "<details class='sb-archiv' style='margin-top:20px;'><summary>📦 Archiv rodin (" . count($archivovane) . ")</summary>";
        echo "<div class='sb-rod-grid'>";
        foreach ($archivovane as $r) {
            $vykresli_kartu($r, true);
        }
        echo "</div></details>";
    }

    if ($ulozena_id) {
        // Po uložení přejít na uloženou (sbalenou) kartu a zvýraznění po chvíli zrušit
        echo "<script>setTimeout(function() {
    var k = document.getElementById('sb-rodina-" . intval($ulozena_id) . "');
    if (!k) return;
    k.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(function() { k.classList.remove('sb-rod-ulozeno'); }, 4000);
}, 400);</script>";
    }

    echo "<script>
document.addEventListener('change', function(e) {
    var cb = e.target;
    if (!cb.classList || !cb.classList.contains('sb-volba-ditete') || !cb.checked) return;
    cb.closest('.sb-dite-row').querySelectorAll('.sb-volba-ditete').forEach(function(o) { if (o !== cb) o.checked = false; });
});
(function() {
    var inp = document.getElementById('sb-rod-hledat');
    if (!inp) return;
    inp.addEventListener('input', function() {
        var q = inp.value.toLowerCase().trim();
        document.querySelectorAll('.sb-rod-card[data-hledat]').forEach(function(c) {
            c.style.display = (!q || c.getAttribute('data-hledat').indexOf(q) > -1) ? '' : 'none';
        });
    });
})();
</script>";

    return ob_get_clean();
}

function sb_moje_brigady_shortcode() {
    $user_id = get_current_user_id();
    if (!$user_id) return '<p>Musíš být přihlášen(a).</p>';

    // Najdeme rodinu, do které je rodič přiřazen
    $rodiny = get_posts([
        'post_type'   => 'rodina',
        'numberposts' => -1,
        'meta_query'  => [[
            'key'     => 'rodice_rodiny',
            'compare' => 'EXISTS'
        ]]
    ]);

    $rodina = null;
    foreach ($rodiny as $r) {
        $rodice = get_post_meta($r->ID, 'rodice_rodiny', true);
        if ((is_array($rodice) && in_array($user_id, $rodice)) || $rodice == $user_id) {
            // Aktivní (nearchivovaná) rodina má přednost před archivovanou
            if (!$rodina) $rodina = $r;
            if (!sb_rodina_archiv($r->ID)) {
                $rodina = $r;
                break;
            }
        }
    }

    if (!$rodina) return '<p>Nemáš přiřazenou žádnou rodinu.</p>';

    $rodina_id    = $rodina->ID;
    $nazev_rodiny = esc_html($rodina->post_title);

    // --- Zpracování formulářů s hláškami ---
    $zprava = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['_wpnonce_sb_moje_brigady']) ||
            !wp_verify_nonce($_POST['_wpnonce_sb_moje_brigady'], 'sb_moje_brigady')) {
            $zprava = "<p style='color:red;'>Neplatný bezpečnostní token. Zkuste to prosím znovu.</p>";
        } else {
            if (isset($_POST['prihlasit_brigadu']) && intval($_POST['brigada_id'])) {
                $id    = intval($_POST['brigada_id']);
                $pocet = max(1, intval($_POST['pocet_osob']));
                $potrebujeme = intval(get_post_meta($id, 'pozadovany_pocet', true));
                $celkem_uz = 0;
                foreach (get_post_meta($id) as $mk => $mv) {
                    if (strpos($mk, 'ucastnici_') === 0 && isset($mv[0]) && $mv[0] !== '') {
                        $celkem_uz += intval($mv[0]);
                    }
                }
                if ($potrebujeme > 0 && $celkem_uz + $pocet > $potrebujeme) {
                    $zprava = "<p style='color:red;'>Brigáda je již plná – přihlášeno $celkem_uz z $potrebujeme míst.</p>";
                } else {
                    update_post_meta($id, 'ucastnici_' . $rodina_id, $pocet);
                    wp_cache_delete($id, 'post_meta');
                    sb_notify_prihlaseni($id, $rodina_id, $pocet);
                    sb_notify_spravci_zmena($id, 'Rodina ' . get_the_title($rodina_id) . ' se přihlásila (' . $pocet . ' ' . ($pocet === 1 ? 'osoba' : ($pocet <= 4 ? 'osoby' : 'osob')) . ').');
                    $zprava = "<p style='color:green;'>Byli jste přihlášeni na brigádu.</p>";
                }
            }

            if (isset($_POST['upravit_brigadu']) && intval($_POST['brigada_id'])) {
                $id    = intval($_POST['brigada_id']);
                $pocet = max(1, intval($_POST['pocet_osob']));
                update_post_meta($id, 'ucastnici_' . $rodina_id, $pocet);
                wp_cache_delete($id, 'post_meta');
                sb_notify_zmena($id, $rodina_id, $pocet);
                sb_notify_spravci_zmena($id, 'Rodina ' . get_the_title($rodina_id) . ' změnila počet účastníků na ' . $pocet . '.');
                $zprava = "<p style='color:green;'>Přihláška byla upravena.</p>";
            }

            if (isset($_POST['odhlasit_brigadu']) && intval($_POST['brigada_id'])) {
                $id = intval($_POST['brigada_id']);
                delete_post_meta($id, 'ucastnici_' . $rodina_id);
                wp_cache_delete($id, 'post_meta');
                sb_notify_odhlaseni($id, $rodina_id);
                sb_notify_spravci_zmena($id, 'Rodina ' . get_the_title($rodina_id) . ' se odhlásila.');
                $zprava = "<p style='color:orange;'>Byli jste odhlášeni z brigády.</p>";
            }

            // Sponzorský dar k členskému příspěvku
            if (isset($_POST['sb_ulozit_dar'])) {
                $rok_daru = intval($_POST['sb_dar_rok'] ?? 0);
                $info_daru = sb_prispevek_aktivni($rok_daru) ? sb_prispevek_rodiny($rodina, $rok_daru) : null;
                if ($info_daru && $info_daru['stav'] === 'zaplaceno') {
                    $zprava = "<p style='color:red;'>Příspěvek je už uhrazený, dar k němu nelze změnit. Pokud chcete přispět, napište nám prosím na info@skautchlumec.cz.</p>";
                } elseif ($info_daru) {
                    $dar = max(0, min(1000000, intval($_POST['sb_dar'] ?? 0)));
                    if ($dar > 0) {
                        update_post_meta($rodina_id, 'sb_dar_' . $rok_daru, $dar);
                        $zprava = "<p style='color:green;'>Děkujeme! Dar " . esc_html(sb_kc($dar)) . " byl přidán k příspěvku, částka i QR kód se upravily.</p>";
                    } else {
                        delete_post_meta($rodina_id, 'sb_dar_' . $rok_daru);
                        $zprava = "<p style='color:green;'>Dar byl z příspěvku odebrán.</p>";
                    }
                }
            }
        }
    }

    // --- Seznam dětí ---
    $deti       = get_post_meta($rodina_id, 'deti_rodiny', true);
    $pocet_deti = is_array($deti) ? count($deti) : 0; // aktuální počet (pro zobrazení)

    // --- Roční logika ---
    $tz        = wp_timezone();
    $dnes      = new DateTime('today', $tz);
    $mesic     = intval($dnes->format('m'));
    $rok_nyni  = intval($dnes->format('Y'));

    if ($mesic === 12) {
        $rok_aktivni = $rok_nyni;
        $rok_druhy   = $rok_nyni + 1;
    } elseif ($mesic <= 2) {
        $rok_aktivni = $rok_nyni;
        $rok_druhy   = $rok_nyni - 1;
    } else {
        $rok_aktivni = $rok_nyni;
        $rok_druhy   = null;
    }

    $rodina_obj      = get_post($rodina_id);
    $v_aktivnim_roce = sb_rodina_v_roce($rodina_obj, $rok_aktivni);
    $v_druhem_roce   = $rok_druhy ? sb_rodina_v_roce($rodina_obj, $rok_druhy) : false;

    // --- Požadavky pro aktivní rok ---
    $pozadavky_vse = get_option('pozadavky_dle_roku', []);
    $pozadavky_rok = isset($pozadavky_vse[$rok_aktivni]) ? $pozadavky_vse[$rok_aktivni] : null;

    $pozadavek = 0;
    $sazba     = 0;

    if ($pozadavky_rok && $v_aktivnim_roce) {
        $pozadavek = sb_pozadavek_hodin($rodina_id, $rok_aktivni, $pozadavky_rok);
        $sazba = isset($pozadavky_rok['sazba']) ? floatval($pozadavky_rok['sazba']) : 0;
    }

    // --- Výpočet odpracovaných hodin podle roku ---
    $brigady              = get_posts(['post_type' => 'brigada', 'numberposts' => -1]);
    $odpracovano_aktivni  = 0;
    $odpracovano_druhy    = 0;
    $zaznamy_absolvovane  = [];
    $zaznamy_nadchazejici = [];
    $zaznamy_neprihlasene = [];

    foreach ($brigady as $b) {
        $datum_raw           = get_post_meta($b->ID, 'datum_brigady', true);
        $datum_obj           = DateTime::createFromFormat('Y-m-d', $datum_raw, $tz);
        $prihlaseno           = get_post_meta($b->ID, 'ucastnici_' . $rodina_id, true);
        $celkem_hodin_brigady = sb_celkem_hodin($b->ID, $rodina_id);

        // Výpočet podle roku brigády
        if ($celkem_hodin_brigady !== null) {
            $rok_brigady = null;
            if ($datum_obj) {
                $rok_brigady = intval($datum_obj->format('Y'));
            } elseif (preg_match('/\b(19|20)\d{2}\b/', $datum_raw, $m)) {
                $rok_brigady = intval($m[0]);
            }
            if ($rok_brigady === $rok_aktivni) {
                $odpracovano_aktivni += $celkem_hodin_brigady;
            } elseif ($rok_druhy && $rok_brigady === $rok_druhy) {
                $odpracovano_druhy += $celkem_hodin_brigady;
            }
        }

        // Rozdělení na minulost/budoucnost
        if ($datum_obj && $datum_obj < $dnes) {
            if ($celkem_hodin_brigady !== null) {
                $n_osob_abs = max(1, intval($prihlaseno));
                $hodiny_os_raw  = get_post_meta($b->ID, 'odpracovano_' . $rodina_id, true);
                $hodiny_osob_arr = get_post_meta($b->ID, 'hodiny_osob_' . $rodina_id, true);
                $zaznamy_absolvovane[] = [
                    'nazev'       => $b->post_title,
                    'datum'       => $datum_raw,
                    'hodiny'      => ($hodiny_os_raw !== '' && $hodiny_os_raw !== null) ? intval($hodiny_os_raw) : null,
                    'hodiny_osob' => (is_array($hodiny_osob_arr) && !empty($hodiny_osob_arr)) ? $hodiny_osob_arr : null,
                    'pocet_osob'  => $n_osob_abs,
                    'celkem_h'    => $celkem_hodin_brigady,
                ];
            } else {
                $walkins_b = get_post_meta($b->ID, 'walkin_ucastnici', true);
                if (is_array($walkins_b)) {
                    foreach ($walkins_b as $w) {
                        if (isset($w['jmeno']) && $w['jmeno'] === $rodina->post_title) {
                            $walkin_h    = intval($w['pocet']) * intval($w['hodiny']);
                            $rok_brigady = $datum_obj ? intval($datum_obj->format('Y')) : null;
                            if ($rok_brigady === $rok_aktivni) $odpracovano_aktivni += $walkin_h;
                            elseif ($rok_druhy && $rok_brigady === $rok_druhy) $odpracovano_druhy += $walkin_h;
                            $zaznamy_absolvovane[] = [
                                'nazev'       => $b->post_title,
                                'datum'       => $datum_raw,
                                'hodiny'      => intval($w['hodiny']),
                                'hodiny_osob' => null,
                                'pocet_osob'  => intval($w['pocet']),
                                'celkem_h'    => $walkin_h,
                            ];
                            break;
                        }
                    }
                }
            }
        } else {
            // Celkový počet přihlášených přes všechny rodiny
            $vsechna_meta       = get_post_meta($b->ID);
            $celkem_prihlasenych = 0;
            foreach ($vsechna_meta as $mk => $mv) {
                if (strpos($mk, 'ucastnici_') === 0 && isset($mv[0]) && $mv[0] !== '') {
                    $celkem_prihlasenych += intval($mv[0]);
                }
            }
            $potrebujeme_b = intval(get_post_meta($b->ID, 'pozadovany_pocet', true));
            $je_plno = ($potrebujeme_b > 0 && $celkem_prihlasenych >= $potrebujeme_b);

            if ($prihlaseno) {
                $zaznamy_nadchazejici[] = [
                    'id'                  => $b->ID,
                    'nazev'               => $b->post_title,
                    'datum'               => $datum_raw,
                    'pocet_osob'          => intval($prihlaseno),
                    'celkem_prihlasenych' => $celkem_prihlasenych,
                    'potrebujeme'         => $potrebujeme_b,
                    'je_plno'             => $je_plno,
                ];
            } else {
                $walkins_dnes = get_post_meta($b->ID, 'walkin_ucastnici', true);
                $walkin_found = false;
                if (is_array($walkins_dnes)) {
                    foreach ($walkins_dnes as $w) {
                        if (isset($w['jmeno']) && $w['jmeno'] === $rodina->post_title) {
                            $walkin_h    = intval($w['pocet']) * intval($w['hodiny']);
                            $rok_brigady = $datum_obj ? intval($datum_obj->format('Y')) : null;
                            if ($rok_brigady === $rok_aktivni) $odpracovano_aktivni += $walkin_h;
                            elseif ($rok_druhy && $rok_brigady === $rok_druhy) $odpracovano_druhy += $walkin_h;
                            $zaznamy_absolvovane[] = [
                                'nazev'       => $b->post_title,
                                'datum'       => $datum_raw,
                                'hodiny'      => intval($w['hodiny']),
                                'hodiny_osob' => null,
                                'pocet_osob'  => intval($w['pocet']),
                                'celkem_h'    => $walkin_h,
                            ];
                            $walkin_found = true;
                            break;
                        }
                    }
                }
                if (!$walkin_found) {
                    $zaznamy_neprihlasene[] = [
                        'id'                  => $b->ID,
                        'nazev'               => $b->post_title,
                        'datum'               => $datum_raw,
                        'celkem_prihlasenych' => $celkem_prihlasenych,
                        'potrebujeme'         => $potrebujeme_b,
                        'je_plno'             => $je_plno,
                    ];
                }
            }
        }
    }

    $prevod_aktivni = sb_prevod_hodin($rodina_obj, $rok_aktivni);
    $prevod_druhy   = $rok_druhy ? sb_prevod_hodin($rodina_obj, $rok_druhy) : 0;
    $je_vedouci = sb_bez_doplatku($rodina_id);
    $bez_doplatku_txt = sb_je_dospely($rodina_id) ? 'dospělý člen – bez doplatku' : 'rodina vedoucího – bez doplatku';
    $zbyva    = max(0, $pozadavek - $odpracovano_aktivni - $prevod_aktivni);
    $barva    = ($zbyva === 0) ? '#2e7d32' : '#d32f2f';
    $poplatek = $je_vedouci ? 0 : $zbyva * $sazba;

    // --- Druhý rok ---
    $pozadavky_druhy = ($rok_druhy && isset($pozadavky_vse[$rok_druhy])) ? $pozadavky_vse[$rok_druhy] : null;
    $pozadavek_druhy = 0;
    $sazba_druhy     = 0;
    $zbyva_druhy     = 0;
    $poplatek_druhy  = 0;

    if ($rok_druhy && $pozadavky_druhy && $v_druhem_roce) {
        $pozadavek_druhy = sb_pozadavek_hodin($rodina_id, $rok_druhy, $pozadavky_druhy);
        $sazba_druhy    = isset($pozadavky_druhy['sazba']) ? floatval($pozadavky_druhy['sazba']) : 0;
        $zbyva_druhy    = max(0, $pozadavek_druhy - $odpracovano_druhy - $prevod_druhy);
        $poplatek_druhy = $je_vedouci ? 0 : $zbyva_druhy * $sazba_druhy;
    }

    // --- Výpis hlavičky a základních údajů ---
    ob_start();
    ?>
    <style>
    .sbf { font-family: inherit; }
    .sbf-btn { display:inline-block; padding:2px 9px; font-size:12px; line-height:1.6; border:none; border-radius:3px; cursor:pointer; text-decoration:none; vertical-align:middle; font-weight:500; width:auto !important; }
    .sbf-btn-blue  { background:#0073aa !important; color:#fff !important; }
    .sbf-btn-red   { background:#c0392b !important; color:#fff !important; }
    .sbf-btn-green { background:#2e7d32 !important; color:#fff !important; }
    .sbf-num { width:52px !important; max-width:52px !important; min-width:0 !important; padding:2px 4px !important; font-size:13px !important; line-height:1.4 !important; text-align:center !important; border:1px solid #8c8f94 !important; border-radius:3px !important; box-sizing:border-box !important; color:#000 !important; background:#fff !important; height:auto !important; }
    .sbf-req-table { border:none !important; border-collapse:collapse !important; font-size:13px !important; line-height:1.4 !important; }
    .sbf-req-table td { border:none !important; padding:2px 0 !important; margin:0 !important; line-height:1.4 !important; vertical-align:baseline !important; background:transparent !important; }
    .sbf-form { display:flex; gap:6px; align-items:center; margin-top:6px; flex-wrap:wrap; }
    .sbf-form label { font-size:12px; color:#555; margin:0; }
    .sbf-card { border:1px solid #b0d4ea; border-radius:4px; padding:8px 10px; margin-bottom:8px; background:#f0f8ff; }
    .sbf-card-gray { border:1px dashed #ccc; border-radius:4px; padding:8px 10px; margin-bottom:8px; background:#f9f9f9; }
    .sbf-name { font-weight:600; font-size:13px; margin-bottom:2px; }
    .sbf-meta { font-size:12px; color:#444; line-height:1.5; }
    .sbf-badge { font-size:12px; color:#0a5b2e; background:#d1e7dd; border-radius:3px; padding:1px 6px; }
    .sbf-table { border-collapse:collapse; width:100%; font-size:12px; }
    .sbf-table th { background:#e8f0f7; color:#333; border:1px solid #c0cfe0; padding:4px 6px; font-weight:600; white-space:nowrap; }
    .sbf-table td { border:1px solid #ddd; padding:3px 6px; vertical-align:middle; }
    .sbf-table td.c { text-align:center; }
    .sbf-col-title { margin-top:0; border-bottom:2px solid #0073aa; padding-bottom:5px; font-size:14px; }
    .sbf-cols { display:flex; gap:20px; align-items:flex-start; flex-wrap:wrap; margin-top:20px; }
    .sbf-col { flex:1; min-width:240px; }
    </style>
    <?php

    // Zobrazení hlášky po akci
    if (!empty($zprava)) {
        echo $zprava;
    }

    echo "<h3>🧮 Moje brigády</h3>";
    echo "<p style='font-size:12px; color:#888; font-style:italic; margin-top:-6px;'>Je zde něco špatně? Helpdesk: Honza Outlý – outly@skaut.cz, tel. 777 640 504.</p>";

    // Rodina + děti na jednom řádku
    echo "<div style='font-size:13px; margin-bottom:12px;'>";
    if (sb_je_dospely($rodina_id)) {
        echo "Dospělý člen: <strong>$nazev_rodiny</strong>";
    } else {
        echo "Rodina: <strong>$nazev_rodiny</strong> &nbsp;·&nbsp; Děti: <strong>$pocet_deti</strong>";
        if (is_array($deti) && !empty($deti)) {
            $jmena_deti = array_map(fn($d) => esc_html($d['jmeno']) . ' (' . esc_html($d['oddil']) . ')', $deti);
            echo " — " . implode(', ', $jmena_deti);
        }
        $dospeli_rodiny = array_map(fn($d) => esc_html($d['jmeno']), sb_dospeli_rodiny($rodina_id));
        if ($dospeli_rodiny) echo " &nbsp;·&nbsp; Dospělí členové: " . implode(', ', $dospeli_rodiny);
    }
    echo "</div>";

    // --- Přehled požadavků ---
    echo "<h5 class='sbf-col-title' style='margin-bottom:10px;'>📘 Přehled brigádnických požadavků</h5>";
    echo "<div style='display:flex; flex-wrap:wrap; gap:12px; margin-bottom:16px;'>";

    $barva_zbyva = ($zbyva === 0) ? '#2e7d32' : '#c0392b';
    echo "<div style='flex:1 1 auto; min-width:0; max-width:100%; box-sizing:border-box; border:1px solid #b0d4ea; border-radius:4px; padding:8px 14px; background:#f0f8ff; font-size:13px;'>";
    echo "<div style='font-weight:600; margin-bottom:4px;'>Rok $rok_aktivni</div>";
    if (!$v_aktivnim_roce) {
        echo "<p style='font-size:12px; color:#666; margin:4px 0 6px;'>" . esc_html(sb_poznamka_mimo_rok($rodina_obj, $rok_aktivni)) . "</p>";
    }
    echo "<table class='sbf-req-table'>";
    echo "<tr><td style='padding-right:16px; color:#555;'>Požadavek:</td><td><strong>$pozadavek h</strong></td></tr>";
    echo sb_prevod_radek_html($prevod_aktivni, $rok_aktivni);
    echo "<tr><td style='color:#555;'>Odpracováno:</td><td><strong>$odpracovano_aktivni h</strong></td></tr>";
    echo "<tr><td style='color:#555;'>Zbývá:</td><td><strong style='color:" . esc_attr($barva_zbyva) . ";'>$zbyva h</strong></td></tr>";
    echo "<tr><td style='color:#555;'>Sazba:</td><td>" . number_format($sazba, 0, ',', ' ') . " Kč/h</td></tr>";
    echo "<tr><td style='color:#555;'>Poplatek:</td><td><strong>" . number_format($poplatek, 0, ',', ' ') . " Kč</strong>" . ($je_vedouci && $zbyva > 0 ? " <span style='color:#777; font-size:12px;'>(" . esc_html($bez_doplatku_txt) . ")</span>" : "") . "</td></tr>";
    echo "</table></div>";

    if ($rok_druhy) {
        $barva_zbyva_d = ($zbyva_druhy === 0) ? '#2e7d32' : '#c0392b';
        echo "<div style='flex:1 1 auto; min-width:0; max-width:100%; box-sizing:border-box; border:1px solid #ddd; border-radius:4px; padding:8px 14px; background:#f9f9f9; font-size:13px;'>";
        echo "<div style='font-weight:600; margin-bottom:4px;'>Rok $rok_druhy</div>";
        if (!$v_druhem_roce) {
            echo "<p style='font-size:12px; color:#666; margin:4px 0 6px;'>" . esc_html(sb_poznamka_mimo_rok($rodina_obj, $rok_druhy)) . "</p>";
        }
        echo "<table class='sbf-req-table'>";
        echo "<tr><td style='padding-right:16px; color:#555;'>Požadavek:</td><td><strong>$pozadavek_druhy h</strong></td></tr>";
        echo sb_prevod_radek_html($prevod_druhy, $rok_druhy);
        echo "<tr><td style='color:#555;'>Odpracováno:</td><td><strong>$odpracovano_druhy h</strong></td></tr>";
        echo "<tr><td style='color:#555;'>Zbývá:</td><td><strong style='color:" . esc_attr($barva_zbyva_d) . ";'>$zbyva_druhy h</strong></td></tr>";
        echo "<tr><td style='color:#555;'>Sazba:</td><td>" . number_format($sazba_druhy, 0, ',', ' ') . " Kč/h</td></tr>";
        echo "<tr><td style='color:#555;'>Poplatek:</td><td><strong>" . number_format($poplatek_druhy, 0, ',', ' ') . " Kč</strong>" . ($je_vedouci && $zbyva_druhy > 0 ? " <span style='color:#777; font-size:12px;'>(" . esc_html($bez_doplatku_txt) . ")</span>" : "") . "</td></tr>";
        echo "</table></div>";
    }

    echo "</div>";

    // --- Členský příspěvek (jen v období nastaveném správcem) ---
    foreach ([intval(wp_date('Y')), intval(wp_date('Y')) + 1] as $rok_prisp) {
        if (!sb_prispevek_aktivni($rok_prisp)) continue;
        $info_prisp = sb_prispevek_rodiny($rodina, $rok_prisp);
        if (!$info_prisp) continue;
        $spd = sb_platba_spd($info_prisp);
        echo "<h5 class='sbf-col-title' style='margin:4px 0 10px;'>💳 Členský příspěvek " . intval($rok_prisp) . "</h5>";
        echo "<div style='border:1px solid #b0d4ea; border-radius:4px; padding:12px 14px; background:#f0f8ff; margin-bottom:20px; max-width:100%; box-sizing:border-box;'>";
        echo sb_prispevek_html($info_prisp, $spd ? sb_qr_svg($spd, 180) : '');
        if ($info_prisp['stav'] !== 'zaplaceno') {
            echo "<p style='font-size:13px; margin:14px 0 6px;'>Pokud vám to přijde smysluplné a váš rodinný rozpočet to dovolí, můžete nás podpořit i formou sponzorského daru – částku stačí k příspěvku jednoduše přidat. Na dar vám rádi vystavíme potvrzení.</p>";
            echo "<form method='post' class='sbf-form'>";
            wp_nonce_field('sb_moje_brigady', '_wpnonce_sb_moje_brigady');
            echo "<input type='hidden' name='sb_dar_rok' value='" . intval($rok_prisp) . "'>";
            echo "<label for='sb-dar-" . intval($rok_prisp) . "'>Dar (Kč):</label>";
            echo "<input type='number' id='sb-dar-" . intval($rok_prisp) . "' name='sb_dar' min='0' step='1' value='" . ($info_prisp['dar'] > 0 ? intval($info_prisp['dar']) : '') . "' placeholder='0' style='width:100px !important; max-width:100px !important;'>";
            echo "<input type='submit' name='sb_ulozit_dar' value='" . ($info_prisp['dar'] > 0 ? 'Změnit dar a přepočítat' : 'Přidat dar a přepočítat') . "' class='sbf-btn sbf-btn-blue'>";
            echo "</form>";
        }
        echo "</div>";
    }

    // --- Tři sloupce ---
    echo "<div class='sbf-cols'>";

    // === SLOUPEC 1: Absolvované brigády ===
    echo "<div class='sbf-col'>";
    echo "<h5 class='sbf-col-title'>✅ Absolvované brigády</h5>";
    if ($zaznamy_absolvovane) {
        usort($zaznamy_absolvovane, fn($a, $b) => strcmp($b['datum'], $a['datum']));
        echo "<table class='sbf-table'>";
        echo "<tr><th>Brigáda</th><th>Datum</th><th>Osob</th><th>Hodiny</th><th>Celkem</th></tr>";
        foreach ($zaznamy_absolvovane as $z) {
            $d_obj = DateTime::createFromFormat('Y-m-d', $z['datum'], $tz);
            $d_fmt = $d_obj ? $d_obj->format('d. m. Y') : esc_html($z['datum']);
            if ($z['hodiny_osob'] !== null) {
                $hodiny_cell = implode(', ', array_map(fn($h) => $h . ' h', $z['hodiny_osob']));
            } elseif ($z['hodiny'] !== null) {
                $hodiny_cell = $z['hodiny'] . ' h/os.';
            } else {
                $hodiny_cell = '<span style="color:#999;">–</span>';
            }
            echo "<tr>";
            echo "<td>" . esc_html($z['nazev']) . "</td>";
            echo "<td class='c'>" . $d_fmt . "</td>";
            echo "<td class='c'>" . intval($z['pocet_osob']) . "</td>";
            echo "<td class='c'>" . $hodiny_cell . "</td>";
            echo "<td class='c'><strong>" . intval($z['celkem_h']) . " h</strong></td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color:#777; font-style:italic; font-size:13px;'>Zatím žádné záznamy.</p>";
    }
    echo "</div>";

    // === SLOUPEC 2: Přihlášeni ===
    echo "<div class='sbf-col'>";
    echo "<h5 class='sbf-col-title'>📅 Brigády, na které jste přihlášeni</h5>";
    if ($zaznamy_nadchazejici) {
        usort($zaznamy_nadchazejici, fn($a, $b) => strcmp($a['datum'], $b['datum']));
        foreach ($zaznamy_nadchazejici as $z) {
            $d_obj    = DateTime::createFromFormat('Y-m-d', $z['datum'], $tz);
            $d_fmt    = $d_obj ? $d_obj->format('d. m. Y') : esc_html($z['datum']);
            $cas      = get_post_meta($z['id'], 'cas_brigady', true);
            $misto    = get_post_meta($z['id'], 'misto_konani', true);
            $trvani   = get_post_meta($z['id'], 'delka_brigady', true);
            $vhodne   = get_post_meta($z['id'], 'vhodne_pro', true);
            $poznamka = get_post_meta($z['id'], 'poznamka_brigady', true);
            echo "<div class='sbf-card'>";
            echo "<div class='sbf-name'>" . esc_html($z['nazev']) . "</div>";
            echo "<div class='sbf-meta'>📅 " . $d_fmt;
            if ($cas)   echo " · ⏰ " . esc_html($cas);
            if ($misto) echo " · 📍 " . esc_html($misto);
            echo "</div>";
            if ($trvani)   echo "<div class='sbf-meta'>⏳ " . esc_html($trvani) . "</div>";
            if ($vhodne)   echo "<div class='sbf-meta'>👤 " . esc_html($vhodne) . "</div>";
            if ($poznamka) echo "<div class='sbf-meta'>📝 " . esc_html($poznamka) . "</div>";
            echo "<div class='sbf-meta' style='margin-top:3px;'>Vaše přihláška: <span class='sbf-badge'>" . intval($z['pocet_osob']) . " osob</span></div>";
            // Obsazenost brigády
            $obsaz_txt = $z['potrebujeme'] > 0
                ? intval($z['celkem_prihlasenych']) . " / " . intval($z['potrebujeme']) . " brigádníků"
                : intval($z['celkem_prihlasenych']) . " brigádníků celkem";
            echo "<div class='sbf-meta'>Obsazenost: <strong>" . $obsaz_txt . "</strong></div>";
            echo "<form method='post' class='sbf-form'>";
            wp_nonce_field('sb_moje_brigady', '_wpnonce_sb_moje_brigady');
            echo "<input type='hidden' name='brigada_id' value='" . intval($z['id']) . "'>";
            echo "<label>Počet:</label>";
            echo "<input type='number' name='pocet_osob' min='1' value='" . intval($z['pocet_osob']) . "' required class='sbf-num'>";
            echo "<small style='color:#777; display:block; margin-top:2px;'>Jen osoby od 15 let.</small>";
            echo "<input type='submit' name='upravit_brigadu' value='💾 Uložit' class='sbf-btn sbf-btn-blue'>";
            echo "<input type='submit' name='odhlasit_brigadu' value='✕ Odhlásit' class='sbf-btn sbf-btn-red' onclick='return confirm(\"Opravdu se chcete odhlásit?\");'>";
            echo "</form>";
            echo "</div>";
        }
    } else {
        echo "<p style='color:#777; font-style:italic; font-size:13px;'>Na žádnou nadcházející brigádu nejste přihlášeni.</p>";
    }
    echo "</div>";

    // === SLOUPEC 3: Nepřihlášeni ===
    echo "<div class='sbf-col'>";
    echo "<h5 class='sbf-col-title'>📌 Brigády, na které jste se nepřihlásili</h5>";
    if ($zaznamy_neprihlasene) {
        usort($zaznamy_neprihlasene, fn($a, $b) => strcmp($a['datum'], $b['datum']));
        foreach ($zaznamy_neprihlasene as $z) {
            $d_obj       = DateTime::createFromFormat('Y-m-d', $z['datum'], $tz);
            $d_fmt       = $d_obj ? $d_obj->format('d. m. Y') : esc_html($z['datum']);
            $cas         = get_post_meta($z['id'], 'cas_brigady', true);
            $misto       = get_post_meta($z['id'], 'misto_konani', true);
            $trvani      = get_post_meta($z['id'], 'delka_brigady', true);
            $poznamka    = get_post_meta($z['id'], 'poznamka_brigady', true);
            $potrebujeme = get_post_meta($z['id'], 'pozadovany_pocet', true);
            echo "<div class='sbf-card-gray'>";
            echo "<div class='sbf-name'>" . esc_html($z['nazev']) . "</div>";
            echo "<div class='sbf-meta'>📅 " . $d_fmt;
            if ($cas)   echo " · ⏰ " . esc_html($cas);
            if ($misto) echo " · 📍 " . esc_html($misto);
            echo "</div>";
            if ($trvani)   echo "<div class='sbf-meta'>⏳ " . esc_html($trvani) . "</div>";
            if ($poznamka) echo "<div class='sbf-meta'>📝 " . esc_html($poznamka) . "</div>";
            // Obsazenost
            $obsaz_txt = $z['potrebujeme'] > 0
                ? intval($z['celkem_prihlasenych']) . " / " . intval($z['potrebujeme']) . " brigádníků"
                : intval($z['celkem_prihlasenych']) . " brigádníků přihlášeno";
            echo "<div class='sbf-meta'>Obsazenost: <strong>" . $obsaz_txt . "</strong></div>";
            if ($z['je_plno']) {
                echo "<div style='margin-top:6px; font-size:12px; color:#2e7d32; font-weight:600;'>✅ Na tuto brigádu už máme lidí dost.</div>";
            } else {
                echo "<form method='post' class='sbf-form'>";
                wp_nonce_field('sb_moje_brigady', '_wpnonce_sb_moje_brigady');
                echo "<input type='hidden' name='brigada_id' value='" . intval($z['id']) . "'>";
                echo "<label>Počet osob:</label>";
                echo "<input type='number' name='pocet_osob' min='1' value='1' required class='sbf-num'>";
                echo "<small style='color:#777; display:block; margin-top:2px;'>Jen osoby od 15 let.</small>";
                echo "<input type='submit' name='prihlasit_brigadu' value='✓ Přihlásit se' class='sbf-btn sbf-btn-green'>";
                echo "</form>";
            }
            echo "</div>";
        }
    } else {
        echo "<p style='color:#777; font-style:italic; font-size:13px;'>Všechny nadcházející brigády jsou přihlášené, nebo zatím žádné nejsou vypsané.</p>";
    }
    echo "</div>";

    echo "</div>"; // end sbf-cols

    return ob_get_clean();
}

add_shortcode('moje_brigady', 'sb_moje_brigady_shortcode');

// =====================================================================
// ADMIN TAB: Rodiny dle brigád – read-only přehled vybrané rodiny
// =====================================================================
function sb_rodiny_dle_brigad_tab() {
    $rodiny = get_posts(['post_type' => 'rodina', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);
    $vybrana_rodina_id = isset($_POST['vybrana_rodina_admin']) ? intval($_POST['vybrana_rodina_admin']) : 0;

    ob_start();
    echo "<h3 class='sb-section-title'>👪 Rodiny dle brigád</h3>";

    echo "<div class='sb-card'>";
    echo "<form method='post' class='sb-form-row'>";
    echo "<div class='sb-form-group sb-fg-inline'><label>Vyber rodinu:</label><select name='vybrana_rodina_admin'>";
    foreach ($rodiny as $r) {
        $sel = ($vybrana_rodina_id === $r->ID) ? 'selected' : '';
        echo "<option value='" . intval($r->ID) . "' $sel>" . esc_html($r->post_title) . "</option>";
    }
    echo "</select></div>";
    echo "<input type='submit' name='zobraz_rodinu_admin' value='Zobrazit' class='sb-btn sb-btn-primary'>";
    echo "</form>";
    echo "</div>";

    if (!$vybrana_rodina_id && !empty($rodiny)) {
        return ob_get_clean();
    }

    if (!$vybrana_rodina_id) $vybrana_rodina_id = $rodiny[0]->ID;

    // --- Data rodiny ---
    $nazev_rodiny = get_the_title($vybrana_rodina_id);
    $deti         = get_post_meta($vybrana_rodina_id, 'deti_rodiny', true);
    $pocet_deti   = is_array($deti) ? count($deti) : 0;

    $tz       = wp_timezone();
    $dnes     = new DateTime('today', $tz);
    $mesic    = intval($dnes->format('m'));
    $rok_nyni = intval($dnes->format('Y'));

    if ($mesic === 12) {
        $rok_aktivni = $rok_nyni; $rok_druhy = $rok_nyni + 1;
    } elseif ($mesic <= 2) {
        $rok_aktivni = $rok_nyni; $rok_druhy = $rok_nyni - 1;
    } else {
        $rok_aktivni = $rok_nyni; $rok_druhy = null;
    }

    $vybrana_rodina_obj = get_post($vybrana_rodina_id);
    $v_aktivnim_roce    = sb_rodina_v_roce($vybrana_rodina_obj, $rok_aktivni);
    $v_druhem_roce      = $rok_druhy ? sb_rodina_v_roce($vybrana_rodina_obj, $rok_druhy) : false;

    $pozadavky_vse = get_option('pozadavky_dle_roku', []);
    $pozadavky_rok = isset($pozadavky_vse[$rok_aktivni]) ? $pozadavky_vse[$rok_aktivni] : null;
    $pozadavek = $sazba = 0;
    if ($pozadavky_rok && $v_aktivnim_roce) {
        $pozadavek = sb_pozadavek_hodin($vybrana_rodina_id, $rok_aktivni, $pozadavky_rok);
        $sazba     = floatval($pozadavky_rok['sazba'] ?? 0);
    }

    $brigady             = get_posts(['post_type' => 'brigada', 'numberposts' => -1]);
    $odpracovano_aktivni = 0;
    $odpracovano_druhy   = 0;
    $absolvovane         = [];
    $nadchazejici        = [];
    $neprihlasene        = [];

    foreach ($brigady as $b) {
        $datum_raw  = get_post_meta($b->ID, 'datum_brigady', true);
        $datum_obj  = DateTime::createFromFormat('Y-m-d', $datum_raw, $tz);
        $prihlaseno = get_post_meta($b->ID, 'ucastnici_' . $vybrana_rodina_id, true);
        $celkem_h   = sb_celkem_hodin($b->ID, $vybrana_rodina_id);

        if ($celkem_h !== null) {
            $rok_b = $datum_obj ? intval($datum_obj->format('Y')) : null;
            if ($rok_b === $rok_aktivni) $odpracovano_aktivni += $celkem_h;
            elseif ($rok_druhy && $rok_b === $rok_druhy) $odpracovano_druhy += $celkem_h;
        }

        if ($datum_obj && $datum_obj < $dnes) {
            if ($celkem_h !== null) {
                $h_os = get_post_meta($b->ID, 'odpracovano_' . $vybrana_rodina_id, true);
                $absolvovane[] = [
                    'nazev'    => $b->post_title,
                    'datum'    => $datum_raw,
                    'hodiny'   => ($h_os !== '' && $h_os !== null) ? intval($h_os) : null,
                    'pocet'    => max(1, intval($prihlaseno)),
                    'celkem_h' => $celkem_h,
                ];
            } else {
                $walkins_b = get_post_meta($b->ID, 'walkin_ucastnici', true);
                if (is_array($walkins_b)) {
                    foreach ($walkins_b as $w) {
                        if (isset($w['jmeno']) && $w['jmeno'] === $nazev_rodiny) {
                            $walkin_h = intval($w['pocet']) * intval($w['hodiny']);
                            $rok_b    = $datum_obj ? intval($datum_obj->format('Y')) : null;
                            if ($rok_b === $rok_aktivni) $odpracovano_aktivni += $walkin_h;
                            elseif ($rok_druhy && $rok_b === $rok_druhy) $odpracovano_druhy += $walkin_h;
                            $absolvovane[] = [
                                'nazev'    => $b->post_title,
                                'datum'    => $datum_raw,
                                'hodiny'   => intval($w['hodiny']),
                                'pocet'    => intval($w['pocet']),
                                'celkem_h' => $walkin_h,
                            ];
                            break;
                        }
                    }
                }
            }
        } else {
            $meta_b = get_post_meta($b->ID);
            $celkem_prihlasen = 0;
            foreach ($meta_b as $mk => $mv) {
                if (strpos($mk, 'ucastnici_') === 0 && isset($mv[0]) && $mv[0] !== '') $celkem_prihlasen += intval($mv[0]);
            }
            $potrebujeme = intval(get_post_meta($b->ID, 'pozadovany_pocet', true));
            if ($prihlaseno) {
                $nadchazejici[] = ['nazev' => $b->post_title, 'datum' => $datum_raw, 'pocet' => intval($prihlaseno), 'celkem' => $celkem_prihlasen, 'potrebujeme' => $potrebujeme];
            } else {
                $walkins_dnes = get_post_meta($b->ID, 'walkin_ucastnici', true);
                $walkin_found = false;
                if (is_array($walkins_dnes)) {
                    foreach ($walkins_dnes as $w) {
                        if (isset($w['jmeno']) && $w['jmeno'] === $nazev_rodiny) {
                            $walkin_h = intval($w['pocet']) * intval($w['hodiny']);
                            $rok_b    = $datum_obj ? intval($datum_obj->format('Y')) : null;
                            if ($rok_b === $rok_aktivni) $odpracovano_aktivni += $walkin_h;
                            elseif ($rok_druhy && $rok_b === $rok_druhy) $odpracovano_druhy += $walkin_h;
                            $absolvovane[] = [
                                'nazev'    => $b->post_title,
                                'datum'    => $datum_raw,
                                'hodiny'   => intval($w['hodiny']),
                                'pocet'    => intval($w['pocet']),
                                'celkem_h' => $walkin_h,
                            ];
                            $walkin_found = true;
                            break;
                        }
                    }
                }
                if (!$walkin_found) {
                    $je_plno = ($potrebujeme > 0 && $celkem_prihlasen >= $potrebujeme);
                    $neprihlasene[] = ['nazev' => $b->post_title, 'datum' => $datum_raw, 'celkem' => $celkem_prihlasen, 'potrebujeme' => $potrebujeme, 'je_plno' => $je_plno];
                }
            }
        }
    }

    $prevod_aktivni = sb_prevod_hodin($vybrana_rodina_obj, $rok_aktivni);
    $prevod_druhy   = $rok_druhy ? sb_prevod_hodin($vybrana_rodina_obj, $rok_druhy) : 0;
    $je_vedouci = sb_bez_doplatku($vybrana_rodina_id);
    $bez_doplatku_txt = sb_je_dospely($vybrana_rodina_id) ? 'dospělý člen – bez doplatku' : 'rodina vedoucího – bez doplatku';
    $zbyva    = max(0, $pozadavek - $odpracovano_aktivni - $prevod_aktivni);
    $poplatek = $je_vedouci ? 0 : $zbyva * $sazba;

    $pozadavky_druhy = ($rok_druhy && isset($pozadavky_vse[$rok_druhy])) ? $pozadavky_vse[$rok_druhy] : null;
    $pozadavek_druhy = $sazba_druhy = $zbyva_druhy = $poplatek_druhy = 0;
    if ($rok_druhy && $pozadavky_druhy && $v_druhem_roce) {
        $pozadavek_druhy = sb_pozadavek_hodin($vybrana_rodina_id, $rok_druhy, $pozadavky_druhy);
        $sazba_druhy     = floatval($pozadavky_druhy['sazba'] ?? 0);
        $zbyva_druhy     = max(0, $pozadavek_druhy - $odpracovano_druhy - $prevod_druhy);
        $poplatek_druhy  = $je_vedouci ? 0 : $zbyva_druhy * $sazba_druhy;
    }

    // --- Výpis ---
    echo "<div style='font-size:13px; margin:8px 0 12px;'>Rodina: <strong>" . esc_html($nazev_rodiny) . "</strong> &nbsp;·&nbsp; Děti: <strong>$pocet_deti</strong>";
    if (is_array($deti) && !empty($deti)) {
        echo " — " . implode(', ', array_map(fn($d) => esc_html($d['jmeno']) . ' (' . esc_html($d['oddil']) . ')', $deti));
    }
    echo "</div>";

    // Finanční přehled
    echo "<h5 class='sbf-col-title' style='margin-bottom:10px;'>📘 Přehled brigádnických požadavků</h5>";
    echo "<div style='display:flex; flex-wrap:wrap; gap:12px; margin-bottom:16px;'>";

    $barva_z = ($zbyva === 0) ? '#2e7d32' : '#c0392b';
    echo "<div style='flex:1 1 auto; min-width:0; max-width:100%; box-sizing:border-box; border:1px solid #b0d4ea; border-radius:4px; padding:8px 14px; background:#f0f8ff; font-size:13px;'>";
    echo "<div style='font-weight:600; margin-bottom:4px;'>Rok $rok_aktivni</div>";
    if (!$v_aktivnim_roce) {
        echo "<p style='font-size:12px; color:#666; margin:4px 0 6px;'>" . esc_html(sb_poznamka_mimo_rok($vybrana_rodina_obj, $rok_aktivni)) . "</p>";
    }
    echo "<table class='sbf-req-table'>";
    echo "<tr><td style='padding-right:16px; color:#555;'>Požadavek:</td><td><strong>$pozadavek h</strong></td></tr>";
    echo sb_prevod_radek_html($prevod_aktivni, $rok_aktivni);
    echo "<tr><td style='color:#555;'>Odpracováno:</td><td><strong>$odpracovano_aktivni h</strong></td></tr>";
    echo "<tr><td style='color:#555;'>Zbývá:</td><td><strong style='color:" . esc_attr($barva_z) . ";'>$zbyva h</strong></td></tr>";
    echo "<tr><td style='color:#555;'>Sazba:</td><td>" . number_format($sazba, 0, ',', ' ') . " Kč/h</td></tr>";
    echo "<tr><td style='color:#555;'>Poplatek:</td><td><strong>" . number_format($poplatek, 0, ',', ' ') . " Kč</strong>" . ($je_vedouci && $zbyva > 0 ? " <span style='color:#777; font-size:12px;'>(" . esc_html($bez_doplatku_txt) . ")</span>" : "") . "</td></tr>";
    echo "</table></div>";

    if ($rok_druhy && $pozadavky_druhy) {
        $barva_zd = ($zbyva_druhy === 0) ? '#2e7d32' : '#c0392b';
        echo "<div style='flex:1 1 auto; min-width:0; max-width:100%; box-sizing:border-box; border:1px solid #ddd; border-radius:4px; padding:8px 14px; background:#f9f9f9; font-size:13px;'>";
        echo "<div style='font-weight:600; margin-bottom:4px;'>Rok $rok_druhy</div>";
        if (!$v_druhem_roce) {
            echo "<p style='font-size:12px; color:#666; margin:4px 0 6px;'>" . esc_html(sb_poznamka_mimo_rok($vybrana_rodina_obj, $rok_druhy)) . "</p>";
        }
        echo "<table class='sbf-req-table'>";
        echo "<tr><td style='padding-right:16px; color:#555;'>Požadavek:</td><td><strong>$pozadavek_druhy h</strong></td></tr>";
        echo sb_prevod_radek_html($prevod_druhy, $rok_druhy);
        echo "<tr><td style='color:#555;'>Odpracováno:</td><td><strong>$odpracovano_druhy h</strong></td></tr>";
        echo "<tr><td style='color:#555;'>Zbývá:</td><td><strong style='color:" . esc_attr($barva_zd) . ";'>$zbyva_druhy h</strong></td></tr>";
        echo "<tr><td style='color:#555;'>Sazba:</td><td>" . number_format($sazba_druhy, 0, ',', ' ') . " Kč/h</td></tr>";
        echo "<tr><td style='color:#555;'>Poplatek:</td><td><strong>" . number_format($poplatek_druhy, 0, ',', ' ') . " Kč</strong>" . ($je_vedouci && $zbyva_druhy > 0 ? " <span style='color:#777; font-size:12px;'>(" . esc_html($bez_doplatku_txt) . ")</span>" : "") . "</td></tr>";
        echo "</table></div>";
    }
    echo "</div>";

    // Tři sloupce
    echo "<div class='sbf-cols'>";

    // Absolvované
    echo "<div class='sbf-col'>";
    echo "<h5 class='sbf-col-title'>✅ Absolvované brigády</h5>";
    if ($absolvovane) {
        usort($absolvovane, fn($a, $b) => strcmp($b['datum'], $a['datum']));
        echo "<table class='sbf-table'><tr><th>Brigáda</th><th>Datum</th><th>Osob</th><th>Hod./os.</th><th>Celkem</th></tr>";
        foreach ($absolvovane as $z) {
            $d = DateTime::createFromFormat('Y-m-d', $z['datum'], $tz);
            echo "<tr><td>" . esc_html($z['nazev']) . "</td><td class='c'>" . ($d ? $d->format('d. m. Y') : esc_html($z['datum'])) . "</td>";
            echo "<td class='c'>" . $z['pocet'] . "</td>";
            echo "<td class='c'>" . ($z['hodiny'] !== null ? $z['hodiny'] . ' h/os.' : '<span style="color:#999;">–</span>') . "</td>";
            echo "<td class='c'><strong>" . $z['celkem_h'] . " h</strong></td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color:#777; font-style:italic; font-size:13px;'>Zatím žádné záznamy.</p>";
    }
    echo "</div>";

    // Přihlášené nadcházející
    echo "<div class='sbf-col'>";
    echo "<h5 class='sbf-col-title'>📅 Přihlášené brigády</h5>";
    if ($nadchazejici) {
        usort($nadchazejici, fn($a, $b) => strcmp($a['datum'], $b['datum']));
        foreach ($nadchazejici as $z) {
            $d = DateTime::createFromFormat('Y-m-d', $z['datum'], $tz);
            $d_fmt = $d ? $d->format('d. m. Y') : esc_html($z['datum']);
            echo "<div class='sbf-card'>";
            echo "<div class='sbf-name'>" . esc_html($z['nazev']) . "</div>";
            echo "<div class='sbf-meta'>📅 $d_fmt &nbsp;·&nbsp; 👥 Přihlášeno: <strong>" . $z['pocet'] . " os.</strong>";
            if ($z['potrebujeme'] > 0) echo " <span style='color:#888;'>(" . $z['celkem'] . " / " . $z['potrebujeme'] . ")</span>";
            echo "</div></div>";
        }
    } else {
        echo "<p style='color:#777; font-style:italic; font-size:13px;'>Rodina není přihlášena na žádnou nadcházející brigádu.</p>";
    }
    echo "</div>";

    // Nepřihlášené
    echo "<div class='sbf-col'>";
    echo "<h5 class='sbf-col-title'>📌 Brigády bez přihlášení</h5>";
    if ($neprihlasene) {
        usort($neprihlasene, fn($a, $b) => strcmp($a['datum'], $b['datum']));
        foreach ($neprihlasene as $z) {
            $d = DateTime::createFromFormat('Y-m-d', $z['datum'], $tz);
            $d_fmt = $d ? $d->format('d. m. Y') : esc_html($z['datum']);
            $style = $z['je_plno'] ? "border-color:#e57373; background:#fff5f5;" : "";
            echo "<div class='sbf-card-gray' style='$style'>";
            echo "<div class='sbf-name'>" . esc_html($z['nazev']) . "</div>";
            echo "<div class='sbf-meta'>📅 $d_fmt";
            if ($z['potrebujeme'] > 0) echo " &nbsp;·&nbsp; Obsazenost: " . $z['celkem'] . " / " . $z['potrebujeme'];
            if ($z['je_plno']) echo " <span style='color:#c62828; font-weight:600;'>– plno</span>";
            echo "</div></div>";
        }
    } else {
        echo "<p style='color:#777; font-style:italic; font-size:13px;'>Rodina je přihlášena na všechny nadcházející brigády.</p>";
    }
    echo "</div>";

    echo "</div>"; // sbf-cols

    return ob_get_clean();
}

// Frontend: přehled nadcházejících brigád + přihlašování rodin
function sb_prehled_brigad_frontend() {
    // --- Kontexte a bezpečné datum ---
    $current_user  = wp_get_current_user();
    $user_id       = $current_user ? intval($current_user->ID) : 0;
    $is_logged_in  = is_user_logged_in();

    // Použijeme WordPress timezone (ne PHP default) pro porovnání dat
    $now           = new DateTime('now', wp_timezone());
    $dnes          = $now->format('Y-m-d');

    // --- Najdi rodinu aktuálního uživatele ---
    // Rodina je CPT 'rodina', pole 'rodice_rodiny' (array user_id)
    $rodiny    = get_posts([
        'post_type'   => 'rodina',
        'numberposts' => -1,
        'fields'      => 'all'
    ]);
    $rodina_id = null;
    foreach ($rodiny as $r) {
        $rodice = get_post_meta($r->ID, 'rodice_rodiny', true);
        if (is_array($rodice) && in_array($user_id, $rodice, true)) {
            $rodina_id = intval($r->ID);
            break;
        }
    }

    // --- Zpracování POST (přihlásit/upravit/odhlásit) ---
    $zprava = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_logged_in && $rodina_id) {
        // Nonce musí sedět, jinak nevykonáme akci
        if (!isset($_POST['_wpnonce_sb_prehled']) || !wp_verify_nonce($_POST['_wpnonce_sb_prehled'], 'sb_prehled_brigad')) {
            $zprava = "<p style='color:red;'>Neplatný bezpečnostní token. Zkuste to prosím znovu.</p>";
        } else {
            $brigada_id = isset($_POST['brigada_id']) ? intval($_POST['brigada_id']) : 0;

            // Připrav klíč meta 'ucastnici_{rodina_id}'
            $meta_key = 'ucastnici_' . $rodina_id;

            // Rozlišení akce
            $akce_prihlasit = isset($_POST['prihlasit_brigadu']);
            $akce_upravit   = isset($_POST['upravit_ucast']);
            $akce_odhlasit  = isset($_POST['odhlasit_ucast']);

            if ($brigada_id > 0 && ($akce_prihlasit || $akce_upravit)) {
                // přihlášení/úprava: očekáváme číslo osob
                $pocet_osob = isset($_POST['pocet_osob']) ? intval($_POST['pocet_osob']) : 0;
                // u přihlášení min. 1, u úpravy min. 0
                if ($akce_prihlasit) {
                    if ($pocet_osob < 1) {
                        $zprava = "<p style='color:red;'>Zadejte prosím počet osob alespoň 1.</p>";
                    } else {
                        $potrebujeme2 = intval(get_post_meta($brigada_id, 'pozadovany_pocet', true));
                        $celkem_uz2 = 0;
                        foreach (get_post_meta($brigada_id) as $mk => $mv) {
                            if (strpos($mk, 'ucastnici_') === 0 && isset($mv[0]) && $mv[0] !== '') {
                                $celkem_uz2 += intval($mv[0]);
                            }
                        }
                        if ($potrebujeme2 > 0 && $celkem_uz2 + $pocet_osob > $potrebujeme2) {
                            $zprava = "<p style='color:red;'>Brigáda je již plná – přihlášeno $celkem_uz2 z $potrebujeme2 míst.</p>";
                        } else {
                            update_post_meta($brigada_id, $meta_key, $pocet_osob);
                            wp_cache_delete($brigada_id, 'post_meta');
                            sb_notify_prihlaseni($brigada_id, $rodina_id, $pocet_osob);
                            sb_notify_spravci_zmena($brigada_id, 'Rodina ' . get_the_title($rodina_id) . ' se přihlásila (' . $pocet_osob . ' ' . ($pocet_osob === 1 ? 'osoba' : ($pocet_osob <= 4 ? 'osoby' : 'osob')) . ').');
                            $zprava = "<p style='color:green;'>Přihlášení proběhlo v pořádku.</p>";
                        }
                    }
                } else { // úprava
                    if ($pocet_osob < 0) {
                        $zprava = "<p style='color:red;'>Počet osob nemůže být záporný.</p>";
                    } else {
                        update_post_meta($brigada_id, $meta_key, $pocet_osob);
                        wp_cache_delete($brigada_id, 'post_meta');
                        sb_notify_zmena($brigada_id, $rodina_id, $pocet_osob);
                        sb_notify_spravci_zmena($brigada_id, 'Rodina ' . get_the_title($rodina_id) . ' změnila počet účastníků na ' . $pocet_osob . '.');
                        $zprava = "<p style='color:green;'>Změna byla uložena.</p>";
                    }
                }
            } elseif ($brigada_id > 0 && $akce_odhlasit) {
                delete_post_meta($brigada_id, $meta_key);
                wp_cache_delete($brigada_id, 'post_meta');
                sb_notify_odhlaseni($brigada_id, $rodina_id);
                sb_notify_spravci_zmena($brigada_id, 'Rodina ' . get_the_title($rodina_id) . ' se odhlásila.');
                $zprava = "<p style='color:green;'>Byli jste odhlášeni z brigády.</p>";
            }
        }
    }

    // --- Načti brigády (CPT 'brigada') seřazené dle 'datum_brigady' ---
    $brigady = get_posts([
        'post_type'   => 'brigada',
        'numberposts' => -1,
        'meta_key'    => 'datum_brigady',
        'orderby'     => 'meta_value',
        'order'       => 'ASC',
        'fields'      => 'all'
    ]);

    ob_start();

    // Hlavička + zprávy
    echo "<div class='sb-brigady-wrapper'>";
    if ($zprava) {
        echo $zprava;
    }

    // Vypiš budoucí / dnešní brigády; proběhlé si odložíme do sekce Uskutečněné brigády
    echo "<h3 style='margin:0 0 12px;'>Budoucí brigády</h3>";
    $probehle = [];
    $budoucich = 0;
    foreach ($brigady as $b) {
        $datum_raw        = get_post_meta($b->ID, 'datum_brigady', true);
        if (!$datum_raw) {
            continue; // bez datumu neukazujeme
        }

        // Použij robustní parsování (očekáváme Y-m-d), fallback na string
        $datum_obj = DateTime::createFromFormat('Y-m-d', $datum_raw, wp_timezone());
if (!$datum_obj) {
    // Pokud datum není ve správném formátu, brigádu raději přeskočíme
    continue;
}
$datum_valid = $datum_obj->format('Y-m-d');

// filtr: zobraz jen ty, které jsou dnes nebo později
if ($datum_valid < $dnes) {
    $probehle[] = ['brigada' => $b, 'datum' => $datum_valid, 'datum_obj' => $datum_obj];
    continue;
}

        $budoucich++;

        // Další pole brigády
        $cas             = get_post_meta($b->ID, 'cas_brigady', true);
        $misto           = get_post_meta($b->ID, 'misto_konani', true);
        $delka           = get_post_meta($b->ID, 'delka_brigady', true);
        $vhodne          = get_post_meta($b->ID, 'vhodne_pro', true);
        $poznamka        = get_post_meta($b->ID, 'poznamka_brigady', true);
        $datum_predbezne = (get_post_meta($b->ID, 'datum_predbezne', true) === '1');
        $cas_predbezne   = (get_post_meta($b->ID, 'cas_predbezne', true) === '1');
        $pozadovany_pocet = get_post_meta($b->ID, 'pozadovany_pocet', true);

        // Výpočet přihlášených osob napříč rodinami: sečtení všech meta 'ucastnici_*'
        $meta               = get_post_meta($b->ID);
        $pocet_prihlasenych = 0;
        foreach ($meta as $key => $value) {
            if (strpos($key, 'ucastnici_') === 0 && isset($value[0]) && $value[0] !== '') {
                $pocet_prihlasenych += intval($value[0]);
            }
        }

        // Formátování
        $datum_format = $datum_obj ? $datum_obj->format('d. m. Y') : esc_html($datum_raw);
        if ($datum_predbezne) {
            $datum_format .= " (předběžně)";
        }

        $cas_format = ($cas !== '' && $cas !== null) ? esc_html($cas) : 'neuvedeno';
        if ($cas_predbezne && $cas_format !== 'neuvedeno') {
            $cas_format .= " (předběžně)";
        }

        // Karta brigády (styly minimalisticky, v duchu vašeho pluginu)
        echo "<div style='border:1px dashed #ccc; padding:12px; margin-bottom:15px; background:#f9f9f9;'>";

        echo "<strong>Brigáda:</strong> " . esc_html($b->post_title) . "<br>";
        echo "<strong>📅 Datum:</strong> {$datum_format}<br>";
        echo "<strong>⏰ Čas:</strong> {$cas_format}<br>";
        echo "<strong>📍 Místo:</strong> " . esc_html($misto ?: 'neuvedeno') . "<br>";
        echo "<strong>⏳ Trvání:</strong> " . esc_html($delka ?: 'neuvedeno') . "<br>";
        echo "<strong>👤 Vhodné pro:</strong> " . esc_html($vhodne ?: 'neuvedeno') . "<br>";
        echo "<strong>📝 Poznámka:</strong> " . esc_html($poznamka ?: '–') . "<br>";
        echo "<strong>👥 Potřebujeme:</strong> " . esc_html($pozadovany_pocet !== '' ? $pozadovany_pocet : 'neuvedeno') . " brigádníků<br>";
        echo "<strong>✅ Přihlášeno:</strong> " . esc_html($pocet_prihlasenych) . " osob<br><br>";

        // Přihlašovací logika – jen pro přihlášené s nalezenou rodinou
        if ($is_logged_in && $rodina_id) {
            $meta_key = 'ucastnici_' . $rodina_id;
            $prihlaseno_rodina = get_post_meta($b->ID, $meta_key, true);
            $prihlaseno         = ($prihlaseno_rodina !== '' && $prihlaseno_rodina !== null) ? intval($prihlaseno_rodina) : 0;

            // Nonce pro jednotlivou brigádu
            echo "<form method='post' style='display:flex; gap:10px; align-items:center; flex-wrap:wrap;'>";
            wp_nonce_field('sb_prehled_brigad', '_wpnonce_sb_prehled');
            echo "<input type='hidden' name='brigada_id' value='" . intval($b->ID) . "'>";

            if ($prihlaseno > 0) {
                echo "<p style='margin:0 0 8px 0;'><strong>Rodina:</strong> " . esc_html(get_the_title($rodina_id)) . "<br>";
                echo "Jste přihlášeni, přijde vás " . esc_html($prihlaseno) . ".</p>";

                echo "<label style='margin-right:6px;'>Upravit počet:</label>";
                echo "<input type='number' name='pocet_osob' min='0' value='" . esc_attr($prihlaseno) . "' required style='width:60px; text-align:center;'>";
                echo "<small style='color:#777; display:block; margin-top:2px;'>Jen osoby od 15 let.</small>";

                echo "<input type='submit' name='upravit_ucast' value='💾 Uložit změnu' style='background:#0073aa; color:white; padding:5px 10px;'>";
                echo "<input type='submit' name='odhlasit_ucast' value='❌ Nepřijdeme – odhlásit se' style='background:#d32f2f; color:white; padding:5px 10px;'>";
            } else {
                echo "<label style='margin-right:6px;'>Kolik vás za vaši rodinu přijde?</label>";
                echo "<input type='number' name='pocet_osob' min='1' value='1' required style='width:60px; text-align:center;'>";
                echo "<input type='submit' name='prihlasit_brigadu' value='✅ Přihlásit se' style='background:#2e7d32; color:white; padding:5px 10px;'>";
            }

            echo "</form>";
        }

        // Věta pro všechny
       if (!$is_logged_in) {
    echo "<p style='margin-top:10px; font-style:italic;'>Chcete se této brigády zúčastnit? Přihlaste se v záhlaví stránky.</p>";
}

        echo "</div>";
    }

    if ($budoucich === 0) {
        echo "<p style='font-style:italic; color:#666;'>Momentálně nejsou vypsané žádné brigády. Jakmile nějakou vypíšeme, dáme vám vědět.</p>";
    }

    // Uskutečněné brigády – od nejnovější po nejstarší
    if (!empty($probehle)) {
        usort($probehle, function($a, $b) { return strcmp($b['datum'], $a['datum']); });
        echo "<h3 style='margin:50px 0 12px;'>Uskutečněné brigády</h3>";
        foreach ($probehle as $p) {
            $pb    = $p['brigada'];
            $misto = get_post_meta($pb->ID, 'misto_konani', true);
            $delka = get_post_meta($pb->ID, 'delka_brigady', true);
            echo "<div style='border:1px solid #e3e3e3; padding:10px 12px; margin-bottom:10px; background:#fcfcfc; color:#555;'>";
            echo "<strong>" . esc_html($pb->post_title) . "</strong><br>";
            echo "📅 " . esc_html($p['datum_obj']->format('d. m. Y'));
            if ($misto) echo " &nbsp;·&nbsp; 📍 " . esc_html($misto);
            if ($delka) echo " &nbsp;·&nbsp; ⏳ " . esc_html($delka);
            echo "</div>";
        }
    }

    echo "</div>";
    return ob_get_clean();
}

// Shortcode pro frontend přehled – zaregistrovat oba názvy kvůli kompatibilitě
add_shortcode('prehled_brigad', 'sb_prehled_brigad_frontend');    // původní
add_shortcode('sb_prehled_brigad', 'sb_prehled_brigad_frontend'); // nově zavedený



function sb_prihlaseni_na_brigadu($brigada_id) {
    $user_id = get_current_user_id();
    $moje_rodina = null;

    if (is_user_logged_in()) {
        $rodiny = get_posts([
            'post_type'   => 'rodina',
            'numberposts' => -1
        ]);

        foreach ($rodiny as $r) {
            $rodice = get_post_meta($r->ID, 'rodice_rodiny', true);
            if ((is_array($rodice) && in_array($user_id, $rodice)) || $rodice == $user_id) {
                $moje_rodina = $r;
                break;
            }
        }
    }

    $zprava = '';
    $klic = $moje_rodina ? 'ucastnici_' . $moje_rodina->ID : null;
    $uz_prihlaseno = $klic ? get_post_meta($brigada_id, $klic, true) : null;

    // Zpracování přihlášení
    if (isset($_POST['prihlasit_na_brigadu']) && intval($_POST['brigada_id']) === $brigada_id) {
        if (!isset($_POST['_wpnonce_sb_prihlaseni']) || !wp_verify_nonce($_POST['_wpnonce_sb_prihlaseni'], 'sb_prihlaseni_na_brigadu')) {
            $zprava = "<p style='color:red;'>Neplatný bezpečnostní token. Zkuste to prosím znovu.</p>";
        } elseif (!is_user_logged_in()) {
            $zprava = "<p style='color:red;'>Nejdřív se prosím přihlaste do webu jako rodič – v záhlaví stránky.</p>";
        } elseif (!$moje_rodina) {
            $zprava = "<p style='color:red;'><strong>Díky za zájem.</strong> Máme menší problém. Brigády evidujeme po rodinách a vás nemáme do žádné rodiny zařazenou/zařazeného. Je to naše chyba. Zavolejte prosím Honzovi Outlému (Spajdymu) na <strong>777 640 504</strong>, nebo mu napište na <strong>outly@skaut.cz</strong>. Omlouváme se za potíže. Díky za pochopení!</p>";
        } else {
            $pocet = max(1, intval($_POST['pocet_osob']));
            $jiz_prihlasen = ($uz_prihlaseno !== '' && $uz_prihlaseno !== null && intval($uz_prihlaseno) > 0);
            update_post_meta($brigada_id, $klic, $pocet);
            wp_cache_delete($brigada_id, 'post_meta');
            $uz_prihlaseno = $pocet;
            if ($jiz_prihlasen) {
                sb_notify_zmena($brigada_id, $moje_rodina->ID, $pocet);
                sb_notify_spravci_zmena($brigada_id, 'Rodina ' . get_the_title($moje_rodina->ID) . ' změnila počet účastníků na ' . $pocet . '.');
            } else {
                sb_notify_prihlaseni($brigada_id, $moje_rodina->ID, $pocet);
                sb_notify_spravci_zmena($brigada_id, 'Rodina ' . get_the_title($moje_rodina->ID) . ' se přihlásila (' . $pocet . ' ' . ($pocet === 1 ? 'osoba' : ($pocet <= 4 ? 'osoby' : 'osob')) . ').');
            }
            $zprava = ''; // hlášku ukáže šedý box níže
        }
    }

    // Zpracování odhlášení
    if (isset($_POST['odhlasit_z_brigady']) && intval($_POST['brigada_id']) === $brigada_id) {
        if (!isset($_POST['_wpnonce_sb_prihlaseni']) || !wp_verify_nonce($_POST['_wpnonce_sb_prihlaseni'], 'sb_prihlaseni_na_brigadu')) {
            $zprava = "<p style='color:red;'>Neplatný bezpečnostní token. Zkuste to prosím znovu.</p>";
        } elseif (is_user_logged_in() && $moje_rodina) {
            delete_post_meta($brigada_id, $klic);
            wp_cache_delete($brigada_id, 'post_meta');
            $uz_prihlaseno = null;
            sb_notify_odhlaseni($brigada_id, $moje_rodina->ID);
            sb_notify_spravci_zmena($brigada_id, 'Rodina ' . get_the_title($moje_rodina->ID) . ' se odhlásila.');
            $zprava = "<p style='color:orange;'>Vaše rodina byla odhlášena z brigády.</p>";
        }
    }

    ob_start();
    echo "<div style='margin-top:15px;'><a id='brigada_" . intval($brigada_id) . "'></a>";
    echo $zprava;

    // Šedý box s informací o přihlášení
    if ($moje_rodina && $uz_prihlaseno) {
        echo "<div style='background:#f0f0f0; padding:10px; margin-top:10px;'>";
        echo "<strong>Rodina:</strong> " . esc_html($moje_rodina->post_title) . "<br>";
        echo "Jste přihlášení, přijde vás <strong>" . esc_html($uz_prihlaseno) . "</strong>.";
        echo "</div><br>";

        // Formulář pro úpravu počtu
        echo "<form method='post' action='" . esc_url(get_permalink()) . "#brigada_" . intval($brigada_id) . "' style='display:inline-block; margin-right:10px;'>";
        wp_nonce_field('sb_prihlaseni_na_brigadu', '_wpnonce_sb_prihlaseni');
        echo "<input type='hidden' name='brigada_id' value='" . esc_attr($brigada_id) . "'>";
        echo "<label>Upravit počet:</label> ";
        echo "<input type='number' name='pocet_osob' min='1' value='" . esc_attr($uz_prihlaseno) . "' required>";
        echo "<small style='color:#777; display:block; margin-top:2px;'>Jen osoby od 15 let.</small>";
        echo "<input type='submit' name='prihlasit_na_brigadu' value='💾 Uložit' style='background:#0073aa; color:white; padding:5px 10px;'>";
        echo "</form>";

        // Formulář pro odhlášení
        echo "<form method='post' action='" . esc_url(get_permalink()) . "#brigada_" . intval($brigada_id) . "' style='display:inline-block;'>";
        wp_nonce_field('sb_prihlaseni_na_brigadu', '_wpnonce_sb_prihlaseni');
        echo "<input type='hidden' name='brigada_id' value='" . esc_attr($brigada_id) . "'>";
        echo "<input type='submit' name='odhlasit_z_brigady' value='❌ Nepřijdeme – odhlásit se' style='background:#aaa; color:white; padding:5px 10px;'>";
        echo "</form>";
    }

    // Formulář pro nové přihlášení
    if ($moje_rodina && !$uz_prihlaseno) {
        echo "<form method='post' action='" . esc_url(get_permalink()) . "#brigada_" . intval($brigada_id) . "' style='margin-top:10px;'>";
        wp_nonce_field('sb_prihlaseni_na_brigadu', '_wpnonce_sb_prihlaseni');
        echo "<input type='hidden' name='brigada_id' value='" . esc_attr($brigada_id) . "'>";
        echo "<label>Kolik vás za vaši rodinu přijde?</label><br>";
        echo "<input type='number' name='pocet_osob' min='1' required>";
        echo "<small style='color:#777; display:block; margin-top:2px;'>Jen osoby od 15 let.</small><br>";
        echo "<input type='submit' name='prihlasit_na_brigadu' value='💾 Zapsat se' style='background:#0073aa; color:white; padding:5px 10px;'>";
        echo "</form>";
    }

    // Nepřihlášený uživatel – zobrazíme jen tlačítko (s nonce, aby POST byl chráněný)
    if (!$moje_rodina) {
        echo "<form method='post' action='" . esc_url(get_permalink()) . "#brigada_" . intval($brigada_id) . "' style='margin-top:10px;'>";
        wp_nonce_field('sb_prihlaseni_na_brigadu', '_wpnonce_sb_prihlaseni');
        echo "<input type='hidden' name='brigada_id' value='" . esc_attr($brigada_id) . "'>";
        echo "<input type='submit' name='prihlasit_na_brigadu' value='💾 Zapsat se' style='background:#0073aa; color:white; padding:5px 10px;'>";
        echo "</form>";
    }

    echo "</div>";
    return ob_get_clean();
}


function sb_format_rodic_jmeno($user) {
    if (!$user || !is_object($user)) return '';

    $first = $user->first_name;
    $last = $user->last_name;

    $jmeno = trim("$last $first");

    return esc_html($jmeno);
}


// =====================================================================
// E-MAILOVÉ NOTIFIKACE
// =====================================================================

// Vrátí základní info o brigádě jako asociativní pole
function sb_get_brigada_info($brigada_id) {
    $b = get_post($brigada_id);
    if (!$b) return null;
    return [
        'nazev'  => $b->post_title,
        'datum'  => get_post_meta($brigada_id, 'datum_brigady',   true) ?: 'neuvedeno',
        'cas'    => get_post_meta($brigada_id, 'cas_brigady',     true) ?: 'neuvedeno',
        'misto'  => get_post_meta($brigada_id, 'misto_konani',    true) ?: 'neuvedeno',
        'trvani' => get_post_meta($brigada_id, 'delka_brigady',   true) ?: 'neuvedeno',
    ];
}

// Vrátí e-mailové adresy obou rodičů z rodiny (jen platné adresy)
function sb_get_rodic_emails($rodina_id) {
    $rodice_ids = get_post_meta($rodina_id, 'rodice_rodiny', true);
    if (!is_array($rodice_ids)) return [];
    $emails = [];
    foreach ($rodice_ids as $uid) {
        $u = get_user_by('id', intval($uid));
        if ($u && is_email($u->user_email)) {
            $emails[] = $u->user_email;
        }
    }
    return array_unique($emails);
}

// Vrátí e-mailové adresy všech správců brigád (role spravce_brigad + administrator)
function sb_get_spravce_emails() {
    $users = get_users(['role__in' => ['spravce_brigad', 'administrator']]);
    $emails = [];
    foreach ($users as $u) {
        if (is_email($u->user_email)) {
            $emails[] = $u->user_email;
        }
    }
    return array_unique($emails);
}

// Odešle e-mail přes wp_mail s pevným odesílatelem
function sb_send_mail($to, $subject, $body) {
    $headers = [
        'Content-Type: text/plain; charset=UTF-8',
        'From: Skaut Chlumec nad Cidlinou <outly@skaut.cz>',
    ];
    wp_mail($to, $subject, $body, $headers);
}

// Sestaví přehled přihlášených rodin + walk-in pro danou brigádu (pro maily správcům)
function sb_sestavit_prehled_prihlasenych($brigada_id) {
    $rodiny = get_posts(['post_type' => 'rodina', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);
    $radky  = [];
    $celkem = 0;
    foreach ($rodiny as $r) {
        $pocet = get_post_meta($brigada_id, 'ucastnici_' . $r->ID, true);
        if ($pocet !== '' && $pocet !== null && intval($pocet) > 0) {
            $n = intval($pocet);
            $radky[]  = '• ' . $r->post_title . ' – ' . $n . ' ' . ($n === 1 ? 'osoba' : ($n <= 4 ? 'osoby' : 'osob'));
            $celkem  += $n;
        }
    }
    // Walk-in účastníci
    $walkins = get_post_meta($brigada_id, 'walkin_ucastnici', true);
    if (is_array($walkins) && !empty($walkins)) {
        foreach ($walkins as $w) {
            $radky[] = '• ' . $w['jmeno'] . ' (walk-in) – ' . intval($w['pocet'] ?? 1) . ' os., ' . intval($w['hodiny']) . ' h';
            $celkem += intval($w['pocet'] ?? 1);
        }
    }
    $prehled = empty($radky) ? '(zatím nikdo)' : implode("\n", $radky);
    return [$prehled, $celkem];
}

// --- Notifikace rodičům: přihlášení ---
function sb_notify_prihlaseni($brigada_id, $rodina_id, $pocet) {
    $emails = sb_get_rodic_emails($rodina_id);
    if (empty($emails)) return;
    $info  = sb_get_brigada_info($brigada_id);
    $nazev_rodiny = get_the_title($rodina_id);
    $subject = 'Přihlášení na brigádu: ' . $info['nazev'];
    $body = "Dobrý den,\npotvrzujeme přihlášení rodiny {$nazev_rodiny} na brigádu.\n\n"
        . "📌 Brigáda: {$info['nazev']}\n"
        . "📅 Datum: {$info['datum']}\n"
        . "⏰ Čas: {$info['cas']}\n"
        . "📍 Místo: {$info['misto']}\n"
        . "⏳ Trvání: {$info['trvani']}\n"
        . "👥 Počet přihlášených za vaši rodinu: {$pocet}\n\n"
        . "S pozdravem,\nSkaut Chlumec nad Cidlinou";
    foreach ($emails as $email) {
        sb_send_mail($email, $subject, $body);
    }
}

// --- Notifikace rodičům: odhlášení ---
function sb_notify_odhlaseni($brigada_id, $rodina_id) {
    $emails = sb_get_rodic_emails($rodina_id);
    if (empty($emails)) return;
    $info  = sb_get_brigada_info($brigada_id);
    $nazev_rodiny = get_the_title($rodina_id);
    $subject = 'Odhlášení z brigády: ' . $info['nazev'];
    $body = "Dobrý den,\nvaše rodina {$nazev_rodiny} byla odhlášena z brigády.\n\n"
        . "📌 Brigáda: {$info['nazev']}\n"
        . "📅 Datum: {$info['datum']}\n"
        . "⏰ Čas: {$info['cas']}\n"
        . "📍 Místo: {$info['misto']}\n\n"
        . "Pokud šlo o omyl, znovu se přihlaste na webu.\n\n"
        . "S pozdravem,\nSkaut Chlumec nad Cidlinou";
    foreach ($emails as $email) {
        sb_send_mail($email, $subject, $body);
    }
}

// --- Notifikace rodičům: změna počtu ---
function sb_notify_zmena($brigada_id, $rodina_id, $pocet) {
    $emails = sb_get_rodic_emails($rodina_id);
    if (empty($emails)) return;
    $info  = sb_get_brigada_info($brigada_id);
    $nazev_rodiny = get_the_title($rodina_id);
    $subject = 'Změna přihlášky na brigádu: ' . $info['nazev'];
    $body = "Dobrý den,\npotvrzujeme úpravu přihlášky rodiny {$nazev_rodiny} na brigádu.\n\n"
        . "📌 Brigáda: {$info['nazev']}\n"
        . "📅 Datum: {$info['datum']}\n"
        . "⏰ Čas: {$info['cas']}\n"
        . "📍 Místo: {$info['misto']}\n"
        . "⏳ Trvání: {$info['trvani']}\n"
        . "👥 Nový počet přihlášených za vaši rodinu: {$pocet}\n\n"
        . "S pozdravem,\nSkaut Chlumec nad Cidlinou";
    foreach ($emails as $email) {
        sb_send_mail($email, $subject, $body);
    }
}

// --- Notifikace správcům: změna přihlášek ---
function sb_notify_spravci_zmena($brigada_id, $zmena = '') {
    $datum = get_post_meta($brigada_id, 'datum_brigady', true);
    if ($datum && strtotime($datum) - time() > 7 * DAY_IN_SECONDS) return;
    $emails = array_unique(array_merge(sb_get_spravce_emails(), ['v.appl@email.cz']));
    if (empty($emails)) return;
    $info = sb_get_brigada_info($brigada_id);
    list($prehled, $celkem) = sb_sestavit_prehled_prihlasenych($brigada_id);
    $subject = 'Změna přihlášek – ' . $info['nazev'] . ' (' . $info['datum'] . ')';
    $zmena_radek = $zmena ? "🔔 Co se změnilo: {$zmena}\n\n" : '';
    $body = $zmena_radek
        . "Aktuální stav přihlášených rodin:\n{$prehled}\n\n"
        . "Celkem přihlášeno: {$celkem} " . ($celkem === 1 ? 'osoba' : ($celkem <= 4 ? 'osoby' : 'osob')) . "\n\n"
        . "📌 Brigáda: {$info['nazev']}\n"
        . "📅 Datum: {$info['datum']}\n"
        . "⏰ Čas: {$info['cas']}\n"
        . "📍 Místo: {$info['misto']}\n\n"
        . "Skaut Chlumec nad Cidlinou";
    sb_send_mail($emails, $subject, $body);
}

// --- Notifikace správcům: 24h připomínka ---
function sb_notify_spravci_pripominka($brigada_id) {
    $emails = sb_get_spravce_emails();
    if (empty($emails)) return;
    $info = sb_get_brigada_info($brigada_id);
    list($prehled, $celkem) = sb_sestavit_prehled_prihlasenych($brigada_id);
    $subject = 'Zítra brigáda: ' . $info['nazev'] . ' (' . $info['datum'] . ')';
    $body = "Zítra se koná brigáda. Tady je aktuální přehled přihlášených rodin.\n\n"
        . "📌 Brigáda: {$info['nazev']}\n"
        . "📅 Datum: {$info['datum']}\n"
        . "⏰ Čas: {$info['cas']}\n"
        . "📍 Místo: {$info['misto']}\n"
        . "⏳ Trvání: {$info['trvani']}\n\n"
        . "Přihlášené rodiny:\n{$prehled}\n\n"
        . "Celkem přihlášeno: {$celkem} " . ($celkem === 1 ? 'osoba' : ($celkem <= 4 ? 'osoby' : 'osob')) . "\n\n"
        . "Skaut Chlumec nad Cidlinou";
    sb_send_mail($emails, $subject, $body);
}

// =====================================================================
// WP-CRON: 24h připomínka správcům
// =====================================================================

// Registrace vlastního intervalu (každou hodinu, pro spuštění přes serverový cron)
add_filter('cron_schedules', function($schedules) {
    if (!isset($schedules['sb_hourly'])) {
        $schedules['sb_hourly'] = [
            'interval' => 3600,
            'display'  => 'Každou hodinu (Skautské brigády)'
        ];
    }
    return $schedules;
});

// Naplánování úlohy při aktivaci pluginu
register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('sb_cron_pripominka')) {
        wp_schedule_event(time(), 'sb_hourly', 'sb_cron_pripominka');
    }
});

// Zrušení úlohy při deaktivaci pluginu
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('sb_cron_pripominka');
});

// Samotná cron úloha: projde všechny brigády a odešle připomínku těm, co jsou zítra
add_action('sb_cron_pripominka', function() {
    $tz   = wp_timezone();
    $zitra = new DateTime('tomorrow', $tz);
    $zitra_str = $zitra->format('Y-m-d');

    $brigady = get_posts(['post_type' => 'brigada', 'numberposts' => -1]);
    foreach ($brigady as $b) {
        $datum = get_post_meta($b->ID, 'datum_brigady', true);
        if ($datum !== $zitra_str) continue;

        // Aby se připomínka neposílala víckrát — uložíme příznak s datem odeslání
        $odeslano = get_post_meta($b->ID, '_sb_pripominka_odeslana', true);
        if ($odeslano === $zitra_str) continue;

        sb_notify_spravci_pripominka($b->ID);
        update_post_meta($b->ID, '_sb_pripominka_odeslana', $zitra_str);
    }
});


