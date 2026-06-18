-- ============================================================
--  WordPress Security Audit SQL Queries
--  Site: skautchlumec.cz
--  Run in phpMyAdmin against the WordPress database
--  NOTE: Replace "wp_" with your actual table prefix if different
-- ============================================================


-- ============================================================
--  QUERY 1: All Code Snippets (full details)
-- ============================================================
-- Shows all snippets including inactive ones.
-- Check: active=1 means it runs on your site.
-- Scope: 'global' runs on frontend+backend, 'admin' = backend only, 'front-end' = frontend only

SELECT
    id,
    name,
    active,
    scope,
    modified,
    CHAR_LENGTH(code) AS code_length_chars,
    code
FROM wp_snippets
ORDER BY id ASC;


-- ============================================================
--  QUERY 2: Snippets with dangerous function patterns
-- ============================================================
-- Searches snippet code for known dangerous functions.
-- Any result here warrants manual review.

SELECT
    id,
    name,
    active,
    scope,
    modified,
    CASE
        WHEN code LIKE '%eval(%'           THEN 'eval() detected'
        WHEN code LIKE '%base64_decode(%'  THEN 'base64_decode() detected'
        WHEN code LIKE '%exec(%'           THEN 'exec() detected'
        WHEN code LIKE '%system(%'         THEN 'system() detected'
        WHEN code LIKE '%passthru(%'       THEN 'passthru() detected'
        WHEN code LIKE '%shell_exec(%'     THEN 'shell_exec() detected'
        WHEN code LIKE '%file_put_contents(%' THEN 'file_put_contents() detected'
        WHEN code LIKE '%create_function(%'   THEN 'create_function() detected'
        WHEN code LIKE '%preg_replace%/e%'    THEN 'preg_replace /e modifier detected'
        WHEN code LIKE '%assert(%'            THEN 'assert() detected'
        WHEN code LIKE '%proc_open(%'         THEN 'proc_open() detected'
        WHEN code LIKE '%popen(%'             THEN 'popen() detected'
        ELSE 'other'
    END AS danger_reason,
    LEFT(code, 500) AS code_preview
FROM wp_snippets
WHERE
    code LIKE '%eval(%'
    OR code LIKE '%base64_decode(%'
    OR code LIKE '%exec(%'
    OR code LIKE '%system(%'
    OR code LIKE '%passthru(%'
    OR code LIKE '%shell_exec(%'
    OR code LIKE '%file_put_contents(%'
    OR code LIKE '%create_function(%'
    OR code LIKE '%preg_replace%/e%'
    OR code LIKE '%assert(%'
    OR code LIKE '%proc_open(%'
    OR code LIKE '%popen(%'
ORDER BY active DESC, id ASC;


-- ============================================================
--  QUERY 3: Snippet #25 specifically (recently deactivated)
-- ============================================================
-- Inspect the snippet that was deactivated after spam incident.

SELECT
    id,
    name,
    active,
    scope,
    description,
    modified,
    code
FROM wp_snippets
WHERE id = 25;


-- ============================================================
--  QUERY 4: All WordPress users with roles
-- ============================================================
-- Lists all users and their assigned roles.
-- Look for: unexpected administrator accounts, test users,
-- users with suspicious email domains.

SELECT
    u.ID,
    u.user_login,
    u.user_email,
    u.user_registered,
    u.user_status,
    u.display_name,
    m.meta_value AS capabilities
FROM wp_users u
LEFT JOIN wp_usermeta m
    ON u.ID = m.user_id
    AND m.meta_key = 'wp_capabilities'
ORDER BY u.ID ASC;


-- ============================================================
--  QUERY 5: Only administrator-role users
-- ============================================================
-- Should have only known/trusted accounts.

SELECT
    u.ID,
    u.user_login,
    u.user_email,
    u.user_registered,
    u.display_name
FROM wp_users u
INNER JOIN wp_usermeta m
    ON u.ID = m.user_id
    AND m.meta_key = 'wp_capabilities'
    AND m.meta_value LIKE '%administrator%'
ORDER BY u.ID ASC;


-- ============================================================
--  QUERY 6: Users with custom roles (rodic, spravce_brigad)
-- ============================================================

SELECT
    u.ID,
    u.user_login,
    u.user_email,
    u.user_registered,
    m.meta_value AS capabilities
FROM wp_users u
INNER JOIN wp_usermeta m
    ON u.ID = m.user_id
    AND m.meta_key = 'wp_capabilities'
WHERE
    m.meta_value LIKE '%rodic%'
    OR m.meta_value LIKE '%spravce_brigad%'
ORDER BY u.ID ASC;


-- ============================================================
--  QUERY 7: Key wp_options security settings
-- ============================================================

SELECT
    option_name,
    option_value
FROM wp_options
WHERE option_name IN (
    'siteurl',
    'home',
    'blogname',
    'admin_email',
    'users_can_register',
    'default_role',
    'anyone_can_register',
    'blogpublic',
    'ping_sites',
    'default_pingback_flag',
    'default_ping_status',
    'default_comment_status',
    'comment_registration',
    'close_comments_for_old_posts',
    'active_plugins',
    'template',
    'stylesheet',
    'upload_path',
    'upload_url_path',
    'permalink_structure',
    'wp_user_roles'
)
ORDER BY option_name ASC;


