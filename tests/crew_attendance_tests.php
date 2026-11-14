<?php
/*
 * =====================================================================
 *  tests/crew_attendance_tests.php  —  Checklist #42, DAILY attendance (no permanent shifts)
 * =====================================================================
 *  Do NOT run this file directly: run tests/run_crew_attendance_tests.php (temporary copy, own .env,
 *  disposable *_test database only).
 *
 *  DAILY_ATTENDANCE_V1: drivers, helpers and mechanics have no Day/Night shift. Covers:
 *    A. One duty date (Philippine time) and the daily reminder hour
 *    B. Crew roster without shifts (drivers AND helpers), marking, history
 *    C. Masterlist: no shift asked, legacy duty_shift values kept
 *    D. Daily Admin bell (no "shift not assigned" bell any more)
 *    E. Attendance Phase 1 behaviour kept (future date, full roster, audit, mechanics, roles)
 *    F. Trip planning / dispatch use the daily mark
 *    G. Migration: legacy shift-based marks are preserved and interpreted per day; rollback
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/backend/Actions.php';
require_once __DIR__ . '/_launcher.php';
core('Access');
core('Settings');
core('CrewAttendance');
core('Notify');

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
    catch (BusinessRuleException $e) { ok(($code === null || $e->httpStatus === $code) && ($contains === '' || stripos($e->getMessage(), $contains) !== false), $name, "got {$e->httpStatus}: {$e->getMessage()}"); }
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
$adm = $U('admin'); $ops = $U('operations_head'); $wvd = $U('container_wingvan_dispatcher');
Actions::load('Attendance'); Actions::load('Fleet'); Actions::load('Trips');
$setting = function (string $key, $value) use ($p, $adm) {
    if ($value === null) run($p, 'DELETE FROM app_settings WHERE setting_key=?', [$key]);
    else run($p, 'INSERT INTO app_settings (setting_key, setting_value, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)', [$key, (string)$value, $adm['id']]);
    Settings::clearCache();
};
$person = fn(string $name, string $type, ?string $legacyShift = null) => insert($p, 'personnel', ['full_name' => $name, 'person_type' => $type, 'duty_shift' => $legacyShift,
    'license_number' => $type === 'Driver' ? 'L-' . strtoupper(substr(md5($name), 0, 8)) : null, 'license_expiry' => $type === 'Driver' ? date('Y-m-d', strtotime('+1 year')) : null]);

// ---------------------------------------------------------------------
section('A. One duty date (Philippine time) and the daily reminder hour');
foreach (['2026-11-08 00:30:00' => '2026-11-08', '2026-11-08 05:59:59' => '2026-11-08', '2026-11-08 18:00:00' => '2026-11-08', '2026-11-08 23:59:59' => '2026-11-08', '2028-02-29 03:00:00' => '2028-02-29'] as $local => $date)
    ok(CrewAttendance::dayFor(strtotime($local)) === ['date' => $date], "$local -> attendance date $date (no shift in the result)", json_encode(CrewAttendance::dayFor(strtotime($local))));
ok(date_default_timezone_get() === 'Asia/Manila' && CrewAttendance::dayFor(gmmktime(16, 30, 0, 11, 8, 2026)) === ['date' => '2026-11-09'], '16:30 UTC is 00:30 of the NEXT day in Philippine time');
// Integrated with kenneth: shiftFor/shiftText only label Dispatcher Time In sessions; no permanent shift restricts anyone.
ok(CrewAttendance::unassignedCrew($p) === [] && !array_key_exists('shift', CrewAttendance::dayFor()), 'no permanent shift: nobody waits for a shift assignment and the attendance date has no shift');
Actions::load('Admin');
$all = array_column(Settings::all(), null, 'key');
ok(isset($all['attendance_day_start_hour'], $all['attendance_night_start_hour']) && $all['attendance_day_start_hour']['format'] === 'hour' && $all['attendance_night_start_hour']['format'] === 'hour', 'daily reminder hour + Night label hour of Dispatcher sessions (labels only)');
ok(CrewAttendance::reminderHour() === 6, 'default reminder hour is 6 (the saved key is kept)');
$r = Admin::action($adm, 'setting_save', ['key' => 'attendance_day_start_hour', 'value' => '4']);
ok(str_contains($r['message'], '(4:00 AM)') && Settings::value('attendance_day_start_hour') === 4.0 && CrewAttendance::reminderHour() === 4, 'Admin can still change the reminder hour (12-hour display)', $r['message']);
fails(fn() => Admin::action($adm, 'setting_save', ['key' => 'attendance_day_start_hour', 'value' => '6.5']), 'half hour refused', 400, 'whole hour');
$r18 = Admin::action($adm, 'setting_save', ['key' => 'attendance_night_start_hour', 'value' => '18']); ok(str_contains($r18['message'], '6:00 PM') && !array_key_exists('shift', CrewAttendance::dayFor()), 'the Night hour only labels Dispatcher sessions: it saves as an hour and the attendance date stays shift-free');
$aud = one($p, "SELECT details FROM audit_events WHERE action='setting changed' ORDER BY id DESC LIMIT 1");
ok($aud !== null, 'setting change still audited');
$setting('attendance_day_start_hour', null);
foreach ([0 => '12:00 AM', 6 => '6:00 AM', 12 => '12:00 PM', 18 => '6:00 PM'] as $h => $label) ok(CrewAttendance::hourLabel($h) === $label, "$h -> $label");

// ---------------------------------------------------------------------
section('B. Crew roster without shifts: drivers AND helpers');
$dA = $person('ALPHA, DRIVER', 'Driver'); $hA = $person('ALPHA, HELPER', 'Helper');
$dLegacy = $person('LEGACY, DRIVER', 'Driver', 'Night');   // an old duty_shift value must not matter
$hOff = $person('INACTIVE, HELPER', 'Helper'); run($p, 'UPDATE personnel SET active=0 WHERE id=?', [$hOff]);
$today = date('Y-m-d');
$l = Attendance::action($adm, 'list', ['kind' => 'Driver', 'duty_date' => $today]);
$ids = array_map('intval', array_column($l['rows'], 'id'));
ok($ids == [$dA, $dLegacy, $hA] && $l['total'] === 3, 'roster: every ACTIVE driver and helper, with or without a shift value (inactive excluded)', json_encode($ids));
ok(array_column($l['rows'], 'person_type') === ['Driver', 'Driver', 'Helper'] && !array_key_exists('shift', $l) && !array_key_exists('unassigned', $l), 'roster shows the crew type; no shift and no "unassigned" list in the answer');
$l2 = Attendance::action($adm, 'list', ['kind' => 'Driver', 'duty_date' => $today, 'duty_shift' => 'Night']);
ok(array_map('intval', array_column($l2['rows'], 'id')) == $ids, 'a duty_shift sent by an old client is ignored (same roster)');
Attendance::action($adm, 'save', ['kind' => 'Driver', 'duty_date' => $today, 'items' => [['id' => $dA, 'status' => 'Present'], ['id' => $dLegacy, 'status' => 'Present'], ['id' => $hA, 'status' => 'Absent']]]);
$marks = many($p, 'SELECT personnel_id, status, legacy_shifts FROM attendance_daily WHERE duty_date=? ORDER BY personnel_id', [$today]);
ok(array_column($marks, 'status', 'personnel_id') == [$dA => 'Present', $dLegacy => 'Present', $hA => 'Absent'] && count(array_filter(array_column($marks, 'legacy_shifts'))) === 0, 'attendance marked without any shift (new rows carry no legacy shift)', json_encode($marks));
ok((int)one($p, 'SELECT COUNT(*) AS n FROM attendance_marks')['n'] === 0, 'the legacy attendance_marks table is not written any more');
fails(fn() => Attendance::action($adm, 'save', ['kind' => 'Driver', 'duty_date' => $today, 'items' => [['id' => $dA, 'status' => 'Present'], ['id' => $dLegacy, 'status' => 'Present'], ['id' => $hA, 'status' => 'Present'], ['id' => $hOff, 'status' => 'Present']]]),
    'an inactive helper cannot be marked', 409, 'inactive');
$again = Attendance::action($adm, 'list', ['kind' => 'Driver', 'duty_date' => $today]);
ok(array_column($again['rows'], 'status', 'id') == [$dA => 'Present', $dLegacy => 'Present', $hA => 'Absent'] && $again['marked'] === 3, 'attendance history for the date is read back (persists across reloads)');
Attendance::action($adm, 'save', ['kind' => 'Driver', 'duty_date' => $today, 'items' => [['id' => $dA, 'status' => 'Present'], ['id' => $dLegacy, 'status' => null], ['id' => $hA, 'status' => 'Present']]]);
ok(!one($p, 'SELECT id FROM attendance_daily WHERE duty_date=? AND personnel_id=?', [$today, $dLegacy]) && one($p, 'SELECT status FROM attendance_daily WHERE duty_date=? AND personnel_id=?', [$today, $hA])['status'] === 'Present', 'a mark can be corrected or cleared the same day');

// ---------------------------------------------------------------------
section('C. Masterlist: no shift asked, legacy values kept');
$crew = fn(int $id) => one($p, 'SELECT * FROM personnel WHERE id=?', [$id]);
$save = fn(array $u, int $id, array $x = []) => Fleet::action($u, 'crew_save', $x + ['id' => $id, 'full_name' => $crew($id)['full_name'], 'person_type' => $crew($id)['person_type'],
    'license_number' => $crew($id)['license_number'] ?? '', 'license_expiry' => $crew($id)['license_expiry'] ?? '']);
$save($adm, $hA);
ok(true, 'Admin saves a helper without a shift');
$save($adm, $dLegacy, ['full_name' => 'LEGACY, DRIVER EDITED', 'duty_shift' => 'Day']);
ok($crew($dLegacy)['duty_shift'] === 'Night' && $crew($dLegacy)['full_name'] === 'LEGACY, DRIVER EDITED', 'a duty_shift in the request is not written; the old value stays as history');
$newId = (int)Fleet::action($ops, 'crew_save', ['full_name' => 'NEW, HELPER', 'person_type' => 'Helper', 'duty_shift' => 'Day'])['id'];
ok($crew($newId)['duty_shift'] === null, 'a helper added by the Operations Head has no shift');
$newAdm = (int)Fleet::action($adm, 'crew_save', ['full_name' => 'NEWER, HELPER', 'person_type' => 'Helper'])['id'];
ok($crew($newAdm)['duty_shift'] === null && in_array($newAdm, array_map('intval', array_column(Attendance::action($adm, 'list', ['kind' => 'Driver', 'duty_date' => $today])['rows'], 'id')), true), 'a helper added by Admin without a shift is on the attendance roster at once');
foreach (['finance', 'billing', 'container_wingvan_dispatcher'] as $role)
    fails(fn() => Fleet::action($U($role), 'crew_save', ['id' => $hA, 'full_name' => 'ALPHA, HELPER', 'person_type' => 'Helper']), "$role cannot change crew records", 403);
$fjs = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/modules/operations/fleet.js');
ok(!str_contains($fjs, 'duty_shift') && !str_contains($fjs, "['Shift'"), 'drivers/helpers screen: no shift field and no shift column');

// ---------------------------------------------------------------------
section('D. Daily Admin bell');
require_once dirname(__DIR__) . '/backend/Modules/Administration/Attendance.php';
run($p, 'DELETE FROM notifications');
$setting('attendance_day_start_hour', 0);   // reminder hour reached at any time of day
Attendance::remind($p);
$bell = one($p, "SELECT message, state FROM notifications WHERE dedupe_key=? AND user_id=?", ['att-' . $today . '-Driver', $adm['id']]);
ok($bell && str_contains($bell['message'], 'not marked for ' . $today) && in_array($bell['state'], ['Unread', 'Read'], true), 'unmarked crew: one daily bell for the Admin (no shift in the key)', json_encode($bell));
Attendance::remind($p);
ok((int)one($p, "SELECT COUNT(*) AS n FROM notifications WHERE dedupe_key=? AND user_id=?", ['att-' . $today . '-Driver', $adm['id']])['n'] === 1, 'no duplicate bell on refresh');
ok(!one($p, "SELECT 1 AS x FROM notifications WHERE dedupe_key='att-noshift'"), 'there is no "shift not assigned" bell any more');
$full = array_map(fn($r) => ['id' => (int)$r['id'], 'status' => 'Present'], Attendance::action($adm, 'list', ['kind' => 'Driver', 'duty_date' => $today])['rows']);
Attendance::action($adm, 'save', ['kind' => 'Driver', 'duty_date' => $today, 'items' => $full]);
Attendance::remind($p);
ok(one($p, "SELECT state FROM notifications WHERE dedupe_key=? AND user_id=?", ['att-' . $today . '-Driver', $adm['id']])['state'] === 'Resolved', 'everyone marked: bell resolved');
$setting('attendance_day_start_hour', null);

// ---------------------------------------------------------------------
section('E. Attendance Phase 1 behaviour kept');
fails(fn() => Attendance::action($adm, 'list', ['kind' => 'Driver', 'duty_date' => date('Y-m-d', strtotime('+1 day'))]), 'future attendance still refused', 409, 'future');
fails(fn() => Attendance::action($adm, 'save', ['kind' => 'Driver', 'duty_date' => $today, 'items' => [['id' => $dA, 'status' => 'Present']]]), 'partial roster still refused', 409, 'roster changed');
foreach (['operations_head', 'container_wingvan_dispatcher', 'finance'] as $role)
    fails(fn() => Attendance::action($U($role), 'list', ['kind' => 'Driver', 'duty_date' => $today]), "$role cannot open attendance", 403);
$m = insert($p, 'mechanics', ['mechanic_code' => 'MEC-T-1', 'full_name' => 'MECH, ONE']);
$m2 = insert($p, 'mechanics', ['mechanic_code' => 'MEC-T-2', 'full_name' => 'MECH, TWO', 'duty_shift' => 'Night']);
$m3 = insert($p, 'mechanics', ['mechanic_code' => 'MEC-T-3', 'full_name' => 'MECH, OFF', 'active' => 0]);
$ml = Attendance::action($adm, 'list', ['kind' => 'Mechanic', 'duty_date' => $today]);
ok(array_map('intval', array_column($ml['rows'], 'id')) == [$m, $m2], 'mechanic roster: every ACTIVE mechanic, with or without a shift value');
Attendance::action($adm, 'save', ['kind' => 'Mechanic', 'duty_date' => $today, 'items' => [['id' => $m, 'status' => 'Present'], ['id' => $m2, 'status' => 'Absent']]]);
ok(array_column(many($p, 'SELECT mechanic_id, status FROM attendance_daily WHERE duty_date=? AND mechanic_id IS NOT NULL', [$today]), 'status', 'mechanic_id') == [$m => 'Present', $m2 => 'Absent'], 'mechanic attendance can be marked without a shift');
$ch = one($p, "SELECT a.action, c.changes_json FROM audit_events a JOIN audit_event_changes c ON c.audit_event_id=a.id WHERE a.record_ref=? ORDER BY a.id DESC LIMIT 1", ['driver ' . $hA]);
ok($ch && $ch['action'] === 'attendance updated' && str_contains((string)$ch['changes_json'], 'Absent') && str_contains((string)$ch['changes_json'], 'Present'), 'correction Absent -> Present kept in the audit trail', json_encode($ch));
$yesterday = date('Y-m-d', strtotime('-1 day'));
Attendance::action($adm, 'save', ['kind' => 'Driver', 'duty_date' => $yesterday, 'items' => array_map(fn($r) => ['id' => (int)$r['id'], 'status' => 'Present'], Attendance::action($adm, 'list', ['kind' => 'Driver', 'duty_date' => $yesterday])['rows'])]);
ok((int)one($p, "SELECT COUNT(*) AS n FROM attendance_daily WHERE duty_date=? AND personnel_id IS NOT NULL", [$yesterday])['n'] === 5, 'a past date can be marked and read back (history)');

// ---------------------------------------------------------------------
section('F. Trip planning and dispatch use the daily mark');
$cid = insert($p, 'clients', ['company_name' => 'ATT CLIENT', 'status' => 'Active', 'created_by' => $ops['id']]);
insert($p, 'client_rates', ['client_id' => $cid, 'truck_type' => 'Wing Van', 'rate_per_km' => '50.00', 'minimum_charge' => '0.00', 'updated_by' => $U('billing')['id']]);
insert($p, 'vehicles', ['plate_number' => 'WV-1', 'truck_type' => 'Wing Van']);
run($p, "UPDATE personnel SET availability='Unavailable' WHERE id NOT IN (?,?)", [$dLegacy, $newAdm]);   // only a shift-less pair is free
$r = test_create_planned_trip($ops, ['client_id' => $cid, 'truck_type' => 'Wing Van', 'pickup_point' => 'A', 'delivery_point' => 'B', 'scheduled_at' => date('Y-m-d\TH:i', time() + 86400), 'distance_km' => '10']);
$t = one($p, 'SELECT * FROM trips WHERE id=?', [$r['trip_id']]);
ok($t['status'] === 'Pre-Assigned' && (int)$t['driver_id'] === $dLegacy && (int)$t['helper_id'] === $newAdm, 'auto-assignment picks a driver and a helper that have no shift (a legacy "Night" value is irrelevant)');
$det = Trips::action($ops, 'details', ['trip_id' => $t['id']]);
ok(!preg_grep('/attendance|Present|shift/i', $det['trip']['dispatch_blockers']), 'Warn mode (default): attendance never blocks Confirm Dispatch');
ok(CrewAttendance::mode() === CrewAttendance::MODE_WARN, 'default attendance check is Warn (enforcement suite: run_dispatch_attendance_tests.php)');
$ca = $det['trip']['crew_attendance'];
ok(isset($ca['crew']) && $ca['crew'] && !array_filter(array_column($ca['crew'], 'duty_shift')) && !array_filter(array_column($ca['crew'], 'outside_scheduled_shift')), 'trip details carry no permanent personnel shift (Day/Night only labels the dispatch time)');
$js = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/attendance.js');
ok(str_contains($js, 'Crew Attendance (Drivers and Helpers)') && !str_contains($js, 'duty_shift') && !str_contains($js, 'Night Shift'), 'attendance modal: crew title, date only (no shift select)');

// ---------------------------------------------------------------------
section('G. Migration: legacy shift-based history is preserved; rollback');
$baseSchema = __DIR__ . '/_base_schema.sql'; $baseExt = __DIR__ . '/_base_extension.sql';
if (!is_file($baseSchema) || !is_file($baseExt)) {
    echo "  NOTE  git history not available: migration upgrade check skipped (run from a git clone)\n";
} else {
    test_recreate($UPGRADE);
    $up = test_conn($UPGRADE);
    test_import($up, (string)file_get_contents($baseSchema));
    test_import($up, (string)file_get_contents($baseExt));
    $chain = test_migrations_from('2026-11-08_08_attendance');
    ok(in_array('2026-11-10_11_daily_attendance', $chain, true) && array_search('2026-11-10_11_daily_attendance', $chain, true) > array_search('2026-11-08_08_attendance', $chain, true), 'the daily-attendance migration follows 08 in the chain (' . implode(', ', $chain) . ')');
    foreach ($chain as $mig) { if ($mig !== '2026-11-10_11_daily_attendance') test_import($up, test_sql("database/migrations/$mig.sql")); }
    $uA = insert($up, 'users', ['username' => 'a1', 'email' => 'a1@example.test', 'password_hash' => 'x', 'full_name' => 'ADMIN ONE', 'role' => 'admin']);
    $uB = insert($up, 'users', ['username' => 'a2', 'email' => 'a2@example.test', 'password_hash' => 'x', 'full_name' => 'ADMIN TWO', 'role' => 'admin']);
    $pe = fn(string $n, ?string $sh) => insert($up, 'personnel', ['full_name' => $n, 'person_type' => 'Driver', 'duty_shift' => $sh]);
    $p1 = $pe('ONE DAY ONLY', 'Day'); $p2 = $pe('NIGHT ONLY', 'Night'); $p3 = $pe('BOTH SHIFTS PRESENT/ABSENT', 'Day'); $p4 = $pe('BOTH ABSENT', 'Night'); $p5 = $pe('NO SHIFT NOW', null);
    $mc = insert($up, 'mechanics', ['mechanic_code' => 'M-L', 'full_name' => 'MECH LEGACY', 'duty_shift' => 'Day']);
    $mk = fn($d, $sh, $col, $id, $st, $by, $at) => insert($up, 'attendance_marks', ['duty_date' => $d, 'duty_shift' => $sh, $col => $id, 'status' => $st, 'marked_by' => $by, 'marked_at' => $at]);
    $mk('2026-11-01', 'Day', 'personnel_id', $p1, 'Present', $uA, '2026-11-01 07:00:00');
    $mk('2026-11-01', 'Night', 'personnel_id', $p2, 'Absent', $uA, '2026-11-01 19:00:00');
    $mk('2026-11-02', 'Day', 'personnel_id', $p3, 'Absent', $uA, '2026-11-02 07:00:00');
    $mk('2026-11-02', 'Night', 'personnel_id', $p3, 'Present', $uB, '2026-11-02 19:00:00');
    $mk('2026-11-02', 'Day', 'personnel_id', $p4, 'Absent', $uA, '2026-11-02 07:00:00');
    $mk('2026-11-02', 'Night', 'personnel_id', $p4, 'Absent', $uB, '2026-11-02 19:30:00');
    $mk('2026-11-03', 'Day', 'mechanic_id', $mc, 'Present', $uA, '2026-11-03 07:00:00');
    $before = $up->query('SELECT id, duty_date, duty_shift, personnel_id, mechanic_id, status, marked_by, marked_at FROM attendance_marks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $personnelBefore = $up->query('SELECT id, duty_shift FROM personnel ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    test_import($up, test_sql('database/migrations/2026-11-10_11_daily_attendance.sql'));
    ok((bool)one($up, "SELECT id FROM schema_migrations WHERE id='2026-11-10_11_daily_attendance'"), 'migration recorded itself in schema_migrations');
    ok($up->query('SELECT id, duty_date, duty_shift, personnel_id, mechanic_id, status, marked_by, marked_at FROM attendance_marks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $before, 'legacy attendance_marks rows are untouched (same rows, same shifts)');
    ok($up->query('SELECT id, duty_shift FROM personnel ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR) == $personnelBefore, 'personnel.duty_shift values are untouched');
    $d = fn($date, $col, $id) => one($up, "SELECT status, marked_by, legacy_shifts FROM attendance_daily WHERE duty_date=? AND $col=?", [$date, $id]);
    ok($d('2026-11-01', 'personnel_id', $p1) == ['status' => 'Present', 'marked_by' => $uA, 'legacy_shifts' => 'Day'], 'a single Day mark becomes that day\'s mark (legacy_shifts = Day)');
    ok($d('2026-11-01', 'personnel_id', $p2) == ['status' => 'Absent', 'marked_by' => $uA, 'legacy_shifts' => 'Night'], 'a single Night mark becomes that day\'s mark (legacy_shifts = Night)');
    ok($d('2026-11-02', 'personnel_id', $p3) == ['status' => 'Present', 'marked_by' => $uB, 'legacy_shifts' => 'Day+Night'], 'Day Absent + Night Present on one date -> ONE daily row, Present (any Present wins), latest marker kept');
    ok($d('2026-11-02', 'personnel_id', $p4) == ['status' => 'Absent', 'marked_by' => $uB, 'legacy_shifts' => 'Day+Night'], 'Absent on both shifts -> Absent');
    ok($d('2026-11-03', 'mechanic_id', $mc) == ['status' => 'Present', 'marked_by' => $uA, 'legacy_shifts' => 'Day'], 'mechanic marks are carried over too');
    ok((int)$up->query('SELECT COUNT(*) FROM attendance_daily')->fetchColumn() === 5, 'one daily row per person per date (7 legacy rows -> 5 daily rows)');
    $dupFail = false;
    try { $up->exec("INSERT INTO attendance_daily (duty_date, personnel_id, status, marked_by) VALUES ('2026-11-01', $p1, 'Absent', $uA)"); } catch (PDOException $e) { $dupFail = true; }
    ok($dupFail, 'the database refuses a second mark for the same person and date');
    $fa = test_fingerprint($p, $FRESH); $fb = test_fingerprint($up, $UPGRADE);
    foreach ($fa as $k => $v) ok($v === $fb[$k], "fresh install and upgraded database have identical $k", count(array_diff($v, $fb[$k])) . ' / ' . count(array_diff($fb[$k], $v)) . ' differences');
    test_import($up, test_sql('database/migrations/rollback/2026-11-10_11_daily_attendance_rollback.sql'));
    ok(!$up->query("SHOW TABLES LIKE 'attendance_daily'")->fetch() && $up->query('SELECT id, duty_date, duty_shift, personnel_id, mechanic_id, status, marked_by, marked_at FROM attendance_marks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $before,
       'rollback drops only attendance_daily; the legacy rows are still there for the old code');
}

echo "\n----------------------------------------\n";
echo "RESULT: $PASS passed, $FAIL failed\n";
if ($FAILED) { echo "Failed:\n"; foreach ($FAILED as $f) echo "  - $f\n"; }
test_drop($FRESH);
exit($FAIL === 0 ? 0 : 1);
