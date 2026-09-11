<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * tiger-install.php — the one-file Tiger web installer.
 *
 * Drop this single file into a domain's document root (e.g. public_html/) and open it in a
 * browser. It:
 *   1. checks your host meets Tiger's requirements,
 *   2. downloads the latest Tiger release ZIP from GitHub (verified against its .sha256),
 *   3. extracts the app ABOVE the document root (so your secrets are never web-reachable),
 *   4. writes only a tiny shim + asset links into the document root,
 *   5. writes your DB settings + minted secrets into local.ini (above the docroot, chmod 600),
 *   6. builds the schema and creates your admin account,
 *   7. deletes itself.
 *
 * You create the empty MySQL database + user in cPanel first (a normal DB account can't create
 * one from PHP); the installer does everything else. It supports domain-namespaced multi-domain
 * cPanel accounts: each domain becomes its own self-contained install.
 *
 * This file is intentionally dependency-free, pre-bootstrap PHP — Tiger isn't installed yet. Once
 * the code is extracted it bootstraps Tiger and calls the platform's OWN installer (Tiger_Install)
 * so nothing here re-implements migrations, secrets, or admin creation.
 *
 * Repo: https://github.com/WebTigers/TigerInstall  (evergreen — one file, resolves the latest release)
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
@ini_set('display_errors', '1');
@set_time_limit(0);

const INSTALLER_VERSION = '1.1.0';
const RELEASE_REPO      = 'webtigers/tiger';   // the skeleton repo whose releases host the full-app bundle
const MIN_PHP           = '8.1.0';
const GH_API            = 'https://api.github.com';

// CSRF via a same-site double-submit COOKIE — deliberately NOT a PHP session: a native session
// (session_start) defines SID, and Zend_Session::start() then throws "session has already been
// started" when we boot Tiger to build the schema. A cookie sidesteps that collision entirely.
$__csrf = (isset($_COOKIE['tiger_install_csrf']) && preg_match('/^[a-f0-9]{32}$/', (string) $_COOKIE['tiger_install_csrf']))
    ? (string) $_COOKIE['tiger_install_csrf']
    : bin2hex(random_bytes(16));
@setcookie('tiger_install_csrf', $__csrf, ['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
$GLOBALS['__csrf'] = $__csrf;

/* ---------------------------------------------------------------------------
 * Tiny helpers
 * ------------------------------------------------------------------------- */

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function post($k, $d = '') { return isset($_POST[$k]) ? trim((string) $_POST[$k]) : $d; }
function req($k, $d = '') { return isset($_REQUEST[$k]) ? trim((string) $_REQUEST[$k]) : $d; }

/** Checkbox/flag truthiness — '1', 'true', 'yes', 'on' are on; everything else (incl. '0') is off. */
function truthy($v) { return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true); }

/** The per-visitor CSRF token (a same-site cookie; see the top of the file). */
function csrf_token() {
    return isset($GLOBALS['__csrf']) ? (string) $GLOBALS['__csrf'] : '';
}
function csrf_ok() {
    return isset($_COOKIE['tiger_install_csrf'])
        && hash_equals((string) $_COOKIE['tiger_install_csrf'], (string) post('_csrf'));
}

/** Detect the cPanel account home reliably (POSIX first — correct even for addon docroots). */
function detect_home($docroot) {
    if (function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
        $pw = @posix_getpwuid(@posix_getuid());
        if (!empty($pw['dir']) && is_dir($pw['dir'])) { return rtrim($pw['dir'], '/'); }
    }
    // Fallback: /home/<user> pattern walk.
    $p = rtrim((string) $docroot, '/');
    while ($p && $p !== '/' && dirname($p) !== '/home' && basename(dirname($p)) !== 'home') {
        $parent = dirname($p);
        if ($parent === $p) { break; }
        $p = $parent;
    }
    return ($p && dirname($p) !== '' && is_dir($p)) ? $p : dirname((string) $docroot);
}

/** The docroot this installer is serving from (it lives IN the docroot). */
function detect_docroot() {
    $dir = @realpath(__DIR__);
    return $dir ?: rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? getcwd()), '/');
}

/** The domain being served, sanitized for use in a path. */
function detect_domain() {
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $host = preg_replace('/:\d+$/', '', $host);                 // strip port
    $host = preg_replace('/[^a-z0-9.\-]/i', '', strtolower($host));
    return $host !== '' ? $host : 'site';
}

/** GET a URL as a string (cURL, else stream). Returns [body, httpCode] or [null, 0] on failure. */
function http_get($url, $accept = null) {
    $ua = 'tiger-install/' . INSTALLER_VERSION;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $headers = ['User-Agent: ' . $ua];
        if ($accept) { $headers[] = 'Accept: ' . $accept; }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 120,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $body === false ? [null, 0] : [$body, $code];
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['header' => "User-Agent: {$ua}\r\n" . ($accept ? "Accept: {$accept}\r\n" : '')],
                                      'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? [null, 0] : [$body, 200];
    }
    return [null, 0];
}

/** Download a URL to a local file (streamed). Returns true on success. */
function http_download($url, $dest) {
    $ua = 'tiger-install/' . INSTALLER_VERSION;
    if (function_exists('curl_init')) {
        $fp = @fopen($dest, 'wb');
        if (!$fp) { return false; }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['User-Agent: ' . $ua], CURLOPT_TIMEOUT => 600,
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        return $ok !== false && $code < 400 && filesize($dest) > 0;
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['header' => "User-Agent: {$ua}\r\n"],
                                      'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $data = @file_get_contents($url, false, $ctx);
        return $data !== false && @file_put_contents($dest, $data) !== false;
    }
    return false;
}

/** Recursively copy a directory tree. */
function rcopy($src, $dst) {
    $src = rtrim($src, '/'); $dst = rtrim($dst, '/');
    if (is_link($src)) { @symlink(readlink($src), $dst); return; }
    if (is_dir($src)) {
        @mkdir($dst, 0755, true);
        foreach (scandir($src) as $f) {
            if ($f === '.' || $f === '..') { continue; }
            rcopy("$src/$f", "$dst/$f");
        }
        return;
    }
    @copy($src, $dst);
}

