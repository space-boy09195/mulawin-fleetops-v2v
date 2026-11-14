<?php
/*
 * =====================================================================
 *  tests/account_security_tests.php  —  SECURITY_CURRENT_PASSWORD_LOCK_V1
 * =====================================================================
 *  Do NOT run this file directly: run tests/run_account_security_tests.php (temporary copy, own .env,
 *  disposable *_test database, local SMTP receiver only).
 *
 *    A. Password change: wrong current password counts toward the login lock (5 = locked 15 minutes)
 *    B. Email change: same lock
 *    C. Shared counter with login; correct password resets it; atomic count (stale rows)
 *    D. A locked account cannot pass the check or finish a change with a correct code
 *    E. Email change: notice to the PREVIOUS address (no code, no full new address); mail problem keeps the change
 *    F. Normal flows still work
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/backend/Actions.php';
require_once __DIR__ . '/_launcher.php';
core('AccountSecurity');
core('SecurityPasswordChange');

$FRESH = envValue('DB_NAME');
test_guard([$FRESH]);

$PASS = 0; $FAIL = 0; $FAILED = [];
function ok(bool $c, string $name, string $detail = ''): void {
    global $PASS, $FAIL, $FAILED;
    if ($c) { $PASS++; echo "  PASS  $name\n"; } else { $FAIL++; $FAILED[] = $name; echo "  FAIL  $name" . ($detail !== '' ? "  -> $detail" : '') . "\n"; }
}
function fails(callable $fn, string $name, ?int $code = null, string $contains = ''): void {
    try { $fn(); ok(false, $name, 'no error was raised'); }
    catch (BusinessRuleException $e) { ok(($code === null || $e->httpStatus === $code) && ($contains === '' || stripos($e->getMessage(), $contains) !== false), $name, "got {$e->httpStatus}: {$e->getMessage()}"); }
    catch (Throwable $e) { ok(false, $name, get_class($e) . ': ' . $e->getMessage()); }
}
function section(string $t): void { echo "\n== $t ==\n"; }

// Local SMTP receiver (tests/_smtp_sink.php): every email is saved as a file in $mailDir.
$mailDir = sys_get_temp_dir() . '/mulawin_mail_' . bin2hex(random_bytes(4)); mkdir($mailDir);
$sinkStart = function () use ($mailDir) {
    @unlink($mailDir . '/ready');
    $proc = proc_open([PHP_BINARY, __DIR__ . '/_smtp_sink.php', envValue('TEST_SMTP_PORT'), $mailDir], [1 => ['file', (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), 'w'], 2 => ['file', (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), 'w']], $pipes);
    for ($i = 0; $i < 50 && !is_file($mailDir . '/ready'); $i++) usleep(100000);
    return $proc;
};
$sink = $sinkStart();
$mails = function () use ($mailDir): array {
    $out = [];
    foreach (glob($mailDir . '/mail_*.eml') ?: [] as $f) {
        $raw = (string)file_get_contents($f);
        // Decode the body (PHPMailer may use quoted-printable) so the text can be checked.
        $out[] = ['to' => substr(strtok($raw, "\r\n"), 8), 'raw' => $raw, 'text' => quoted_printable_decode($raw)];
    }
    return $out;
};
$lastCode = function () use ($mails): ?string {
    $m = $mails(); $last = end($m);
    return $last && preg_match('/\b(\d{6})\b/', $last['text'], $x) ? $x[1] : null;
};

section('Setup: disposable fresh database');
test_recreate($FRESH);
$p = db();
ok($p->query('SELECT DATABASE()')->fetchColumn() === $FRESH, 'db() is the disposable test database');
test_import($p, test_sql('database/schema.sql'));
test_import($p, test_sql('database/extension.sql'));
const PW = 'Alice-Pass-2026';
$mkUser = function (string $name) use ($p): array {
    $id = insert($p, 'users', ['username' => $name, 'email' => "$name@example.test", 'password_hash' => password_hash(PW, PASSWORD_DEFAULT), 'full_name' => strtoupper($name) . ' USER', 'role' => 'finance']);
    return ['id' => $id, 'username' => $name, 'email' => "$name@example.test", 'full_name' => strtoupper($name) . ' USER', 'role' => 'finance', 'shift' => null];
};
$state = fn(array $u) => one($p, 'SELECT failed_logins, locked_until, password_hash, email FROM users WHERE id=?', [$u['id']]);
$locked = fn(array $u) => AccountSecurity::isLocked($state($u));
$unlock = fn(array $u) => run($p, 'UPDATE users SET failed_logins=0, locked_until=NULL WHERE id=?', [$u['id']]);
$audits = fn(array $u, string $action) => (int)one($p, 'SELECT COUNT(*) AS n FROM audit_events WHERE record_ref=? AND action=?', ['user ' . $u['id'], $action])['n'];
$pwReq = fn(array $u, string $cur) => SecurityPasswordChange::request($u, ['current_password' => $cur, 'new_password' => 'Changed-Pass-2026', 'confirm' => 'Changed-Pass-2026']);
$emReq = fn(array $u, string $cur, string $new) => SecurityPasswordChange::emailRequest($u, ['current_password' => $cur, 'new_email' => $new]);
$fresh = fn() => run($p, 'DELETE FROM password_change_challenges'); // allows a new request (60-second wait)
$_SESSION = [];

// ---------------------------------------------------------------------
section('A. Password change: wrong current password counts toward the account lock');
$a = $mkUser('alice');
for ($i = 1; $i <= 4; $i++) fails(fn() => $pwReq($a, "wrong-$i"), "wrong current password #$i refused (403)", 403, 'incorrect');
ok((int)$state($a)['failed_logins'] === 4 && !$locked($a), 'four wrong tries counted (4), not locked yet');
ok($audits($a, 'wrong current password') === 4, 'each wrong try is audited');
$before = count($mails());
fails(fn() => $pwReq($a, 'wrong-5'), 'fifth wrong try locks the account (429)', 429, 'locked for 15 minutes');
ok($locked($a) && $audits($a, 'account locked') === 1, 'account locked for 15 minutes and audited');
fails(fn() => $pwReq($a, PW), 'CORRECT current password refused while locked (429)', 429, 'locked');
ok(count($mails()) === $before && !one($p, 'SELECT user_id FROM password_change_challenges WHERE user_id=?', [$a['id']]), 'no code emailed and no change started while locked');
$unlock($a);
$r = $pwReq($a, PW);
ok(str_contains($r['message'], 'verification code was sent') && $lastCode() !== null, 'after the lock ends, the correct password works (code emailed)');
ok((int)$state($a)['failed_logins'] === 0, 'correct password resets the wrong-try count');

// ---------------------------------------------------------------------
section('B. Email change: same lock');
$b = $mkUser('bob');
for ($i = 1; $i <= 4; $i++) fails(fn() => $emReq($b, "nope-$i", 'bob.new@example.test'), "email change: wrong current password #$i refused (403)", 403, 'incorrect');
fails(fn() => $emReq($b, 'nope-5', 'bob.new@example.test'), 'email change: fifth wrong try locks the account (429)', 429, 'locked');
fails(fn() => $emReq($b, PW, 'bob.new@example.test'), 'email change: CORRECT password refused while locked (429)', 429);
ok(!one($p, 'SELECT user_id FROM email_change_challenges WHERE user_id=?', [$b['id']]) && $state($b)['email'] === 'bob@example.test', 'no email change started; email unchanged');
$unlock($b);

// ---------------------------------------------------------------------
section('C. Shared counter with login; atomic count');
$c = $mkUser('carol');
run($p, 'UPDATE users SET failed_logins=3 WHERE id=?', [$c['id']]);          // 3 wrong logins before
fails(fn() => $pwReq($c, 'x1'), 'login failures + current-password failures share one counter (4th)', 403);
fails(fn() => $emReq($c, 'x2', 'carol2@example.test'), '5th wrong try (email change) locks the account', 429, 'locked');
$unlock($c);
run($p, 'UPDATE users SET failed_logins=3 WHERE id=?', [$c['id']]);
$fresh(); $pwReq($c, PW);
ok((int)$state($c)['failed_logins'] === 0, 'a correct current password resets earlier wrong logins (same as a successful login)');
// Several requests at the same moment each hold the row as it was BEFORE the others counted (stale row).
$d = $mkUser('dave');
$stale = one($p, 'SELECT * FROM users WHERE id=?', [$d['id']]);
$res = []; for ($i = 0; $i < 5; $i++) $res[] = AccountSecurity::failure($stale);
ok($locked($d) && $res === [false, false, false, false, true], 'count is atomic: 5 failures with the same stale row still lock the account', json_encode([$res, $state($d)]));
$unlock($d);
// Two processes guessing at the same time.
$child = __DIR__ . '/_guess_child.php';
file_put_contents($child, '<?php
declare(strict_types=1);
require dirname(__DIR__) . "/backend/Actions.php";
core("SecurityPasswordChange");
$u = json_decode($argv[1], true); $out = [];
for ($i = 0; $i < 5; $i++) { try { SecurityPasswordChange::request($u, ["current_password" => "g$i", "new_password" => "Changed-Pass-2026", "confirm" => "Changed-Pass-2026"]); $out[] = 200; } catch (BusinessRuleException $e) { $out[] = $e->httpStatus; } }
echo json_encode($out);
');
$procs = []; $pipes = [];
for ($k = 0; $k < 2; $k++) { $procs[$k] = proc_open([PHP_BINARY, $child, json_encode($d)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp); $pipes[$k] = $pp; }
$outs = []; foreach ($procs as $k => $pr) { $outs[$k] = json_decode((string)stream_get_contents($pipes[$k][1]), true) ?: (string)stream_get_contents($pipes[$k][2]); proc_close($pr); }
@unlink($child);
$all = array_merge(...array_map(fn($o) => is_array($o) ? $o : [], $outs));
ok(count($all) === 10 && $locked($d) && in_array(429, $all, true) && !in_array(200, $all, true), 'two processes guessing in parallel (10 tries): account locked, the rest refused with 429', json_encode($outs));
ok(count(array_filter($all, fn($s) => $s === 403)) <= 4, 'at most 4 guesses were actually checked before the lock', json_encode($all));
$unlock($d);

// ---------------------------------------------------------------------
section('D. A locked account cannot finish a change, even with the right code');
$e = $mkUser('erin');
$pwReq($e, PW); $code = $lastCode();
run($p, 'UPDATE users SET locked_until=DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id=?', [$e['id']]);   // locked by wrong logins meanwhile
$hash = $state($e)['password_hash'];
fails(fn() => SecurityPasswordChange::confirm($e, ['code' => $code]), 'password change: correct code refused while locked (429)', 429, 'locked');
ok($state($e)['password_hash'] === $hash, 'password unchanged');
$unlock($e);
$r = SecurityPasswordChange::confirm($e, ['code' => $code]);
ok(str_contains($r['message'], 'Password changed') && password_verify('Changed-Pass-2026', $state($e)['password_hash']), 'after the lock ends the same code completes the change');
$f = $mkUser('frank');
$emReq($f, PW, 'frank.new@example.test'); $ecode = $lastCode();
run($p, 'UPDATE users SET locked_until=DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id=?', [$f['id']]);
fails(fn() => SecurityPasswordChange::emailConfirm($f, ['code' => $ecode]), 'email change: correct code refused while locked (429)', 429, 'locked');
ok($state($f)['email'] === 'frank@example.test', 'email unchanged while locked');
$unlock($f);

// ---------------------------------------------------------------------
section('E. Email change: notice to the previous address');
$tok = 'tok-' . bin2hex(random_bytes(8));
run($p, 'INSERT INTO trusted_login_devices (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))', [$f['id'], hash('sha256', $tok)]);
$n0 = count($mails());
$r = SecurityPasswordChange::emailConfirm($f, ['code' => $ecode]);
ok($state($f)['email'] === 'frank.new@example.test', 'email changed after the verified code');
$new = array_slice($mails(), $n0);
$notice = array_values(array_filter($new, fn($m) => $m['to'] === 'frank@example.test'));
ok(count($new) === 1 && count($notice) === 1, 'exactly one notice, sent to the PREVIOUS address', json_encode(array_column($new, 'to')));
$t = $notice[0]['text'] ?? '';
$body = substr($t, (int)strpos($t, "\r\n\r\n"));   // message body only (headers have dates, ids and the mailer's own link)
ok(str_contains($t, 'email address was changed') && str_contains($t, 'frank') && str_contains($t, 'contact your Admin'), 'notice says what changed, for which account, and what to do');
ok(str_contains($t, 'f*******w@example.test') && !str_contains($t, 'frank.new@example.test'), 'new address shown masked only', $t);
ok(!preg_match('/\b\d{6}\b/', $body) && !str_contains($body, $ecode) && !str_contains($body, PW) && !preg_match('#https?://|href=#i', $body),
    'notice has no code, no password and no link', $body);
ok(str_contains($r['message'], 'notice was sent to your previous email'), 'user is told the notice was sent');
ok($audits($f, 'email change notice sent') === 1 && $audits($f, 'email verified and updated') === 1, 'change and notice are audited');
// 657a761 removed trusted devices: the leftover row above must not be able to sign anyone in, so no sign-in code may read the table.
$readers = array_filter(array_merge(glob(dirname(__DIR__) . '/backend/*.php'), glob(dirname(__DIR__) . '/backend/*/*.php'), glob(dirname(__DIR__) . '/backend/*/*/*.php'), [dirname(__DIR__) . '/public/index.php']),
    fn($f) => str_contains((string)file_get_contents($f), 'trusted_login_devices'));
