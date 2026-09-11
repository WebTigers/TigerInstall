<?php
/**
 * Load the installer's FUNCTIONS without running its controller.
 *
 * tiger-install.php is deliberately one file: functions at the top, a Back/Next controller at the
 * bottom that runs on include. To unit-test the functions we cut the file at that boundary and eval
 * only the first half. The cut is taken from the shipped file every run, so a test can never drift
 * away from the code it claims to cover.
 */

const INSTALLER_FILE = __DIR__ . '/../tiger-install.php';
const CONTROLLER_MARK = "/* ---------------------------------------------------------------------------\n * Controller";

function installer_source() {
    $src = file_get_contents(INSTALLER_FILE);
    if ($src === false) { fwrite(STDERR, "cannot read " . INSTALLER_FILE . "\n"); exit(2); }
    return $src;
}

function load_installer_functions() {
    $src = installer_source();
    $i = strpos($src, CONTROLLER_MARK);
    if ($i === false) {
        fwrite(STDERR, "FATAL: controller boundary marker not found — tests/bootstrap.php needs updating\n");
        exit(2);
    }
    $head = substr($src, 0, $i);
    $tmp  = tempnam(sys_get_temp_dir(), 'tinst') . '.php';
    file_put_contents($tmp, $head);
    require $tmp;
    @unlink($tmp);
}

/** Extract a named region of the controller so a test exercises the REAL lines, not a copy. */
function installer_region($startNeedle, $endNeedle) {
    $src = installer_source();
    $a = strpos($src, $startNeedle);
    if ($a === false) { fwrite(STDERR, "FATAL: region start not found: $startNeedle\n"); exit(2); }
    $b = strpos($src, $endNeedle, $a);
    if ($b === false) { fwrite(STDERR, "FATAL: region end not found: $endNeedle\n"); exit(2); }
    return substr($src, $a, $b - $a + strlen($endNeedle));
}

/* --- the world's smallest test harness ------------------------------------ */
$GLOBALS['_ok'] = 0; $GLOBALS['_fail'] = 0; $GLOBALS['_group'] = '';

function group($name) { $GLOBALS['_group'] = $name; echo "\n  $name\n"; }

function is_same($label, $got, $want) {
    if ($got === $want) { $GLOBALS['_ok']++; printf("    ok    %s\n", $label); return true; }
    $GLOBALS['_fail']++;
    printf("    FAIL  %s\n          got  %s\n          want %s\n", $label,
        var_export($got, true), var_export($want, true));
    return false;
}

function is_true($label, $got)  { return is_same($label, (bool) $got, true); }
function is_false($label, $got) { return is_same($label, (bool) $got, false); }

function done() {
    printf("\n  %d passed, %d failed\n", $GLOBALS['_ok'], $GLOBALS['_fail']);
    exit($GLOBALS['_fail'] ? 1 : 0);
}