/* ---------------------------------------------------------------------------
 * Preflight
 * ------------------------------------------------------------------------- */

/**
 * Merge the tiger.db.* keys into an existing local.ini, PRESERVING every other line.
 *
 * The installer used to rebuild this file from scratch on each call, which destroyed whatever was
 * already in it — including `tiger.crypto.key` and `tiger.security.pepper`. Tiger_Install::
 * provisionSecrets() then minted REPLACEMENTS, because it generates a secret whenever the key is
 * absent. do_provision() runs on BOTH the admin and the finish step, so a back/forward or a retry
 * rotated the pepper *after* the owner account had been hashed with the old one — locking the
 * operator out of the site they had just installed. Merging makes the routine idempotent in fact,
 * which its docblock already claimed.
 *
 * @param string $path the local.ini path (may not exist yet)
 * @param array  $db   key => value, e.g. ['tiger.db.dbname' => 'foo']
 * @return string the full file text to write
 */
function local_ini_merge_db($path, array $db) {
    $text = is_file($path) ? (string) @file_get_contents($path) : '';
    if (trim($text) === '') { $text = "[production]\n"; }
    foreach ($db as $key => $val) {
        $line = $key . ' = "' . $val . '"';
        $pat  = '/^[ \t]*' . preg_quote($key, '/') . '[ \t]*=.*$/m';
        if (preg_match($pat, $text)) {
            $text = preg_replace($pat, $line, $text, 1);
            continue;
        }
        if (preg_match('/^\[production\][ \t]*\r?\n/m', $text, $m, PREG_OFFSET_CAPTURE)) {
            $at   = $m[0][1] + strlen($m[0][0]);
            $text = substr($text, 0, $at) . $line . "\n" . substr($text, $at);
        } else {
            $text = rtrim($text, "\n") . "\n" . $line . "\n";
        }
    }
    return $text;
}

/** The value of a single key already in local.ini, or '' — used to tell a retry from a live app. */
function local_ini_value($path, $key) {
    if (!is_file($path)) { return ''; }
    $text = (string) @file_get_contents($path);
    if (preg_match('/^[ \t]*' . preg_quote($key, '/') . '[ \t]*=[ \t]*"?([^"\r\n]*)"?/m', $text, $m)) {
        return trim($m[1]);
    }
    return '';
}

/**
 * Write a file atomically, and REPORT failure instead of swallowing it. Temp-then-rename, so a
 * failed write can never leave a half-written config (and never loses the previous one).
 *
 * @return bool true on success
 */
function write_file_checked($path, $text, $mode = 0600) {
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { return false; }
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $text) === false) { @unlink($tmp); return false; }
    @chmod($tmp, $mode);
    if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
    return true;
}

function preflight($docroot, $home) {
    $checks = [];
    $add = function ($label, $ok, $hard, $detail, $fix = '') use (&$checks) {
        $checks[] = compact('label', 'ok', 'hard', 'detail', 'fix');
    };
    $add('PHP ' . MIN_PHP . '+', version_compare(PHP_VERSION, MIN_PHP, '>='), true,
        'You have PHP ' . PHP_VERSION, 'Set the PHP version in cPanel → MultiPHP Manager / Select PHP Version.');
    $add('pdo_mysql', extension_loaded('pdo_mysql'), true, 'MySQL database driver',
        'Enable pdo_mysql in cPanel → Select PHP Version → Extensions.');
    $add('zip (ZipArchive)', class_exists('ZipArchive'), true, 'To extract the download',
        'Enable the zip extension in cPanel → Select PHP Version → Extensions.');
    $add('curl or allow_url_fopen', function_exists('curl_init') || ini_get('allow_url_fopen'), false,
        'To download Tiger (manual upload offered if missing)', 'Enable curl, or upload the ZIP manually.');
    $add('mbstring', extension_loaded('mbstring'), true, 'UTF-8 text handling', 'Enable mbstring.');
    // No openssl/sodium rows: sodium is polyfilled (paragonie/sodium_compat is bundled) and openssl is
    // near-universal + covered in practice by the download check — both were just noise for a novice.
    $add('Home dir writable', is_writable($home), true, h($home) . ' — where the app is placed',
        'PHP must run as your cPanel user (it does on modern hosts).');
    $add('Docroot writable', is_writable($docroot), true, h($docroot) . ' — for the shim + assets',
        'Fix file ownership/permissions on the document root.');
    $add('symlink()', function_exists('symlink'), false,
        function_exists('symlink')
            ? 'Assets are linked, so a Tiger update is picked up automatically'
            : 'Blocked here — assets will be COPIED instead. Tiger installs and runs fine; updates '
              . 're-copy them for you.',
        'Optional. Ask your host to allow symlink() for slightly leaner updates.');
    return $checks;
}

/* ---------------------------------------------------------------------------
 * Release resolution + download
 * ------------------------------------------------------------------------- */

