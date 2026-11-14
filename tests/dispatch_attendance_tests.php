<?php
/*
 * =====================================================================
 *  tests/dispatch_attendance_tests.php  —  integrated attendance at Confirm Dispatch (luis + kenneth)
 * =====================================================================
 *  Do NOT run this file directly: run tests/run_dispatch_attendance_tests.php.
 *  Agreed behaviour (no new approval step):
 *    - crew must hold an open Dispatcher Time In session in the trip's group (both Warn and Enforce modes);
 *    - an Admin Absent mark (daily attendance) blocks Time In, assignment and Confirm Dispatch;
 *    - an Admin Present mark is NOT an additional prerequisite;
 *    - Day / Night only label Time In sessions; daily attendance stays one mark per person per date.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/backend/Actions.php';
require_once __DIR__ . '/_launcher.php';
core('Access'); core('Settings'); core('CrewAttendance');
require_once dirname(__DIR__) . '/backend/Modules/Operations/Inspections/Checklist.php';

$DB = envValue('DB_NAME');
test_guard([$DB]);
$PASS = 0; $FAIL = 0;
function ok(bool $c, string $name, string $detail = ''): void {
    global $PASS, $FAIL;
    if ($c) { $PASS++; echo "  PASS  $name\n"; } else { $FAIL++; echo "  FAIL  $name" . ($detail !== '' ? "  -> $detail" : '') . "\n"; }
}
function fails(callable $fn, string $name, ?int $code = null, string $text = ''): void {
    try { $fn(); ok(false, $name, 'no error was raised'); }
    catch (BusinessRuleException $e) { ok(($code === null || $e->httpStatus === $code) && ($text === '' || str_contains($e->getMessage(), $text)), $name, "got {$e->httpStatus}: {$e->getMessage()}"); }
    catch (Throwable $e) { ok(false, $name, get_class($e) . ': ' . $e->getMessage()); }
}
function section(string $t): void { echo "\n== $t ==\n"; }
function key36(): string { $h = bin2hex(random_bytes(16)); return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12); }

section('Setup');
test_recreate($DB);
$p = db();
test_import($p, test_sql('database/schema.sql'));
test_import($p, test_sql('database/extension.sql'));
$users = [];
foreach (['admin' => null, 'operations_head' => null, 'container_wingvan_dispatcher' => 'Day', 'finance' => null] as $role => $shift) {
    $id = insert($p, 'users', ['username' => 'att_' . $role, 'email' => "att_$role@example.test", 'password_hash' => 'x', 'full_name' => 'ATT ' . $role, 'role' => $role, 'shift' => $shift]);
    $users[$role] = ['id' => $id, 'role' => $role, 'shift' => $shift, 'full_name' => 'ATT ' . $role, 'username' => 'att_' . $role, 'email' => "att_$role@example.test"];
}
['admin' => $adm, 'operations_head' => $ops, 'container_wingvan_dispatcher' => $wvd, 'finance' => $fin] = $users;
foreach (['Trips', 'Funds', 'Admin', 'Attendance'] as $m) Actions::load($m);
$cid = insert($p, 'clients', ['company_name' => 'ATTENDANCE CLIENT', 'status' => 'Active', 'created_by' => $ops['id']]);
for ($i = 1; $i <= 5; $i++) {
    insert($p, 'vehicles', ['plate_number' => "ATT-00$i", 'truck_type' => 'Wing Van']);
    insert($p, 'personnel', ['full_name' => "ATT Driver $i", 'person_type' => 'Driver', 'license_number' => "ATT-LIC-$i", 'license_expiry' => '2099-12-31']);
    insert($p, 'personnel', ['full_name' => "ATT Helper $i", 'person_type' => 'Helper']);
}
Admin::action($adm, 'setting_save', ['key' => 'inspection_min_minutes', 'value' => '0']);
Admin::action($adm, 'setting_save', ['key' => 'dispatch_early_minutes', 'value' => '240']);
foreach (['diesel' => '60', 'Wing Van' => '3.5'] as $k => $v) Admin::action($adm, 'pricing_save', ['setting_key' => $k, 'value' => $v, 'effective_date' => date('Y-m-d'), 'reference' => 'Attendance fixture']);
Admin::action($adm, 'pricing_save', ['setting_key' => 'garage', 'lat' => 14.12, 'lng' => 121.07, 'verified' => '1', 'effective_date' => date('Y-m-d'), 'reference' => 'Attendance fixture']);
Settings::clearCache();
$items = [];
foreach (Checklist::sections() as $s) foreach ($s['items'] as $i => $label) $items[$s['key'] . '_' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT)] = ['condition' => 'OK', 'remarks' => ''];
$trip = fn(int $id) => one($p, 'SELECT * FROM trips WHERE id=?', [$id]);
/** Like the Admin screen: load the date's roster, change one person's mark, save the whole roster. */
$mark = function (int $pid, ?string $status, ?string $date = null) use ($adm): void {
    $date ??= date('Y-m-d');
    $rows = Attendance::action($adm, 'list', ['kind' => 'Driver', 'duty_date' => $date])['rows'];
    $items = array_map(fn($r) => ['id' => (int)$r['id'], 'status' => (int)$r['id'] === $pid ? $status : $r['status']], $rows);
    Attendance::action($adm, 'save', ['kind' => 'Driver', 'duty_date' => $date, 'items' => $items]);
};
/** Planned, assigned (crew timed in), funded and checklisted trip, ready for Confirm Dispatch. */
$ready = function (int $n) use ($ops, $wvd, $fin, $cid, $items): int {
    $tid = (int)test_create_planned_trip($ops, ['client_id' => $cid, 'truck_type' => 'Wing Van', 'pickup_point' => "ATT pickup $n", 'delivery_point' => "ATT delivery $n",
        'scheduled_at' => date('Y-m-d\TH:i', time() + 3600), 'distance_km' => '30', 'request_key' => key36()])['trip_id'];
    $f = Funds::action($wvd, 'submit', ['trip_id' => $tid, 'categories' => ['Driver Meal Allowance', 'Helper Meal Allowance']]);
    Funds::action($ops, 'decide', ['fund_id' => $f['fund_id'], 'decision' => 'approve']);
    Funds::action($fin, 'release', ['fund_id' => $f['fund_id']]);
    Trips::action($wvd, 'checklist_start', ['trip_id' => $tid, 'kind' => 'Pre-Dispatch']);
    Trips::action($wvd, 'checklist', ['trip_id' => $tid, 'items' => $items, 'meta' => [], 'odometer_km' => '1000', 'fuel_level' => 'Full']);
    return $tid;
};

