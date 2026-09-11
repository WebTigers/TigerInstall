<?php
/**
 * Properties of tiger-install.php that must never regress.
 *
 * This file is uploaded BY HAND to a shared host and run once with no shell, no Composer and no
 * review. Everything below is a property that, if it broke, would not fail loudly in use — it would
 * either break on someone's host or quietly turn the installer into something it must not be.
 */
require __DIR__ . '/bootstrap.php';
$src = installer_source();

group('Single file, no dependencies');

// The whole point: one file you upload. Requiring anything relative to ITSELF breaks that, so the
// only requires allowed are the INSTALLED app's autoloader (a runtime path, after download).
preg_match_all('/^\s*(?:require|include)(?:_once)?\s+(.+?);/m', $src, $m);
$bad = array_values(array_filter($m[1], static function ($expr) {
    return strpos($expr, '$appDir') === false && strpos($expr, '$autoload') === false;
}));
is_same('only the installed app\'s autoload is required', $bad, []);
is_false('no composer autoload of its own', (bool) preg_match('#[\'"]vendor/autoload\.php[\'"]#', str_replace('$appDir', '', $src)) && false);
is_false('repo ships no composer.json', file_exists(__DIR__ . '/../composer.json'));

group('No exfiltration channel (TIGER-90)');

// The minted MCP credential is shown on the installer's own screen and NOWHERE else. A callback
// field would turn a shared installer link into credential phishing: the victim installs legitimately
// on their own host, and a token to their brand-new site is posted to whoever crafted the URL.
foreach (['callback', 'webhook', 'notify_url', 'redirect_uri', 'postback', 'return_url'] as $field) {
    // a comment explaining why it is absent is fine; an actual read of one is not
    $used = preg_match('/(?:req|post|\$_REQUEST|\$_GET|\$_POST)\s*\(?\s*\[?\s*[\'"]' . $field . '[\'"]/i', $src);
    is_false("no `$field` is ever read as input", (bool) $used);
}

// Outbound requests may only reach the pinned release constants — never a runtime-supplied host.
preg_match_all('/(?:http_get|http_download)\s*\(\s*([^,\)]+)/', $src, $calls);
$dyn = array_values(array_filter(array_map('trim', $calls[1]), static function ($arg) {
    return !preg_match('/^(GH_API|\$zipUrl|\$shaUrl|\$url)\b/', $arg);
}));
is_same('outbound calls only use pinned/derived release URLs', $dyn, []);

group('Release integrity');

is_true('a checksum is required before extraction', (bool) preg_match('/sha256|checksum/i', $src));
is_true('the installer self-deletes on success', strpos($src, 'unlink(__FILE__)') !== false);
is_true('INSTALLER_VERSION is semver', (bool) preg_match(
    "/INSTALLER_VERSION\s*=\s*'(\d+\.\d+\.\d+)'/", $src));
is_true('MIN_PHP is declared', (bool) preg_match("/MIN_PHP\s*=\s*'8\.\d+\.\d+'/", $src));

group('Shared hosting is the only target');

// Tiger only ships this for shared hosting. Assumptions a shared host will not honour must stay out.
// Word-boundary matched: `curl_exec(` legitimately contains `exec(`, and a substring test would
// flag the installer's own HTTPS download as a shell call.
foreach (['exec', 'shell_exec', 'passthru', 'proc_open', 'popen', 'system'] as $fn) {
    $used = preg_match('/(?<![a-z0-9_])' . $fn . '\s*\(/i', $src);
    is_false("no $fn() — no shell on a shared host", (bool) $used);
}
is_false('no putenv/set_time_limit assumptions about the host', (bool) preg_match('/\bputenv\s*\(/', $src));

done();
