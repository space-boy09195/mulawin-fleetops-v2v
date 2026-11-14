<?php
/*
 * =====================================================================
 *  tests/mechanic_attendance_tests.php  —  Mechanic Attendance (Time In / Time Out) + dispatcher-only account shifts
 * =====================================================================
 *  Do NOT run this file directly: run tests/run_mechanic_attendance_tests.php (temporary copy, own .env,
 *  disposable *_test databases only).
 *
 *  A. Dispatcher-only account shifts (Admin.user_save): Day/Night, vacancy lock, other roles no shift, temporary password still shown once
 *  B. Mechanic masterlist without a shift: create, edit, existing records and duty_shift preserved
 *  C. Time In / Time Out: state, timestamps, duplicate and out-without-in refused, persistence, history kept, inactive handling
 *  D. Roles: only Admin / Motorpool Custodian act; others refused; CSRF is checked by the shared API route (source check)
 *  E. Active repair safeguard on Time Out (real statuses: Reported, Under Inspection, Under Repair)
 *  F. Maintenance assignment: Available mechanics only, checked again on the server (report / assign / assess)
 *  G. Migration: existing database upgraded with the new migration equals a fresh install; rollback file runs
 *  H. Operations Attendance roster needs no mechanic shift; Time In / Time Out is a separate record
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/backend/Actions.php';
require_once __DIR__ . '/_launcher.php';
core('Access');
core('Settings');

$FRESH = envValue('DB_NAME');
$UPGRADE = envValue('TEST_DB_UPGRADE');
test_guard([$FRESH, $UPGRADE]);

$PASS = 0; $FAIL = 0; $FAILED = [];
function ok(bool $c, string $name, string $detail = ''): void {
    global $PASS, $FAIL, $FAILED;
    if ($c) { $PASS++; echo "  PASS  $name\n"; } else { $FAIL++; $FAILED[] = $name; echo "  FAIL  $name" . ($detail !== '' ? "  -> $detail" : '') . "\n"; }
}
function fails(callable $fn, string $name, ?int $code = null, string $contains = ''): void {
    try { $fn(); ok(false, $name, 'no error was raised'); }
    catch (BusinessRuleException $e) { ok(($code === null || $e->httpStatus === $code) && ($contains === '' || stripos($e->getMessage(), $contains) !== false), $name, "got {$e->httpStatus}: " . $e->getMessage()); }
    catch (Throwable $e) { ok(false, $name, get_class($e) . ': ' . $e->getMessage()); }
}
function section(string $t): void { echo "\n== $t ==\n"; }

section('Setup: disposable fresh database');
test_recreate($FRESH);
$p = db();
ok($p->query('SELECT DATABASE()')->fetchColumn() === $FRESH, 'db() is the disposable test database');
test_import($p, test_sql('database/schema.sql'));
test_import($p, test_sql('database/extension.sql'));
$users = [];
foreach (array_keys(ROLE_LABELS) as $role) {
    $id = insert($p, 'users', ['username' => $role, 'email' => "$role@example.test", 'password_hash' => 'x', 'full_name' => strtoupper($role) . ' USER', 'role' => $role]);
    $users[$role] = ['id' => $id, 'username' => $role, 'email' => "$role@example.test", 'full_name' => strtoupper($role) . ' USER', 'role' => $role, 'shift' => null];
}
$U = fn(string $r) => $users[$r];
$mp = $U('motorpool'); $adm = $U('admin');
foreach (['Maintenance', 'Mechanics', 'Admin', 'Attendance'] as $m) Actions::load($m);

// ---------------------------------------------------------------------
section('A. Dispatcher-only account shifts');
$new = fn(array $x) => Admin::action($adm, 'user_save', $x + ['email' => 'x' . bin2hex(random_bytes(3)) . '@example.test']);
$d1 = $new(['username' => 'disp_car_day', 'full_name' => 'DISPATCH CAR DAY', 'role' => 'car_carrier_dispatcher', 'shift' => 'Day']);
$d2 = $new(['username' => 'disp_car_night', 'full_name' => 'DISPATCH CAR NIGHT', 'role' => 'car_carrier_dispatcher', 'shift' => 'Night']);
ok(one($p, 'SELECT shift FROM users WHERE id=?', [$d1['id']])['shift'] === 'Day' && one($p, 'SELECT shift FROM users WHERE id=?', [$d2['id']])['shift'] === 'Night', 'dispatcher accounts can be assigned Day or Night');
ok(!empty($d1['temporary_password']) && !password_verify($d1['temporary_password'], 'x') && one($p, 'SELECT must_change_password mc FROM users WHERE id=?', [$d1['id']])['mc'] == 1, 'new account still gets a one-time temporary password and the first-login flag (Phase 1)');
fails(fn() => $new(['username' => 'disp_car_day2', 'full_name' => 'DISPATCH CAR DAY 2', 'role' => 'car_carrier_dispatcher', 'shift' => 'Day']), 'second active dispatcher for the same role and shift is refused', 409, 'already have an active account');
fails(fn() => $new(['username' => 'disp_noshift', 'full_name' => 'DISPATCH NO SHIFT', 'role' => 'container_wingvan_dispatcher']), 'a dispatcher still needs a shift', 400);
$p->exec("UPDATE users SET active=0 WHERE role IN ('finance','billing')"); // free the single slots so the non-dispatcher rules can be exercised
$other = $new(['username' => 'finance2', 'full_name' => 'FINANCE TWO', 'role' => 'finance']);
ok(one($p, 'SELECT shift FROM users WHERE id=?', [$other['id']])['shift'] === null, 'non-dispatcher account is created without a shift (users.shift stays NULL)');
$other2 = $new(['username' => 'billing3', 'full_name' => 'BILLING THREE', 'role' => 'billing', 'shift' => 'Night']);
ok(one($p, 'SELECT shift FROM users WHERE id=?', [$other2['id']])['shift'] === null, 'a shift sent for a non-dispatcher role is ignored (users.shift stays NULL)');
Admin::action($adm, 'user_save', ['id' => $other['id'], 'username' => 'finance2', 'full_name' => 'FINANCE TWO EDITED', 'email' => 'f2@example.test', 'role' => 'finance']);
ok(one($p, 'SELECT full_name, shift FROM users WHERE id=?', [$other['id']]) === ['full_name' => 'FINANCE TWO EDITED', 'shift' => null], 'non-dispatcher account can be edited without a shift');
$js = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/modules/administration/admin.js');
ok(str_contains($js, 'dispatcherRoles.includes(role)') && str_contains($js, "['Shift','shift']"), 'dispatcher shift UI is still in the approved account workspace');

// ---------------------------------------------------------------------
section('B. Mechanic masterlist without a shift');
$legacy = insert($p, 'mechanics', ['mechanic_code' => 'MEC-L-1', 'full_name' => 'LEGACY, MECH', 'duty_shift' => 'Night', 'availability' => 'Available']);
$new1 = Mechanics::action($mp, 'save', ['full_name' => 'NEW MECH ONE', 'specialization' => 'Brakes'])['id'];
$r = one($p, 'SELECT duty_shift, availability, active FROM mechanics WHERE id=?', [$new1]);
ok($r['duty_shift'] === null && $r['availability'] === 'Unavailable' && (int)$r['active'] === 1, 'mechanic created without a shift; starts Unavailable until Time In');
Mechanics::action($mp, 'save', ['id' => $new1, 'full_name' => 'NEW MECH ONE', 'specialization' => 'Brakes and engine', 'contact_number' => '0917 111 2222']);
ok(one($p, 'SELECT specialization, contact_number, duty_shift FROM mechanics WHERE id=?', [$new1]) === ['specialization' => 'Brakes and engine', 'contact_number' => '0917 111 2222', 'duty_shift' => null], 'mechanic edited without a shift');
Mechanics::action($mp, 'save', ['id' => $legacy, 'full_name' => 'LEGACY, MECH', 'specialization' => 'Welding']);
$r = one($p, 'SELECT full_name, specialization, duty_shift FROM mechanics WHERE id=?', [$legacy]);
ok($r['full_name'] === 'LEGACY, MECH' && $r['specialization'] === 'Welding' && $r['duty_shift'] === 'Night', 'existing mechanic keeps its name and its old duty_shift (never cleared)');
Mechanics::action($mp, 'save', ['id' => $legacy, 'full_name' => 'LEGACY, MECH', 'duty_shift' => 'Day']);
ok(one($p, 'SELECT duty_shift FROM mechanics WHERE id=?', [$legacy])['duty_shift'] === 'Night', 'a duty_shift sent by a crafted request is not written');
$mjs = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/modules/maintenance/mechanics.js');
ok(!str_contains($mjs, "name: 'duty_shift'") && !str_contains($mjs, "['Shift', 'duty_shift'"), 'mechanic form and list no longer show a shift');

// ---------------------------------------------------------------------
section('C. Time In / Time Out');
$row = fn(int $id) => one($p, 'SELECT * FROM mechanics WHERE id=?', [$id]);
$listed = fn(int $id) => array_values(array_filter(Mechanics::read($mp, 'mechanics', [])['mechanics'], fn($m) => (int)$m['id'] === $id))[0] ?? null;
ok($row($new1)['availability'] === 'Unavailable' && (int)$listed($new1)['timed_in'] === 0, 'not timed in = Unavailable');
$ti = Mechanics::action($mp, 'time_in', ['id' => $new1]);
$s = one($p, 'SELECT * FROM mechanic_attendance WHERE mechanic_id=?', [$new1]);
ok($row($new1)['availability'] === 'Available' && $s['time_out'] === null && $s['time_in'] !== null && (int)$s['time_in_by'] === (int)$mp['id'] && $ti['availability'] === 'Available', 'Time In: Available, time_in saved with the user, no time_out');
ok(abs(strtotime((string)$s['time_in']) - time()) < 30, 'time_in uses the configured timezone (matches PHP now)');
fails(fn() => Mechanics::action($mp, 'time_in', ['id' => $new1]), 'duplicate Time In is rejected', 409, 'already timed in');
ok((int)one($p, 'SELECT COUNT(*) c FROM mechanic_attendance WHERE mechanic_id=?', [$new1])['c'] === 1, 'duplicate Time In created no second session');
$l = $listed($new1);
ok((int)$l['timed_in'] === 1 && $l['availability'] === 'Available' && $l['last_time_in'] === $s['time_in'] && $l['last_time_out'] === null, 'status persists: the next read shows timed in (refresh / new login)');
$to = Mechanics::action($mp, 'time_out', ['id' => $new1]);
$s = one($p, 'SELECT * FROM mechanic_attendance WHERE mechanic_id=? ORDER BY id DESC', [$new1]);
ok($row($new1)['availability'] === 'Unavailable' && $s['time_out'] !== null && (int)$s['time_out_by'] === (int)$mp['id'] && $to['availability'] === 'Unavailable', 'Time Out: Unavailable and time_out saved');
fails(fn() => Mechanics::action($mp, 'time_out', ['id' => $new1]), 'Time Out without an open Time In is rejected', 409, 'has not timed in');
$l = $listed($new1);
ok((int)$l['timed_in'] === 0 && $l['availability'] === 'Unavailable' && $l['last_time_out'] !== null, 'timed out status persists');
Mechanics::action($mp, 'time_in', ['id' => $new1]);
Mechanics::action($mp, 'time_out', ['id' => $new1]);
Mechanics::action($mp, 'time_in', ['id' => $new1]);
$hist = many($p, 'SELECT id, time_in, time_out FROM mechanic_attendance WHERE mechanic_id=? ORDER BY id', [$new1]);
ok(count($hist) === 3 && $hist[0]['time_out'] !== null && $hist[1]['time_out'] !== null && $hist[2]['time_out'] === null, 'every session is kept: earlier Time In / Time Out are never overwritten');
$det = Mechanics::action($mp, 'details', ['id' => $new1]);
ok(count($det['attendance']) === 3 && $det['attendance'][0]['time_out'] === null, 'details show the attendance history, newest first');
ok((int)one($p, 'SELECT COUNT(*) c FROM mechanic_attendance WHERE mechanic_id=? AND time_out IS NULL', [$new1])['c'] === 1, 'only one open session per mechanic');
$dup = false;
try { run($p, 'INSERT INTO mechanic_attendance (mechanic_id, time_in, time_in_by) VALUES (?, NOW(), ?)', [$new1, $mp['id']]); } catch (PDOException $e) { $dup = str_contains($e->getMessage(), 'uq_mattn_one_open'); }
ok($dup, 'the database itself refuses a second open session (unique rule)');
// An old session never makes a mechanic Available.
$old = Mechanics::action($mp, 'save', ['full_name' => 'OLD SESSION MECH'])['id'];
run($p, 'INSERT INTO mechanic_attendance (mechanic_id, time_in, time_out, time_in_by, time_out_by) VALUES (?, DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY), ?, ?)', [$old, $mp['id'], $mp['id']]);
ok($row($old)['availability'] === 'Unavailable' && (int)$listed($old)['timed_in'] === 0, 'an attendance record from an earlier date does not make a mechanic Available');
fails(fn() => Maintenance::action($mp, 'assign_mechanic', ['job_id' => 1, 'mechanic_id' => $old]), 'such a mechanic cannot be given a repair (job lookup or availability refuses)');
// Inactive mechanics.
ok(!array_filter(Mechanics::read($mp, 'mechanics', [])['mechanics'], fn($m) => (int)$m['active'] === 0), 'no inactive mechanic yet; deactivating next');
Mechanics::action($mp, 'set_active', ['id' => $new1, 'active' => 0]);
$s = one($p, 'SELECT time_out FROM mechanic_attendance WHERE mechanic_id=? ORDER BY id DESC', [$new1]);
ok($s['time_out'] !== null && $row($new1)['availability'] === 'Unavailable', 'deactivating closes the open Time In and makes the mechanic Unavailable');
fails(fn() => Mechanics::action($mp, 'time_in', ['id' => $new1]), 'an inactive mechanic cannot Time In', 404);
Mechanics::action($mp, 'set_active', ['id' => $new1, 'active' => 1]);
ok($row($new1)['availability'] === 'Unavailable' && !one($p, 'SELECT id FROM mechanic_attendance WHERE mechanic_id=? AND time_out IS NULL', [$new1]), 'a reactivated mechanic is Unavailable until a new Time In');
fails(fn() => Mechanics::action($mp, 'availability', ['id' => $new1, 'availability' => 'Available']), 'the old manual "Set Available" switch is closed', 409, 'Mechanic Attendance');
ok(!empty(one($p, "SELECT id FROM audit_events WHERE action='mechanic time in' LIMIT 1")) && !empty(one($p, "SELECT id FROM audit_events WHERE action='mechanic time out' LIMIT 1")), 'Time In and Time Out are audited');

// ---------------------------------------------------------------------
section('D. Roles and CSRF');
foreach (['admin', 'purchasing', 'audit', 'operations_head', 'finance', 'billing', 'car_carrier_dispatcher'] as $role) {
    fails(fn() => Mechanics::action($U($role), 'time_in', ['id' => $legacy]), "$role cannot Time In", 403);
    fails(fn() => Mechanics::action($U($role), 'time_out', ['id' => $legacy]), "$role cannot Time Out", 403);
}
ok(!one($p, 'SELECT id FROM mechanic_attendance WHERE mechanic_id=?', [$legacy]), 'refused roles changed nothing');
Mechanics::action($mp, 'time_in', ['id' => $legacy]); Mechanics::action($mp, 'time_out', ['id' => $legacy]);
ok((int)one($p, 'SELECT COUNT(*) c FROM mechanic_attendance WHERE mechanic_id=?', [$legacy])['c'] === 1, 'Motorpool manages mechanic attendance (Admin monitors read-only)');
fails(fn() => Mechanics::action($adm, 'save', ['id' => $legacy, 'full_name' => 'LEGACY, MECH']), 'Admin cannot edit the mechanic masterlist (Head Management policy)', 403);
$ix = (string)file_get_contents(dirname(__DIR__) . '/public/index.php');
$defaultRoute = substr($ix, strpos($ix, "default:"));
ok(str_contains($defaultRoute, "must(\$_SERVER['REQUEST_METHOD'] === 'POST'") && strpos($defaultRoute, 'checkCsrf') < strpos($defaultRoute, 'Actions::run'), 'module actions (incl. Mechanics.time_in / time_out) go through POST + checkCsrf before Actions::run (source check, not an HTTP test)');

// ---------------------------------------------------------------------
section('E. Active repair safeguard on Time Out');
$V = []; for ($i = 1; $i <= 4; $i++) $V[$i] = insert($p, 'vehicles', ['plate_number' => "MA-$i", 'truck_number' => (string)(200 + $i), 'truck_type' => 'Wing Van']);
$M = []; foreach (['ALPHA MECH', 'BRAVO MECH', 'CHARLIE MECH'] as $n) $M[] = Mechanics::action($mp, 'save', ['full_name' => $n])['id'];
$png = function (): array {
    $path = sys_get_temp_dir() . '/' . bin2hex(random_bytes(6));
    $b = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    file_put_contents($path, $b);
    return ['name' => 'p.png', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($b)];
};
$report = function (int $vid, ?int $mech = null) use ($mp, $png) {
    $_FILES = ['photos' => $png()];
    return Maintenance::action($mp, 'report', ['vehicle_id' => $vid, 'problem' => 'Engine overheating', 'reported_on' => date('Y-m-d')] + ($mech ? ['mechanic_id' => $mech] : []));
};
fails(fn() => $report($V[1], $M[0]), 'F-pre: reporting a truck with a mechanic who has not timed in is refused', 409, 'not timed in');
ok(!one($p, 'SELECT id FROM maintenance_jobs'), 'F-pre: the refused report created no repair record');
Mechanics::action($mp, 'time_in', ['id' => $M[0]]);
$r1 = $report($V[1], $M[0]);
ok($row($M[0])['availability'] === 'Assigned', 'timed-in mechanic on a repair is Assigned');
fails(fn() => Mechanics::action($mp, 'time_out', ['id' => $M[0]]), 'Time Out is blocked while the mechanic has an active repair (Under Inspection)', 409, $r1['job_ref']);
ok((int)$listed($M[0])['timed_in'] === 1 && $row($M[0])['availability'] === 'Assigned', 'the blocked Time Out changed nothing');
$statuses = [];
foreach (['Under Repair' => 'status'] as $st => $c) { run($p, "UPDATE maintenance_jobs SET status='Under Repair' WHERE id=?", [$r1['job_id']]); }
fails(fn() => Mechanics::action($mp, 'time_out', ['id' => $M[0]]), 'Time Out is blocked while Under Repair', 409);
run($p, "UPDATE maintenance_jobs SET status='Completed', completed_on=CURDATE() WHERE id=?", [$r1['job_id']]);
Mechanics::action($mp, 'time_out', ['id' => $M[0]]);
ok($row($M[0])['availability'] === 'Unavailable', 'after the repair is Completed the mechanic can Time Out');

// ---------------------------------------------------------------------
section('F. Maintenance assignment uses attendance');
Mechanics::action($mp, 'time_in', ['id' => $M[1]]);
$r2 = $report($V[2]);
fails(fn() => Maintenance::action($mp, 'assign_mechanic', ['job_id' => $r2['job_id'], 'mechanic_id' => $M[2]]), 'an Unavailable mechanic cannot be assigned (server-side)', 409, 'not timed in');
// Crafted state: column says Available but there is no open Time In -> still refused.
run($p, "UPDATE mechanics SET availability='Available' WHERE id=?", [$M[2]]);
fails(fn() => Maintenance::action($mp, 'assign_mechanic', ['job_id' => $r2['job_id'], 'mechanic_id' => $M[2]]), 'the stored Available label alone is not enough: an open Time In is required', 409, 'not timed in');
Maintenance::action($mp, 'assign_mechanic', ['job_id' => $r2['job_id'], 'mechanic_id' => $M[1]]);
ok((int)one($p, 'SELECT mechanic_id FROM maintenance_jobs WHERE id=?', [$r2['job_id']])['mechanic_id'] === $M[1], 'an Available (timed-in) mechanic can be assigned');
$pd = Maintenance::read($mp, 'maintenance', [])['mechanics'];
$mm = array_column($pd, 'availability', 'id');
ok(($mm[$M[1]] ?? null) === 'Assigned' && !in_array($mm[$M[0]] ?? '', ['Available', 'Assigned'], true), 'Repair Management mechanic list carries the real availability for the dropdown filter');
$mjs2 = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/modules/maintenance/maintenance.js');
ok(str_contains($mjs2, "m.availability === 'Available'"), 'dropdown still lists only Available mechanics (and the current one)');
// Mechanic changed while Under Inspection; the replacement must be timed in too.
Mechanics::action($mp, 'time_in', ['id' => $M[2]]);
$r3 = $report($V[3], $M[2]);
ok($row($M[2])['availability'] === 'Assigned', 'report with a timed-in mechanic goes straight to Under Inspection');
fails(fn() => Maintenance::action($mp, 'assign_mechanic', ['job_id' => $r3['job_id'], 'mechanic_id' => $M[0], 'reason' => 'swap']), 'changing to a mechanic who timed out is refused', 409, 'not timed in');
ok($row($M[0])['availability'] === 'Unavailable', 'the refused change left the timed-out mechanic Unavailable');

// ---------------------------------------------------------------------
section('G. Migration for an existing database');
$baseSchema = __DIR__ . '/_base_schema.sql'; $baseExt = __DIR__ . '/_base_extension.sql';
if (!is_file($baseSchema) || !is_file($baseExt)) {
    echo "  NOTE  git history not available: migration upgrade check skipped (run from a git clone)\n";
} else {
    test_recreate($UPGRADE);
    $up = test_conn($UPGRADE);
    test_import($up, (string)file_get_contents($baseSchema));
    test_import($up, (string)file_get_contents($baseExt));
    $up->exec("INSERT INTO mechanics (mechanic_code, full_name, availability) VALUES ('MEC-U-1','UPGRADE ONE','Available'), ('MEC-U-2','UPGRADE TWO','Assigned'), ('MEC-U-3','UPGRADE THREE','Unavailable')");
    $chain = test_migrations_from('2026-11-08_08_attendance');
    ok(in_array('2026-11-10_10_mechanic_attendance', $chain, true), 'the mechanic attendance migration is in the chain (' . implode(', ', $chain) . ')');
    foreach ($chain as $m) test_import($up, test_sql("database/migrations/$m.sql"));
    ok((bool)one($up, "SELECT id FROM schema_migrations WHERE id='2026-11-10_10_mechanic_attendance'"), 'migration recorded itself in schema_migrations');
    $av = $up->query('SELECT mechanic_code, availability FROM mechanics ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    ok($av === ['MEC-U-1' => 'Unavailable', 'MEC-U-2' => 'Assigned', 'MEC-U-3' => 'Unavailable'] && (int)$up->query('SELECT COUNT(*) FROM mechanics')->fetchColumn() === 3, 'existing mechanics are kept; only "Available without Time In" became Unavailable');
    $fa = test_fingerprint($p, $FRESH); $fb = test_fingerprint($up, $UPGRADE);
    foreach ($fa as $k => $v) ok($v === $fb[$k], "fresh install and upgraded database have identical $k", count(array_diff($v, $fb[$k])) . ' / ' . count(array_diff($fb[$k], $v)) . ' differences');
    test_import($up, test_sql('database/migrations/rollback/2026-11-10_10_mechanic_attendance_rollback.sql'));
    $av = $up->query('SELECT mechanic_code, availability FROM mechanics ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    ok($av === ['MEC-U-1' => 'Available', 'MEC-U-2' => 'Assigned', 'MEC-U-3' => 'Unavailable'] && !$up->query("SHOW TABLES LIKE 'mechanic_attendance'")->fetch(), 'rollback restores the old availability values and drops only mechanic_attendance');
}

// ---------------------------------------------------------------------
section('H. No shift dependency left in Operations Attendance');
$att = (string)file_get_contents(dirname(__DIR__) . '/backend/Modules/Administration/Attendance.php');
ok(!str_contains($att, 'duty_shift') || str_contains($att, 'ignored'), 'Attendance.php no longer reads mechanics.duty_shift');
$noShiftMech = insert($p, 'mechanics', ['mechanic_code' => 'MEC-N-1', 'full_name' => 'NO SHIFT, MECH']);
$ml = Attendance::action($adm, 'list', ['kind' => 'Mechanic', 'duty_date' => date('Y-m-d')]);
$mids = array_map('intval', array_column($ml['rows'], 'id'));
ok(in_array($legacy, $mids, true) && in_array($noShiftMech, $mids, true) && in_array($new1, $mids, true), 'Operations Attendance roster lists mechanics with and without a legacy duty_shift');
ok((int)one($p, 'SELECT COUNT(*) c FROM attendance_marks')['c'] === 0 && (int)one($p, 'SELECT COUNT(*) c FROM attendance_daily')['c'] === 0, 'mechanic Time In / Time Out writes no attendance_marks / attendance_daily rows (separate record)');

echo "\nRESULT: $PASS passed, $FAIL failed\n";
if ($FAIL) echo "FAILED: " . implode('; ', $FAILED) . "\n";
exit($FAIL ? 1 : 0);
