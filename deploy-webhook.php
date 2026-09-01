<?php
// Token musí odpovídat hodnotě GitHub secret DEPLOY_SECRET.
// Po nahrání na server změň CHANGE_ME na náhodný řetězec.
define('DEPLOY_SECRET', 'CHANGE_ME');

if (($_GET['token'] ?? '') !== DEPLOY_SECRET) {
    http_response_code(403);
    exit('Unauthorized');
}

$url     = 'https://raw.githubusercontent.com/outly-jan/skautske-brigady/main/skautske-brigady.php';
$content = @file_get_contents($url);

if (!$content || strlen($content) < 10000) {
    http_response_code(500);
    exit('Download failed');
}

$target = __DIR__ . '/skautske-brigady.php';
$written = file_put_contents($target, $content);

if ($written === false) {
    http_response_code(500);
    exit('Write failed – check file permissions on ' . $target);
}

if ($written !== strlen($content)) {
    http_response_code(500);
    exit('Write incomplete: wrote ' . $written . ' of ' . strlen($content) . ' bytes');
}

file_put_contents(__DIR__ . '/.sb_deploy_flag', time());
if (function_exists('opcache_invalidate')) {
    opcache_invalidate($target, true);
}
echo 'OK – deployed ' . $written . ' bytes at ' . date('Y-m-d H:i:s');
