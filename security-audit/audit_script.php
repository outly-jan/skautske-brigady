<?php
/**
 * WordPress Security Audit Script
 * Site: skautchlumec.cz
 *
 * INSTRUCTIONS:
 * 1. Change AUDIT_KEY below to a strong random password
 * 2. Upload to public_html root (same dir as wp-config.php)
 * 3. Access via: https://yourdomain.com/audit_script.php?audit_key=YOUR_KEY
 * 4. After review, delete via: https://yourdomain.com/audit_script.php?audit_key=YOUR_KEY&self_delete=1
 * 5. Or delete manually from the server
 *
 * Compatible: PHP 7.4+, WordPress 5.x+
 */

// ============================================================
//  CONFIGURATION - CHANGE THIS BEFORE UPLOADING
// ============================================================
define('AUDIT_KEY', 'CHANGE_ME_NOW_USE_RANDOM_STRING');
// ============================================================

// Self-delete feature
if (isset($_GET['self_delete']) && $_GET['self_delete'] === '1' && isset($_GET['audit_key']) && $_GET['audit_key'] === AUDIT_KEY) {
    $self = __FILE__;
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Self Delete</title></head><body>';
    echo '<h2>Deleting audit script...</h2>';
    if (unlink($self)) {
        echo '<p style="color:green;font-weight:bold;">Script deleted successfully. This file no longer exists on the server.</p>';
    } else {
        echo '<p style="color:red;font-weight:bold;">Failed to delete script. Please delete manually: ' . htmlspecialchars($self) . '</p>';
    }
    echo '</body></html>';
    exit;
}

// Password check
if (!isset($_GET['audit_key']) || $_GET['audit_key'] !== AUDIT_KEY) {
    http_response_code(403);
    die('403 Forbidden');
}

// Security: prevent caching
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Content-Type: text/html; charset=UTF-8');

// ============================================================
//  HELPERS
// ============================================================

function sev_badge(string $level): string {
    $map = [
        'CRITICAL' => ['bg' => '#c0392b', 'fg' => '#fff'],
        'WARNING'  => ['bg' => '#e67e22', 'fg' => '#fff'],
        'OK'       => ['bg' => '#27ae60', 'fg' => '#fff'],
        'INFO'     => ['bg' => '#2980b9', 'fg' => '#fff'],
    ];
    $c = $map[$level] ?? ['bg' => '#7f8c8d', 'fg' => '#fff'];
    return sprintf(
        '<span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:0.8em;font-weight:bold;background:%s;color:%s;">%s</span>',
        $c['bg'], $c['fg'], htmlspecialchars($level)
    );
}

function row(string $label, string $value, string $level = 'INFO'): string {
    $bg = ['CRITICAL' => '#fde8e8', 'WARNING' => '#fef3e2', 'OK' => '#e8f8ee', 'INFO' => '#eaf3fb'];
    $bg_color = $bg[$level] ?? '#f9f9f9';
    return sprintf(
        '<tr style="background:%s;"><td style="padding:6px 10px;font-weight:bold;width:35%%;border-bottom:1px solid #ddd;">%s</td><td style="padding:6px 10px;border-bottom:1px solid #ddd;">%s</td><td style="padding:6px 10px;border-bottom:1px solid #ddd;text-align:center;">%s</td></tr>',
        $bg_color,
        htmlspecialchars($label),
        $value,
        sev_badge($level)
    );
}

function section(string $title, string $icon = ''): string {
    return sprintf(
        '<h2 style="margin-top:40px;padding:10px 15px;background:#2c3e50;color:#ecf0f1;border-radius:5px;">%s %s</h2>',
        $icon,
        htmlspecialchars($title)
    );
}

function table_start(): string {
    return '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:0.9em;"><thead><tr style="background:#34495e;color:#fff;"><th style="padding:8px 10px;text-align:left;">Item</th><th style="padding:8px 10px;text-align:left;">Value / Details</th><th style="padding:8px 10px;text-align:center;width:100px;">Status</th></tr></thead><tbody>';
}

function table_end(): string {
    return '</tbody></table>';
}

function contains_dangerous_pattern(string $code): array {
    $patterns = [
        'eval('              => 'CRITICAL',
        'base64_decode('     => 'CRITICAL',
        'exec('              => 'CRITICAL',
        'system('            => 'CRITICAL',
        'passthru('          => 'CRITICAL',
        'shell_exec('        => 'CRITICAL',
        'file_put_contents(' => 'WARNING',
        'assert('            => 'WARNING',
        'preg_replace'       => 'INFO',   // checked below for /e
        'create_function('   => 'CRITICAL',
        'call_user_func('    => 'WARNING',
        'popen('             => 'CRITICAL',
        'proc_open('         => 'CRITICAL',
        'curl_exec('         => 'WARNING',
        'fsockopen('         => 'WARNING',
        'gzinflate('         => 'WARNING',
        'str_rot13('         => 'WARNING',
    ];

    $found = [];
    foreach ($patterns as $pattern => $severity) {
        if (stripos($code, $pattern) !== false) {
            if ($pattern === 'preg_replace') {
                // Only flag if /e modifier is present
                if (preg_match('#preg_replace\s*\(\s*[\'"].*?/e[\'"]#i', $code)) {
                    $found[$pattern . ' with /e modifier'] = 'CRITICAL';
                }
            } else {
                $found[$pattern] = $severity;
            }
        }
    }
    return $found;
}