// ---------------------------------------------------------------------
section('A. Time In sessions; no Admin Present mark needed (Warn and Enforce)');
$T1 = $ready(1); $t1 = $trip($T1);
$session = one($p, 'SELECT * FROM dispatcher_attendance_sessions WHERE personnel_id=? AND time_out IS NULL', [$t1['driver_id']]);
$label = CrewAttendance::shiftFor(strtotime($session['time_in']));
ok($session && $session['dispatcher_role'] === 'container_wingvan_dispatcher' && $session['duty_shift'] === $label['shift'] && in_array($session['duty_shift'], ['Day', 'Night'], true),
   'Time In session carries the Dispatcher group and a Day/Night label from the configured hours', json_encode([$session['duty_shift'] ?? null, $label]));
ok(!one($p, 'SELECT 1 AS x FROM attendance_daily WHERE personnel_id IN (?,?)', [$t1['driver_id'], $t1['helper_id']]), 'no Admin daily mark exists for the crew');
Admin::action($adm, 'setting_save', ['key' => 'crew_attendance_dispatch_check', 'value' => '2']); Settings::clearCache();
Trips::action($wvd, 'dispatch', ['trip_id' => $T1]);
ok($trip($T1)['status'] === 'Dispatched', 'Enforce mode: timed-in crew dispatch without an Admin Present mark');
$row = one($p, 'SELECT duty_date FROM dispatcher_attendance_sessions WHERE id=?', [$session['id']]);
ok($row['duty_date'] === $label['date'], 'session date follows the same Day/Night label rule (labels only)');

