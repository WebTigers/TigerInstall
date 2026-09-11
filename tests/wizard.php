<?php
/**
 * The wizard's behaviour: the machine-readable state block (TIGER-89) and the agent checkbox that
 * gates credential minting (TIGER-90).
 */
require __DIR__ . '/bootstrap.php';
load_installer_functions();

group('truthy() — checkbox and query-param flags');
is_true ('"1" is on',        truthy('1'));
is_true ('"on" is on',       truthy('on'));
is_true ('"TRUE" is on',     truthy('TRUE'));
is_true ('" yes " is on',    truthy(' yes '));
is_false('"0" is off',       truthy('0'));
is_false('"" is off',        truthy(''));
is_false('"maybe" is off',   truthy('maybe'));
is_false('"false" is off',   truthy('false'));

group('The agent checkbox is visible and reversible (TIGER-90)');
$base   = ['org' => '', 'email' => '', 'username' => '', 'password' => ''];
$bagOff = $base + ['agent' => ''];
$bagOn  = $base + ['agent' => '1'];
$off = admin_form($bagOff);
$on  = admin_form($bagOn);

is_true ('the checkbox is rendered',         (bool) preg_match('/name="agent" value="1"/', $off));
is_false('it is UNticked by default',        (bool) preg_match('/name="agent" value="1"[^>]*checked/', $off));
is_true ('it is ticked when seeded',         (bool) preg_match('/name="agent" value="1"[^>]*checked/', $on));
// If it also rode in the hidden bag, un-ticking the visible box could not turn it off.
is_false('it is NOT also a hidden input',    (bool) preg_match('/type="hidden" name="agent"/', $on));
is_true ('it names where to revoke',         strpos($on, '/mcp/admin') !== false);
is_true ('it says what it does in plain words', stripos($on, 'assistant') !== false);

group('Seeding is GET-only, so a crafted link cannot force it');
// Exercises the REAL controller lines, lifted from the shipped file at run time.
$seed = installer_region(
    '// `agent` may be SEEDED from the query string',
    "\$agentWanted = truthy(\$bag['agent']);"
);
$seedFile = tempnam(sys_get_temp_dir(), 'seed') . '.php';
file_put_contents($seedFile, "<?php\n\$bag['agent'] = post('agent','');\n" . $seed . "\n");

$scenario = function ($method, array $get, array $post) use ($seedFile) {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_GET = $get; $_POST = $post; $_REQUEST = array_merge($get, $post);
    $bag = [];
    include $seedFile;
    return $agentWanted;
};

is_false('GET, no param',                    $scenario('GET',  [],             []));
is_true ('GET ?agent=1 seeds the box',       $scenario('GET',  ['agent' => '1'], []));
is_false('GET ?agent=0',                     $scenario('GET',  ['agent' => '0'], []));
is_true ('POST with the box ticked',         $scenario('POST', [],             ['agent' => '1']));
is_false('POST with the box unticked',       $scenario('POST', [],             []));
// THE one that matters: a shared ?agent=1 link must not survive the user un-ticking the box.
is_false('POST ?agent=1 but box UNTICKED',   $scenario('POST', ['agent' => '1'], []));
is_true ('POST ?agent=1 and box ticked',     $scenario('POST', ['agent' => '1'], ['agent' => '1']));
@unlink($seedFile);

group('The machine-readable state block (TIGER-89)');
$b = state_block(['step' => 'finish', 'status' => 'ok', 'agent' => ['token' => 'tgr_x']]);
is_true ('it is emitted',                    strpos($b, 'id="tiger-install-state"') !== false);
is_true ('it declares application/json',     strpos($b, 'type="application/json"') !== false);
is_same ('the payload parses',               json_decode(strip_tags($b), true)['status'] ?? null, 'ok');
is_same ('nested structure survives',        json_decode(strip_tags($b), true)['agent']['token'] ?? null, 'tgr_x');
// A '<' inside the payload must never close the script element early. Isolate the payload — what
// sits between the opening tag and the final '</script>' — and assert it carries no '<' at all.
$evil    = state_block(['x' => '</script><img src=x onerror=alert(1)>']);
$open    = strpos($evil, '>') + 1;
$payload = substr($evil, $open, strrpos($evil, '</script>') - $open);
is_same ('the payload contains no raw "<"',  substr_count($payload, '<'), 0);
is_true ('the "<" was escaped, not dropped', strpos($payload, '\u003C') !== false);
is_same ('the escaped payload still parses', json_decode(str_replace('\u003C', '<', $payload), true)['x'] ?? null,
                                             '</script><img src=x onerror=alert(1)>');
is_same ('an empty state emits nothing',     state_block([]), '');

group('Confirm email / password (typo protection)');
$ok = ['org'=>'A','email'=>'me@example.com','email2'=>'me@example.com','username'=>'',
       'password'=>'correct-horse','password2'=>'correct-horse','agent'=>''];
is_same ('a matching pair passes',        admin_errors($ok), '');
is_same ('missing email is caught',       admin_errors(['email'=>'']+$ok), 'Enter an admin email address.');
is_true ('mismatched email is caught',    admin_errors(['email2'=>'you@example.com']+$ok) !== '');
// cPanel users retype with different capitalisation constantly; that is not a typo.
is_same ('email match is case-insensitive', admin_errors(['email2'=>'ME@Example.com']+$ok), '');
is_same ('missing password is caught',    admin_errors(['password'=>'']+$ok), 'Choose a password.');
is_true ('mismatched password is caught', admin_errors(['password2'=>'correct-horse ']+$ok) !== '');
is_true ('password match is EXACT',       admin_errors(['password2'=>'Correct-Horse']+$ok) !== '');

group('The admin form asks for both confirmations');
$af = admin_form($ok);
is_true ('confirm email field',    (bool) preg_match('/name="email2"/', $af));
is_true ('confirm password field', (bool) preg_match('/name="password2"/', $af));
is_false('neither rides in the hidden bag',
    (bool) preg_match('/type="hidden" name="(email2|password2)"/', $af));

group('The credentials backup file');
$bag = $ok + ['db_host'=>'localhost','db_name'=>'cp_tiger','db_user'=>'cp_tiger','db_pass'=>'dbsecret',
              'app_dir'=>'/home/u/site/tiger-app','docroot'=>'/home/u/public_html'];
$plain = credentials_file($bag, 'https://example.com', ['ok'=>false]);
foreach ([['the admin email','me@example.com'], ['the admin password','correct-horse'],
          ['the db name','cp_tiger'], ['the db password','dbsecret'],
          ['the app dir','/home/u/site/tiger-app'], ['the config path','local.ini'],
          ['a keep-it-safe warning','CONTAINS PASSWORDS']] as $c) {
    is_true('carries ' . $c[0], strpos($plain, $c[1]) !== false);
}
is_false('no agent section when it was not enabled', strpos($plain, 'AGENT ACCESS') !== false);

$withAgent = credentials_file($bag, 'https://example.com',
    ['ok'=>true, 'token'=>'tgr_abc', 'modules'=>['cms','media']]);
is_true ('agent section when enabled',    strpos($withAgent, 'AGENT ACCESS') !== false);
is_true ('carries the access key',        strpos($withAgent, 'tgr_abc') !== false);
is_true ('says it cannot be retrieved later', stripos($withAgent, 'shown once') !== false);
is_true ('says where to revoke',          strpos($withAgent, '/mcp/admin') !== false);


done();