/** Resolve the release + the full-app asset. Returns [tag, zipUrl, shaUrl, error]. */
function resolve_release($version = '') {
    if ($version !== '') {
        list($body, $code) = http_get(GH_API . '/repos/' . RELEASE_REPO . '/releases/tags/' . rawurlencode($version), 'application/vnd.github+json');
        if ($body === null || $code >= 400) {
            return [null, null, null, "Couldn't find release {$version} on GitHub (HTTP {$code}). Use manual upload below."];
        }
        $releases = [json_decode($body, true)];
    } else {
        // NOT /releases/latest — that endpoint SKIPS pre-releases, and a beta ships pre-releases.
        // List recent releases (newest first) and take the first that actually carries our bundle.
        list($body, $code) = http_get(GH_API . '/repos/' . RELEASE_REPO . '/releases?per_page=20', 'application/vnd.github+json');
        if ($body === null || $code >= 400) {
            return [null, null, null, "Couldn't reach GitHub to find a release (HTTP {$code}). Use manual upload below."];
        }
        $releases = json_decode($body, true);
        if (!is_array($releases)) { $releases = []; }
    }

    foreach ($releases as $rel) {
        if (!is_array($rel) || !empty($rel['draft']) || empty($rel['assets'])) { continue; }
        $tag = (string) ($rel['tag_name'] ?? '');
        $zipUrl = $shaUrl = null; $zipName = '';
        foreach ($rel['assets'] as $a) {
            $name = (string) ($a['name'] ?? '');
            // Full-app bundle: tiger-<version>.zip — NOT the vendor-only tiger-core-vendored-*.zip.
            if ($zipUrl === null && preg_match('/^tiger-\d[\w.\-]*\.zip$/', $name) && strpos($name, 'core-vendored') === false) {
                $zipUrl = (string) $a['browser_download_url']; $zipName = $name;
            }
        }
        if ($zipUrl === null) { continue; }
        foreach ($rel['assets'] as $a) {
            if ((string) ($a['name'] ?? '') === $zipName . '.sha256') { $shaUrl = (string) $a['browser_download_url']; }
        }
        return [$tag, $zipUrl, $shaUrl, null];
    }
    return [null, null, null, 'No installable full-app bundle (tiger-<version>.zip) found on a recent release yet. Use manual upload below.'];
}

/* ---------------------------------------------------------------------------
 * Rendering
 * ------------------------------------------------------------------------- */

/**
 * The machine-readable state block (TIGER-89/90).
 *
 * Every screen carries one, so a browser-aware client can tell where it is and whether the last action
 * worked WITHOUT scraping prose — the acceptance bar in TIGER-89 ("determine success or the specific
 * failure without human interpretation"). It is also how the client reads the agent credential minted at
 * finish (TIGER-90), so there is ONE contract to learn rather than a separate mechanism per question.
 *
 * A <script type="application/json"> block, not a <meta>: it holds structure (scope, URLs, field lists)
 * that does not fit an attribute, it is inert to the browser, and it is trivially readable by anything
 * that can already see the DOM it just filled in.
 */
function state_block(array $state) {
    if (!$state) { return ''; }
    $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    // </script> can never appear inside a JSON string here, but belt-and-braces for embedded content.
    $json = str_replace('<', '\u003C', (string) $json);
    return '<script type="application/json" id="tiger-install-state">' . $json . '</script>';
}

function page($title, $body, array $state = []) {
    $csrf = csrf_token();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . h($title) . ' — Tiger Installer</title><style>'
       . ':root{--bg:#0f1216;--card:#171b21;--ink:#e8eaed;--mut:#9aa4b2;--line:#2a2f37;--brand:#f59e0b;--ok:#22c55e;--bad:#ef4444;--warn:#eab308}'
       . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}'
       . '.wrap{max-width:760px;margin:0 auto;padding:32px 20px 64px}h1{font-size:1.5rem;margin:.2em 0}h2{font-size:1.1rem}'
       . '.brand{display:flex;align-items:center;gap:10px;color:var(--brand);font-weight:700;letter-spacing:.02em}'
       . '.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:20px 22px;margin:18px 0}'
       . '.mut{color:var(--mut)}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:7px 6px;border-bottom:1px solid var(--line);vertical-align:top}'
       . '.pill{font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:99px}.p-ok{background:rgba(34,197,94,.15);color:var(--ok)}'
       . '.p-bad{background:rgba(239,68,68,.15);color:var(--bad)}.p-warn{background:rgba(234,179,8,.15);color:var(--warn)}'
       . 'label{display:block;margin:12px 0 4px;font-weight:600}input[type=text],input[type=password],input[type=email]{width:100%;padding:10px 12px;'
       . 'background:#0d1014;border:1px solid var(--line);border-radius:8px;color:var(--ink);font:inherit}'
       . 'code{background:#0d1014;border:1px solid var(--line);border-radius:5px;padding:1px 6px;font-size:.85em}'
       . '.btn{display:inline-block;margin-top:18px;background:var(--brand);color:#1a1205;border:0;border-radius:8px;padding:11px 22px;font:inherit;font-weight:700;cursor:pointer}'
       . '.btn.sec{background:transparent;color:var(--ink);border:1px solid var(--line)}'
       . '.note{border-left:3px solid var(--brand);padding:8px 12px;background:rgba(245,158,11,.06);border-radius:0 8px 8px 0;margin:12px 0}'
       . '.bad{border-left-color:var(--bad);background:rgba(239,68,68,.07)}.ok{border-left-color:var(--ok);background:rgba(34,197,94,.07)}'
       . 'ol.steps{counter-reset:s;list-style:none;padding:0;display:flex;gap:8px;flex-wrap:wrap;margin:0 0 8px}ol.steps li{color:var(--mut);font-size:.8rem}'
       . 'ol.steps li.on{color:var(--brand);font-weight:700}'
       . '</style></head><body><div class="wrap">'
       . state_block($state)
       . '<div class="brand"><span style="font-size:1.4rem">&#128062;</span> Tiger Installer <span class="mut" style="font-weight:400">v' . INSTALLER_VERSION . '</span></div>'
       . $body
       . '<p class="mut" style="margin-top:28px;font-size:.8rem">One file, nothing more. Downloads &amp; verifies the latest Tiger release, installs it above your document root, then deletes itself.</p>'
       . '</div></body></html>';
}

function steps_nav($active) {
    $steps = ['requirements' => 'Requirements', 'location' => 'Location', 'download' => 'Download', 'database' => 'Database', 'admin' => 'Admin'];
    $out = '<ol class="steps">';
    foreach ($steps as $k => $v) { $out .= '<li class="' . ($k === $active ? 'on' : '') . '">' . h($v) . '</li>'; }
    return $out . '</ol>';
}

/** Hidden inputs for the whole value bag (nothing lost on Back/Next), minus the currently-visible fields. */
function hidden_bag($bag, $exclude = []) {
    $out = '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
    foreach ($bag as $k => $v) {
        if (in_array($k, $exclude, true)) { continue; }
        $out .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    }
    return $out;
}