// ---------------------------------------------------------------------
section('B. An Admin Absent mark blocks dispatch even with an open Time In');
$T2 = $ready(2); $t2 = $trip($T2);
$mark((int)$t2['helper_id'], 'Absent');
ok(one($p, 'SELECT status FROM attendance_daily WHERE personnel_id=? AND duty_date=?', [$t2['helper_id'], date('Y-m-d')])['status'] === 'Absent', 'Admin daily attendance recorded Absent (one mark per person per date)');
fails(fn() => Trips::action($wvd, 'dispatch', ['trip_id' => $T2]), 'Enforce mode: Confirm Dispatch refused for an Absent helper', 409, 'Absent');
Admin::action($adm, 'setting_save', ['key' => 'crew_attendance_dispatch_check', 'value' => '1']); Settings::clearCache();
fails(fn() => Trips::action($wvd, 'dispatch', ['trip_id' => $T2]), 'Warn mode: still refused (Absent is a blocker in both modes)', 409, 'Absent');
ok($trip($T2)['status'] === 'Ready for Dispatch' && !one($p, 'SELECT 1 AS x FROM billings WHERE trip_id=?', [$T2]), 'refused dispatch changes nothing (no billing draft)');
$mark((int)$t2['helper_id'], null);
Trips::action($wvd, 'dispatch', ['trip_id' => $T2]);
ok($trip($T2)['status'] === 'Dispatched', 'after the Admin clears the mark, the same crew dispatches');

