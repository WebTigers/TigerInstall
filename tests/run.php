<?php
/**
 * Run every test file. Used by CI and by a contributor: `php tests/run.php`
 */
$files = ['invariants.php', 'wizard.php', 'smoke.php'];
$fail  = 0;
foreach ($files as $f) {
    echo "\n=== $f " . str_repeat('=', max(0, 60 - strlen($f))) . "\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $f), $rc);
    if ($rc !== 0) { $fail++; }
}
echo "\n" . ($fail ? "$fail test file(s) FAILED\n" : "All test files passed.\n");
exit($fail ? 1 : 0);
