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
    fputcsv($output, ['Rodina', 'Děti', 'Počet dětí', 'Odpracováno (h)', 'Požadavek (h)', 'Rozdíl (h)', 'Poplatek (Kč)'], ';');

    foreach ($rodiny as $r) {
        $deti_meta  = get_post_meta($r->ID, 'deti_rodiny', true);
        $pocet_deti = is_array($deti_meta) ? count($deti_meta) : 0;

        $pozadavek = 0;
        if ($poz_rok) {
            if ($pocet_deti == 1)      $pozadavek = intval($poz_rok['1']);
            elseif ($pocet_deti == 2)  $pozadavek = intval($poz_rok['2']);
            elseif ($pocet_deti >= 3)  $pozadavek = intval($poz_rok['3']);
        }

        $odpracovano = 0;
        foreach ($brigady as $b) {
            $datum_raw = get_post_meta($b->ID, 'datum_brigady', true);
            if ($datum_raw && preg_match('/\b(19|20)\d{2}\b/', $datum_raw, $m) && intval($m[0]) === $rok) {
                $celkem = sb_celkem_hodin($b->ID, $r->ID);
                if ($celkem !== null) {
                    $odpracovano += $celkem;
                }
            }
        }

        $rozdil   = $odpracovano - $pozadavek;
        $poplatek = max(0, $pozadavek - $odpracovano) * $sazba;

        $jmena = [];
        if (is_array($deti_meta)) {
            foreach ($deti_meta as $dite) {
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
            number_format($poplatek, 2, ',', ' ')
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


// SHORTCODE: hlavní rozhraní správce
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
        if (isset($_POST['upravit_pozadavky_dle_roku']) || isset($_POST['ulozit_pozadavky'])) {
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
        });
    </script>
    
    <div class="sb-admin-wrap">
        <div class="sb-menu-horizontal">
        <?php if (array_intersect($roles, $allowed_full)) : ?>
            <button onclick="sbShowSection('nova')">📘 Návod</button>
            <button onclick="sbShowSection('rodiny')">➕ Vytvořit rodinu</button>
            <button onclick="sbShowSection('prehled')">👥 Správa rodin</button>
            <button onclick="sbShowSection('upravit_pozadavky')">📅 Nastavit požadavky dle roku</button>
            <button onclick="sbShowSection('upravit_brigady')">🛠️ Vytvoření a správa brigád</button>
            <button onclick="sbShowSection('upravit_ucastniky')">🧮 Správa účastníků</button>
            <button onclick="sbShowSection('ucastnici_dle')">🧮 Účastníci dle brigád</button>
            <button onclick="sbShowSection('rodiny_dle_brigad')">👪 Rodiny dle brigád</button>
            <button onclick="sbShowSection('rocni_vypis')">📊 Roční výpis</button>
        <?php elseif (array_intersect($roles, $allowed_limited)) : ?>
    <button onclick="sbShowSection('ucastnici_dle')">🧮 Účastníci dle brigád</button>
    <button onclick="sbShowSection('rodiny_dle_brigad')">👪 Rodiny dle brigád</button>
    <button onclick="sbShowSection('rocni_vypis')">📊 Roční výpis</button>
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
                    <li>Zobrazí se seznam účastníků</li>
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
                    <li>Zadej název rodiny a počet dětí</li>
                    <li>Vyber rodiče (uživatelé s rolí „rodic")</li>
                    <li>Plugin kontroluje, zda rodiče nejsou už přiřazeni jinde</li>
                </ul>
                <p>Rodiče jsou řazeni podle příjmení pro přehlednost.</p>

                <br><br>
                <h5>👥 <b>Správa rodin</b></h5>
                <ul>
                    <li>Rodiny jsou řazeny abecedně</li>
                    <li>U každé lze upravit údaje nebo ji smazat</li>
                </ul>

                <br><br>
                <h5>📅 <b>Nastavit požadavky dle roku</b></h5>
                <ul>
                    <li>Vyber rok ze seznamu</li>
                    <li>Zadej požadavky pro rodiny s 1, 2 nebo 3+ dětmi</li>
                    <li>Zadej sazbu za neodpracovanou hodinu (v Kč)</li>
                    <li>Ulož požadavky</li>
                </ul>
                <p>Na konci stránky se zobrazuje přehledová tabulka všech nastavených let, včetně sazby.</p>

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
                </ul>
                <p>Výpis pomáhá sledovat, kdo splnil požadavek a jaký poplatek případně vzniká.</p>
            </div>
        <?php endif; ?>
        
                <?php if (array_intersect($roles, $allowed_full)) : ?>
            <div id="sb-rodiny" class="<?php echo ($aktivni_sekce === 'rodiny') ? 'sb-active' : ''; ?>"><?php echo sb_vytvorit_rodinu(); ?></div>
            <div id="sb-prehled" class="<?php echo ($aktivni_sekce === 'prehled') ? 'sb-active' : ''; ?>"><?php echo sb_prehled_rodin(); ?></div>
            <div id="sb-upravit_pozadavky" class="<?php echo ($aktivni_sekce === 'upravit_pozadavky') ? 'sb-active' : ''; ?>"><?php echo sb_upravit_pozadavky_dle_roku(); ?></div>
            <div id="sb-upravit_brigady" class="<?php echo ($aktivni_sekce === 'upravit_brigady') ? 'sb-active' : ''; ?>"><?php echo sb_sprava_brigad(); ?></div>
            <div id="sb-upravit_ucastniky" class="<?php echo ($aktivni_sekce === 'upravit_ucastniky') ? 'sb-active' : ''; ?>"><?php echo sb_sprava_ucastniku(); ?></div>
            <div id="sb-ucastnici_dle" class="<?php echo ($aktivni_sekce === 'ucastnici_dle') ? 'sb-active' : ''; ?>"><?php echo sb_ucastnici_dle_brigad(); ?></div>
            <div id="sb-rodiny_dle_brigad" class="<?php echo ($aktivni_sekce === 'rodiny_dle_brigad') ? 'sb-active' : ''; ?>"><?php echo sb_rodiny_dle_brigad_tab(); ?></div>
            <div id="sb-rocni_vypis" class="<?php echo ($aktivni_sekce === 'rocni_vypis') ? 'sb-active' : ''; ?>"><?php echo sb_rocni_vypis(); ?></div>
        <?php elseif (array_intersect($roles, $allowed_limited)) : ?>
            <div id="sb-ucastnici_dle" class="<?php echo ($aktivni_sekce === 'ucastnici_dle') ? 'sb-active' : ''; ?>"><?php echo sb_ucastnici_dle_brigad(); ?></div>
            <div id="sb-rodiny_dle_brigad" class="<?php echo ($aktivni_sekce === 'rodiny_dle_brigad') ? 'sb-active' : ''; ?>"><?php echo sb_rodiny_dle_brigad_tab(); ?></div>
            <div id="sb-rocni_vypis" class="<?php echo ($aktivni_sekce === 'rocni_vypis') ? 'sb-active' : ''; ?>"><?php echo sb_rocni_vypis(); ?></div>
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
    return "<div class='sb-alert {$class}'>{$text}</div>";
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
    echo "<tr><th style='width:220px;'>Položka</th><th>Hodnota</th></tr>";
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
        echo "<div style='display:flex; justify-content:space-between; align-items:center; margin-top:20px; margin-bottom:8px;'>";
        echo "<h5 class='sb-card-title' style='margin:0;'>👥 Přehled účastníků</h5>";
        echo "<a href='" . esc_url($export_url) . "' class='sb-btn sb-btn-secondary sb-btn-sm'>📥 Stáhnout Excel (CSV)</a>";
        echo "</div>";
        echo "<table class='sb-table'>";
        echo "<tr><th>Rodina / jméno</th><th class='center'>Osob</th><th class='center'>Hod./os.</th><th class='center'>Celkem odpracováno</th><th>Způsob přihlášení</th></tr>";
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
                ? "<span style='background:#fff3cd;color:#856404;border:1px solid #ffc107;border-radius:3px;padding:1px 6px;font-size:12px;'>Walk-in</span>"
                : "<span style='background:#d1e7dd;color:#0a5b2e;border:1px solid #a3cfbb;border-radius:3px;padding:1px 6px;font-size:12px;'>Přihlášena</span>";
            echo "<tr>";
            echo "<td>" . esc_html($row['jmeno']) . "</td>";
            echo "<td class='center'>" . intval($row['osob']) . "</td>";
            echo "<td class='center'>" . $h_os_str . "</td>";
            echo "<td class='center'>" . $celkem_str . "</td>";
            echo "<td>" . $typ_badge . "</td>";
            echo "</tr>";
        }
        echo "</table>";

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
        echo "<div class='sb-form-group'><label>Délka brigády:</label><input type='text' name='delka_brigady' value='$delka'></div>";
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
    echo "<div class='sb-form-group'><label>Délka brigády:</label><input type='text' name='novy_delka_brigady' value='" . esc_attr($d['delka']) . "'></div>";
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


function sb_vytvorit_rodinu() {
    $current_user = wp_get_current_user();
    $roles = $current_user->roles;
    if (!array_intersect($roles, ['administrator', 'spravce_brigad'])) {
        return '<p>Nemáš oprávnění pro vytváření rodin.</p>';
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
            $vybrani_rodice = isset($_POST['rodice']) ? array_map('intval', $_POST['rodice']) : [];

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
                        $zprava = sb_alert('Rodina byla vytvořena: <strong>' . esc_html($nazev) . '</strong>.');
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
            $sazba = max(0, floatval($_POST['sazba_za_hodinu']));

            $pozadavky[$rok] = [
                '1' => $hodiny1,
                '2' => $hodiny2,
                '3' => $hodiny3,
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

    $hodiny = isset($pozadavky[$vybrany_rok]) ? $pozadavky[$vybrany_rok] : ['1' => '', '2' => '', '3' => '', 'sazba' => ''];

    ob_start();
    echo "<h3 class='sb-section-title'>📅 Požadavky dle roku</h3>";
    echo $zprava;

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
    echo "<div class='sb-form-group'><label>Poplatek za neodpracovanou hodinu (Kč):</label><input type='number' step='0.01' name='sazba_za_hodinu' value='{$hodiny['sazba']}' min='0' style='width:110px;'></div>";

    echo "<div class='sb-btn-row'><input type='submit' value='💾 Uložit' class='sb-btn sb-btn-primary'></div>";
    echo "</form>";
    echo "</div>";
    
// Přehledová tabulka všech uložených požadavků
echo "<div class='sb-card'>";
echo "<h4 class='sb-card-title'>📊 Přehled požadavků dle roku</h4>";
echo "<table class='sb-table' style='max-width:500px;'>";
echo "<tr><th class='center'>Rok</th><th class='center'>1 dítě (h)</th><th class='center'>2 děti (h)</th><th class='center'>3+ dětí (h)</th><th class='center'>Sazba (Kč)</th></tr>";

$ma_data = false;
foreach ($pozadavky as $rok => $data) {
    if (!is_array($data)) continue;
    $h1    = isset($data['1']) ? intval($data['1']) : 0;
    $h2    = isset($data['2']) ? intval($data['2']) : 0;
    $h3    = isset($data['3']) ? intval($data['3']) : 0;
    $sazba = isset($data['sazba']) ? number_format(floatval($data['sazba']), 2, ',', ' ') : '';
    if ($h1 === 0 && $h2 === 0 && $h3 === 0 && $sazba === '') continue;
    $ma_data = true;
    echo "<tr><td class='center'><strong>" . intval($rok) . "</strong></td><td class='center'>$h1</td><td class='center'>$h2</td><td class='center'>$h3</td><td class='center'>$sazba</td></tr>";
}
if (!$ma_data) {
    echo "<tr><td colspan='5' class='center sb-text-muted'>Žádné požadavky dosud nebyly nastaveny.</td></tr>";
}
echo "</table>";
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
            $rodina_id = $r->ID;
            $nazev = esc_html($r->post_title);
            $deti = get_post_meta($rodina_id, 'deti_rodiny', true);
            $rodice = get_post_meta($rodina_id, 'rodice_rodiny', true);
            $pocet_deti = is_array($deti) ? count($deti) : 0;

            $jmena_deti = '';
            if (is_array($deti)) {
                foreach ($deti as $dite) {
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

            $pozadavek = 0;
            if ($pozadavky_rok) {
                if ($pocet_deti == 1) $pozadavek = intval($pozadavky_rok['1']);
                elseif ($pocet_deti == 2) $pozadavek = intval($pozadavky_rok['2']);
                elseif ($pocet_deti >= 3) $pozadavek = intval($pozadavky_rok['3']);
            }

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

            $rozdil  = $odpracovano - $pozadavek;
            $poplatek = max(0, $pozadavek - $odpracovano) * $sazba;
            $celkem_poplatek += $poplatek;
            $radky[] = compact('nazev', 'jmena_deti', 'jmena_rodicu', 'pocet_deti', 'odpracovano', 'pozadavek', 'rozdil', 'poplatek', 'brigady_seznam', 'rodina_id');
        }

        // --- Agregované statistiky brigád ---
        $minule_clovekoh   = 0;
        foreach ($radky as $z) $minule_clovekoh += $z['odpracovano'];

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
        echo "<div style='margin-bottom:20px; font-size:12px; color:#666; font-style:italic;'>Část rodin je na brigády přihlášena, přestože svůj požadavek hodin již splnily — jejich hodiny inkaso dále nesnižují.</div>";

        echo "<table class='sb-table'>";
        echo "<tr>
            <th>Rodina</th>
            <th>Děti</th>
            <th>Rodiče</th>
            <th class='center'>Počet dětí</th>
            <th class='center'>Odpracováno (h)</th>
            <th class='center'>Požadavek (h)</th>
            <th class='center'>Rozdíl (h)</th>
            <th class='center'>Poplatek (Kč)</th>
            <th>Brigády</th>
        </tr>";

        foreach ($radky as $z) {
            $trida_rozdil = $z['rozdil'] >= 0 ? 'green' : 'red';
            $brigady_str  = implode('<br>', $z['brigady_seznam']);
            echo "<tr>
                <td>" . $z['nazev'] . "</td>
                <td class='sb-small'>" . $z['jmena_deti'] . "</td>
                <td class='sb-small'>" . $z['jmena_rodicu'] . "</td>
                <td class='center'>" . $z['pocet_deti'] . "</td>
                <td class='center'>" . $z['odpracovano'] . "</td>
                <td class='center'>" . $z['pozadavek'] . "</td>
                <td class='center $trida_rozdil'>" . intval($z['rozdil']) . "</td>
                <td class='center'>" . number_format($z['poplatek'], 2, ',', ' ') . "</td>
                <td class='sb-small'>$brigady_str</td>
            </tr>";
        }
        echo "</table>";

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
    $current_user = wp_get_current_user();
    $roles = $current_user->roles;
    if (!array_intersect($roles, ['administrator', 'spravce_brigad'])) {
        return '<p>Nemáš oprávnění pro správu rodin.</p>';
    }

    $rodiny = get_posts([
        'post_type'   => 'rodina',
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC'
    ]);
    $vsichni_rodice = get_users(['role' => 'rodic']);
    $zprava = '';

    // Seřazení rodičů podle příjmení (z display_name sebere poslední slovo)
    usort($vsichni_rodice, function($a, $b) {
    $la = $a->last_name ?: array_slice(explode(' ', $a->display_name), -1)[0];
    $lb = $b->last_name ?: array_slice(explode(' ', $b->display_name), -1)[0];
    $fa = $a->first_name ?: $a->display_name;
    $fb = $b->first_name ?: $b->display_name;
    $cmp = strcasecmp($la, $lb);
    return $cmp === 0 ? strcasecmp($fa, $fb) : $cmp;
});

    // Zjistit, kteří rodiče už jsou přiřazeni a ke které rodině
    $obsazeni_rodice = [];
    foreach ($rodiny as $r) {
        $prirazeni = get_post_meta($r->ID, 'rodice_rodiny', true);
        if (is_array($prirazeni)) {
            foreach ($prirazeni as $rid) {
                $obsazeni_rodice[$rid] = $r->ID;
            }
        }
    }

    // Seznam oddílů
    $oddily = [
        'Benjamínci',
        'Lišky (světlušky)',
        'Braši (vlčata)',
        'Sněženky (skautky)',
        'Skauti',
        'Rangers a roveři'
    ];

    // Uložení změn v rodině (název, rodiče, děti) – pouze při submitu tlačítka "Uložit rodinu"
    if (isset($_POST['edit_rodina_id']) && isset($_POST['ulozit_rodinu'])) {

        // Ověření nonce uložení rodiny
        if (!isset($_POST['_wpnonce_ulozit_rodinu']) ||
            !wp_verify_nonce($_POST['_wpnonce_ulozit_rodinu'], 'ulozit_rodinu')) {

            $zprava .= sb_alert('Neplatný bezpečnostní token.', 'error');
            $zprava .= "<script>setTimeout(function() { sbShowSection('prehled'); }, 100);</script>";

        } else {

            $id = intval($_POST['edit_rodina_id']);
            $nazev = sanitize_text_field($_POST['novy_nazev']);
            $vybrani_rodice = isset($_POST['rodice']) ? array_map('intval', $_POST['rodice']) : [];

            // Děti z formuláře
            $deti = [];
            if (isset($_POST['deti']) && is_array($_POST['deti'])) {
                foreach ($_POST['deti'] as $dite) {
                    if (!empty($dite['jmeno'])) {
                        $deti[] = [
                            'jmeno' => sanitize_text_field($dite['jmeno']),
                            'oddil' => sanitize_text_field($dite['oddil'])
                        ];
                    }
                }
            }

            // Kontrola konfliktů rodičů (jiná rodina)
            $konflikt = [];
            foreach ($vybrani_rodice as $rid) {
                if (isset($obsazeni_rodice[$rid]) && $obsazeni_rodice[$rid] != $id) {
                    $konflikt[] = $rid;
                }
            }

            if (!empty($konflikt)) {
                $zprava = sb_alert('Někteří vybraní rodiče už patří do jiné rodiny.', 'error');
            } else {
                wp_update_post(['ID' => $id, 'post_title' => $nazev]);
                update_post_meta($id, 'rodice_rodiny', $vybrani_rodice);
                update_post_meta($id, 'deti_rodiny', $deti);
                delete_post_meta($id, 'pocet_deti'); // uklid starého pole, pokud existuje
                $zprava = sb_alert('Rodina byla upravena.');
            }

            $zprava .= "<script>setTimeout(function() { sbShowSection('prehled'); }, 100);</script>";
        }
    }

    // Odebrání konkrétního dítěte – vlastní submit s nonce
    if (isset($_POST['smazat_dite'])) {

        if (!isset($_POST['_wpnonce_smazat_dite']) ||
            !wp_verify_nonce($_POST['_wpnonce_smazat_dite'], 'smazat_dite')) {

            $zprava .= sb_alert('Neplatný bezpečnostní token.', 'error');

        } else {

            $id = intval($_POST['edit_rodina_id']);
            $index = intval($_POST['smazat_dite']);
            $deti = get_post_meta($id, 'deti_rodiny', true);

            if (is_array($deti) && isset($deti[$index])) {
                unset($deti[$index]);
                $deti = array_values($deti);
                update_post_meta($id, 'deti_rodiny', $deti);
                $zprava = sb_alert('Dítě bylo odebráno.');
                $zprava .= "<script>setTimeout(function() { sbShowSection('prehled'); }, 100);</script>";
            }
        }
    }

    // Smazání rodiny – s ověřením nonce
    if (isset($_POST['confirm_delete_rodina'])) {

        if (!isset($_POST['_wpnonce_smazat_rodinu']) ||
            !wp_verify_nonce($_POST['_wpnonce_smazat_rodinu'], 'smazat_rodinu')) {

            $zprava .= sb_alert('Neplatný bezpečnostní token.', 'error');

        } else {
            wp_delete_post(intval($_POST['confirm_delete_rodina']), true);
            $zprava = sb_alert('Rodina byla smazána.');
            $zprava .= "<script>setTimeout(function() { sbShowSection('prehled'); }, 100);</script>";
        }
    }

    // Výběr rodiny k editaci
    if (isset($_POST['akce']) && $_POST['akce'] === 'vyber' && isset($_POST['vybrana_rodina'])) {
        $vybrana_id = intval($_POST['vybrana_rodina']);
        $zprava .= "<script>setTimeout(function() { sbShowSection('prehled'); }, 100);</script>";
    } else {
        $vybrana_id = 0;
    }

    ob_start();
    echo "<h3 class='sb-section-title'>👥 Správa rodin</h3>";
    echo $zprava;

    // Výběrový formulář
    echo "<div class='sb-card'>";
    echo "<form method='post' id='vyberRodinyForm' class='sb-form-row'>";
    echo "<div class='sb-form-group sb-fg-inline'><label>Vyber rodinu k úpravě:</label>";
    echo "<select name='vybrana_rodina' onchange='this.form.submit();'>";
    echo "<option value='0'>-- vyber --</option>";
    foreach ($rodiny as $r) {
        $selected = ($r->ID == $vybrana_id) ? 'selected' : '';
        echo "<option value='{$r->ID}' $selected>" . esc_html($r->post_title) . "</option>";
    }
    echo "</select></div>";
    echo "<input type='hidden' name='akce' value='vyber'>";
    echo "</form>";
    echo "</div>";

    // Formulář pro úpravu vybrané rodiny
    if ($vybrana_id) {
        $r = get_post($vybrana_id);
        $nazev = esc_attr($r->post_title);
        $prirazeni = get_post_meta($r->ID, 'rodice_rodiny', true);
        if (!is_array($prirazeni)) $prirazeni = [];
        $deti = get_post_meta($r->ID, 'deti_rodiny', true);
        if (!is_array($deti)) $deti = [];

        echo "<div class='sb-card'>";
        echo "<h4 class='sb-card-title'>✏️ " . esc_html($r->post_title) . "</h4>";
        echo "<form method='post'>";
        wp_nonce_field('ulozit_rodinu', '_wpnonce_ulozit_rodinu');
        echo "<input type='hidden' name='edit_rodina_id' value='{$r->ID}'>";

        echo "<div class='sb-form-group'><label>Název rodiny:</label><input type='text' name='novy_nazev' value='$nazev' required></div>";

        echo "<div class='sb-form-group'><label>Rodiče:</label>";
        foreach ($vsichni_rodice as $u) {
            $rodic_id = $u->ID;
            // Zobrazit jen členy této rodiny a volné rodiče
            $je_clen  = in_array($rodic_id, $prirazeni);
            $je_jinde = isset($obsazeni_rodice[$rodic_id]) && $obsazeni_rodice[$rodic_id] != $r->ID;
            if ($je_jinde) continue;
            $checked = $je_clen ? 'checked' : '';
            echo "<label class='sb-label-normal'><input type='checkbox' name='rodice[]' value='" . esc_attr($u->ID) . "' $checked> " . sb_format_rodic_jmeno($u) . "</label>";
        }
        echo "</div>";

        echo "<div class='sb-form-group'><label>Děti (max. 4):</label>";
        echo "<div id='deti-wrapper'>";
        for ($index = 0; $index < 4; $index++) {
            $jmeno_d = isset($deti[$index]['jmeno']) ? esc_attr($deti[$index]['jmeno']) : '';
            $oddil_d = isset($deti[$index]['oddil']) ? esc_attr($deti[$index]['oddil']) : '';
            echo "<div class='sb-children-row'>";
            echo "<input type='text' name='deti[$index][jmeno]' value='$jmeno_d' placeholder='Jméno dítěte'>";
            echo "<select name='deti[$index][oddil]'>";
            foreach ($oddily as $o) {
                $sel = ($o == $oddil_d) ? 'selected' : '';
                echo "<option value='" . esc_attr($o) . "' $sel>" . esc_html($o) . "</option>";
            }
            echo "</select></div>";
        }
        echo "</div></div>";

        echo "<div class='sb-btn-row'><input type='submit' name='ulozit_rodinu' value='💾 Uložit rodinu' class='sb-btn sb-btn-primary'></div>";
        echo "</form>";

        // Smazání jednotlivých dětí
        if (!empty($deti)) {
            echo "<div class='sb-action-section'>";
            echo "<strong>Odebrat konkrétní dítě:</strong><br><br>";
            foreach ($deti as $idx => $dite) {
                $popis = esc_html(($dite['jmeno'] ?? '') . ' – ' . ($dite['oddil'] ?? ''));
                echo "<form method='post' onsubmit='return confirm(\"Odebrat dítě?\")' style='display:inline-block; margin:0 6px 6px 0;'>";
                wp_nonce_field('smazat_dite', '_wpnonce_smazat_dite');
                echo "<input type='hidden' name='edit_rodina_id' value='{$r->ID}'>";
                echo "<input type='hidden' name='smazat_dite' value='{$idx}'>";
                echo "<input type='submit' value='🗑️ {$popis}' class='sb-btn sb-btn-danger sb-btn-sm'>";
                echo "</form>";
            }
            echo "</div>";
        }

        // Smazání rodiny
        echo "<div class='sb-action-section'>";
        echo "<form method='post' onsubmit='return confirm(\"Opravdu smazat celou rodinu?\")'>";
        wp_nonce_field('smazat_rodinu', '_wpnonce_smazat_rodinu');
        echo "<input type='hidden' name='confirm_delete_rodina' value='{$r->ID}'>";
        echo "<input type='submit' value='🗑️ Smazat rodinu' class='sb-btn sb-btn-danger'>";
        echo "</form></div>";
        echo "</div>"; // sb-card
    }

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
            $rodina = $r;
            break;
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
        }
    }

    // --- Seznam dětí ---
    $deti       = get_post_meta($rodina_id, 'deti_rodiny', true);
    $pocet_deti = is_array($deti) ? count($deti) : 0;

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

    // --- Požadavky pro aktivní rok ---
    $pozadavky_vse = get_option('pozadavky_dle_roku', []);
    $pozadavky_rok = isset($pozadavky_vse[$rok_aktivni]) ? $pozadavky_vse[$rok_aktivni] : null;

    $pozadavek = 0;
    $sazba     = 0;

    if ($pozadavky_rok) {
        if ($pocet_deti == 1) {
            $pozadavek = intval($pozadavky_rok['1']);
        } elseif ($pocet_deti == 2) {
            $pozadavek = intval($pozadavky_rok['2']);
        } elseif ($pocet_deti >= 3) {
            $pozadavek = intval($pozadavky_rok['3']);
        }
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

    $zbyva    = max(0, $pozadavek - $odpracovano_aktivni);
    $barva    = ($zbyva === 0) ? '#2e7d32' : '#d32f2f';
    $poplatek = $zbyva * $sazba;

    // --- Druhý rok ---
    $pozadavky_druhy = ($rok_druhy && isset($pozadavky_vse[$rok_druhy])) ? $pozadavky_vse[$rok_druhy] : null;
    $pozadavek_druhy = 0;
    $sazba_druhy     = 0;
    $zbyva_druhy     = 0;
    $poplatek_druhy  = 0;

    if ($rok_druhy && $pozadavky_druhy) {
        if ($pocet_deti == 1) {
            $pozadavek_druhy = intval($pozadavky_druhy['1']);
        } elseif ($pocet_deti == 2) {
            $pozadavek_druhy = intval($pozadavky_druhy['2']);
        } elseif ($pocet_deti >= 3) {
            $pozadavek_druhy = intval($pozadavky_druhy['3']);
        }
        $sazba_druhy    = isset($pozadavky_druhy['sazba']) ? floatval($pozadavky_druhy['sazba']) : 0;
        $zbyva_druhy    = max(0, $pozadavek_druhy - $odpracovano_druhy);
        $poplatek_druhy = $zbyva_druhy * $sazba_druhy;
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
    echo "Rodina: <strong>$nazev_rodiny</strong> &nbsp;·&nbsp; Děti: <strong>$pocet_deti</strong>";
    if (is_array($deti) && !empty($deti)) {
        $jmena_deti = array_map(fn($d) => esc_html($d['jmeno']) . ' (' . esc_html($d['oddil']) . ')', $deti);
        echo " — " . implode(', ', $jmena_deti);
    }
    echo "</div>";

    // --- Přehled požadavků ---
    echo "<h5 class='sbf-col-title' style='margin-bottom:10px;'>📘 Přehled brigádnických požadavků</h5>";
    echo "<div style='display:flex; flex-wrap:wrap; gap:12px; margin-bottom:16px;'>";

    $barva_zbyva = ($zbyva === 0) ? '#2e7d32' : '#c0392b';
    echo "<div style='flex:0 0 auto; border:1px solid #b0d4ea; border-radius:4px; padding:8px 14px; background:#f0f8ff; font-size:13px;'>";
    echo "<div style='font-weight:600; margin-bottom:4px;'>Rok $rok_aktivni</div>";
    echo "<table class='sbf-req-table'>";
    echo "<tr><td style='padding-right:16px; color:#555;'>Požadavek:</td><td><strong>$pozadavek h</strong></td></tr>";
    echo "<tr><td style='color:#555;'>Odpracováno:</td><td><strong>$odpracovano_aktivni h</strong></td></tr>";
    echo "<tr><td style='color:#555;'>Zbývá:</td><td><strong style='color:" . esc_attr($barva_zbyva) . ";'>$zbyva h</strong></td></tr>";
    echo "<tr><td style='color:#555;'>Sazba:</td><td>" . number_format($sazba, 0, ',', ' ') . " Kč/h</td></tr>";
    echo "<tr><td style='color:#555;'>Poplatek:</td><td><strong>" . number_format($poplatek, 0, ',', ' ') . " Kč</strong></td></tr>";
    echo "</table></div>";

    if ($rok_druhy) {
        $barva_zbyva_d = ($zbyva_druhy === 0) ? '#2e7d32' : '#c0392b';
        echo "<div style='flex:0 0 auto; border:1px solid #ddd; border-radius:4px; padding:8px 14px; background:#f9f9f9; font-size:13px;'>";
        echo "<div style='font-weight:600; margin-bottom:4px;'>Rok $rok_druhy</div>";
        echo "<table class='sbf-req-table'>";
        echo "<tr><td style='padding-right:16px; color:#555;'>Požadavek:</td><td><strong>$pozadavek_druhy h</strong></td></tr>";
        echo "<tr><td style='color:#555;'>Odpracováno:</td><td><strong>$odpracovano_druhy h</strong></td></tr>";
        echo "<tr><td style='color:#555;'>Zbývá:</td><td><strong style='color:" . esc_attr($barva_zbyva_d) . ";'>$zbyva_druhy h</strong></td></tr>";
        echo "<tr><td style='color:#555;'>Sazba:</td><td>" . number_format($sazba_druhy, 0, ',', ' ') . " Kč/h</td></tr>";
        echo "<tr><td style='color:#555;'>Poplatek:</td><td><strong>" . number_format($poplatek_druhy, 0, ',', ' ') . " Kč</strong></td></tr>";
        echo "</table></div>";
    }

    echo "</div>";

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

    $pozadavky_vse = get_option('pozadavky_dle_roku', []);
    $pozadavky_rok = isset($pozadavky_vse[$rok_aktivni]) ? $pozadavky_vse[$rok_aktivni] : null;
    $pozadavek = $sazba = 0;
    if ($pozadavky_rok) {
        $pozadavek = intval($pozadavky_rok[min($pocet_deti, 3)] ?? 0);
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

    $zbyva    = max(0, $pozadavek - $odpracovano_aktivni);
    $poplatek = $zbyva * $sazba;

    $pozadavky_druhy = ($rok_druhy && isset($pozadavky_vse[$rok_druhy])) ? $pozadavky_vse[$rok_druhy] : null;
    $pozadavek_druhy = $sazba_druhy = $zbyva_druhy = $poplatek_druhy = 0;
    if ($rok_druhy && $pozadavky_druhy) {
        $pozadavek_druhy = intval($pozadavky_druhy[min($pocet_deti, 3)] ?? 0);
        $sazba_druhy     = floatval($pozadavky_druhy['sazba'] ?? 0);
        $zbyva_druhy     = max(0, $pozadavek_druhy - $odpracovano_druhy);
        $poplatek_druhy  = $zbyva_druhy * $sazba_druhy;
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
    echo "<div style='border:1px solid #b0d4ea; border-radius:4px; padding:8px 14px; background:#f0f8ff; font-size:13px;'>";
    echo "<div style='font-weight:600; margin-bottom:4px;'>Rok $rok_aktivni</div><table class='sbf-req-table'>";
    echo "<tr><td style='padding-right:16px; color:#555;'>Požadavek:</td><td><strong>$pozadavek h</strong></td></tr>";
    echo "<tr><td style='color:#555;'>Odpracováno:</td><td><strong>$odpracovano_aktivni h</strong></td></tr>";
    echo "<tr><td style='color:#555;'>Zbývá:</td><td><strong style='color:" . esc_attr($barva_z) . ";'>$zbyva h</strong></td></tr>";
    echo "<tr><td style='color:#555;'>Sazba:</td><td>" . number_format($sazba, 0, ',', ' ') . " Kč/h</td></tr>";
    echo "<tr><td style='color:#555;'>Poplatek:</td><td><strong>" . number_format($poplatek, 0, ',', ' ') . " Kč</strong></td></tr>";
    echo "</table></div>";

    if ($rok_druhy && $pozadavky_druhy) {
        $barva_zd = ($zbyva_druhy === 0) ? '#2e7d32' : '#c0392b';
        echo "<div style='border:1px solid #ddd; border-radius:4px; padding:8px 14px; background:#f9f9f9; font-size:13px;'>";
        echo "<div style='font-weight:600; margin-bottom:4px;'>Rok $rok_druhy</div><table class='sbf-req-table'>";
        echo "<tr><td style='padding-right:16px; color:#555;'>Požadavek:</td><td><strong>$pozadavek_druhy h</strong></td></tr>";
        echo "<tr><td style='color:#555;'>Odpracováno:</td><td><strong>$odpracovano_druhy h</strong></td></tr>";
        echo "<tr><td style='color:#555;'>Zbývá:</td><td><strong style='color:" . esc_attr($barva_zd) . ";'>$zbyva_druhy h</strong></td></tr>";
        echo "<tr><td style='color:#555;'>Sazba:</td><td>" . number_format($sazba_druhy, 0, ',', ' ') . " Kč/h</td></tr>";
        echo "<tr><td style='color:#555;'>Poplatek:</td><td><strong>" . number_format($poplatek_druhy, 0, ',', ' ') . " Kč</strong></td></tr>";
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

    // Vypiš jen budoucí / dnešní brigády
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
    continue;
}

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


