<?php
/*
 * =====================================================================
 *  scripts/dev_setup_accounts.php  —  DEVELOPMENT-ONLY department test accounts (idempotent)
 * =====================================================================
 *  Creates the agreed role accounts that do not exist yet. It NEVER updates, renames, deactivates, deletes or
 *  re-passwords an existing account, and never creates a second active account for a role / dispatcher shift
 *  (the same one-account-per-role / per-dispatcher-shift rule as Admin > Users).
 *
 *  Password: taken from the environment variable DEV_ACCOUNT_PASSWORD (never stored in this file, never printed).
 *  It is hashed with password_hash(PASSWORD_DEFAULT) like Admin.user_save, and every new account must change it at
 *  first login (must_change_password = 1). Local development only.
 *
 *  RUN (PowerShell):
 *    php scripts/dev_setup_accounts.php                                  dry run on the .env database (no writes)
 *    $env:DEV_ACCOUNT_PASSWORD='...'; php scripts/dev_setup_accounts.php --db=<name>_test --apply
 *                                                                        writes to a disposable *_test copy only
 *    $env:DEV_ACCOUNT_PASSWORD='...'; php scripts/dev_setup_accounts.php --apply --confirm-live=<.env DB_NAME> --backup=<verified backup .sql>
 *                                                                        writes to the live development database
 *  Exit code 0 = nothing blocked.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once dirname(__DIR__) . '/backend/Actions.php';
Actions::load('Admin');

$opt = getopt('', ['db:', 'apply', 'confirm-live:', 'backup:', 'reset-existing:']);
$resetList = isset($opt['reset-existing']) ? array_values(array_filter(array_map('trim', explode(',', (string)$opt['reset-existing'])))) : [];
$env =strtolower((string)envValue('APP_ENV'));
if (!in_array($env, ['development', 'local', 'test'], true)) { fwrite(STDERR, "Refused: APP_ENV is '$env'. This script is for local development only.\n"); exit(2); }
$liveDb = (string)envValue('DB_NAME');
$target = isset($opt['db']) ? (string)$opt['db'] : $liveDb;
$apply = isset($opt['apply']);
if (!preg_match('/^[a-zA-Z0-9_]+$/', $target)) { fwrite(STDERR, "Refused: invalid database name.\n"); exit(2); }
if ($apply && $target === $liveDb) {
    if (($opt['confirm-live'] ?? '') !== $liveDb) { fwrite(STDERR, "Refused: writing to $liveDb needs --confirm-live=$liveDb (after approval).\n"); exit(2); }
    $bk = (string)($opt['backup'] ?? '');
    if ($bk === '' || !is_file($bk) || filesize($bk) < 10000 || filemtime($bk) < time() - 3600 || !str_contains((string)file_get_contents($bk, false, null, 0, 4096), 'MariaDB dump')
        && !str_contains((string)file_get_contents($bk, false, null, 0, 4096), 'MySQL dump')) {
        fwrite(STDERR, "Refused: --backup must point to a database dump made in the last hour.\n"); exit(2);
    }
} elseif ($apply && !str_ends_with($target, '_test')) { fwrite(STDERR, "Refused: --db must be the .env database or a *_test copy.\n"); exit(2); }

$p = new PDO('mysql:host=' . envValue('DB_HOST') . ';port=' . (envValue('DB_PORT') ?: '3306') . ';dbname=' . $target . ';charset=utf8mb4', envValue('DB_USER'), (string)envValue('DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
if ($p->query('SELECT DATABASE()')->fetchColumn() !== $target) { fwrite(STDERR, "Connected to the wrong database.\n"); exit(2); }

// username => [role, shift, full name, email]. Dispatcher division = role (Car Carrier vs Container / Wing Van); shift = Day / Night.
const ACCOUNTS = [
    'admin'           => ['admin', null, 'Head Management Admin', '(existing account)'],
    'ophead'          => ['operations_head', null, 'Operations Head', '(existing account)'],
    'container_day'   => ['container_wingvan_dispatcher', 'Day', 'Container / Wing Van Dispatcher (Day)', 'spaceboy09195+containerday@gmail.com'],
    'container_night' => ['container_wingvan_dispatcher', 'Night', 'Container / Wing Van Dispatcher (Night)', 'spaceboy09195+containernight@gmail.com'],
    'carrier_day'     => ['car_carrier_dispatcher', 'Day', 'Car Carrier Dispatcher (Day)', 'spaceboy09195+carrierday@gmail.com'],
    'carrier_night'   => ['car_carrier_dispatcher', 'Night', 'Car Carrier Dispatcher (Night)', 'spaceboy09195+carriernight@gmail.com'],
    'maintenance'     => ['motorpool', null, 'Motorpool Custodian', '(existing account)'],
    'Purchasing'      => ['purchasing', null, 'Purchasing Officer', '(existing account)'],
    'laquino'         => ['finance', null, 'Finance Officer', '(existing account)'],
    'billing'         => ['billing', null, 'Billing Officer', 'spaceboy09195+billing@gmail.com'],
    'audit'           => ['audit', null, 'Audit Officer', 'spaceboy09195+audit@gmail.com'],
];
/** Existing accounts whose password may be reset to the shared development password (owner-approved list, with their expected role). */
const RESETTABLE = ['admin' => 'admin', 'ophead' => 'operations_head', 'maintenance' => 'motorpool', 'Purchasing' => 'purchasing', 'laquino' => 'finance'];
foreach ($resetList as $r) if (!array_key_exists($r, RESETTABLE)) { fwrite(STDERR, "Refused: '$r' is not in the approved reset list.\n"); exit(2); }

