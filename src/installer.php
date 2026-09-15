<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * tiger-install.php — the one-file Tiger web installer.
 *
 * Drop this single file into a domain's document root (e.g. public_html/) and open it in a
 * browser. It:
 *   1. checks your host meets Tiger's requirements,
 *   2. asks for the database you created in cPanel and the admin account you want,
 *   3. downloads the latest Tiger release ZIP from GitHub (verified against its .sha256),
 *   4. extracts the app ABOVE the document root (so your secrets are never web-reachable),
 *   5. writes only a tiny shim + asset links into the document root,
 *   6. builds the schema and creates your admin account,
 *   7. deletes itself.
 *
 * You create the empty MySQL database + user in cPanel first (a normal DB account can't create
 * one from PHP); the installer does everything else. It supports domain-namespaced multi-domain
 * cPanel accounts: each domain becomes its own self-contained install.
 *
 * THIS FILE HOLDS NO INSTALL LOGIC OF ITS OWN. The install is tiger-headless — the same engine the
 * WHM plugin and provisioning scripts run — vendored into this file from its tagged release by
 * build.php (see the marker below). The wizard collects the inputs, hands the engine one spec, and
 * runs it a few steps per request so a shared host's request limits never cut an install in half;
 * the engine's ledger carries progress between requests and resumes after any failure.
 *
 * Repo: https://github.com/WebTigers/TigerInstall  (evergreen — one file, resolves the latest release)
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
@ini_set('display_errors', '1');
@set_time_limit(0);

const INSTALLER_VERSION = '2.0.2';
const ENGINE_VERSION    = '@@ENGINE_VERSION@@';   // stamped by build.php from the vendored tag
const RELEASE_REPO      = 'webtigers/tiger';      // the skeleton repo whose releases host the full-app bundle
const MIN_PHP           = '8.1.0';
// What a fresh install is offered — the same two public files the WHM plugin reads: the catalog (featured
// theme/modules + skill packs, WebTigers/TigerCatalog) and the Directory (what is installable, WebTigers/TigerVendors).
const CATALOG_URL       = 'https://raw.githubusercontent.com/WebTigers/TigerCatalog/main/catalog.json';
const DIRECTORY_URL     = 'https://raw.githubusercontent.com/WebTigers/TigerVendors/main/data/index.json';
const LIST_TIMEOUT      = 5;   // seconds a page render may spend on each list; unreachable = Tiger's defaults

/* @@TIGER_HEADLESS@@ */

// CSRF via a same-site double-submit COOKIE — deliberately NOT a PHP session: a native session
// (session_start) defines SID, and Zend_Session::start() then throws "session has already been
// started" when the engine boots Tiger to build the schema. A cookie sidesteps that collision entirely.
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
/** A posted list (checkboxes) as clean slugs. */
function posta($k) {
    $v = isset($_POST[$k]) && is_array($_POST[$k]) ? $_POST[$k] : [];
    return array_values(array_unique(array_filter(array_map(static fn($x) => preg_match('/^[a-z0-9][a-z0-9_-]*$/', strtolower(trim((string) $x))) ? strtolower(trim((string) $x)) : '', $v))));
}
function req($k, $d = '') { return isset($_REQUEST[$k]) ? trim((string) $_REQUEST[$k]) : $d; }

/** Checkbox / query-flag truthiness: "1", "on", "true", "yes" (any case, trimmed). */
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

/* ---------------------------------------------------------------------------
 * The job — one install's spec, kept ABOVE the docroot between requests
 *
 * The engine takes a whole spec or nothing, and it runs across several requests. The spec (with the
 * database password) lives in a 0600 file in the account's home, named by a random token the
 * browser holds in a cookie — never in hidden form fields, never in the docroot. It is removed the
 * moment the install completes (the same values then live in local.ini, above the docroot too).
 * ------------------------------------------------------------------------- */

