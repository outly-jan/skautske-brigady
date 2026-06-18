<?php
/**
 * WordPress wp-config.php Security Hardening Additions
 * Site: skautchlumec.cz
 *
 * ==========================================================
 *  INSTRUCTIONS:
 *  1. Open wp-config.php in your text editor / cPanel File Manager
 *  2. Add each block in the indicated location
 *  3. DO NOT add the opening <?php tag - it is already there
 *  4. ALWAYS back up wp-config.php before editing
 *  5. Test the site after each change
 * ==========================================================
 */


// ==========================================================
//  WHERE TO ADD:
//  Find the line that says:
//    /* That's all, stop editing! Happy publishing. */
//  Add ALL of the following BEFORE that line.
// ==========================================================


// ----------------------------------------------------------
//  SECURITY: Disable file editing via WordPress admin panel
//  This prevents attackers with admin access from editing
//  theme/plugin files through Appearance > Editor.
//  CRITICAL for this site - add if not present.
// ----------------------------------------------------------
define( 'DISALLOW_FILE_EDIT', true );


// ----------------------------------------------------------
//  SECURITY: Disable all automatic updates and file modifications
//  through the WordPress admin (includes plugin/theme updates
//  through the admin UI).
//  NOTE: Set to false if you want to keep auto-updates.
//  Recommended: true for production, then update via SSH/FTP.
// ----------------------------------------------------------
define( 'DISALLOW_FILE_MODS', true );


// ----------------------------------------------------------
//  SECURITY: Ensure debug mode is completely OFF
//  Debug mode exposes error messages, file paths, and
//  potentially sensitive data to visitors.
// ----------------------------------------------------------
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_LOG', false );
define( 'WP_DEBUG_DISPLAY', false );
@ini_set( 'display_errors', 0 );


// ----------------------------------------------------------
//  SECURITY: Force HTTPS for WordPress admin and login
//  Ensures cookies and credentials are always encrypted.
//  Requires a valid SSL certificate (Let's Encrypt is fine).
// ----------------------------------------------------------
define( 'FORCE_SSL_ADMIN', true );


// ----------------------------------------------------------
//  PERFORMANCE + SECURITY: Limit post revision history
//  Reduces database bloat and limits info exposure.
//  Set to false to disable revisions entirely.
// ----------------------------------------------------------
define( 'WP_POST_REVISIONS', 5 );


// ----------------------------------------------------------
//  SECURITY: Set auto-save interval (seconds)
//  Default is 60 seconds. Increase to reduce DB writes.
// ----------------------------------------------------------
define( 'AUTOSAVE_INTERVAL', 300 );


// ----------------------------------------------------------
//  SECURITY: Disable concatenation of admin scripts
//  Helps with debugging but also reduces a minor attack surface.
//  Keep false in production for better performance.
// ----------------------------------------------------------
define( 'CONCATENATE_SCRIPTS', false );


// ----------------------------------------------------------
//  SECURITY: Set cookie path and domain explicitly
//  Replace 'skautchlumec.cz' with your actual domain.
//  This prevents cookie theft via subdomain attacks.
// ----------------------------------------------------------
define( 'COOKIE_DOMAIN', 'skautchlumec.cz' );
define( 'COOKIEPATH',   '/' );
define( 'SITECOOKIEPATH', '/' );


// ----------------------------------------------------------
//  SECURITY: Restrict cron to internal calls only
//  Prevents external triggering of wp-cron.php
//  NOTE: If using WP-Cron (not system cron), keep this false.
//  If you have a real server cron job calling wp-cron.php,
//  set to true.
// ----------------------------------------------------------
// define( 'DISABLE_WP_CRON', true );  // Uncomment if using system cron


// ----------------------------------------------------------
//  SECURITY: Restrict the number of login errors shown
//  WordPress by default shows "incorrect password" which
//  confirms valid usernames. This is handled by plugin,
//  but noting here for reference.
// ----------------------------------------------------------
// (Handled via plugin or functions.php - see note below)


// ----------------------------------------------------------
//  SECURITY: Set WordPress memory limit
//  Prevents memory exhaustion attacks.
// ----------------------------------------------------------
define( 'WP_MEMORY_LIMIT', '256M' );
define( 'WP_MAX_MEMORY_LIMIT', '512M' );


// ----------------------------------------------------------
//  SECURITY: Lock down wp-admin access by IP (optional)
//  If your admin always accesses from the same IP,
//  uncomment and configure. Also requires .htaccess rule.
// ----------------------------------------------------------
// if ( !in_array( $_SERVER['REMOTE_ADDR'], [ '1.2.3.4', '5.6.7.8' ] ) ) {
//     die( 'Access Denied' );
// }


// ==========================================================
//  COMPLETE SECURE WP-CONFIG.PHP TEMPLATE
//  (Replace your existing wp-config.php with this structure)
//  Lines marked [FILL IN] must be set from your current
//  wp-config.php values.
// ==========================================================