/** Back (optional) + primary Next buttons. The clicked submit button sets the target `step`. Next is
 *  rendered first so pressing Enter submits IT (browsers pick the first submit button); CSS `order`
 *  still shows Back on the left. */
function nav_buttons($backStep, $nextStep, $nextLabel) {
    $out = '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">';
    $out .= '<button type="submit" name="step" value="' . h($nextStep) . '" class="btn" style="order:2">' . h($nextLabel) . ' &rarr;</button>';
    if ($backStep !== '') {
        $out .= '<button type="submit" name="step" value="' . h($backStep) . '" class="btn sec" style="order:1">&larr; Back</button>';
    }
    return $out . '</div>';
}

/** A labelled input that re-populates from the bag (so Back keeps what was typed — passwords included). */
function field($label, $name, $type, $value, $placeholder = '') {
    return '<label>' . h($label) . '</label>'
         . '<input type="' . h($type) . '" name="' . h($name) . '" value="' . h($value) . '"'
         . ($placeholder !== '' ? ' placeholder="' . h($placeholder) . '"' : '') . '>';
}

/** The database form — used for the first ask AND on a connection error (re-populates all fields). */
function db_form($bag, $errNote = '') {
    return '<h1>Connect your database</h1>'
        . ($errNote !== '' ? '<div class="note bad">' . h($errNote) . '</div>' : '<div class="note ok">Tiger&rsquo;s files are installed.</div>')
        . '<div class="card"><h2>First, create a database in cPanel</h2><ol class="mut" style="margin:0;padding-left:18px">'
        . '<li>cPanel &rarr; <strong>MySQL&reg; Databases</strong>.</li>'
        . '<li>Create a <strong>New Database</strong> (e.g. <code>tiger</code>).</li>'
        . '<li>Create a <strong>User</strong> + password, then <strong>Add User to Database</strong> with <strong>ALL PRIVILEGES</strong>.</li>'
        . '<li>Paste the resulting names below (cPanel prefixes them, e.g. <code>acct_tiger</code>).</li></ol></div>'
        . '<form method="post">' . hidden_bag($bag, ['db_host', 'db_name', 'db_user', 'db_pass'])
        . '<div class="card">'
        . field('Database host', 'db_host', 'text', $bag['db_host'] !== '' ? $bag['db_host'] : 'localhost')
        . field('Database name', 'db_name', 'text', $bag['db_name'], 'acct_tiger')
        . field('Database user', 'db_user', 'text', $bag['db_user'], 'acct_tiger')
        . field('Database password', 'db_pass', 'password', $bag['db_pass'])
        . '</div>' . nav_buttons('location', 'admin', $errNote !== '' ? 'Try again' : 'Continue') . '</form>';
}

/** The admin-account form — used for the first ask AND on a create error (remembers all values). */
function admin_form($bag, $errNote = '') {
    return '<h1>Create your admin account</h1>'
        . ($errNote !== '' ? '<div class="note bad">' . h($errNote) . '</div>' : '<div class="note ok">Database installed and ready.</div>')
        . '<form method="post">' . hidden_bag($bag, ['org', 'email', 'username', 'password', 'agent'])
        . '<div class="card">'
        . field('Organization name', 'org', 'text', $bag['org'], 'My Company')
        . field('Admin email', 'email', 'email', $bag['email'])
        . field('Username (optional)', 'username', 'text', $bag['username'])
        . field('Password (min 8)', 'password', 'password', $bag['password'])
        . '<label style="display:flex;gap:9px;align-items:flex-start;margin-top:18px;font-weight:600">'
        . '<input type="checkbox" name="agent" value="1" style="margin-top:4px"' . (truthy($bag['agent']) ? ' checked' : '') . '>'
        . '<span>Let the assistant that installed Tiger manage it'
        . '<br><span class="mut" style="font-weight:400;font-size:.9em">Turns on the <code>/mcp</code> endpoint and shows a scoped access key on the next screen. '
        . 'You can see and revoke it any time at <code>/mcp/admin</code>. Leave this off if you are installing by hand.</span></span></label>'
        . '</div>' . nav_buttons('database', 'finish', $errNote !== '' ? 'Try again' : 'Finish install') . '</form>';
}

/** Shown when the download/extract can't proceed — offers a manual upload + Back. */
function download_error($bag, $errNote) {
    return '<h1>Couldn&rsquo;t install the files</h1><div class="note bad">' . h($errNote) . '</div>'
        . '<div class="card"><h2>Manual upload</h2>'
        . '<p class="mut">Download <code>tiger-&lt;version&gt;.zip</code> from the '
        . '<a style="color:var(--brand)" href="https://github.com/' . RELEASE_REPO . '/releases" target="_blank" rel="noopener">releases page</a> '
        . 'on your computer, then upload it here.</p>'
        . '<form method="post" enctype="multipart/form-data">' . hidden_bag($bag)
        . '<input type="file" name="bundle" accept=".zip" style="margin-bottom:10px">'
        . nav_buttons('location', 'database', 'Upload & continue') . '</form></div>';
}

/* ---------------------------------------------------------------------------
 * Actions (idempotent, so Back/Next can re-enter a step without re-doing harm)
 * ------------------------------------------------------------------------- */

/** Bootstrap Tiger once per request (autoload + boot), guarded so repeated calls are cheap. */
function ensure_booted($appDir) {
    static $booted = false;
    require_once $appDir . '/vendor/autoload.php';
    if (!defined('APPLICATION_ROOT')) { define('APPLICATION_ROOT', $appDir); }
    if (!$booted) { (new Tiger_Application($appDir))->boot(); $booted = true; }
}

/** Download + extract the app above the docroot + wire the shim/assets. Idempotent (skips if already
 *  installed). Returns '' on success or an error message. */