$pw = (string)getenv('DEV_ACCOUNT_PASSWORD');
if ($apply) {
    if ($pw === '') { fwrite(STDERR, "Set DEV_ACCOUNT_PASSWORD first (it is not stored or printed).\n"); exit(2); }
    try { Admin::strongPassword($pw); } catch (Throwable $e) { fwrite(STDERR, 'Password refused by the password policy: ' . $e->getMessage() . "\n"); exit(2); }
}

$q = fn(string $sql, array $a = []) => (function () use ($p, $sql, $a) { $s = $p->prepare($sql); $s->execute($a); return $s; })();
$rows = []; $blocked = 0; $created = 0;
foreach (ACCOUNTS as $username => [$role, $shift, $name, $email]) {
    $have = $q('SELECT id, username, role, shift, active, must_change_password FROM users WHERE username=?', [$username])->fetch();   // collation: case-insensitive
    if ($have) {
        $same = $have['role'] === $role && $have['shift'] === $shift;
        $rows[] = [$username, $same ? 'EXISTS (matches)' : 'EXISTS (different role)', "user #{$have['id']} '{$have['username']}': {$have['role']}" . ($have['shift'] ? " / {$have['shift']}" : '') . ((int)$have['active'] ? ', active' : ', INACTIVE') . ' — not changed'];
        if (!$same) $blocked++;
        continue;
    }
    $occ = roleIsDispatcher($role)
        ? $q('SELECT id, username FROM users WHERE role=? AND shift=? AND active=1 LIMIT 1', [$role, $shift])->fetch()
        : $q('SELECT id, username FROM users WHERE role=? AND active=1 LIMIT 1', [$role])->fetch();
    if ($occ) { $rows[] = [$username, 'BLOCKED', "an active $role" . ($shift ? " ($shift)" : '') . " account already exists: user #{$occ['id']} '{$occ['username']}' (one active account per role" . ($shift ? ' and shift' : '') . ')']; $blocked++; continue; }
    if ($q('SELECT id FROM users WHERE email=?', [$email])->fetch()) { $rows[] = [$username, 'BLOCKED', "email $email is already used"]; $blocked++; continue; }
    if (!$apply) { $rows[] = [$username, 'WOULD CREATE', "$role" . ($shift ? " / $shift" : '') . " · $name · $email"]; continue; }
    $p->beginTransaction();
    try {
        // re-check under the same named lock Admin uses for role slots, then insert with a fresh hash
        $q("SELECT GET_LOCK('mulawin_user_role_slots', 10)");
        $q('INSERT INTO users (username, email, password_hash, full_name, role, shift, must_change_password, active) VALUES (?,?,?,?,?,?,1,1)',
            [$username, $email, password_hash($pw, PASSWORD_DEFAULT), $name, $role, $shift]);
        $id = (int)$p->lastInsertId();
        $details = "$username / $role / $email (development account setup script)";
        // Same audit format as Admin.user_save (actor = System); no password or hash is recorded.
        auditWithChanges($p, null, 'Access', 'user ' . $id, 'account created', $details, [],
            ['Username' => $username, 'Full name' => $name, 'Email' => $email, 'Role' => $role, 'Shift' => $shift, 'Active' => 'Yes', 'Must change password' => 'Yes']);
        $q("SELECT RELEASE_LOCK('mulawin_user_role_slots')");
        $p->commit(); $created++;
        $rows[] = [$username, 'CREATED', "user #$id · $role" . ($shift ? " / $shift" : '') . ' · must change password at first login'];
    } catch (Throwable $e) { $p->rollBack(); $rows[] = [$username, 'FAILED', $e->getMessage()]; $blocked++; }
}
// Approved password resets of existing accounts: ONLY password_hash and must_change_password change (no other field).
foreach ($resetList as $username) {
    $have = $q('SELECT id, username, role, active FROM users WHERE BINARY username=?', [$username])->fetch();
    if (!$have || $have['role'] !== RESETTABLE[$username] || !(int)$have['active']) { $rows[] = [$username, 'RESET BLOCKED', $have ? "role {$have['role']}, active={$have['active']} (expected " . RESETTABLE[$username] . ', active)' : 'not found']; $blocked++; continue; }
    if (!$apply) { $rows[] = [$username, 'WOULD RESET PASSWORD', "user #{$have['id']} ({$have['role']}): password hash + must change at first login"]; continue; }
    $p->beginTransaction();
    try {
        $n = $q('UPDATE users SET password_hash=?, must_change_password=1 WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $have['id']])->rowCount();
        if ($n !== 1) throw new RuntimeException('expected 1 updated row');
        audit($p, null, 'Access', 'user ' . $have['id'], 'password reset (development setup)', 'shared development password set by the setup script; user must change it at next login');
        $p->commit();
        $rows[] = [$username, 'PASSWORD RESET', "user #{$have['id']} · must change at next login"];
    } catch (Throwable $e) { $p->rollBack(); $rows[] = [$username, 'FAILED', $e->getMessage()]; $blocked++; }
}
echo "Database: $target" . ($apply ? '  (APPLY)' : '  (dry run: nothing written)') . "\n";
foreach ($rows as [$u, $s, $m]) printf("  %-16s %-24s %s\n", $u, $s, $m);
echo "Created: $created · blocked / different: $blocked\n";
exit($blocked ? 1 : 0);