function job_dir($home)  { return $home . '/.tiger-install-tmp'; }
function job_token() {
    $t = (string) ($_COOKIE['tiger_install_job'] ?? '');
    return preg_match('/^[a-f0-9]{32}$/', $t) ? $t : '';
}
function job_path($home, $token) { return job_dir($home) . '/job-' . $token . '.json'; }
function job_load($home) {
    $t = job_token();
    if ($t === '' || !is_file(job_path($home, $t))) { return null; }
    $j = json_decode((string) @file_get_contents(job_path($home, $t)), true);
    return is_array($j) && is_array($j['spec'] ?? null) ? $j : null;
}
function job_save($home, array $job) {
    $t = job_token();
    if ($t === '') {
        $t = bin2hex(random_bytes(16));
        @setcookie('tiger_install_job', $t, ['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE['tiger_install_job'] = $t;
    }
    @mkdir(job_dir($home), 0700, true);
    $ok = @file_put_contents(job_path($home, $t), json_encode($job, JSON_UNESCAPED_SLASHES)) !== false;
    if ($ok) { @chmod(job_path($home, $t), 0600); }
    return $ok;
}
function job_clear($home) {
    $t = job_token();
    if ($t !== '') { @unlink(job_path($home, $t)); }
    @setcookie('tiger_install_job', '', ['path' => '/', 'expires' => 1]);
}

/* ---------------------------------------------------------------------------
 * The lists — what a fresh install is offered (theme, modules, skill packs)
 *
 * Read from the same two public files the WHM plugin uses, cached for an hour in the job dir above
 * the docroot, each with a short budget: an unreachable GitHub means "Tiger's defaults", never a stuck
 * page. Everything is shape-checked; junk upstream degrades to an empty list.
 * ------------------------------------------------------------------------- */

function list_fetch($home, $name, $url) {
    $cache = job_dir($home) . '/' . $name . '.json';
    if (is_file($cache) && filemtime($cache) > time() - 3600) { $j = json_decode((string) @file_get_contents($cache), true); if (is_array($j)) { return $j; } }
    list($body, $code) = Tiger_Headless_Http::get($url, 'application/json', LIST_TIMEOUT);
    $j = ($body !== null && $code < 400) ? json_decode($body, true) : null;
    if (is_array($j)) { @mkdir(job_dir($home), 0700, true); @file_put_contents($cache, $body); @chmod($cache, 0600); return $j; }
    return is_file($cache) ? (json_decode((string) @file_get_contents($cache), true) ?: null) : null;   // stale beats absent
}

/** {featured:{theme,modules}, packs:[{id,name,description,default,skills:[{repo,path,ref}]}]} */
function catalog_load($home) {
    $out = ['featured' => ['theme' => '', 'modules' => []], 'packs' => []];
    $doc = list_fetch($home, 'catalog', CATALOG_URL);
    if (!is_array($doc)) { return $out; }
    $slug = static fn($v) => preg_match('/^[a-z0-9][a-z0-9_-]*$/', $v = strtolower(trim((string) $v))) ? $v : '';
    $f = is_array($doc['featured'] ?? null) ? $doc['featured'] : [];
    $out['featured']['theme']   = $slug($f['theme'] ?? '');
    $out['featured']['modules'] = array_values(array_filter(array_map($slug, is_array($f['modules'] ?? null) ? $f['modules'] : [])));
    foreach ((is_array($doc['skill_packs'] ?? null) ? $doc['skill_packs'] : []) as $pk) {
        if (!is_array($pk) || ($id = $slug($pk['id'] ?? '')) === '') { continue; }
        $skills = [];
        foreach ((is_array($pk['skills'] ?? null) ? $pk['skills'] : []) as $sk) {
            if (!is_array($sk)) { continue; }
            $repo = trim((string) ($sk['repo'] ?? '')); $path = trim((string) ($sk['path'] ?? ''), '/'); $ref = trim((string) ($sk['ref'] ?? 'main'));
            if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) || $path === '' || strpos($path, '..') !== false) { continue; }
            $skills[] = ['repo' => $repo, 'path' => $path, 'ref' => $ref !== '' ? $ref : 'main'];
        }
        if (!$skills) { continue; }
        $out['packs'][] = ['id' => $id, 'name' => trim(strip_tags((string) ($pk['name'] ?? $id))), 'description' => trim(strip_tags((string) ($pk['description'] ?? ''))), 'default' => !empty($pk['default']), 'skills' => $skills];
    }
    return $out;
}