function do_install_files($bag, $home) {
    $appDir  = $bag['app_dir'];
    $docroot = $bag['docroot'];
    if (is_file($appDir . '/vendor/autoload.php')) { return ''; }               // already installed — skip
    if ($appDir === '' || $appDir[0] !== '/') { return 'Please provide an absolute app folder path.'; }
    if (is_file($appDir . '/application/configs/local.ini')
        && preg_match('/tiger\.db\.dbname\s*=\s*"?\S/', (string) @file_get_contents($appDir . '/application/configs/local.ini'))) {
        return 'Tiger already appears to be installed at ' . $appDir . ' — refusing to overwrite. Delete that folder to reinstall.';
    }
    @mkdir($appDir, 0755, true);
    $tmp = $home . '/.tiger-install-tmp';
    @mkdir($tmp, 0700, true);
    $zipPath = $tmp . '/tiger.zip';

    if (!empty($_FILES['bundle']['tmp_name']) && is_uploaded_file($_FILES['bundle']['tmp_name'])) {
        @move_uploaded_file($_FILES['bundle']['tmp_name'], $zipPath);
    } else {
        list($tag, $zipUrl, $shaUrl, $rerr) = resolve_release(req('version', ''));
        if ($rerr) { return $rerr; }
        // Fail CLOSED. Verification used to be skipped entirely when the release carried no
        // .sha256 asset, and skipped again when the fetch came back empty (the `if ($expected …)`
        // short-circuited), so exactly the release/download failures the digest exists to catch
        // were the ones that sailed through unverified. An automatic download now REQUIRES a
        // well-formed digest that matches, and stops before extraction otherwise. A manual upload
        // is a separate, explicit trust decision and is not checked here.
        if (!$shaUrl) {
            return 'That release has no .sha256 checksum to verify the download against. Aborting — '
                 . 'download the ZIP yourself and use the manual upload below if you trust it.';
        }
        if (!http_download($zipUrl, $zipPath)) { return 'Download failed. Try the manual upload below.'; }
        list($shaBody,) = http_get($shaUrl);
        $expected = $shaBody ? strtolower(trim(preg_split('/\s+/', trim((string) $shaBody))[0])) : '';
        if (!preg_match('/^[0-9a-f]{64}$/', $expected)) {
            return 'Could not fetch a valid checksum for the download. Aborting before install — '
                 . 'retry, or use the manual upload below.';
        }
        if (!hash_equals($expected, strtolower(hash_file('sha256', $zipPath)))) {
            return 'Checksum mismatch — the download may be corrupt or tampered. Aborting.';
        }
    }
    if (!is_file($zipPath)) { return 'No bundle to install — please upload the ZIP.'; }

    $ex = $tmp . '/extract';
    @mkdir($ex, 0755, true);
    $za = new ZipArchive();
    if ($za->open($zipPath) !== true) { return 'Could not open the downloaded ZIP.'; }
    $za->extractTo($ex);
    $za->close();
    $rootSrc = $ex;
    $entries = array_values(array_diff(scandir($ex), ['.', '..']));
    if (count($entries) === 1 && is_dir($ex . '/' . $entries[0]) && !is_dir($ex . '/application')) {
        $rootSrc = $ex . '/' . $entries[0];
    }
    if (!is_dir($rootSrc . '/application') || !is_dir($rootSrc . '/vendor')) {
        return 'The downloaded bundle is missing application/ or vendor/ — expected a vendored full-app ZIP.';
    }
    rcopy($rootSrc, $appDir);

    $autoload = $appDir . '/vendor/autoload.php';
    if (!is_file($autoload)) { return 'Extraction incomplete (no vendor/autoload.php).'; }
    $shim = "<?php\n"
          . "// Generated by tiger-install.php — Tiger front controller shim.\n"
          . "define('APPLICATION_ROOT', " . var_export($appDir, true) . ");\n"
          . "require APPLICATION_ROOT . '/vendor/autoload.php';\n"
          . "(new Tiger_Application(APPLICATION_ROOT))->run();\n";
    @file_put_contents($docroot . '/index.php', $shim);
    if (is_file($appDir . '/public/.htaccess')) { @copy($appDir . '/public/.htaccess', $docroot . '/.htaccess'); }

    require_once $autoload;
    if (!defined('APPLICATION_ROOT')) { define('APPLICATION_ROOT', $appDir); }
    try {
        Tiger_Install::provisionStorage($appDir);
        Tiger_Install::linkPublicAssets($docroot, $appDir, 'puma');
        // These were previously created ONLY when symlink() existed, and silently skipped
        // otherwise — no link, no copy, no error. Copy where linking is unavailable so media,
        // code assets and module assets are actually served on a locked-down host.
        foreach (['_media', '_code', '_modules'] as $pub) {
            $target = $appDir . '/public/' . $pub;
            $link   = $docroot . '/' . $pub;
            if (!is_dir($target) || file_exists($link)) { continue; }
            if (!(function_exists('symlink') && @symlink($target, $link))) { rcopy($target, $link); }
        }
    } catch (Throwable $e) {
        return 'Placed files, but wiring assets failed: ' . $e->getMessage();
    }
    return '';
}