-- ============================================================
--  QUERY 8: Find eval/base64 in ALL wp_options values
-- ============================================================
-- This is a broad backdoor scan. Some false positives expected
-- from legitimate plugins storing base64-encoded data.
-- Focus on: option_name values you don't recognize,
-- or option_values that look like obfuscated PHP code.

SELECT
    option_id,
    option_name,
    LEFT(option_value, 300) AS option_value_preview,
    CHAR_LENGTH(option_value) AS value_length
FROM wp_options
WHERE
    option_value LIKE '%eval(%'
    OR option_value LIKE '%base64_decode(base64%'
    OR option_value LIKE '%assert(%$%'
    OR option_value LIKE '%gzinflate(%'
    OR option_value LIKE '%str_rot13(%'
    OR option_value LIKE '%shell_exec(%'
    OR option_value LIKE '%<? %'
    OR option_value LIKE '%<?php%'
ORDER BY option_id ASC;


-- ============================================================
--  QUERY 9: Check wp_usermeta for capabilities (all users)
-- ============================================================

SELECT
    u.user_login,
    m.meta_key,
    m.meta_value
FROM wp_usermeta m
JOIN wp_users u ON u.ID = m.user_id
WHERE m.meta_key = 'wp_capabilities'
ORDER BY u.ID ASC;


-- ============================================================
--  QUERY 10: Inspect WP Cron jobs (scheduled tasks)
-- ============================================================
-- Attackers sometimes inject malicious cron jobs.
-- This returns the serialized cron option for manual inspection.

SELECT
    option_name,
    option_value
FROM wp_options
WHERE option_name = 'cron';

-- For a more readable view of cron hooks (MySQL 5.7+):
-- Look for any unrecognized hook names in the output.
-- Known WP hooks: wp_version_check, wp_update_plugins,
-- wp_update_themes, wp_scheduled_delete, etc.


-- ============================================================
--  QUERY 11: Check for suspicious/unknown option names
-- ============================================================
-- Look for option names that don't match standard WP/plugin patterns.
-- Random-looking or base64-looking names are red flags.

SELECT
    option_id,
    option_name,
    LEFT(option_value, 200) AS value_preview,
    autoload
FROM wp_options
WHERE
    option_name NOT REGEXP '^[a-z0-9_\\-]+$'   -- non-standard characters
    OR option_name LIKE '%=%'
    OR option_name LIKE '% %'
ORDER BY option_id ASC
LIMIT 50;


-- ============================================================
--  QUERY 12: Recently modified options (last 30 days)
-- ============================================================
-- wp_options doesn't have a timestamp by default, but some
-- plugins add modification time. Use this as a starting point.
-- NOTE: Standard wp_options has no modified_at column.
-- This query uses autoload as a proxy for important options.

SELECT
    option_id,
    option_name,
    LEFT(option_value, 200) AS value_preview,
    autoload
FROM wp_options
WHERE autoload = 'yes'
ORDER BY option_id DESC
LIMIT 100;


-- ============================================================
--  QUERY 13: Check wp_postmeta for backdoor patterns
-- ============================================================
-- Less common vector but worth checking.

SELECT
    pm.meta_id,
    pm.post_id,
    pm.meta_key,
    LEFT(pm.meta_value, 300) AS meta_value_preview
FROM wp_postmeta pm
WHERE
    pm.meta_value LIKE '%eval(%'
    OR pm.meta_value LIKE '%base64_decode(%'
    OR pm.meta_value LIKE '%shell_exec(%'
    OR pm.meta_value LIKE '%<?php%'
LIMIT 50;


-- ============================================================
--  QUERY 14: Check transients for injected data
-- ============================================================
-- Transients are temporary cached data. Rare but possible
-- vector for persistent payloads.

SELECT
    option_name,
    LEFT(option_value, 300) AS value_preview,
    CHAR_LENGTH(option_value) AS value_length
FROM wp_options
WHERE
    option_name LIKE '_transient_%'
    AND (
        option_value LIKE '%eval(%'
        OR option_value LIKE '%base64_decode(%'
        OR option_value LIKE '%shell_exec(%'
    )
ORDER BY option_id ASC;


-- ============================================================
--  QUERY 15: User sessions (logged-in users right now)
-- ============================================================
-- Check for unexpected active sessions.

SELECT
    u.user_login,
    u.user_email,
    m.meta_value AS session_tokens
FROM wp_usermeta m
JOIN wp_users u ON u.ID = m.user_id
WHERE m.meta_key = 'session_tokens';


-- ============================================================
--  QUERY 16: All wp_snippets - compact summary view
-- ============================================================
-- Good for a quick overview in phpMyAdmin.

SELECT
    id,
    name,
    active,
    scope,
    modified,
    CHAR_LENGTH(code) AS code_chars,
    LEFT(REPLACE(REPLACE(code, '\n', ' '), '\r', ''), 100) AS code_preview
FROM wp_snippets
ORDER BY active DESC, id ASC;
