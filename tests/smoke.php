<?php
/**
 * End-to-end: serve the installer and drive its first screen the way a client would.
 *
 * Unit tests call functions; this proves the file actually RUNS and that a client can read where it
 * is from the response. It stops at the requirements step on purpose — going further needs a real
 * database and a release download, which belong in a manual test against a real host.
 */
require __DIR__ . '/bootstrap.php';

$root = dirname(__DIR__);
$port = getenv('SMOKE_PORT') ?: '8911';
$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$srv  = proc_open(PHP_BINARY . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root), $desc, $pipes);
if (!is_resource($srv)) { fwrite(STDERR, "could not start php -S\n"); exit(2); }

// wait for the socket rather than sleeping a guess
$html = false;
for ($i = 0; $i < 50; $i++) {
    usleep(100000);
    $html = @file_get_contents("http://127.0.0.1:$port/tiger-install.php");
    if ($html !== false) { break; }
}

group('The installer serves and reports its state');
is_true('it responds at all', $html !== false);

if ($html !== false) {
    is_true('no PHP error leaked into the page',
        !preg_match('/(Fatal error|Parse error|Warning:|Notice:|Deprecated:)/', $html));

    $found = preg_match('#<script type="application/json" id="tiger-install-state">(.*?)</script>#s', $html, $m);
    is_true('the state block is present', (bool) $found);

    if ($found) {
        // \u003C is a valid JSON escape — json_decode unescapes it for us.
        $state = json_decode($m[1], true);
        is_true ('the state parses as JSON',        is_array($state));
        is_same ('it reports the requirements step', $state['step'] ?? null, 'requirements');
        is_true ('status is a known value',          in_array($state['status'] ?? null, ['awaiting-input', 'blocked'], true));
        is_true ('it lists the preflight checks',    !empty($state['checks']));
        is_true ('every check declares ok+required',
            count(array_filter($state['checks'], static fn($c) => isset($c['ok'], $c['required']))) === count($state['checks']));
        is_true ('the installer version is reported', !empty($state['installer']));

        // A failing REQUIRED check must be the thing that sets status=blocked — that is the signal a
        // client acts on, and it must agree with the checks it ships alongside.
        $hardFail = (bool) array_filter($state['checks'], static fn($c) => !$c['ok'] && $c['required']);
        is_same('status agrees with the checks', $state['status'], $hardFail ? 'blocked' : 'awaiting-input');
    }

    is_true('the human page rendered too', strpos($html, 'Tiger Installer') !== false);
}

foreach ($pipes as $p) { @fclose($p); }
proc_terminate($srv);
proc_close($srv);
done();