/** Test the DB, write local.ini, mint secrets, boot, build the schema. Idempotent. Returns ''/error. */
function do_provision($bag) {
    $appDir = $bag['app_dir'];
    $dbHost = $bag['db_host'] !== '' ? $bag['db_host'] : 'localhost';
    $dbName = $bag['db_name'];
    $dbUser = $bag['db_user'];
    $dbPass = $bag['db_pass'];
    if ($dbName === '' || $dbUser === '') { return 'Database name and user are required.'; }
    if (strpos($dbPass, '"') !== false) { return 'Please use a database password without double-quote (") characters.'; }
    try {
        new PDO('mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4', $dbUser, $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
    } catch (Throwable $e) {
        return 'Could not connect to the database: ' . $e->getMessage();
    }
    $iniPath = $appDir . '/application/configs/local.ini';

    // Refuse to repoint a DIFFERENT, already-configured app. A resume/retry submits the SAME
    // database that is already in the file, so it passes; pointing the wizard at a live install
    // and typing new credentials does not. do_install_files()'s own guard misses this case: it
    // returns success early when vendor/autoload.php exists, before it ever reads local.ini.
    $existingDb = local_ini_value($iniPath, 'tiger.db.dbname');
    if ($existingDb !== '' && strcasecmp($existingDb, $dbName) !== 0) {
        return 'This app folder is already configured for database "' . $existingDb . '". Refusing to '
             . 'repoint an existing installation at "' . $dbName . '" — edit application/configs/local.ini '
             . 'by hand if that is really what you want.';
    }

    // MERGE, never replace: this file also holds tiger.crypto.key and tiger.security.pepper by the
    // time we run a second time, and rewriting it wholesale threw them away (see local_ini_merge_db).
    $ini = local_ini_merge_db($iniPath, [
        'tiger.db.host'     => $dbHost,
        'tiger.db.dbname'   => $dbName,
        'tiger.db.username' => $dbUser,
        'tiger.db.password' => $dbPass,
        'tiger.db.charset'  => 'utf8mb4',
    ]);
    if (!write_file_checked($iniPath, $ini)) {
        return 'Could not write ' . $iniPath . ' — check that the folder is writable.';
    }
    try {
        require_once $appDir . '/vendor/autoload.php';
        Tiger_Install::provisionSecrets($iniPath);
        ensure_booted($appDir);
        // ONE authority for the migration scan — the same helper `bin/tiger migrate` and the module
        // installer use, so a browser install applies BUNDLED tiger-core module migrations too (this
        // hand-rolled scan only saw application/modules, stranding e.g. the agent module's
        // agent_attachment table). Guarded for older bundles that predate the public helper.
        if (method_exists('Tiger_Module_Installer', 'migrationPaths')) {
            $paths = Tiger_Module_Installer::migrationPaths();
        } else {
            $paths = [TIGER_CORE_PATH . '/migrations', APPLICATION_PATH . '/migrations'];
            foreach (glob(MODULES_PATH . '/*/migrations') ?: [] as $m) { $paths[] = $m; }
            foreach (glob(TIGER_CORE_PATH . '/modules/*/migrations') ?: [] as $m) { $paths[] = $m; }
        }
        (new Tiger_Db_Migrator(Zend_Db_Table_Abstract::getDefaultAdapter(), $paths))->migrate(function ($l) {});
    } catch (Throwable $e) {
        return 'Database setup failed: ' . $e->getMessage();
    }
    return '';
}

/** Create the founding org + admin. Returns '' or an error message. */
function do_create_owner($bag, &$owner = null) {
    try {
        ensure_booted($bag['app_dir']);
        $username = $bag['username'] !== '' ? $bag['username'] : null;
        $owner = Tiger_Install::createOwner($bag['email'], $bag['password'], $bag['org'], null, 'developer', $username);
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return '';
}

/**
 * Mint the agent credential and switch `/mcp` on — TIGER-90, the connect handshake.
 *
 * Runs ONLY after do_create_owner() has succeeded: the token is scoped to the owner that was just
 * created, so there is no ordering in which MCP is reachable before an admin exists to revoke it.
 *
 * Within that, mint BEFORE enabling. The ticket requires both to happen after the owner exists and
 * warns that the reverse order can leave "MCP enabled on a site with no admin"; minting first also
 * means a failure at the mint step leaves the site on today's defaults (MCP off) rather than on with
 * no credential to show for it. The safe half-state is the one that grants nothing.
 *
 * Failure here NEVER fails the install. The site is already live and the owner already exists; all
 * that is lost is the convenience, and the finish screen says so plainly.
 *
 * Scope: the curated starter set (Tiger_Mcp_Token::DEFAULT_MODULES), org-scoped, not read-only.
 * `tiger.api.discovery` is deliberately left alone — publishing the OpenAPI document is a separate
 * decision, and MCP's tools/list already gives the client its typed surface.
 *
 * @return array {ok: bool, token?: string, modules?: string[], error?: string}
 */
function do_enable_agent($bag, $owner) {
    try {
        ensure_booted($bag['app_dir']);

        $userId = is_array($owner) ? ($owner['user_id'] ?? null) : null;
        $orgId  = is_array($owner) ? ($owner['org_id']  ?? null) : null;
        if ($userId === null) { return ['ok' => false, 'error' => 'No owner id was returned; agent access not enabled.']; }

        $cred = (new Tiger_Model_UserCredential())->createToken($userId);
        Tiger_Mcp_Token::saveConfig($cred['credential_id'], [
            'modules'    => Tiger_Mcp_Token::DEFAULT_MODULES,
            'read_only'  => false,
            'org_scoped' => true,
            'role'       => 'developer',
            'org_id'     => (string) $orgId,
        ]);

        // Only now is there a credential to reach it with.
        (new Tiger_Model_Config())->set(Tiger_Model_Config::SCOPE_GLOBAL, '', Tiger_Mcp::CONFIG_ENABLED, '1');

        return ['ok' => true, 'token' => $cred['token'], 'modules' => Tiger_Mcp_Token::DEFAULT_MODULES];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/* ---------------------------------------------------------------------------
 * Controller — a Back/Next wizard. Every field rides in a "bag" carried on every
 * request, so navigating Back never loses what you typed (passwords included).
 * Side-effecting work (download, migrate, create-admin) runs on forward entry and
 * is idempotent, so re-entering a step never re-does harm.
 * ------------------------------------------------------------------------- */

$docroot = detect_docroot();
$home    = detect_home($docroot);
$domain  = detect_domain();
$step    = req('step', 'welcome');

// The value bag — read every field each request; fill sensible defaults once.
$bag = [];
foreach (['app_dir', 'docroot', 'db_host', 'db_name', 'db_user', 'db_pass', 'org', 'email', 'username', 'password', 'agent'] as $f) {
    $bag[$f] = post($f, '');
}
// `agent` may be SEEDED from the query string (?agent=1) but only on a GET. On a POST the visible
// checkbox is the only authority, so un-ticking it actually turns it off — an unchecked box submits
// nothing, which is exactly what makes the seeded choice reversible (TIGER-90).
//
// This is safe ONLY because there is no callback: the minted token is displayed on the installer's own
// screen and nowhere else, so a crafted ?agent=1 link gains its sender nothing — they do not see the
// screen, the person running the install does. Re-read TIGER-90 before adding any field that would
// send the credential somewhere.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $bag['agent'] = truthy(req('agent', '')) ? '1' : '';
}
$agentWanted = truthy($bag['agent']);
if ($bag['docroot'] === '') { $bag['docroot'] = $docroot; }
if ($bag['app_dir'] === '') { $bag['app_dir'] = $home . '/' . $domain . '/tiger-app'; }
if ($bag['db_host'] === '') { $bag['db_host'] = 'localhost'; }

// CSRF gate for every POST.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_ok()) {
    page('Session expired', '<div class="card"><div class="note bad">This page expired. <a href="?" style="color:var(--brand)">Start over</a>.</div></div>',
        ['installer' => INSTALLER_VERSION, 'step' => 'expired', 'status' => 'error', 'error' => 'csrf_expired',
         'detail' => 'The CSRF cookie did not match. Reload the installer and start again.']);
    exit;
}

switch ($step) {

/* --- Requirements ------------------------------------------------------- */
case 'welcome':
default:
    $checks  = preflight($docroot, $home);
    $blocked = false;
    $rows    = '';
    foreach ($checks as $c) {
        $pill = $c['ok'] ? '<span class="pill p-ok">PASS</span>'
             : ($c['hard'] ? '<span class="pill p-bad">FAIL</span>' : '<span class="pill p-warn">WARN</span>');
        if (!$c['ok'] && $c['hard']) { $blocked = true; }
        $rows .= '<tr><td>' . $pill . '</td><td><strong>' . h($c['label']) . '</strong><br><span class="mut">'
              . $c['detail'] . '</span>' . (!$c['ok'] ? '<br><span class="mut" style="font-size:.85em">&rarr; ' . h($c['fix']) . '</span>' : '')
              . '</td></tr>';
    }
    $body = steps_nav('requirements')
        . '<h1>Install Tiger</h1><p class="mut">This checks your hosting, then installs Tiger in a few clicks — '
        . 'the app and your secrets go <strong>above</strong> your web root, unlike the old <code>wp-config.php</code> way.</p>'
        . '<div class="card"><h2>Requirements</h2><table>' . $rows . '</table></div>';
    $body .= $blocked
        ? '<div class="note bad">Fix the <strong>FAIL</strong> items in cPanel, then reload this page.</div>'
        : '<form method="post">' . hidden_bag($bag) . nav_buttons('', 'location', 'Continue') . '</form>';
    page('Requirements', $body, [
        'installer' => INSTALLER_VERSION,
        'step'      => 'requirements',
        'status'    => $blocked ? 'blocked' : 'awaiting-input',
        'next_step' => $blocked ? null : 'location',
        'checks'    => array_map(static fn($c) => ['label' => $c['label'], 'ok' => (bool) $c['ok'],
                                                   'required' => (bool) $c['hard'], 'fix' => $c['ok'] ? null : $c['fix']], $checks),
    ]);
    break;

/* --- Location ----------------------------------------------------------- */
case 'location':
    $body = steps_nav('location')
        . '<h1>Where Tiger goes</h1>'
        . '<div class="card"><table>'
        . '<tr><td class="mut">Domain</td><td><strong>' . h($domain) . '</strong></td></tr>'
        . '<tr><td class="mut">Document root</td><td><code>' . h($docroot) . '</code><br><span class="mut" style="font-size:.85em">detected — the only web-reachable folder</span></td></tr>'
        . '<tr><td class="mut">Account home</td><td><code>' . h($home) . '</code></td></tr>'
        . '</table></div>'
        . '<form method="post">' . hidden_bag($bag, ['app_dir'])
        . '<div class="card"><h2>Application folder (above the web root)</h2>'
        . '<p class="mut">Your code + <code>local.ini</code> live here, safely out of the web root. The default is '
        . 'domain-namespaced so multiple domains never collide.</p>'
        . field('App folder', 'app_dir', 'text', $bag['app_dir'])
        . '<div class="note">Running several domains on this account? Each gets its own folder like '
        . '<code>' . h($home) . '/&lt;domain&gt;/tiger-app</code> and its own database — fully independent installs.</div>'
        . '</div>' . nav_buttons('welcome', 'database', 'Download & install') . '</form>';
    page('Location', $body, ['installer' => INSTALLER_VERSION, 'step' => 'location', 'status' => 'awaiting-input',
        'next_step' => 'download', 'fields' => ['app_dir', 'docroot'], 'app_dir' => $bag['app_dir'], 'docroot' => $bag['docroot']]);
    break;

/* --- Database — download+extract on entry, then the DB form ------------- */
case 'database':
    $err = do_install_files($bag, $home);
    if ($err !== '') { page('Download', steps_nav('download') . download_error($bag, $err),
        ['installer' => INSTALLER_VERSION, 'step' => 'download', 'status' => 'error', 'error' => 'download_failed', 'detail' => $err]); break; }
    page('Database', steps_nav('database') . db_form($bag),
        ['installer' => INSTALLER_VERSION, 'step' => 'database', 'status' => 'awaiting-input', 'next_step' => 'admin',
         'fields' => ['db_host', 'db_name', 'db_user', 'db_pass']]);
    break;

/* --- Admin — provision the DB on entry, then the admin form ------------- */
case 'admin':
    $err = do_install_files($bag, $home);
    if ($err !== '') { page('Download', steps_nav('download') . download_error($bag, $err),
        ['installer' => INSTALLER_VERSION, 'step' => 'download', 'status' => 'error', 'error' => 'download_failed', 'detail' => $err]); break; }
    $err = do_provision($bag);
    if ($err !== '') { page('Database', steps_nav('database') . db_form($bag, $err),
        ['installer' => INSTALLER_VERSION, 'step' => 'database', 'status' => 'error', 'error' => 'database_failed', 'detail' => $err,
         'fields' => ['db_host', 'db_name', 'db_user', 'db_pass']]); break; }
    page('Admin', steps_nav('admin') . admin_form($bag),
        ['installer' => INSTALLER_VERSION, 'step' => 'admin', 'status' => 'awaiting-input', 'next_step' => 'finish',
         'fields' => ['org', 'email', 'username', 'password', 'agent'], 'agent_requested' => $agentWanted]);
    break;

/* --- Finish — create the admin, self-delete ----------------------------- */
case 'finish':
    $err = do_install_files($bag, $home);
    if ($err !== '') { page('Download', steps_nav('download') . download_error($bag, $err),
        ['installer' => INSTALLER_VERSION, 'step' => 'download', 'status' => 'error', 'error' => 'download_failed', 'detail' => $err]); break; }
    $err = do_provision($bag);
    if ($err !== '') { page('Database', steps_nav('database') . db_form($bag, $err),
        ['installer' => INSTALLER_VERSION, 'step' => 'database', 'status' => 'error', 'error' => 'database_failed', 'detail' => $err,
         'fields' => ['db_host', 'db_name', 'db_user', 'db_pass']]); break; }
    $owner = null;
    $err = do_create_owner($bag, $owner);
    if ($err !== '') { page('Admin', steps_nav('admin') . admin_form($bag, $err),
        ['installer' => INSTALLER_VERSION, 'step' => 'admin', 'status' => 'error', 'error' => 'owner_failed', 'detail' => $err,
         'fields' => ['org', 'email', 'username', 'password', 'agent'], 'agent_requested' => $agentWanted]); break; }

    // TIGER-90 — only now: the owner exists, so the credential has someone to belong to.
    $agent = $agentWanted ? do_enable_agent($bag, $owner) : ['ok' => false, 'error' => ''];

    @unlink($home . '/.tiger-install-tmp/tiger.zip');
    $deleted = @unlink(__FILE__);
    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base    = $scheme . '://' . $domain;
    $body = '<h1>&#127881; Tiger is installed</h1>'
        . '<div class="note ok">Your site is live. The app + your secrets are safely above the web root at <code>' . h($bag['app_dir']) . '</code>.</div>'
        . '<div class="card"><table>'
        . '<tr><td class="mut">Your site</td><td><a style="color:var(--brand)" target="_blank" rel="noopener" href="' . h($base) . '/">' . h($base) . '/</a></td></tr>'
        . '<tr><td class="mut">Sign in</td><td><a style="color:var(--brand)" target="_blank" rel="noopener" href="' . h($base) . '/login">' . h($base) . '/login</a></td></tr>'
        . '<tr><td class="mut">Admin</td><td><a style="color:var(--brand)" target="_blank" rel="noopener" href="' . h($base) . '/admin">' . h($base) . '/admin</a></td></tr>'
        . '</table></div>'
        . ($deleted
            ? '<div class="note ok">This installer has deleted itself. Nothing else to clean up.</div>'
            : '<div class="note bad"><strong>Delete this file now.</strong> The installer couldn&rsquo;t remove itself — delete <code>' . h(__FILE__) . '</code> via File Manager/FTP immediately.</div>');

    // --- The agent credential, shown once (TIGER-90) -------------------------------------------
    // The installer self-deletes, so this screen is the ONLY place the user learns the credential
    // exists. Say where to manage it, not just what it is.
    if ($agentWanted && !empty($agent['ok'])) {
        $body .= '<div class="card"><h2>&#129302; Your assistant can manage this site</h2>'
            . '<p class="mut">Give this key to the assistant that installed Tiger. It is shown <strong>once</strong>. '
            . 'It reaches ' . h(implode(', ', $agent['modules'])) . ' for this organization only, and it is never more than your own permissions allow.</p>'
            . '<table>'
            . '<tr><td class="mut">Endpoint</td><td><code>' . h($base) . '/mcp</code></td></tr>'
            . '<tr><td class="mut">Access key</td><td><code style="word-break:break-all">' . h($agent['token']) . '</code></td></tr>'
            . '<tr><td class="mut">Manage / revoke</td><td><a style="color:var(--brand)" target="_blank" rel="noopener" href="' . h($base) . '/mcp/admin">' . h($base) . '/mcp/admin</a></td></tr>'
            . '</table>'
            . '<div class="note">Keep it like a password. If it ever leaks, revoke it at <code>/mcp/admin</code> and mint a new one — the site itself is unaffected.</div>'
            . '</div>';
    } elseif ($agentWanted) {
        // Asked for, but the mint failed. The install is fine; only the convenience was lost.
        $body .= '<div class="note bad"><strong>Agent access was not enabled.</strong> '
            . h($agent['error'] !== '' ? $agent['error'] : 'The access key could not be created.')
            . ' Your site is installed and working. Turn it on any time at <code>' . h($base) . '/mcp/admin</code>.</div>';
    } else {
        // The majority path for a hand install — a clear next step, not silence.
        $body .= '<div class="note"><strong>Using an AI assistant?</strong> The <code>/mcp</code> endpoint is off. '
            . 'Turn it on and mint a scoped key at <code>' . h($base) . '/mcp/admin</code>, then reconnect your assistant.</div>';
    }

    page('Done', $body, [
        'installer'    => INSTALLER_VERSION,
        'step'         => 'finish',
        'status'       => 'ok',
        'site'         => $base . '/',
        'login'        => $base . '/login',
        'admin'        => $base . '/admin',
        'app_dir'      => $bag['app_dir'],
        'self_deleted' => (bool) $deleted,
        'agent'        => $agentWanted && !empty($agent['ok'])
            ? [
                'enabled'  => true,
                'endpoint' => $base . '/mcp',
                'token'    => $agent['token'],
                'manage'   => $base . '/mcp/admin',
                'scope'    => ['modules' => $agent['modules'], 'org_scoped' => true, 'read_only' => false],
            ]
            : [
                'enabled' => false,
                'manage'  => $base . '/mcp/admin',
                'reason'  => $agentWanted ? 'mint_failed' : 'not_requested',
                'error'   => $agentWanted ? $agent['error'] : null,
            ],
    ]);
    break;
}
