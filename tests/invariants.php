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
// The wizard's own code: the built file minus the vendored engine section.
$wizard = preg_replace('#/\* ==== vendored tiger-headless .*?/\* ==== end tiger-headless [^*]*\*/#s', '', $src);

group('Single file, no dependencies');

// The whole point: one file you upload. Requiring anything relative to ITSELF breaks that, so the
// only requires allowed are the INSTALLED app's autoloader (a runtime path, after download).
preg_match_all('/^\s*(?:require|include)(?:_once)?\s+(.+?);/m', $src, $m);
$bad = array_values(array_filter($m[1], static function ($expr) {
    return strpos($expr, '$appDir') === false && strpos($expr, '$autoload') === false && strpos($expr, '$appRoot') === false;
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

// Outbound requests from the wizard reach ONLY the two pinned public lists (catalog + Directory); the
// release download belongs to the vendored engine. A runtime-supplied URL here would be an exfiltration
// or a fetch-from-anywhere channel, so every call site is checked by name.
preg_match_all('/Tiger_Headless_Http::(?:get|download)\s*\(\s*([^,\)]+)/', $wizard, $calls);
$dyn = array_values(array_filter(array_map('trim', $calls[1]), static fn($arg) => !preg_match('/^(\$url|CATALOG_URL|DIRECTORY_URL)$/', $arg)));
is_same('wizard outbound calls use only the pinned list URLs', $dyn, []);
is_true ('list_fetch is only ever handed the two constants', preg_match_all('/list_fetch\(\$home,\s*\'[a-z]+\',\s*(CATALOG_URL|DIRECTORY_URL)\)/', $wizard) === 2 && preg_match_all('/list_fetch\(/', $wizard) === 3);
is_true ('the list URLs are raw.githubusercontent.com', (bool) preg_match("/CATALOG_URL\s*=\s*'https:\/\/raw\.githubusercontent\.com\/WebTigers\/TigerCatalog\//", $wizard) && (bool) preg_match("/DIRECTORY_URL\s*=\s*'https:\/\/raw\.githubusercontent\.com\/WebTigers\/TigerVendors\//", $wizard));
is_false('the wizard has no HTTP client of its own', (bool) preg_match('/\b(curl_init|file_get_contents\s*\(\s*[\'"]https?:)/', $wizard));

group('The install is the engine, not this file (TIGER-127)');

// The whole point of the rewrite: one install path. The wizard collects inputs and drives the engine;
// it must never grow an install step of its own again.
is_true ('the engine is vendored from a tagged release', (bool) preg_match('/vendored tiger-headless v\d+\.\d+\.\d+ /', $src));
is_true ('ENGINE_VERSION is stamped',   (bool) preg_match("/ENGINE_VERSION\s*=\s*'\d+\.\d+\.\d+'/", $src));
is_true ('the engine section is closed', strpos($src, '/* ==== end tiger-headless') !== false);
// Calls, not words: a step LABEL may say "migrate"; a call to migrate() may not exist here.
foreach (['migrate', 'createOwner', 'linkPublicAssets', 'provisionSecrets', 'provisionStorage', 'republishAssets', 'hash_file', 'curl_init'] as $fn) {
    is_false("no $fn() call outside the engine", (bool) preg_match('/\b' . $fn . '\s*\(/', $wizard));
}
foreach (['Tiger_Db_Migrator', 'Tiger_Install::', 'Tiger_Application', 'ZipArchive', 'Tiger_Mcp', 'Tiger_Model_'] as $sym) {
    is_false("no $sym outside the engine", strpos($wizard, $sym) !== false);
}
is_true ('every engine step is one the wizard can name', (bool) preg_match('/Tiger_Headless_Installer::STEPS/', $wizard));
is_true ('the wizard runs the engine in hops', (bool) preg_match('/->run\(\$hop\)/', $wizard));
is_true ('the spec is validated by the engine before anything is written', strpos($wizard, 'new Tiger_Headless_Spec($spec)') !== false && strpos($wizard, '->check()') !== false);
is_true ('the job (spec + db password) lives above the docroot, 0600', (bool) preg_match('/job_dir\(\$home\)/', $wizard) && strpos($wizard, '0600') !== false);
is_false('the db password never rides in a hidden field', (bool) preg_match('/hidden_bag\(\$bag\)(?![^;]*\[)/', substr($wizard, strpos($wizard, "case 'install':"))));

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
