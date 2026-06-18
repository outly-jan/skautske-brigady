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

file_put_contents(__DIR__ . '/skautske-brigady.php', $content);
echo 'OK – deployed ' . strlen($content) . ' bytes at ' . date('Y-m-d H:i:s');
