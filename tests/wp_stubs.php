<?php
// Atrapy WordPressu pro testování pluginu mimo WordPress (bez databáze).
// Spuštění testů: php tests/test_plugin.php   (z kořene repozitáře)
// Tato složka se na server nenahrává (viz .github/workflows/deploy.yml).

error_reporting(E_ALL);
define('ABSPATH', '/x/');
$_SERVER['REQUEST_METHOD'] = 'GET';

$GLOBALS['posts'] = []; $GLOBALS['meta'] = []; $GLOBALS['opts'] = []; $GLOBALS['users'] = [];
$GLOBALS['roles'] = ['author']; $GLOBALS['user_id'] = 0; $GLOBALS['logged'] = false;
$GLOBALS['hooks'] = []; $GLOBALS['deleted'] = []; $GLOBALS['maily'] = [];

function add_action($h, $cb, $pr = 10, $n = 1) { $GLOBALS['hooks'][$h][] = $cb; }
function remove_action($h, $cb) { foreach (($GLOBALS['hooks'][$h] ?? []) as $i => $c) if ($c === $cb) unset($GLOBALS['hooks'][$h][$i]); }
function add_shortcode(...$a) {} function add_filter(...$a) {}
function register_activation_hook(...$a) {} function register_deactivation_hook(...$a) {}
function wp_slash($v) { return $v; } function wp_unslash($v) { return $v; }
function wp_cache_delete(...$a) {}
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_attr($t) { return esc_html($t); }
function esc_url($u) { return htmlspecialchars((string) $u, ENT_QUOTES); }
function remove_accents($t) { return strtr($t, ['á'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ň'=>'n','ó'=>'o','ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ý'=>'y','ž'=>'z','Á'=>'A','Č'=>'C','Ď'=>'D','É'=>'E','Ě'=>'E','Í'=>'I','Ň'=>'N','Ó'=>'O','Ř'=>'R','Š'=>'S','Ť'=>'T','Ú'=>'U','Ů'=>'U','Ý'=>'Y','Ž'=>'Z']); }
function wp_nonce_field($a, $n) { echo "<input type='hidden' name='$n' value='tok'>"; }
function wp_verify_nonce($v, $a) { return $v === 'tok' ? 1 : false; }
function wp_create_nonce($a) { return 'tok'; }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function wp_get_current_user() { return (object) ['ID' => $GLOBALS['user_id'], 'roles' => $GLOBALS['roles'], 'user_email' => 'spravce@example.cz']; }
function get_current_user_id() { return $GLOBALS['user_id']; }
function is_user_logged_in() { return !empty($GLOBALS['logged']); }
function get_option($k, $d = false) { return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k, $v, $al = null) { $GLOBALS['opts'][$k] = $v; return true; }
function get_post($id) { return $GLOBALS['posts'][intval($id)] ?? null; }
function get_the_title($id) { return isset($GLOBALS['posts'][$id]) ? $GLOBALS['posts'][$id]->post_title : ''; }
function get_posts($args = []) {
    $r = [];
    foreach ($GLOBALS['posts'] as $p) { if (isset($args['post_type']) && $p->post_type !== $args['post_type']) continue; $r[] = $p; }
    usort($r, function($a, $b) { return strcmp($a->post_title, $b->post_title); });
    return $r;
}
function get_post_meta($id, $k = '', $single = false) {
    if ($k === '') { $r = []; foreach (($GLOBALS['meta'][$id] ?? []) as $kk => $vv) $r[$kk] = [$vv]; return $r; }
    return $GLOBALS['meta'][$id][$k] ?? '';
}
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }
function delete_post_meta($id, $k) { unset($GLOBALS['meta'][$id][$k]); return true; }
function wp_update_post($a) {
    $p = $GLOBALS['posts'][$a['ID']]; $pred = clone $p;
    if (isset($a['post_title'])) $p->post_title = $a['post_title'];
    foreach (($GLOBALS['hooks']['post_updated'] ?? []) as $cb) $cb($a['ID'], clone $p, $pred);
    return $a['ID'];
}
function wp_delete_post($id, $f = false) { $GLOBALS['deleted'][] = $id; unset($GLOBALS['posts'][$id]); return true; }
function wp_insert_post($a) {
    $id = max(array_merge([0], array_keys($GLOBALS['posts']))) + 1;
    $GLOBALS['posts'][$id] = (object) ['ID' => $id, 'post_type' => $a['post_type'], 'post_title' => $a['post_title'], 'post_date' => date('Y-m-d H:i:s'), 'post_status' => 'publish'];
    return $id;
}
function get_users($a = []) { return array_values($GLOBALS['users']); }
function get_userdata($id) { return $GLOBALS['users'][$id] ?? false; }
function get_user_by($f, $v) { return $GLOBALS['users'][$v] ?? false; }
function wp_date($f) { return date($f); }
function wp_timezone() { return new DateTimeZone('Europe/Prague'); }
function add_query_arg($a, $u = '') { return '?' . http_build_query($a); }
function get_permalink($id = 0) { return 'https://example.cz/stranka-' . intval($id) . '/'; }
function home_url($p = '') { return 'https://example.cz' . $p; }
function wp_mail($to, $subj, $body, $headers = []) {
    $pm = new class { public $obrazky = []; function addStringEmbeddedImage($data, $cid, $name, $enc, $type) { $this->obrazky[] = [$cid, strlen($data)]; } };
    foreach (($GLOBALS['hooks']['phpmailer_init'] ?? []) as $cb) $cb($pm);
    $GLOBALS['maily'][] = ['to' => $to, 'subject' => $subj, 'body' => $body, 'headers' => $headers, 'obrazky' => $pm->obrazky];
    return true;
}
class FakeWpdb {
    public $posts = 'posts';
    function prepare($q, ...$a) { return $a; }
    function get_var($a) {
        if (is_string($a)) {
            if (strpos($a, '[moje_brigady') !== false) return 4242;
            return null;
        }
        foreach ($GLOBALS['posts'] as $p) if ($p->post_type === $a[0] && $p->post_title === $a[1]) return $p->ID;
        return null;
    }
}
$GLOBALS['wpdb'] = new FakeWpdb();