// ============================================================
//  BOOTSTRAP WORDPRESS
// ============================================================
$wp_root = __DIR__;
$wp_config_path = $wp_root . '/wp-config.php';
$wp_load_path   = $wp_root . '/wp-load.php';

// Load WordPress without running theme/plugins unnecessarily
if (file_exists($wp_load_path)) {
    // Suppress output from plugins/themes during load
    ob_start();
    try {
        require_once $wp_load_path;
    } catch (\Throwable $e) {
        ob_end_clean();
        // If WP fails to load, we still run what we can without DB
    }
    ob_end_clean();
    $wp_loaded = function_exists('get_option');
} else {
    $wp_loaded = false;
}

$db_available = $wp_loaded && isset($wpdb) && $wpdb instanceof wpdb;

// ============================================================
//  HTML OUTPUT STARTS
// ============================================================
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WordPress Security Audit - skautchlumec.cz</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f2f5; color: #333; margin: 0; padding: 20px; }
        .container { max-width: 1100px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #2c3e50, #3498db); color: white; padding: 25px; border-radius: 6px; margin-bottom: 30px; }
        .header h1 { margin: 0 0 5px 0; font-size: 1.8em; }
        .header p { margin: 0; opacity: 0.85; }
        .warning-box { background: #c0392b; color: white; padding: 12px 18px; border-radius: 5px; margin-bottom: 20px; font-weight: bold; }
        .info-box { background: #2980b9; color: white; padding: 12px 18px; border-radius: 5px; margin-bottom: 20px; }
        pre { background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 5px; overflow-x: auto; font-size: 0.82em; white-space: pre-wrap; word-break: break-all; }
        .delete-btn { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #c0392b; color: white; text-decoration: none; border-radius: 5px; font-weight: bold; }
        .delete-btn:hover { background: #a93226; }
        .summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin: 20px 0; }
        .summary-card { padding: 15px; border-radius: 6px; text-align: center; }
        .summary-card .count { font-size: 2em; font-weight: bold; }
        .summary-card .label { font-size: 0.85em; margin-top: 5px; }
        .card-critical { background: #fde8e8; color: #c0392b; }
        .card-warning  { background: #fef3e2; color: #e67e22; }
        .card-ok       { background: #e8f8ee; color: #27ae60; }
        .card-info     { background: #eaf3fb; color: #2980b9; }
        code { background: #f4f4f4; padding: 1px 5px; border-radius: 3px; font-size: 0.9em; }
        .snippet-block { background: #f9f9f9; border: 1px solid #ddd; border-radius: 5px; padding: 15px; margin: 10px 0; }
        .snippet-block h4 { margin: 0 0 8px 0; color: #2c3e50; }
    </style>
</head>
<body>
<div class="container">

<div class="header">
    <h1>WordPress Security Audit</h1>
    <p>Site: <strong>skautchlumec.cz</strong> &nbsp;|&nbsp; Generated: <strong><?= date('Y-m-d H:i:s T') ?></strong> &nbsp;|&nbsp; Server: <strong><?= htmlspecialchars($_SERVER['SERVER_NAME'] ?? 'unknown') ?></strong></p>
</div>

<div class="warning-box">
    &#9888; SENSITIVE REPORT - Contains security information. Delete this file immediately after review using the button at the bottom.
</div>

<?php if (!$wp_loaded): ?>
<div class="warning-box">WordPress could not be loaded. Some checks require WordPress/DB access and will be skipped. Verify the script is in the same directory as wp-config.php.</div>
<?php endif; ?>

<?php
// ============================================================
//  COUNTERS
// ============================================================
$counts = ['CRITICAL' => 0, 'WARNING' => 0, 'OK' => 0, 'INFO' => 0];

// We'll collect all rows to count severities; re-use a buffer approach
$sections_html = '';
ob_start();

// ============================================================
//  SECTION 1: WORDPRESS VERSION
// ============================================================
echo section('1. WordPress Version & Core', '&#128274;');
echo table_start();

$wp_version = 'Unknown';
$wp_version_file = $wp_root . '/wp-includes/version.php';
if (file_exists($wp_version_file)) {
    include $wp_version_file;
    // $wp_version is now set by version.php
}

if ($wp_version !== 'Unknown') {
    // Very rough check - anything below 6.4 could have known vulns
    // Actual latest as of script writing - admin should verify
    $is_old = version_compare($wp_version, '6.0', '<');
    $sev = $is_old ? 'WARNING' : 'OK';
    echo row('WordPress Version', htmlspecialchars($wp_version) . ($is_old ? ' <strong>(Potentially outdated - verify latest at wordpress.org)</strong>' : ' (verify against latest at wordpress.org)'), $sev);
} else {
    echo row('WordPress Version', 'Could not determine', 'WARNING');
}

if ($wp_loaded) {
    $db_version = get_option('db_version');
    echo row('DB Version', htmlspecialchars((string)$db_version), 'INFO');
}

echo table_end();

// ============================================================
//  SECTION 2: ACTIVE PLUGINS
// ============================================================
echo section('2. Active Plugins', '&#128268;');

if ($wp_loaded) {
    $active_plugins = get_option('active_plugins', []);
    $all_plugins = [];
    $plugin_dir = $wp_root . '/wp-content/plugins/';

    if (!empty($active_plugins)) {
        echo '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:0.9em;">';
        echo '<thead><tr style="background:#34495e;color:#fff;"><th style="padding:8px;">Plugin File</th><th style="padding:8px;">Name</th><th style="padding:8px;">Version</th><th style="padding:8px;">Status</th></tr></thead><tbody>';

        foreach ($active_plugins as $plugin_file) {
            $full_path = $plugin_dir . $plugin_file;
            $name = $plugin_file;
            $version = 'Unknown';

            if (file_exists($full_path)) {
                $plugin_data = get_file_data($full_path, ['Name' => 'Plugin Name', 'Version' => 'Version']);
                if (!empty($plugin_data['Name'])) $name = $plugin_data['Name'];
                if (!empty($plugin_data['Version'])) $version = $plugin_data['Version'];
            }

            $known_risky = ['revslider', 'wysija', 'gravityforms', 'wp-file-manager', 'wp-super-cache'];
            $is_risky = false;
            foreach ($known_risky as $r) {
                if (stripos($plugin_file, $r) !== false) { $is_risky = true; break; }
            }

            $row_bg = $is_risky ? '#fef3e2' : '#f9f9f9';
            echo sprintf(
                '<tr style="background:%s;"><td style="padding:6px 10px;border-bottom:1px solid #ddd;font-family:monospace;font-size:0.9em;">%s</td><td style="padding:6px 10px;border-bottom:1px solid #ddd;">%s</td><td style="padding:6px 10px;border-bottom:1px solid #ddd;">%s</td><td style="padding:6px 10px;border-bottom:1px solid #ddd;text-align:center;">%s</td></tr>',
                $row_bg,
                htmlspecialchars($plugin_file),
                htmlspecialchars($name),
                htmlspecialchars($version),
                $is_risky ? sev_badge('WARNING') : sev_badge('OK')
            );
        }
        echo '</tbody></table>';
        echo '<p class="info-box">Total active plugins: <strong>' . count($active_plugins) . '</strong>. Cross-check versions at <a href="https://wpvulndb.com" style="color:#fff;">wpvulndb.com</a> or <a href="https://wpscan.com" style="color:#fff;">wpscan.com</a>.</p>';
    } else {
        echo '<p>No active plugins found or could not retrieve plugin list.</p>';
    }
} else {
    echo '<p style="color:orange;">WordPress not loaded - cannot check plugins from DB.</p>';
    // Fallback: scan plugins directory
    $plugin_dir = $wp_root . '/wp-content/plugins/';
    if (is_dir($plugin_dir)) {
        echo '<p>Plugins directory listing (may include inactive):</p><ul>';
        foreach (glob($plugin_dir . '*', GLOB_ONLYDIR) as $dir) {
            echo '<li>' . htmlspecialchars(basename($dir)) . '</li>';
        }
        echo '</ul>';
    }
}

// ============================================================
//  SECTION 3: WP-CONFIG.PHP SECURITY SETTINGS
// ============================================================
echo section('3. wp-config.php Security Settings', '&#9881;');
echo table_start();

if (file_exists($wp_config_path)) {
    $config_content = file_get_contents($wp_config_path);

    // File permissions
    $perms = fileperms($wp_config_path);
    $perms_octal = substr(sprintf('%o', $perms), -4);
    $perms_ok = in_array($perms_octal, ['0400', '0440', '0600', '0640']);
    echo row('wp-config.php permissions', $perms_octal . ($perms_ok ? '' : ' <strong>(Should be 0600 or 0400)</strong>'), $perms_ok ? 'OK' : 'CRITICAL');

    // Key settings checks via regex
    $checks = [
        ['WP_DEBUG', "define\s*\(\s*'WP_DEBUG'\s*,\s*(true|false)", 'false', 'CRITICAL', 'WP_DEBUG should be false on production'],
        ['WP_DEBUG_LOG', "define\s*\(\s*'WP_DEBUG_LOG'\s*,\s*(true|false)", 'false', 'WARNING', 'WP_DEBUG_LOG should be false'],
        ['WP_DEBUG_DISPLAY', "define\s*\(\s*'WP_DEBUG_DISPLAY'\s*,\s*(true|false)", 'false', 'WARNING', 'WP_DEBUG_DISPLAY should be false'],
        ['DISALLOW_FILE_EDIT', "define\s*\(\s*'DISALLOW_FILE_EDIT'\s*,\s*(true|false)", 'true', 'CRITICAL', 'DISALLOW_FILE_EDIT must be true'],
        ['DISALLOW_FILE_MODS', "define\s*\(\s*'DISALLOW_FILE_MODS'\s*,\s*(true|false)", 'true', 'WARNING', 'DISALLOW_FILE_MODS recommended true'],
        ['FORCE_SSL_ADMIN', "define\s*\(\s*'FORCE_SSL_ADMIN'\s*,\s*(true|false)", 'true', 'WARNING', 'FORCE_SSL_ADMIN should be true'],
    ];

    foreach ($checks as [$name, $pattern, $expected, $sev_if_wrong, $note]) {
        if (preg_match('/' . $pattern . '/i', $config_content, $matches)) {
            $val = strtolower($matches[1]);
            $ok = ($val === strtolower($expected));
            echo row("define('$name')", $val . ($ok ? '' : " <strong>$note</strong>"), $ok ? 'OK' : $sev_if_wrong);
        } else {
            $missing_sev = ($name === 'DISALLOW_FILE_EDIT') ? 'CRITICAL' : 'WARNING';
            echo row("define('$name')", 'NOT SET - ' . $note, $missing_sev);
        }
    }

    // Table prefix
    if (preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]/', $config_content, $m)) {
        $prefix = $m[1];
        $prefix_ok = ($prefix !== 'wp_');
        echo row('Table Prefix', htmlspecialchars($prefix) . ($prefix_ok ? '' : ' <strong>(Default "wp_" is insecure - change it)</strong>'), $prefix_ok ? 'OK' : 'WARNING');
    }

    // Auth keys / salts
    $has_salts = preg_match('/AUTH_KEY/', $config_content) && !preg_match('/put your unique phrase here/', $config_content);
    echo row('Auth Keys & Salts', $has_salts ? 'Defined (non-default)' : 'DEFAULT OR MISSING - Generate at https://api.wordpress.org/secret-key/1.1/salt/', $has_salts ? 'OK' : 'CRITICAL');

    // DB credentials exposure
    if (preg_match("/define\s*\(\s*'DB_HOST'\s*,\s*'([^']+)'/", $config_content, $m)) {
        $db_host = $m[1];
        $db_host_ok = ($db_host === 'localhost' || $db_host === '127.0.0.1');
        echo row('DB_HOST', htmlspecialchars($db_host) . ($db_host_ok ? '' : ' <strong>(Non-local DB host - verify this is intentional)</strong>'), $db_host_ok ? 'OK' : 'WARNING');
    }

} else {
    echo row('wp-config.php', 'FILE NOT FOUND at expected path', 'CRITICAL');
}

echo table_end();

// ============================================================
//  SECTION 4: .HTACCESS CONTENT
// ============================================================
echo section('4. .htaccess Review', '&#128196;');

$htaccess_path = $wp_root . '/.htaccess';
if (file_exists($htaccess_path)) {
    $htaccess = file_get_contents($htaccess_path);
    echo table_start();

    $htaccess_checks = [
        ['Block lostpassword', 'lostpassword', 'OK', 'WARNING', 'Not blocked - spam vector (known issue on this site)'],
        ['Block xmlrpc', 'xmlrpc', 'OK', 'WARNING', 'xmlrpc.php not blocked - bruteforce/DDoS amplification risk'],
        ['Block author enum', 'author', 'OK', 'INFO', 'User enumeration via ?author=N not blocked'],
        ['Block wp-config', 'wp-config', 'OK', 'CRITICAL', 'wp-config.php not explicitly blocked in .htaccess'],
        ['Block directory listing', 'Options.*-Indexes\|Options.*All', 'OK', 'WARNING', 'Directory listing may be enabled'],
        ['Block PHP in uploads', 'uploads', 'OK', 'CRITICAL', 'No PHP execution block in uploads directory'],
    ];

    foreach ($htaccess_checks as [$label, $search, $found_sev, $not_found_sev, $not_found_note]) {
        $found = (bool)preg_match('/' . $search . '/i', $htaccess);
        echo row($label, $found ? 'Rule found in .htaccess' : 'MISSING - ' . $not_found_note, $found ? $found_sev : $not_found_sev);
    }

    echo table_end();

    echo '<p><strong>Current .htaccess content:</strong></p>';
    echo '<pre>' . htmlspecialchars($htaccess) . '</pre>';
} else {
    echo table_start();
    echo row('.htaccess file', 'FILE NOT FOUND - WordPress may not be running on Apache, or file is missing', 'WARNING');
    echo table_end();
}

// ============================================================
//  SECTION 5: SUSPICIOUS PHP FILES IN ROOT
// ============================================================
echo section('5. PHP Files in Root (Suspicious File Scan)', '&#128269;');

$wp_core_root_files = [
    'index.php', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php',
    'wp-config.php', 'wp-config-sample.php', 'wp-cron.php', 'wp-links-opml.php',
    'wp-load.php', 'wp-login.php', 'wp-mail.php', 'wp-settings.php',
    'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php',
];

$php_files_root = glob($wp_root . '/*.php');
$suspicious_root = [];

if ($php_files_root) {
    echo '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:0.9em;">';
    echo '<thead><tr style="background:#34495e;color:#fff;"><th style="padding:8px;">File</th><th style="padding:8px;">Size</th><th style="padding:8px;">Last Modified</th><th style="padding:8px;">Status</th></tr></thead><tbody>';

    foreach ($php_files_root as $file) {
        $basename = basename($file);
        $is_core = in_array($basename, $wp_core_root_files);
        $is_this_script = ($file === __FILE__);
        $mtime = date('Y-m-d H:i:s', filemtime($file));
        $size = number_format(filesize($file)) . ' bytes';

        if ($is_this_script) {
            $sev = 'WARNING';
            $status = 'THIS AUDIT SCRIPT - DELETE AFTER USE';
        } elseif (!$is_core) {
            $sev = 'CRITICAL';
            $status = 'UNKNOWN/SUSPICIOUS - Not a WP core file';
            $suspicious_root[] = $basename;
        } else {
            $sev = 'OK';
            $status = 'WP Core file';
        }

        echo sprintf(
            '<tr style="background:%s;"><td style="padding:6px 10px;border-bottom:1px solid #ddd;font-family:monospace;">%s</td><td style="padding:6px 10px;border-bottom:1px solid #ddd;">%s</td><td style="padding:6px 10px;border-bottom:1px solid #ddd;">%s</td><td style="padding:6px 10px;border-bottom:1px solid #ddd;text-align:center;">%s</td></tr>',
            $sev === 'CRITICAL' ? '#fde8e8' : ($sev === 'WARNING' ? '#fef3e2' : '#f9f9f9'),
            htmlspecialchars($basename),
            $size,
            $mtime,
            sev_badge($sev)
        );
    }
    echo '</tbody></table>';
}

// Scan wp-content for .php files not in plugins/themes (potential backdoors)
echo '<p><strong>Scanning wp-content/ for unexpected PHP files (excluding plugins/ and themes/ subdirs):</strong></p>';
$wpcontent = $wp_root . '/wp-content';
$suspicious_wpcontent = [];

if (is_dir($wpcontent)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($wpcontent, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $unexpected = [];
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $rel = str_replace($wp_root . '/', '', $file->getPathname());
            // Flag PHP files directly in wp-content (not in plugins/ or themes/ subdirs)
            if (preg_match('#^wp-content/[^/]+\.php$#', $rel)) {
                $unexpected[] = $rel;
            }
        }
    }

    if ($unexpected) {
        echo '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:0.9em;">';
        echo '<thead><tr style="background:#34495e;color:#fff;"><th style="padding:8px;">File Path</th><th style="padding:8px;">Modified</th><th style="padding:8px;">Status</th></tr></thead><tbody>';
        $known_wpcontent_files = ['index.php', 'mu-plugins/index.php'];
        foreach ($unexpected as $rel_path) {
            $full = $wp_root . '/' . $rel_path;
            $mtime = date('Y-m-d H:i:s', filemtime($full));
            $is_known = (basename($rel_path) === 'index.php');
            $sev = $is_known ? 'OK' : 'WARNING';
            echo sprintf(
                '<tr style="background:%s;"><td style="padding:6px 10px;border-bottom:1px solid #ddd;font-family:monospace;">%s</td><td style="padding:6px 10px;border-bottom:1px solid #ddd;">%s</td><td style="padding:6px 10px;border-bottom:1px solid #ddd;text-align:center;">%s</td></tr>',
                $sev === 'WARNING' ? '#fef3e2' : '#f9f9f9',
                htmlspecialchars($rel_path),
                $mtime,
                sev_badge($sev)
            );
        }
        echo '</tbody></table>';
    } else {
        echo '<p style="color:#27ae60;">&#10003; No unexpected PHP files found directly in wp-content/.</p>';
    }
}

// ============================================================
//  SECTION 6: PHP FILES IN UPLOADS (BACKDOOR SCAN)
// ============================================================
echo section('6. PHP Files in Uploads Directory (Backdoor Scan)', '&#128193;');

$uploads_dir = $wp_root . '/wp-content/uploads';
$php_in_uploads = [];

if (is_dir($uploads_dir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploads_dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && in_array(strtolower($file->getExtension()), ['php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'pht'])) {
            $php_in_uploads[] = [
                'path' => str_replace($wp_root . '/', '', $file->getPathname()),
                'size' => $file->getSize(),
                'mtime' => date('Y-m-d H:i:s', $file->getMTime()),
            ];
        }
    }
}

echo table_start();
if (empty($php_in_uploads)) {
    echo row('PHP files in uploads/', 'None found', 'OK');
} else {
    echo row('PHP files in uploads/', count($php_in_uploads) . ' file(s) found - POTENTIAL BACKDOORS', 'CRITICAL');
    echo table_end();

    echo '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:0.9em;">';
    echo '<thead><tr style="background:#c0392b;color:#fff;"><th style="padding:8px;">Path</th><th style="padding:8px;">Size</th><th style="padding:8px;">Modified</th></tr></thead><tbody>';
    foreach ($php_in_uploads as $f) {
        echo sprintf(
            '<tr style="background:#fde8e8;"><td style="padding:6px 10px;border-bottom:1px solid #ddd;font-family:monospace;">%s</td><td style="padding:6px 10px;border-bottom:1px solid #ddd;">%s bytes</td><td style="padding:6px 10px;border-bottom:1px solid #ddd;">%s</td></tr>',
            htmlspecialchars($f['path']),
            number_format($f['size']),
            $f['mtime']
        );
    }
    echo '</tbody></table>';
    $already_ended = true;
}
if (!isset($already_ended)) echo table_end();
unset($already_ended);

// ============================================================
//  SECTION 7: WP_USERS CHECK
// ============================================================
echo section('7. WordPress Users (wp_users)', '&#128100;');

if ($db_available) {
    $users = $wpdb->get_results("
        SELECT u.ID, u.user_login, u.user_email, u.user_registered, u.user_status,
               MAX(CASE WHEN m.meta_key = '{$wpdb->prefix}capabilities' THEN m.meta_value END) AS capabilities
        FROM {$wpdb->prefix}users u
        LEFT JOIN {$wpdb->prefix}usermeta m ON u.ID = m.user_id
        GROUP BY u.ID
        ORDER BY u.ID ASC
        LIMIT 100
    ");

    if ($users) {
        echo '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:0.9em;">';
        echo '<thead><tr style="background:#34495e;color:#fff;"><th style="padding:8px;">ID</th><th style="padding:8px;">Login</th><th style="padding:8px;">Email</th><th style="padding:8px;">Registered</th><th style="padding:8px;">Roles</th><th style="padding:8px;">Status</th></tr></thead><tbody>';

        foreach ($users as $user) {
            $caps = maybe_unserialize($user->capabilities);
            $roles = is_array($caps) ? implode(', ', array_keys($caps)) : $user->capabilities;
            $is_admin = (strpos((string)$user->capabilities, 'administrator') !== false);
            $is_suspicious = (strpos(strtolower($user->user_login), 'admin') !== false && $user->user_login !== 'administrator') ||
                             preg_match('/^[a-z0-9]{8,}$/i', $user->user_login); // random-looking username

            $sev = 'OK';
            if ($is_admin) $sev = 'INFO';
            if ($is_suspicious && $is_admin) $sev = 'WARNING';

            echo sprintf(
                '<tr style="background:%s;"><td style="padding:6px 10px;border-bottom:1px solid #ddd;">%s</td><td style="padding:6px 10px;border-bottom:1px solid #ddd;font-weight:bold;">%s</td><td style="padding:6px 10px;border-bottom:1px solid #ddd;">%s</td><td style="padding:6px 10px;border-bottom:1px solid #ddd;">%s</td><td style="padding:6px 10px;border-bottom:1px solid #ddd;">%s</td><td style="padding:6px 10px;border-bottom:1px solid #ddd;text-align:center;">%s</td></tr>',
                $sev === 'WARNING' ? '#fef3e2' : ($sev === 'INFO' ? '#eaf3fb' : '#f9f9f9'),
                (int)$user->ID,
                htmlspecialchars($user->user_login),
                htmlspecialchars($user->user_email),
                htmlspecialchars($user->user_registered),
                htmlspecialchars($roles ?: 'none'),
                sev_badge($sev)
            );
        }
        echo '</tbody></table>';
        if (count($users) >= 100) echo '<p style="color:orange;">Note: Limited to first 100 users.</p>';
    } else {
        echo '<p>No users found.</p>';
    }
} else {
    echo '<p style="color:orange;">Database not available. Cannot check users.</p>';
}

// ============================================================
//  SECTION 8: CODE SNIPPETS AUDIT
// ============================================================
echo section('8. Code Snippets Plugin Audit (wp_snippets)', '&#128196;');

if ($db_available) {
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}snippets'");

    if ($table_exists) {
        $snippets = $wpdb->get_results("
            SELECT id, name, code, description, scope, active, modified
            FROM {$wpdb->prefix}snippets
            ORDER BY id ASC
        ");

        if ($snippets) {
            $any_danger = false;
            echo '<p>Total snippets: <strong>' . count($snippets) . '</strong></p>';

            foreach ($snippets as $snip) {
                $dangerous = contains_dangerous_pattern($snip->code ?? '');
                $is_active = (bool)$snip->active;
                $has_danger = !empty($dangerous);

                $border_color = $has_danger ? ($is_active ? '#c0392b' : '#e67e22') : ($is_active ? '#27ae60' : '#95a5a6');

                echo sprintf(
                    '<div class="snippet-block" style="border-left:4px solid %s;">',
                    $border_color
                );
                echo sprintf('<h4>Snippet #%d: %s %s %s</h4>',
                    (int)$snip->id,
                    htmlspecialchars($snip->name),
                    $is_active ? sev_badge('OK') . ' <small>ACTIVE</small>' : '<span style="color:#999;font-size:0.85em;">INACTIVE</span>',
                    $has_danger ? sev_badge($is_active ? 'CRITICAL' : 'WARNING') . ' <strong>DANGEROUS FUNCTIONS DETECTED</strong>' : ''
                );
                echo sprintf('<p style="font-size:0.85em;color:#666;margin:5px 0;">Scope: <code>%s</code> | Modified: %s</p>',
                    htmlspecialchars($snip->scope),
                    htmlspecialchars($snip->modified)
                );

                if ($has_danger) {
                    $any_danger = true;
                    echo '<p style="background:#fde8e8;padding:8px;border-radius:3px;"><strong>Dangerous patterns found:</strong> ';
                    foreach ($dangerous as $func => $sev) {
                        echo sev_badge($sev) . ' <code>' . htmlspecialchars($func) . '</code> &nbsp;';
                    }
                    echo '</p>';
                }

                if (!empty($snip->description)) {
                    echo '<p style="font-size:0.85em;color:#555;"><em>' . htmlspecialchars($snip->description) . '</em></p>';
                }

                echo '<details><summary style="cursor:pointer;color:#2980b9;">Show/Hide Code (' . strlen($snip->code ?? '') . ' chars)</summary>';
                echo '<pre style="max-height:300px;overflow-y:auto;">' . htmlspecialchars($snip->code ?? '') . '</pre>';
                echo '</details></div>';
            }

            if (!$any_danger) {
                echo '<p style="color:#27ae60;font-weight:bold;">&#10003; No dangerous function patterns detected in any snippet.</p>';
            }
        } else {
            echo '<p>No snippets found in wp_snippets table.</p>';
        }
    } else {
        echo '<p>wp_snippets table does not exist. Code Snippets plugin may not be installed.</p>';
    }
} else {
    echo '<p style="color:orange;">Database not available.</p>';
}

// ============================================================
//  SECTION 9: XMLRPC & SECURITY OPTIONS
// ============================================================
echo section('9. XML-RPC, Registration & REST API', '&#127760;');
echo table_start();

// xmlrpc.php
$xmlrpc_path = $wp_root . '/xmlrpc.php';
$xmlrpc_exists = file_exists($xmlrpc_path);
echo row('xmlrpc.php exists', $xmlrpc_exists ? 'YES - File is present on disk' : 'Not found (may have been removed)', $xmlrpc_exists ? 'WARNING' : 'OK');

if ($xmlrpc_exists) {
    // Check if we can reach it (test HTTP request - only works if not in CLI)
    $xmlrpc_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/xmlrpc.php';
    $context = stream_context_create(['http' => ['timeout' => 5, 'method' => 'GET']]);
    $response = @file_get_contents($xmlrpc_url, false, $context);
    if ($response !== false) {
        echo row('xmlrpc.php accessible via HTTP', 'YES - Responded to HTTP GET (' . strlen($response) . ' bytes)', 'CRITICAL');
    } else {
        echo row('xmlrpc.php accessible via HTTP', 'No response (may be blocked by .htaccess or firewall)', 'OK');
    }
}

// Open registration
if ($wp_loaded) {
    $anyone_can_register = get_option('users_can_register');
    echo row('Open User Registration (users_can_register)', $anyone_can_register ? 'ENABLED - Anyone can register!' : 'Disabled', $anyone_can_register ? 'CRITICAL' : 'OK');

    $default_role = get_option('default_role');
    echo row('Default Role for new users', htmlspecialchars((string)$default_role), ($default_role === 'administrator' || $default_role === 'editor') ? 'CRITICAL' : 'INFO');

    // REST API user enumeration
    $rest_url = get_rest_url(null, '/wp/v2/users');
    $context2 = stream_context_create(['http' => ['timeout' => 5]]);
    $rest_response = @file_get_contents($rest_url, false, $context2);
    if ($rest_response !== false) {
        $decoded = json_decode($rest_response, true);
        if (is_array($decoded) && isset($decoded[0]['slug'])) {
            echo row('REST API User Enumeration (/wp/v2/users)', 'EXPOSED - ' . count($decoded) . ' user(s) returned: ' . htmlspecialchars(implode(', ', array_column($decoded, 'slug'))), 'CRITICAL');
        } else {
            echo row('REST API User Enumeration', 'Response received but no user data exposed (or error response)', 'OK');
        }
    } else {
        echo row('REST API User Enumeration', 'Could not test (no HTTP response or blocked)', 'INFO');
    }

    // Pingbacks
    $enable_pingback = get_option('default_pingback_flag');
    echo row('Default Pingbacks enabled', $enable_pingback ? 'Yes' : 'No', $enable_pingback ? 'WARNING' : 'OK');

    // Admin email
    $admin_email = get_option('admin_email');
    echo row('Admin email', htmlspecialchars((string)$admin_email), 'INFO');
}

echo table_end();

// ============================================================
//  SECTION 10: FILE PERMISSIONS
// ============================================================
echo section('10. Critical File Permissions', '&#128274;');
echo table_start();

$perm_checks = [
    [$wp_root . '/wp-config.php', '0600', 'wp-config.php'],
    [$wp_root . '/.htaccess', '0644', '.htaccess'],
    [$wp_root . '/wp-login.php', '0644', 'wp-login.php'],
    [$wp_root . '/xmlrpc.php', '0644', 'xmlrpc.php'],
    [$wp_root . '/wp-content', '0755', 'wp-content/'],
    [$wp_root . '/wp-content/uploads', '0755', 'wp-content/uploads/'],
];

foreach ($perm_checks as [$path, $recommended, $label]) {
    if (file_exists($path)) {
        $perms = substr(sprintf('%o', fileperms($path)), -4);
        $ok = ($perms === $recommended) || ($perms <= $recommended);
        echo row($label . ' permissions', $perms . ' (recommended: ' . $recommended . ')', $ok ? 'OK' : 'WARNING');
    } else {
        echo row($label . ' permissions', 'File/dir not found', 'INFO');
    }
}

echo table_end();

// ============================================================
//  SECTION 11: SERVER ENVIRONMENT
// ============================================================
echo section('11. Server Environment', '&#128187;');
echo table_start();

echo row('PHP Version', PHP_VERSION, version_compare(PHP_VERSION, '7.4', '>=') ? 'OK' : 'CRITICAL');
echo row('PHP SAPI', PHP_SAPI, 'INFO');
echo row('Server Software', htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'), 'INFO');
echo row('Server OS', htmlspecialchars(PHP_OS), 'INFO');
echo row('open_basedir', htmlspecialchars(ini_get('open_basedir') ?: 'Not set'), ini_get('open_basedir') ? 'OK' : 'INFO');
echo row('allow_url_fopen', ini_get('allow_url_fopen') ? 'On' : 'Off', ini_get('allow_url_fopen') ? 'WARNING' : 'OK');
echo row('allow_url_include', ini_get('allow_url_include') ? 'On' : 'Off', ini_get('allow_url_include') ? 'CRITICAL' : 'OK');
echo row('display_errors', ini_get('display_errors') ? 'On' : 'Off', ini_get('display_errors') ? 'WARNING' : 'OK');
echo row('error_reporting', ini_get('error_reporting'), 'INFO');
echo row('max_execution_time', ini_get('max_execution_time') . 's', 'INFO');
echo row('upload_max_filesize', ini_get('upload_max_filesize'), 'INFO');
echo row('disable_functions', htmlspecialchars(ini_get('disable_functions') ?: 'None'), ini_get('disable_functions') ? 'OK' : 'INFO');

echo table_end();

// Capture output and count severities
$html = ob_get_clean();

// Count badges in output
preg_match_all('/sev_badge\(\'(CRITICAL|WARNING|OK|INFO)\'\)/', 'placeholder', $m); // reset
preg_match_all('/<span[^>]+>(CRITICAL|WARNING|OK|INFO)<\/span>/', $html, $badge_matches);
foreach ($badge_matches[1] as $sev) {
    $counts[$sev] = ($counts[$sev] ?? 0) + 1;
}
?>

<!-- SUMMARY -->
<div class="summary-grid">
    <div class="summary-card card-critical">
        <div class="count"><?= $counts['CRITICAL'] ?></div>
        <div class="label">Critical Issues</div>
    </div>
    <div class="summary-card card-warning">
        <div class="count"><?= $counts['WARNING'] ?></div>
        <div class="label">Warnings</div>
    </div>
    <div class="summary-card card-ok">
        <div class="count"><?= $counts['OK'] ?></div>
        <div class="label">OK Checks</div>
    </div>
    <div class="summary-card card-info">
        <div class="count"><?= $counts['INFO'] ?></div>
        <div class="label">Informational</div>
    </div>
</div>

<?= $html ?>

<hr style="margin:40px 0;border-color:#ddd;">

<div style="background:#fde8e8;padding:20px;border-radius:6px;margin-top:20px;">
    <h3 style="margin:0 0 10px 0;color:#c0392b;">&#9888; IMPORTANT: Delete This File</h3>
    <p>This audit script contains sensitive server information. Delete it immediately after use.</p>
    <a href="?audit_key=<?= htmlspecialchars(AUDIT_KEY) ?>&self_delete=1"
       class="delete-btn"
       onclick="return confirm('Are you sure you want to delete this audit script from the server?')">
        &#128465; Delete This Script Now
    </a>
    <p style="font-size:0.85em;margin-top:10px;color:#666;">Or delete manually: <code><?= htmlspecialchars(__FILE__) ?></code></p>
</div>

</div><!-- .container -->
</body>
</html>