ok($readers === [], 'no sign-in code reads trusted_login_devices (a stored device token cannot bypass the password)', implode(', ', $readers));
// Mail problem: the change stays, nothing breaks.
proc_terminate($sink); proc_close($sink);
$g = $mkUser('gina');
run($p, "INSERT INTO email_change_challenges (user_id, new_email, code_hash, expires_at, attempts, requested_at) VALUES (?, 'gina.new@example.test', ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0, NOW())", [$g['id'], password_hash('123456', PASSWORD_DEFAULT)]);
$r = SecurityPasswordChange::emailConfirm($g, ['code' => '123456']);
ok($state($g)['email'] === 'gina.new@example.test' && !str_contains($r['message'], 'notice was sent'), 'mail server down: email change still saved; no false "notice sent" message');
ok($audits($g, 'email change notice not sent') === 1, 'failed notice is audited (no address in clear, no error details to the user)');
$sink = $sinkStart();

// ---------------------------------------------------------------------
section('F. Normal flows still work');
$h = $mkUser('hana');
$fresh(); $pwReq($h, PW); $hc = $lastCode();
fails(fn() => SecurityPasswordChange::confirm($h, ['code' => $hc === '000000' ? '111111' : '000000']), 'wrong verification code still refused (401)', 401);
ok((int)$state($h)['failed_logins'] === 0, 'a wrong verification CODE does not touch the login counter (unchanged behaviour)');
SecurityPasswordChange::confirm($h, ['code' => $hc]);
ok(password_verify('Changed-Pass-2026', $state($h)['password_hash']), 'password change completes normally');
fails(fn() => $pwReq($h, PW), 'old password no longer accepted as current password', 403);
ok((int)$state($h)['failed_logins'] === 1, 'the wrong current password was counted (1)');
fails(fn() => SecurityPasswordChange::request($h, ['current_password' => 'Changed-Pass-2026', 'new_password' => 'short', 'confirm' => 'short']), 'password rules unchanged (too short refused)', 400);
ok((int)$state($h)['failed_logins'] === 0, 'the correct current password reset the count (0)');

proc_terminate($sink); proc_close($sink);
foreach (glob($mailDir . '/*') ?: [] as $x) @unlink($x); @rmdir($mailDir);
echo "\n" . str_repeat('=', 60) . "\nPASSED: $PASS   FAILED: $FAIL\n";
if ($FAILED) { echo "Failed:\n - " . implode("\n - ", $FAILED) . "\n"; }
exit($FAIL ? 1 : 0);