/** {themes:[{slug,name,version}], modules:[{slug,name,version,description}]} — free, end-user installables only. */
function directory_load($home) {
    $out = ['themes' => [], 'modules' => []];
    $idx = list_fetch($home, 'directory', DIRECTORY_URL);
    foreach ((is_array($idx['modules'] ?? null) ? $idx['modules'] : []) as $m) {
        if (!is_array($m)) { continue; }
        $slug = strtolower(trim((string) ($m['slug'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug) || (string) ($m['pricing']['model'] ?? 'free') !== 'free' || empty($m['repository'])) { continue; }
        $type = (string) ($m['type'] ?? '');
        if (!in_array($type, ['theme', 'app', 'plugin', 'code'], true)) { continue; }
        $row = ['slug' => $slug, 'name' => trim(strip_tags((string) ($m['module'] ?? $m['name'] ?? $slug))), 'version' => trim(strip_tags((string) ($m['version'] ?? ''))), 'description' => trim(strip_tags((string) ($m['description'] ?? '')))];
        $out[$type === 'theme' ? 'themes' : 'modules'][] = $row;
    }
    usort($out['themes'],  static fn($a, $b) => strcmp($a['name'], $b['name']));
    usort($out['modules'], static fn($a, $b) => strcmp($a['name'], $b['name']));
    return $out;
}

/** The flat, deduplicated skills list the engine takes, from the chosen pack ids. */
function skills_for(array $catalog, array $packIds) {
    $out = []; $seen = [];
    foreach ($catalog['packs'] as $pk) {
        if (!in_array($pk['id'], $packIds, true)) { continue; }
        foreach ($pk['skills'] as $sk) { $k = strtolower($sk['repo'] . '@' . $sk['path']); if (!isset($seen[$k])) { $seen[$k] = true; $out[] = $sk; } }
    }
    return $out;
}

/**
 * The "What to install" card: theme, modules, skill packs — pre-selected from the catalog until the
 * person has seen the card once (`choices_seen`), after which what they ticked is what they get.
 */
function choices_form(array $bag, array $catalog, array $dir) {
    $seen    = $bag['choices_seen'] === '1';
    $theme   = $seen ? $bag['theme'] : $catalog['featured']['theme'];
    $modules = $seen ? $bag['modules'] : $catalog['featured']['modules'];
    $packs   = $seen ? $bag['packs'] : array_column(array_filter($catalog['packs'], static fn($p) => $p['default']), 'id');
    $h = '<div class="card"><h2>What to install</h2><input type="hidden" name="choices_seen" value="1">';
    if (!$dir['themes'] && !$dir['modules'] && !$catalog['packs']) {
        return $h . '<p class="mut">The lists could not be fetched from GitHub just now — Tiger installs with its built-in defaults; add themes, modules and skills later from the admin.</p></div>';
    }
    $h .= '<label>Theme</label><select name="theme" style="width:100%;padding:10px 12px;background:#0d1014;border:1px solid var(--line);border-radius:8px;color:var(--ink);font:inherit">'
        . '<option value="">Tiger default</option>';
    foreach ($dir['themes'] as $t) { $h .= '<option value="' . h($t['slug']) . '"' . ($theme === $t['slug'] ? ' selected' : '') . '>' . h($t['name']) . ($t['version'] !== '' ? ' ' . h($t['version']) : '') . '</option>'; }
    $h .= '</select>';
    if ($dir['modules']) {
        $h .= '<label style="margin-top:16px">Modules</label><div class="grid">';
        foreach ($dir['modules'] as $m) {
            $h .= '<label style="display:flex;gap:8px;align-items:flex-start;font-weight:400;margin:4px 0" title="' . h($m['description']) . '"><input type="checkbox" name="modules[]" value="' . h($m['slug']) . '"' . (in_array($m['slug'], $modules, true) ? ' checked' : '') . ' style="margin-top:4px"><span>' . h($m['name']) . ($m['version'] !== '' ? ' <span class="mut">' . h($m['version']) . '</span>' : '') . '</span></label>';
        }
        $h .= '</div>';
    }
    if ($catalog['packs']) {
        $h .= '<label style="margin-top:16px">Skills for Tiger\'s AI agent <span class="mut" style="font-weight:400">— installed as sets; add or remove any later from Settings → Agent → Skills</span></label><div class="grid">';
        foreach ($catalog['packs'] as $pk) {
            $h .= '<label style="display:flex;gap:9px;align-items:flex-start;font-weight:400;padding:10px 12px;border:1px solid var(--line);border-radius:8px;margin:4px 0"><input type="checkbox" name="packs[]" value="' . h($pk['id']) . '"' . (in_array($pk['id'], $packs, true) ? ' checked' : '') . ' style="margin-top:4px"><span><strong>' . h($pk['name']) . '</strong><br><span class="mut" style="font-size:.9em">' . h($pk['description']) . '</span></span></label>';
        }
        $h .= '</div>';
    }
    return $h . '</div>';
}

/* ---------------------------------------------------------------------------
 * Page + forms
 * ------------------------------------------------------------------------- */

function state_block(array $state) {
    if (!$state) { return ''; }
    $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    // </script> can never appear inside a JSON string here, but belt-and-braces for embedded content.
    $json = str_replace('<', '\\u003C', (string) $json);
    return '<script type="application/json" id="tiger-install-state">' . $json . '</script>';
}

function page($title, $body, array $state = []) {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . h($title) . ' — Tiger Installer</title><style>'
       . ':root{--bg:#0f1216;--card:#171b21;--ink:#e8eaed;--mut:#9aa4b2;--line:#2a2f37;--brand:#f59e0b;--ok:#22c55e;--bad:#ef4444;--warn:#eab308}'
       . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}'
       . '.wrap{max-width:760px;margin:0 auto;padding:32px 20px 64px}h1{font-size:1.5rem;margin:.2em 0}h2{font-size:1.1rem}'
       . '.brand{display:flex;align-items:center;gap:10px;color:var(--brand);font-weight:700;letter-spacing:.02em}'
       . '.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:20px 22px;margin:18px 0}'
       . '.mut{color:var(--mut)}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:7px 6px;border-bottom:1px solid var(--line);vertical-align:top}'
       . '.pill{font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:99px;white-space:nowrap}.p-ok{background:rgba(34,197,94,.15);color:var(--ok)}'
       . '.p-bad{background:rgba(239,68,68,.15);color:var(--bad)}.p-warn{background:rgba(234,179,8,.15);color:var(--warn)}.p-mut{background:rgba(154,164,178,.15);color:var(--mut)}'
       . 'label{display:block;margin:12px 0 4px;font-weight:600}input[type=text],input[type=password],input[type=email]{width:100%;padding:10px 12px;'
       . 'background:#0d1014;border:1px solid var(--line);border-radius:8px;color:var(--ink);font:inherit}'
       . 'code{background:#0d1014;border:1px solid var(--line);border-radius:5px;padding:1px 6px;font-size:.85em}'
       . '.btn{display:inline-block;margin-top:18px;background:var(--brand);color:#1a1205;border:0;border-radius:8px;padding:11px 22px;font:inherit;font-weight:700;cursor:pointer;text-decoration:none}'
       . '.btn.sec{background:transparent;color:var(--ink);border:1px solid var(--line)}'
       . '.note{border-left:3px solid var(--brand);padding:8px 12px;background:rgba(245,158,11,.06);border-radius:0 8px 8px 0;margin:12px 0}'
       . '.bad{border-left-color:var(--bad);background:rgba(239,68,68,.07)}.ok{border-left-color:var(--ok);background:rgba(34,197,94,.07)}'
       . 'ol.steps{counter-reset:s;list-style:none;padding:0;display:flex;gap:8px;flex-wrap:wrap;margin:0 0 8px}ol.steps li{color:var(--mut);font-size:.8rem}'
       . 'ol.steps li.on{color:var(--brand);font-weight:700}.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}@media(max-width:560px){.grid{grid-template-columns:1fr}}'
       . '</style></head><body><div class="wrap">'
       . state_block($state)
       . '<div class="brand"><span style="font-size:1.4rem">&#128062;</span> Tiger Installer <span class="mut" style="font-weight:400">v' . INSTALLER_VERSION . ' · engine ' . h(ENGINE_VERSION) . '</span></div>'
       . $body
       . '<p class="mut" style="margin-top:28px;font-size:.8rem">One file, nothing more. Downloads &amp; verifies the latest Tiger release, installs it above your document root, then deletes itself.</p>'
       . '</div></body></html>';
}

function steps_nav($active) {
    $steps = ['requirements' => 'Requirements', 'location' => 'Location', 'details' => 'Database & admin', 'install' => 'Install'];
    $out = '<ol class="steps">';
    foreach ($steps as $k => $v) { $out .= '<li class="' . ($k === $active ? 'on' : '') . '">' . h($v) . '</li>'; }
    return $out . '</ol>';
}

/** Hidden inputs for the whole value bag (nothing lost on Back/Next), minus the currently-visible fields. */
function hidden_bag($bag, $exclude = []) {
    $out = '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
    foreach ($bag as $k => $v) {
        if (in_array($k, $exclude, true) || is_array($v)) { continue; }
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

/**
 * The details form — the database you created in cPanel + the admin account you want, one screen.
 * Used for the first ask AND on any error (re-populates every field, passwords included).
 */
function admin_form($bag, $errNote = '', ?array $catalog = null, ?array $dir = null) {
    $bag += ['choices_seen' => '', 'theme' => '', 'modules' => [], 'packs' => []];
    return '<h1>Your database and admin account</h1>'
        . ($errNote !== '' ? '<div class="note bad">' . h($errNote) . '</div>' : '')
        // The handoff. An assistant that drove the browser here should stop: the admin password is the
        // owner's to choose, and this form is the moment a human is present. What happens next is
        // spelled out so nobody sits wondering whether to click or wait.
        . '<div class="note"><strong>Installing with an AI assistant?</strong> This part is yours: fill in the database you created, '
        . 'choose your admin password, and click <strong>Install Tiger</strong>. When it finishes, <strong>download the credentials file</strong> '
        . 'and hand it to your assistant &mdash; it holds the site address, your admin login, and (if you tick the box below) the access key that lets it manage the site.</div>'
        . '<div class="card"><h2>First, create a database in cPanel</h2><ol class="mut" style="margin:0;padding-left:18px">'
        . '<li>cPanel &rarr; <strong>MySQL&reg; Databases</strong>.</li>'
        . '<li>Create a <strong>New Database</strong> (e.g. <code>tiger</code>).</li>'
        . '<li>Create a <strong>User</strong> + password, then <strong>Add User to Database</strong> with <strong>ALL PRIVILEGES</strong>.</li>'
        . '<li>Paste the resulting names below (cPanel prefixes them, e.g. <code>acct_tiger</code>).</li></ol></div>'
        . '<form method="post">' . hidden_bag($bag, ['db_host', 'db_name', 'db_user', 'db_pass', 'org', 'email', 'email2', 'username', 'password', 'password2', 'agent', 'choices_seen', 'theme', 'modules', 'packs'])
        . '<div class="card"><h2>Database</h2><div class="grid">'
        . field('Database host', 'db_host', 'text', $bag['db_host'] !== '' ? $bag['db_host'] : 'localhost')
        . field('Database name', 'db_name', 'text', $bag['db_name'], 'acct_tiger')
        . field('Database user', 'db_user', 'text', $bag['db_user'], 'acct_tiger')
        . field('Database password', 'db_pass', 'password', $bag['db_pass'])
        . '</div></div>'
        . ($catalog !== null && $dir !== null ? choices_form($bag, $catalog, $dir) : '')
        . '<div class="card"><h2>Admin account</h2><div class="grid">'
        . field('Organization name', 'org', 'text', $bag['org'], 'My Company')
        . field('Username (optional)', 'username', 'text', $bag['username'])
        . field('Admin email', 'email', 'email', $bag['email'])
        . field('Confirm email', 'email2', 'email', $bag['email2'])
        . field('Password (min 12)', 'password', 'password', $bag['password'])
        . field('Confirm password', 'password2', 'password', $bag['password2'])
        . '</div>'
        . '<label style="display:flex;gap:9px;align-items:flex-start;margin-top:18px;font-weight:600">'
        . '<input type="checkbox" name="agent" value="1" style="margin-top:4px"' . (truthy($bag['agent']) ? ' checked' : '') . '>'
        . '<span>Let the assistant that installed Tiger manage it'
        . '<br><span class="mut" style="font-weight:400;font-size:.9em">Turns on the <code>/mcp</code> endpoint and shows a scoped access key when the install completes. '
        . 'You can see and revoke it any time at <code>/mcp/admin</code>. Leave this off if you are installing by hand.</span></span></label>'
        . '</div>' . nav_buttons('location', 'install', $errNote !== '' ? 'Try again' : 'Install Tiger') . '</form>';
}

/** Cheap local checks before the slow work — nobody should wait through a download to be told they mistyped their own email. */
function admin_errors($bag) {
    if ($bag['email'] === '')                  { return 'Enter an admin email address.'; }
    if (strcasecmp($bag['email'], $bag['email2']) !== 0) {
        return 'The two email addresses do not match.';
    }
    if ($bag['password'] === '')               { return 'Choose a password.'; }
    if (!hash_equals($bag['password'], $bag['password2'])) {
        return 'The two passwords do not match.';
    }
    return '';
}

/**
 * The credentials the user must keep, as a downloadable file.
 *
 * The installer DELETES ITSELF on success, so this screen is the only moment these values exist in one
 * place — the DB password afterwards lives only in local.ini above the docroot, and the agent key is
 * shown exactly once and never again. A user who closes this tab has lost them.
 *
 * Served as a `data:` URI on a normal <a download>, which means NO JavaScript and, more importantly,
 * nothing sensitive is ever written to the server. Writing this file into the docroot would publish
 * every secret in the install to anyone who guessed the filename; a file that never exists on disk
 * cannot be fetched, and cannot be left behind when the installer removes itself.
 */
function credentials_file($bag, $base, $agent) {
    $L = function ($k, $v) { return $v === '' || $v === null ? '' : sprintf("%-16s %s\n", $k, $v); };

    $t  = "Tiger — installation credentials\n";
    $t .= "================================\n";
    $t .= "Saved " . gmdate('Y-m-d H:i') . " UTC by tiger-install " . INSTALLER_VERSION . "\n\n";
    $t .= "THIS FILE CONTAINS PASSWORDS. Store it somewhere private — a password manager,\n";
    $t .= "not your Downloads folder. Anyone holding it can take over the site.\n\n";

    $t .= "SITE\n";
    $t .= $L('Site', $base . '/');
    $t .= $L('Sign in', $base . '/login');
    $t .= $L('Admin', $base . '/admin');
    $t .= "\nADMIN ACCOUNT\n";
    $t .= $L('Organization', $bag['org']);
    $t .= $L('Email', $bag['email']);
    $t .= $L('Username', $bag['username'] !== '' ? $bag['username'] : '(email is the login)');
    $t .= $L('Password', $bag['password']);
    $t .= "\nDATABASE\n";
    $t .= $L('Host', $bag['db_host']);
    $t .= $L('Name', $bag['db_name']);
    $t .= $L('User', $bag['db_user']);
    $t .= $L('Password', $bag['db_pass']);
    $t .= "\nPATHS\n";
    $t .= $L('Application', $bag['app_dir']);
    $t .= $L('Config', $bag['app_dir'] . '/application/configs/local.ini');
    $t .= $L('Document root', $bag['docroot']);

    if (!empty($agent['ok'])) {
        $t .= "\nAGENT ACCESS (MCP)\n";
        $t .= $L('Endpoint', $base . '/mcp');
        $t .= $L('Access key', $agent['token']);
        $t .= $L('Scope', implode(', ', $agent['modules']) . ' — this organization only');
        $t .= $L('Manage/revoke', $base . '/mcp/admin');
        $t .= "\nThe access key is shown once and cannot be retrieved later. If it leaks, revoke it\n";
        $t .= "at /mcp/admin and mint a new one — the site itself is unaffected.\n";
    }

    $t .= "\nThe database password also lives in local.ini above your document root. The admin\n";
    $t .= "password is not stored anywhere in readable form — if you lose it, reset it by email.\n";
    return $t;
}

/* ---------------------------------------------------------------------------
 * The engine, from the wizard's side
 * ------------------------------------------------------------------------- */

/** The headless spec from the bag — pure, so a test can assert it exactly. */
function spec_from_bag(array $bag, $domain, $scheme) {
    return [
        'db'     => ['host' => $bag['db_host'] !== '' ? $bag['db_host'] : 'localhost', 'name' => $bag['db_name'], 'user' => $bag['db_user'], 'password' => $bag['db_pass']],
        'paths'  => ['app_root' => $bag['app_dir'], 'docroot' => $bag['docroot']],
        'layout' => 'above-docroot',
        'site'   => ['url' => $scheme . '://' . $domain, 'name' => $bag['org'] !== '' ? $bag['org'] : $domain],
        'admin'  => ['username' => $bag['username'], 'email' => $bag['email'], 'password' => $bag['password'], 'org' => $bag['org'] !== '' ? $bag['org'] : $domain],
        'agent'  => truthy($bag['agent']),
        'theme'   => (string) ($bag['theme'] ?? ''),
        'modules' => array_values((array) ($bag['modules'] ?? [])),
        'skills'  => array_values((array) ($bag['skills'] ?? [])),
    ];
}

/** Where each web request stops: four hops, so no single request downloads, migrates AND wires the site. */
function install_hops() { return ['extract', 'owner', 'skills', 'expose']; }

/** The next hop for this app root — the first whose step the ledger has not completed. */
function next_hop($appDir) {
    $state = new Tiger_Headless_State($appDir);
    foreach (install_hops() as $hop) { if (!$state->stepDone($hop)) { return $hop; } }
    return 'expose';
}

/** The progress list: every engine step with what the ledger (and this run's result) say about it. */
function progress_rows($appDir, ?array $result = null) {
    $state = new Tiger_Headless_State($appDir);
    $ran   = $result ? array_column($result['steps'] ?? [], null, 'step') : [];
    $label = ['requirements' => 'Requirements', 'fetch' => 'Download the release', 'extract' => 'Extract above the web root', 'configure' => 'Write local.ini + secrets',
              'migrate' => 'Build the schema', 'storage' => 'Storage folders', 'owner' => 'Organization + admin account', 'modules' => 'Modules', 'theme' => 'Theme',
              'skills' => 'Agent skills', 'assets' => 'Assets into the web root', 'agent' => 'AI agent credential', 'expose' => 'Front controller (go live)'];
    $rows = '';
    foreach (Tiger_Headless_Installer::STEPS as $s) {
        $st = $ran[$s]['status'] ?? ($state->stepDone($s) ? 'ok' : (($state->steps()[$s]['status'] ?? '') === 'failed' ? 'failed' : 'pending'));
        $detail = (string) ($ran[$s]['detail'] ?? ($state->steps()[$s]['detail'] ?? ''));
        $pill = ['ok' => '<span class="pill p-ok">DONE</span>', 'skipped' => '<span class="pill p-ok">DONE</span>', 'failed' => '<span class="pill p-bad">FAILED</span>'][$st] ?? '<span class="pill p-mut">PENDING</span>';
        $rows .= '<tr><td style="width:90px">' . $pill . '</td><td><strong>' . h($label[$s] ?? $s) . '</strong>'
               . ($detail !== '' && $st !== 'pending' ? '<br><span class="mut" style="font-size:.85em">' . h(mb_strimwidth($detail, 0, 160, '…')) . '</span>' : '') . '</td></tr>';
    }
    return $rows;
}

/* ---------------------------------------------------------------------------
 * Controller — a Back/Next wizard. Every typed field rides in a "bag" carried on every request
 * until the engine is handed its spec; from then on the job file above the docroot is the record and
 * each request advances the engine one hop. Every hop is idempotent (the ledger), so re-entering
 * never re-does harm.
 * ------------------------------------------------------------------------- */

$docroot = detect_docroot();
$home    = detect_home($docroot);
$domain  = detect_domain();
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base    = $scheme . '://' . $domain;
$step    = req('step', 'welcome');

// The value bag — read every field each request; fill sensible defaults once.
$bag = [];
foreach (['app_dir', 'docroot', 'db_host', 'db_name', 'db_user', 'db_pass', 'org', 'email', 'email2', 'username', 'password', 'password2', 'agent', 'choices_seen', 'theme'] as $f) {
    $bag[$f] = post($f, '');
}
$bag['modules'] = posta('modules'); $bag['packs'] = posta('packs');
if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $bag['theme'])) { $bag['theme'] = ''; }
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

// A job in flight (the engine has its spec) always lands on the install screen — a reload, a Back, or
// a fresh GET must never restart the wizard around a half-done ledger.
$job = job_load($home);
if ($job !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') { $step = 'install'; }

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
    $checks  = Tiger_Headless_Installer::hostRequirements($bag['app_dir'], $bag['docroot']);
    $blocked = false;
    $rows    = '';
    foreach ($checks as $c) {
        $pill = $c['ok'] ? '<span class="pill p-ok">PASS</span>'
             : ($c['required'] ? '<span class="pill p-bad">FAIL</span>' : '<span class="pill p-warn">WARN</span>');
        if (!$c['ok'] && $c['required']) { $blocked = true; }
        $rows .= '<tr><td>' . $pill . '</td><td><strong>' . h($c['label']) . '</strong><br><span class="mut">'
              . h($c['detail']) . '</span>' . (!$c['ok'] ? '<br><span class="mut" style="font-size:.85em">&rarr; ' . h($c['fix']) . '</span>' : '')
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
        'installer' => INSTALLER_VERSION, 'engine' => ENGINE_VERSION,
        'step'      => 'requirements',
        'status'    => $blocked ? 'blocked' : 'awaiting-input',
        'next_step' => $blocked ? null : 'location',
        'checks'    => array_map(static fn($c) => ['label' => $c['label'], 'ok' => (bool) $c['ok'],
                                                   'required' => (bool) $c['required'], 'fix' => $c['ok'] ? null : $c['fix']], $checks),
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
        . '</div>' . nav_buttons('welcome', 'details', 'Continue') . '</form>';
    page('Location', $body, ['installer' => INSTALLER_VERSION, 'step' => 'location', 'status' => 'awaiting-input',
        'next_step' => 'details', 'fields' => ['app_dir', 'docroot'], 'app_dir' => $bag['app_dir'], 'docroot' => $bag['docroot']]);
    break;

/* --- Details — the database + the admin account, one form -------------- */
case 'details':
    $catalog = catalog_load($home); $dir = directory_load($home);
    page('Database & admin', steps_nav('details') . admin_form($bag, '', $catalog, $dir),
        ['installer' => INSTALLER_VERSION, 'step' => 'details', 'status' => 'awaiting-input', 'next_step' => 'install',
         'fields' => ['db_host', 'db_name', 'db_user', 'db_pass', 'theme', 'modules[]', 'packs[]', 'org', 'email', 'email2', 'username', 'password', 'password2', 'agent'],
         'agent_requested' => $agentWanted,
         'choices' => ['themes' => array_column($dir['themes'], 'slug'), 'modules' => array_column($dir['modules'], 'slug'), 'packs' => array_column($catalog['packs'], 'id'),
                       'preselected' => ['theme' => $catalog['featured']['theme'], 'modules' => $catalog['featured']['modules'], 'packs' => array_column(array_filter($catalog['packs'], static fn($p) => $p['default']), 'id')]],
         // For a client that drove the browser here: stop, hand over, ask for the file afterwards.
         'handoff' => 'Stop here. The person fills in the database and their admin account and clicks Install Tiger; '
                    . 'when it finishes they download the credentials file — ask them for it. It carries the site URL, '
                    . 'the admin login and, if they ticked the agent box, the /mcp access key.']);
    break;

/* --- Install — hand the engine its spec, then one hop per request ------- */
case 'install':
    $detailsFields = ['db_host', 'db_name', 'db_user', 'db_pass', 'theme', 'modules[]', 'packs[]', 'org', 'email', 'email2', 'username', 'password', 'password2', 'agent'];
    $catalog = $job === null ? catalog_load($home) : null; $dir = $job === null ? directory_load($home) : null;
    if ($job === null) { $bag['skills'] = skills_for($catalog, $bag['packs']); }
    $errState = static fn($error, $detail) => ['installer' => INSTALLER_VERSION, 'step' => 'details', 'status' => 'error', 'error' => $error,
        'detail' => $detail, 'fields' => $detailsFields, 'agent_requested' => truthy($bag['agent'])];

    if ($job === null) {
        // First entry: the details form was just submitted. Validate locally, then let the engine
        // validate the whole spec and prove the host + database — before anything is written.
        if (post('email') === '' && post('db_name') === '') {   // a stray GET/POST with no job and no form → start over
            header('Location: ?', true, 302); exit;
        }
        $err = admin_errors($bag);
        if ($err === '' && ($bag['db_name'] === '' || $bag['db_user'] === '')) { $err = 'Enter the database name and user you created in cPanel.'; }
        if ($err !== '') { page('Database & admin', steps_nav('details') . admin_form($bag, $err, $catalog, $dir), $errState('admin_fields_invalid', $err)); break; }

        $spec = spec_from_bag($bag, $domain, $scheme);
        // A bundle uploaded by hand on an earlier attempt (see the download-failure path) is reused.
        if (is_file(job_dir($home) . '/tiger.zip')) { $spec['source'] = ['bundle' => job_dir($home) . '/tiger.zip']; }
        try {
            $validated = new Tiger_Headless_Spec($spec);
        } catch (Tiger_Headless_SpecException $e) {
            $err = implode(' ', $e->problems());
            page('Database & admin', steps_nav('details') . admin_form($bag, $err, $catalog, $dir), $errState('spec_invalid', $err)); break;
        }
        $check = (new Tiger_Headless_Installer($validated))->check()->toArray();
        if (empty($check['ok'])) {
            $err = (string) ($check['error']['message'] ?? 'requirements failed');
            page('Database & admin', steps_nav('details') . admin_form($bag, $err, $catalog, $dir), $errState('requirements_failed', $err)); break;
        }
        $job = ['spec' => $spec, 'bag' => array_diff_key($bag, ['db_pass' => 1, 'password' => 1, 'password2' => 1]) + ['db_pass' => $bag['db_pass'], 'password' => $bag['password']], 'started' => gmdate('c')];
        if (!job_save($home, $job)) {
            $err = 'Could not write the install record under ' . h(job_dir($home)) . ' — is the home folder writable?';
            page('Database & admin', steps_nav('details') . admin_form($bag, $err, $catalog, $dir), $errState('job_write_failed', $err)); break;
        }
    } elseif (!empty($_FILES['bundle']['tmp_name']) && is_uploaded_file($_FILES['bundle']['tmp_name'])) {
        // Manual upload after a download failure: becomes source.bundle (unverified — the engine says so).
        @mkdir(job_dir($home), 0700, true);
        if (@move_uploaded_file($_FILES['bundle']['tmp_name'], job_dir($home) . '/tiger.zip')) {
            $job['spec']['source'] = ['bundle' => job_dir($home) . '/tiger.zip'];
            job_save($home, $job);
        }
    }

    $spec   = $job['spec'];
    $appDir = $spec['paths']['app_root'];
    $hop    = next_hop($appDir);
    // Only a POST runs a hop. A GET (a reload, a Back, the first visit after a lost tab) shows where
    // the ledger stands and lets the page — or the button — post the next hop; a reload never executes.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = (new Tiger_Headless_Installer(new Tiger_Headless_Spec($spec)))->run($hop)->toArray();
    } else {
        $result = ['ok' => true, 'steps' => [], 'complete' => (new Tiger_Headless_State($appDir))->installed(), 'version' => (new Tiger_Headless_State($appDir))->version()];
    }
    $done   = !empty($result['ok']) && (!empty($result['complete']) || !empty($result['already_installed']));

    if ($done) {
        /* --- Finish — the site is live: show what to keep, clean up, self-delete ------------- */
        $jb    = $job['bag'] + ['docroot' => $spec['paths']['docroot'], 'app_dir' => $appDir];
        $agent = !empty($result['agent']['token']) ? ['ok' => true, 'token' => $result['agent']['token'], 'modules' => (array) ($result['agent']['modules'] ?? [])] : ['ok' => false, 'error' => ''];
        $agentWanted = !empty($spec['agent']);
        job_clear($home);
        @unlink(job_dir($home) . '/tiger.zip'); @rmdir(job_dir($home));
        $deleted = @unlink(__FILE__);
        $body = '<h1>&#127881; Tiger is installed</h1>'
            . '<div class="note ok">Your site is live. The app + your secrets are safely above the web root at <code>' . h($appDir) . '</code>.</div>'
            . '<div class="card"><table>'
            . '<tr><td class="mut">Your site</td><td><a style="color:var(--brand)" target="_blank" rel="noopener" href="' . h($base) . '/">' . h($base) . '/</a></td></tr>'
            . '<tr><td class="mut">Sign in</td><td><a style="color:var(--brand)" target="_blank" rel="noopener" href="' . h($base) . '/login">' . h($base) . '/login</a></td></tr>'
            . '<tr><td class="mut">Admin</td><td><a style="color:var(--brand)" target="_blank" rel="noopener" href="' . h($base) . '/admin">' . h($base) . '/admin</a></td></tr>'
            . '<tr><td class="mut">Tiger</td><td>' . h((string) ($result['version'] ?? '')) . '</td></tr>'
            . '</table></div>'
            . ($deleted
                ? '<div class="note ok">This installer has deleted itself. Nothing else to clean up.</div>'
                : '<div class="note bad"><strong>Delete this file now.</strong> The installer couldn&rsquo;t remove itself — delete <code>' . h(__FILE__) . '</code> via File Manager/FTP immediately.</div>');

        // --- The agent credential, shown once (TIGER-90) ---------------------------------------
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
        } elseif ($agentWanted && (new Tiger_Headless_State($appDir))->stepDone('agent') && empty($result['steps'])) {
            // Minted on an earlier request whose page was lost. It was shown once; it cannot be shown again.
            $body .= '<div class="note"><strong>The agent access key was shown on the earlier screen</strong> and cannot be displayed again. '
                . 'If you did not save it, mint a new one at <code>' . h($base) . '/mcp/admin</code>.</div>';
        } elseif ($agentWanted) {
            // Asked for, but the mint did not happen. The install is fine; only the convenience was lost.
            $body .= '<div class="note bad"><strong>Agent access was not enabled.</strong> '
                . h((string) (array_column($result['steps'] ?? [], 'detail', 'step')['agent'] ?? 'The access key could not be created.'))
                . ' Your site is installed and working. Turn it on any time at <code>' . h($base) . '/mcp/admin</code>.</div>';
        } else {
            // The majority path for a hand install — a clear next step, not silence.
            $body .= '<div class="note"><strong>Using an AI assistant?</strong> The <code>/mcp</code> endpoint is off. '
                . 'Turn it on and mint a scoped key at <code>' . h($base) . '/mcp/admin</code>, then reconnect your assistant.</div>';
        }

        // One file with everything, generated in-page so no secret is ever written to the server.
        $credText = credentials_file($jb, $base, $agent);
        $credName = 'tiger-credentials-' . preg_replace('/[^a-z0-9.-]/i', '-', $domain) . '-' . gmdate('Ymd') . '.txt';
        $body .= '<div class="card"><h2>&#128190; Save your credentials</h2>'
            . '<p class="mut">One file with your admin login, database details, paths'
            . ($agentWanted && !empty($agent['ok']) ? ' and the agent access key' : '')
            . '. <strong>This installer deletes itself</strong>, so this is the only time these appear together.'
            . ($agentWanted && !empty($agent['ok']) ? ' <strong>Hand this file to your assistant</strong> &mdash; it is everything it needs to manage the site.' : '') . '</p>'
            . '<a class="btn" download="' . h($credName) . '" href="data:text/plain;charset=utf-8;base64,'
            . base64_encode($credText) . '">&#11015; Download credentials</a>'
            . '<div class="note">It contains passwords. Put it in a password manager, not your Downloads folder.</div>'
            . '</div>';

        page('Done', $body, [
            'installer'    => INSTALLER_VERSION, 'engine' => ENGINE_VERSION,
            'step'         => 'finish',
            'status'       => 'ok',
            'site'         => $base . '/',
            'login'        => $base . '/login',
            'admin'        => $base . '/admin',
            'version'      => $result['version'] ?? null,
            'app_dir'      => $appDir,
            'self_deleted' => (bool) $deleted,
            'agent'        => $agentWanted && !empty($agent['ok'])
                ? ['enabled' => true, 'endpoint' => $base . '/mcp', 'token' => $agent['token'], 'manage' => $base . '/mcp/admin',
                   'scope' => ['modules' => $agent['modules'], 'org_scoped' => true, 'read_only' => false]]
                : ['enabled' => false, 'manage' => $base . '/mcp/admin', 'reason' => $agentWanted ? 'mint_failed' : 'not_requested'],
        ]);
        break;
    }

    /* --- Progress: a hop done, or a hop failed ---------------------------------------------- */
    $failed = empty($result['ok']);
    $errMsg = $failed ? (string) ($result['error']['message'] ?? 'unknown error') : '';
    $errAt  = $failed ? (string) ($result['error']['step'] ?? '') : '';
    $body = steps_nav('install') . '<h1>' . ($failed ? 'The install stopped' : 'Installing Tiger&hellip;') . '</h1>';
    if ($failed) {
        $body .= '<div class="note bad"><strong>Stopped at &ldquo;' . h($errAt) . '&rdquo;:</strong> ' . h($errMsg) . '</div>'
               . '<p class="mut">Fix what it names, then continue — the install resumes from that step; everything already done is kept. Nothing is web-reachable until every step passes.</p>';
    }
    $body .= '<div class="card"><table>' . progress_rows($appDir, $result) . '</table></div>';
    $body .= '<form method="post" id="tiger-go">' . hidden_bag([], []) . '<input type="hidden" name="step" value="install">'
           . '<button type="submit" class="btn">' . ($failed ? 'Retry from &ldquo;' . h($errAt) . '&rdquo;' : 'Continue') . ' &rarr;</button></form>';
    if ($failed && $errAt === 'fetch') {
        $body .= '<div class="card"><h2>Or upload the release by hand</h2>'
            . '<p class="mut">Download <code>tiger-&lt;version&gt;.zip</code> from the '
            . '<a style="color:var(--brand)" href="https://github.com/' . RELEASE_REPO . '/releases" target="_blank" rel="noopener">releases page</a> '
            . 'on your computer, then upload it here. A hand-uploaded bundle is not checksum-verified; the engine records that.</p>'
            . '<form method="post" enctype="multipart/form-data">' . hidden_bag([], []) . '<input type="hidden" name="step" value="install">'
            . '<input type="file" name="bundle" accept=".zip" style="margin-bottom:10px"> '
            . '<button type="submit" class="btn sec">Upload &amp; continue &rarr;</button></form></div>';
    }
    if (!$failed) {
        // Keep going without a click; the button is the no-JS path and the "it is stuck" path.
        $body .= '<script>setTimeout(function(){var f=document.getElementById("tiger-go");if(f){f.submit();}},400);</script>';
    }
    page($failed ? 'Install stopped' : 'Installing', $body, [
        'installer' => INSTALLER_VERSION, 'engine' => ENGINE_VERSION,
        'step'      => 'install',
        'status'    => $failed ? 'error' : 'running',
        'next_step' => 'install',
        'error'     => $failed ? 'step_failed' : null,
        'detail'    => $failed ? $errMsg : null,
        'failed_at' => $failed ? $errAt : null,
        'hop'       => $hop,
        'engine_next_step' => $result['next_step'] ?? null,
        'steps'     => array_map(static fn($s) => ['step' => $s['step'], 'status' => $s['status'], 'detail' => $s['detail'] ?? ''], $result['steps'] ?? []),
        'manual_upload' => $failed && $errAt === 'fetch',
    ]);
    break;
}