// ---------------------------------------------------------------------
section('C. Absent blocks Time In; back-dated marks do not block today');
$free = one($p, "SELECT pe.id FROM personnel pe JOIN vehicles v ON v.assigned_helper_id=pe.id WHERE pe.person_type='Helper'
                 AND NOT EXISTS (SELECT 1 FROM dispatcher_attendance_sessions s WHERE s.personnel_id=pe.id AND s.time_out IS NULL) LIMIT 1");
if (!$free) {   // link the spare helper to the spare truck (master data), as the planning fixture does
    $h = (int)one($p, "SELECT id FROM personnel WHERE person_type='Helper' AND id NOT IN (SELECT assigned_helper_id FROM vehicles WHERE assigned_helper_id IS NOT NULL) LIMIT 1")['id'];
    run($p, 'UPDATE vehicles SET assigned_helper_id=? WHERE id=(SELECT id FROM (SELECT id FROM vehicles WHERE assigned_helper_id IS NULL LIMIT 1) x)', [$h]);
    $free = ['id' => $h];
}
$mark((int)$free['id'], 'Absent');
fails(fn() => Attendance::action($wvd, 'time_in', ['personnel_id' => (int)$free['id'], 'request_key' => key36()]), 'Time In refused while marked Absent today', 409, 'Absent');
$mark((int)$free['id'], null);
$mark((int)$free['id'], 'Absent', date('Y-m-d', strtotime('-1 day')));
Attendance::action($wvd, 'time_in', ['personnel_id' => (int)$free['id'], 'request_key' => key36()]);
ok((bool)one($p, 'SELECT 1 AS x FROM dispatcher_attendance_sessions WHERE personnel_id=? AND time_out IS NULL', [$free['id']]), "yesterday's Absent mark does not block today's Time In");

// ---------------------------------------------------------------------
// Ported from the retired crew_dispatch suite (sections A, I, J, L), rewritten for Time In sessions.
section('D. Warn / Enforce setting (ported A)');
Admin::action($adm, 'setting_save', ['key' => 'crew_attendance_dispatch_check', 'value' => '1']); Settings::clearCache();
foreach (['3', '0', '1.5', 'Enforce', ''] as $v) fails(fn() => Admin::action($adm, 'setting_save', ['key' => 'crew_attendance_dispatch_check', 'value' => $v]), "mode value '$v' refused", 400);
fails(fn() => Admin::action($ops, 'setting_save', ['key' => 'crew_attendance_dispatch_check', 'value' => '2']), 'Operations Head cannot change the mode', 403);
Admin::action($adm, 'setting_save', ['key' => 'crew_attendance_dispatch_check', 'value' => '2']); Settings::clearCache();
$a = one($p, "SELECT c.changes_json FROM audit_events a JOIN audit_event_changes c ON c.audit_event_id=a.id WHERE a.action='setting changed' ORDER BY a.id DESC LIMIT 1");
ok(CrewAttendance::mode() === CrewAttendance::MODE_ENFORCE && str_contains((string)$a['changes_json'], '"Warn"') && str_contains((string)$a['changes_json'], '"Enforce"'), 'Warn -> Enforce saved and in the Activity Log');

section('E. Attendance changed at the same moment as Confirm Dispatch (ported I, two processes)');
$T3 = $ready(3); $t3 = $trip($T3);
$mark((int)$t3['helper_id'], 'Present');   // allowed, not required
$child = __DIR__ . '/_dispatch_child.php';
file_put_contents($child, '<?php
declare(strict_types=1);
require dirname(__DIR__) . "/backend/Actions.php";
Actions::load("Trips");
$u = json_decode($argv[1], true);
try { $r = Trips::action($u, "dispatch", ["trip_id" => (int)$argv[2]]); echo json_encode(["ok" => true, "message" => $r["message"]]); }
catch (BusinessRuleException $e) { echo json_encode(["ok" => false, "code" => $e->httpStatus, "message" => $e->getMessage()]); }
catch (Throwable $e) { echo json_encode(["ok" => false, "code" => 0, "message" => get_class($e) . ": " . $e->getMessage()]); }
');
$adminConn = test_conn($DB);
$adminConn->beginTransaction();
$adminConn->prepare("UPDATE attendance_daily SET status='Absent', marked_at=NOW() WHERE personnel_id=? AND duty_date=?")->execute([$t3['helper_id'], date('Y-m-d')]);   // not committed yet
$pipes = [];
$proc = proc_open([PHP_BINARY, $child, json_encode($wvd), (string)$T3], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
usleep(1500000);
$waiting = (int)$p->query("SELECT COUNT(*) FROM information_schema.processlist WHERE db='$DB' AND command IN ('Query','Execute') AND info LIKE '%attendance_daily%' AND id<>CONNECTION_ID()")->fetchColumn();
$adminConn->commit();
$out = json_decode((string)stream_get_contents($pipes[1]), true) ?: ['ok' => false, 'message' => stream_get_contents($pipes[2])];
proc_close($proc); @unlink($child);
ok($waiting >= 1, 'Confirm Dispatch waited for the unsaved attendance change', "waiting=$waiting");
ok(!$out['ok'] && ($out['code'] ?? 0) === 409 && str_contains($out['message'], 'Absent'), 'it then saw the new Absent mark and refused (no stale decision)', json_encode($out));
ok($trip($T3)['status'] === 'Ready for Dispatch' && !one($p, 'SELECT 1 AS x FROM billings WHERE trip_id=?', [$T3]), 'trip not dispatched, no billing');

section('F. Readiness report (ported J)');
$rd = Attendance::action($adm, 'readiness', []);
ok($rd['mode'] === 'Enforce' && in_array($t3['trip_ref'], array_column($rd['trips'], 'trip_ref'), true), 'readiness lists the upcoming trip with Absent crew');
fails(fn() => Attendance::action($ops, 'readiness', []), 'readiness is Admin-only', 403);
$mark((int)$t3['helper_id'], null);
run($p, 'DELETE FROM inspections WHERE trip_id=?', [$T3]);
fails(fn() => Trips::action($wvd, 'dispatch', ['trip_id' => $T3]), 'timed-in crew but no checklist for the attempt: still refused', 409, 'No pre-dispatch checklist');

section('G. Required crew at Confirm Dispatch (ported L, both modes, direct API)');
$T4 = $ready(4); $t4 = $trip($T4);
foreach (['Warn' => '1', 'Enforce' => '2'] as $m => $v) {
    Admin::action($adm, 'setting_save', ['key' => 'crew_attendance_dispatch_check', 'value' => $v]); Settings::clearCache();
    run($p, 'UPDATE trips SET helper_id=NULL WHERE id=?', [$T4]);
    fails(fn() => Trips::action($wvd, 'dispatch', ['trip_id' => $T4]), "$m: Wing Van without a helper refused", 409, 'No Helper assigned');
    run($p, 'UPDATE trips SET helper_id=? WHERE id=?', [$t4['helper_id'], $T4]);
    run($p, 'UPDATE personnel SET active=0 WHERE id=?', [$t4['helper_id']]);
    fails(fn() => Trips::action($wvd, 'dispatch', ['trip_id' => $T4]), "$m: inactive helper refused (even though timed in)", 409);
    run($p, 'UPDATE personnel SET active=1 WHERE id=?', [$t4['helper_id']]);
    ok($trip($T4)['status'] === 'Ready for Dispatch' && !one($p, 'SELECT 1 AS x FROM billings WHERE trip_id=?', [$T4]), "$m: refused dispatches changed nothing");
}
Trips::action($wvd, 'dispatch', ['trip_id' => $T4]);
ok($trip($T4)['status'] === 'Dispatched', 'complete, timed-in crew: dispatched');

echo "\nPASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL ? 1 : 0);