/*
<?php
// *** IMPORTANT: Keep all these values from your current config ***

// Database settings (copy from your existing wp-config.php)
define( 'DB_NAME',     '[FILL IN - your database name]' );
define( 'DB_USER',     '[FILL IN - your database user]' );
define( 'DB_PASSWORD', '[FILL IN - your database password]' );
define( 'DB_HOST',     'localhost' );
define( 'DB_CHARSET',  'utf8' );
define( 'DB_COLLATE',  '' );

// Authentication keys and salts
// Generate fresh ones at: https://api.wordpress.org/secret-key/1.1/salt/
define( 'AUTH_KEY',         '[FILL IN - unique phrase]' );
define( 'SECURE_AUTH_KEY',  '[FILL IN - unique phrase]' );
define( 'LOGGED_IN_KEY',    '[FILL IN - unique phrase]' );
define( 'NONCE_KEY',        '[FILL IN - unique phrase]' );
define( 'AUTH_SALT',        '[FILL IN - unique phrase]' );
define( 'SECURE_AUTH_SALT', '[FILL IN - unique phrase]' );
define( 'LOGGED_IN_SALT',   '[FILL IN - unique phrase]' );
define( 'NONCE_SALT',       '[FILL IN - unique phrase]' );

// Table prefix (copy from your existing wp-config.php)
// Should NOT be "wp_" - change if it is
$table_prefix = '[FILL IN - e.g. skaut_]';

// *** SECURITY ADDITIONS (all from this file) ***

// Debug - OFF in production
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_LOG', false );
define( 'WP_DEBUG_DISPLAY', false );
@ini_set( 'display_errors', 0 );

// Prevent file editing/modifications via admin
define( 'DISALLOW_FILE_EDIT', true );
define( 'DISALLOW_FILE_MODS', true );

// Force HTTPS for admin
define( 'FORCE_SSL_ADMIN', true );

// Performance
define( 'WP_POST_REVISIONS', 5 );
define( 'AUTOSAVE_INTERVAL', 300 );
define( 'WP_MEMORY_LIMIT', '256M' );
define( 'WP_MAX_MEMORY_LIMIT', '512M' );

// Cookie security
define( 'COOKIE_DOMAIN', 'skautchlumec.cz' );
define( 'COOKIEPATH',   '/' );
define( 'SITECOOKIEPATH', '/' );

// *** STANDARD WP FOOTER - DO NOT CHANGE BELOW ***

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
*/


// ==========================================================
//  FUNCTIONS.PHP ADDITIONS
//  Add these to your active theme's functions.php
//  OR better: create a must-use plugin in
//  wp-content/mu-plugins/security-hardening.php
// ==========================================================

/*
<?php
/**
 * Security hardening for skautchlumec.cz
 * Place in: wp-content/mu-plugins/security-hardening.php
 * (Must-use plugins run automatically, cannot be deactivated)
 * /

if ( ! defined( 'ABSPATH' ) ) exit;

// Remove WordPress version from all output (reduces targeted attacks)
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );

// Remove version from scripts and styles
function skaut_remove_version_from_assets( $src ) {
    if ( strpos( $src, 'ver=' ) ) {
        $src = remove_query_arg( 'ver', $src );
    }
    return $src;
}
add_filter( 'style_loader_src',  'skaut_remove_version_from_assets', 9999 );
add_filter( 'script_loader_src', 'skaut_remove_version_from_assets', 9999 );

// Disable XML-RPC completely (belt-and-suspenders with .htaccess)
add_filter( 'xmlrpc_enabled', '__return_false' );

// Disable pingbacks
add_filter( 'xmlrpc_methods', function( $methods ) {
    unset( $methods['pingback.ping'] );
    unset( $methods['pingback.extensions.getPingbacks'] );
    return $methods;
});

// Disable user enumeration via REST API for non-logged-in users
add_filter( 'rest_endpoints', function( $endpoints ) {
    if ( ! is_user_logged_in() ) {
        if ( isset( $endpoints['/wp/v2/users'] ) ) {
            unset( $endpoints['/wp/v2/users'] );
        }
        if ( isset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] ) ) {
            unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
        }
    }
    return $endpoints;
});

// Prevent login error messages from revealing valid usernames
add_filter( 'login_errors', function() {
    return __( 'Incorrect username or password.' );
});

// Disable file editing (redundant with wp-config but belt-and-suspenders)
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
    define( 'DISALLOW_FILE_EDIT', true );
}

// Limit login error messages exposure
add_filter( 'authenticate', function( $user, $username, $password ) {
    if ( is_wp_error( $user ) ) {
        $error_code = $user->get_error_code();
        if ( in_array( $error_code, ['invalid_username', 'incorrect_password'] ) ) {
            return new WP_Error( 'authentication_failed', __( 'Incorrect username or password.' ) );
        }
    }
    return $user;
}, 100, 3 );

// Remove RSD link (used by blog editors - not needed for private portal)
remove_action( 'wp_head', 'rsd_link' );

// Remove Windows Live Writer manifest link
remove_action( 'wp_head', 'wlwmanifest_link' );

// Remove shortlink
remove_action( 'wp_head', 'wp_shortlink_wp_head' );

// Remove REST API link from head (still functional, just not advertised)
remove_action( 'wp_head', 'rest_output_link_wp_head' );
remove_action( 'template_redirect', 'rest_output_link_header', 11 );

// Disable self-pingbacks
add_action( 'pre_ping', function( &$links ) {
    $home = get_option( 'home' );
    foreach ( $links as $l => $link ) {
        if ( 0 === strpos( $link, $home ) ) {
            unset( $links[ $l ] );
        }
    }
});
*/
