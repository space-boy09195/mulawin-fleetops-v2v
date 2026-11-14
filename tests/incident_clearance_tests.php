<?php
/*
 * =====================================================================
 *  tests/incident_clearance_tests.php  —  INCIDENT_CLEARANCE_V1
 * =====================================================================
 *  Do NOT run this file directly: run tests/run_incident_clearance_tests.php (temporary copy, own .env, *_test database).
 *  Real actions only. Minor / Major = damage classification (Maintenance.assess); Fit / Not Fit to Continue = Motorpool's
 *  clearance (Maintenance.clearance; recorded automatically as Not Fit by a Major assessment); Trips.resume needs Fit.
 *    A. Minor -> repair verified -> Fit to Continue -> Dispatcher resumes (actors, times, history, truck state)
 *    B. Minor -> Not Fit to Continue -> trip stays blocked; later verified repair -> Fit (both decisions kept)
 *    C. Major -> Not Fit recorded -> For Reschedule -> linked repair -> verification -> Operations reschedules
 *    D. Unauthorized: Dispatchers, Ops Head, Admin, Audit cannot record clearance; other group cannot resume;
 *       Motorpool cannot resume or reschedule
 *    E. Duplicate and stale clearance refused (a new incident needs its own clearance)
 *    F. Head Management and Audit read the outcome; schema rules hold
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/backend/Actions.php';
require_once __DIR__ . '/_launcher.php';
core('Access'); core('Settings'); core('FleetState');
require_once dirname(__DIR__) . '/backend/Modules/Operations/Inspections/Checklist.php';

$DB = envValue('DB_NAME');
test_guard([$DB]);
$PASS = 0; $FAIL = 0;
function ok(bool $c, string $name, string $detail = ''): void {
    global $PASS, $FAIL;
    if ($c) { $PASS++; echo "  PASS  $name\n"; } else { $FAIL++; echo "  FAIL  $name" . ($detail !== '' ? "  -> $detail" : '') . "\n"; }
}
function fails(callable $fn, string $name, ?int $code = null): void {
    try { $fn(); ok(false, $name, 'no error was raised'); }
    catch (BusinessRuleException $e) { ok($code === null || $e->httpStatus === $code, $name, "got {$e->httpStatus}: {$e->getMessage()}"); }
    catch (Throwable $e) { ok(false, $name, get_class($e) . ': ' . $e->getMessage()); }
}
function section(string $t): void { echo "\n== $t ==\n"; }
function key36(): string { $h = bin2hex(random_bytes(16)); return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12); }

section('Setup');
test_recreate($DB);
$p = db();
ok($p->query('SELECT DATABASE()')->fetchColumn() === $DB, 'db() is the disposable test database');
test_import($p, test_sql('database/schema.sql'));
test_import($p, test_sql('database/extension.sql'));
$users = [];
foreach (['admin' => null, 'operations_head' => null, 'container_wingvan_dispatcher' => 'Day', 'car_carrier_dispatcher' => 'Day', 'finance' => null,
          'motorpool' => null, 'audit' => null] as $role => $shift) {
    $id = insert($p, 'users', ['username' => 'clr_' . $role, 'email' => "clr_$role@example.test", 'password_hash' => 'x', 'full_name' => 'CLR ' . $role, 'role' => $role, 'shift' => $shift]);
    $users[$role] = ['id' => $id, 'role' => $role, 'shift' => $shift, 'full_name' => 'CLR ' . $role, 'username' => 'clr_' . $role, 'email' => "clr_$role@example.test"];
}
['admin' => $adm, 'operations_head' => $ops, 'container_wingvan_dispatcher' => $wvd, 'car_carrier_dispatcher' => $ccd, 'finance' => $fin, 'motorpool' => $mp, 'audit' => $aud] = $users;
foreach (['Trips', 'Funds', 'Maintenance', 'Mechanics', 'Oversight', 'Admin', 'Attendance'] as $m) Actions::load($m);
$cid = insert($p, 'clients', ['company_name' => 'CLEARANCE CLIENT', 'status' => 'Active', 'created_by' => $ops['id'], 'payment_terms_days' => 15]);
for ($i = 1; $i <= 7; $i++) {
    insert($p, 'vehicles', ['plate_number' => "CLR-00$i", 'truck_type' => 'Wing Van']);
    insert($p, 'personnel', ['full_name' => "CLR Driver $i", 'person_type' => 'Driver', 'license_number' => "CLR-LIC-$i", 'license_expiry' => '2099-12-31']);
    insert($p, 'personnel', ['full_name' => "CLR Helper $i", 'person_type' => 'Helper']);
}
Admin::action($adm, 'setting_save', ['key' => 'inspection_min_minutes', 'value' => '0']);
Admin::action($adm, 'setting_save', ['key' => 'dispatch_early_minutes', 'value' => '240']);
foreach (['diesel' => '60', 'Wing Van' => '3.5'] as $k => $v) Admin::action($adm, 'pricing_save', ['setting_key' => $k, 'value' => $v, 'effective_date' => date('Y-m-d'), 'reference' => 'Clearance fixture']);
Admin::action($adm, 'pricing_save', ['setting_key' => 'garage', 'lat' => 14.12, 'lng' => 121.07, 'verified' => '1', 'effective_date' => date('Y-m-d'), 'reference' => 'Clearance fixture']);
Settings::clearCache();
insert($p, 'client_rates', ['client_id' => $cid, 'truck_type' => 'Wing Van', 'rate_per_km' => '55', 'minimum_charge' => '0', 'updated_by' => $ops['id']]);
$route = insert($p, 'client_routes', ['route_ref' => 'CLR-ROUTE', 'client_id' => $cid, 'truck_type' => 'Wing Van', 'origin_label' => 'Garage pickup', 'destination_label' => 'Destination',
    'origin_key' => 'clr origin', 'destination_key' => 'clr destination', 'origin_lat' => 14.12, 'origin_lng' => 121.07, 'destination_lat' => 14.30, 'destination_lng' => 121.10,
    'distance_km' => '40.00', 'distance_source' => 'Map', 'status' => 'Approved', 'requested_by' => $ops['id']]);
insert($p, 'client_route_rates', ['route_id' => $route, 'version_no' => 1, 'rate_type' => 'Flat', 'rate_amount' => '4000', 'status' => 'Approved', 'entered_by' => $ops['id'], 'effective_from' => date('Y-m-d H:i:s', time() - 60)]);
$mech = [];
foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $n) { $mech[$n] = (int)Mechanics::action($mp, 'save', ['full_name' => "CLR Mechanic $n"])['id']; Mechanics::action($mp, 'time_in', ['id' => $mech[$n]]); }
$tmpDir = sys_get_temp_dir() . '/fleet_clr_' . bin2hex(random_bytes(4)); mkdir($tmpDir);
$file = function (string $name) use ($tmpDir): array {
    $path = $tmpDir . '/' . bin2hex(random_bytes(6));
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    file_put_contents($path, $bytes);
    return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
};
$items = [];
foreach (Checklist::sections() as $s) foreach ($s['items'] as $i => $label) $items[$s['key'] . '_' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT)] = ['condition' => 'OK', 'remarks' => ''];
$trip = fn(int $id) => one($p, 'SELECT * FROM trips WHERE id=?', [$id]);
$inc = fn(int $id) => one($p, 'SELECT * FROM incidents WHERE id=?', [$id]);
$truck = fn(int $vid) => one($p, 'SELECT status FROM vehicles WHERE id=?', [$vid])['status'];
$count = fn(string $sql, array $a = []) => (int)one($p, $sql, $a)['n'];

$dispatchTrip = function (int $n) use ($ops, $wvd, $fin, $cid, $items): int {
    $tid = (int)test_create_planned_trip($ops, ['client_id' => $cid, 'truck_type' => 'Wing Van', 'pickup_point' => "CLR pickup $n", 'delivery_point' => "CLR delivery $n",
        'scheduled_at' => date('Y-m-d\TH:i', time() + 3600), 'distance_km' => '40', 'request_key' => key36()])['trip_id'];
    $f = Funds::action($wvd, 'submit', ['trip_id' => $tid, 'categories' => ['Driver Meal Allowance', 'Helper Meal Allowance']]);
    Funds::action($ops, 'decide', ['fund_id' => $f['fund_id'], 'decision' => 'approve']);
    Funds::action($fin, 'release', ['fund_id' => $f['fund_id']]);
    Trips::action($wvd, 'checklist_start', ['trip_id' => $tid, 'kind' => 'Pre-Dispatch']);
    Trips::action($wvd, 'checklist', ['trip_id' => $tid, 'items' => $items, 'meta' => [], 'odometer_km' => '1000', 'fuel_level' => 'Full']);
    Trips::action($wvd, 'dispatch', ['trip_id' => $tid]);
    return $tid;
};
/** Reports an incident (time not before dispatch, minute precision) and returns [incident id, repair record]. */
$report = function (int $tid, string $problem) use ($wvd, $p, $trip, $file): array {
    $t = $trip($tid);
    while (date('Y-m-d H:i:00') < $t['dispatched_at']) sleep(1);
    $_FILES = ['photos' => $file('damage.png')];
    try { $r = Trips::action($wvd, 'incident', ['trip_id' => $tid, 'incident_type' => 'Breakdown', 'incident_at' => date('Y-m-d\TH:i'), 'location' => 'SLEX KM 40', 'problem' => $problem, 'details' => 'Observed by the driver']); }
    finally { $_FILES = []; }
    return [(int)$r['incident_id'], one($p, 'SELECT * FROM maintenance_jobs WHERE incident_id=?', [$r['incident_id']])];
};
$verifyMsg = [];
$repairAndVerify = function (int $jobId) use ($mp, $file, &$verifyMsg): void {
    Maintenance::action($mp, 'inspect', ['job_id' => $jobId, 'findings' => 'Loose hose clamp', 'diagnosis' => 'Tighten and replace clamp']);
    $_FILES = ['evidence' => $file('fixed.png')];
    try { Maintenance::action($mp, 'complete', ['job_id' => $jobId, 'repair_performed' => 'Clamp replaced on site', 'completed_on' => date('Y-m-d')]); } finally { $_FILES = []; }
    $verifyMsg[$jobId] = Maintenance::action($mp, 'verify', ['job_id' => $jobId, 'result' => 'Passed', 'verification_notes' => 'Engine temperature normal after 10 min idle'])['message'];
};
$clear = fn(array $u, int $jobId, string $decision, string $findings = 'Checked hoses, coolant level and temperature') =>
    Maintenance::action($u, 'clearance', ['job_id' => $jobId, 'decision' => $decision, 'findings' => $findings]);

// ---------------------------------------------------------------------
section('A. Minor -> verified repair -> Fit to Continue -> Dispatcher resumes');
$T1 = $dispatchTrip(1); $t1 = $trip($T1);
[$I1, $J1] = $report($T1, 'Overheating');
ok($trip($T1)['status'] === 'On Hold - Incident Reported' && (int)$J1['vehicle_id'] === (int)$t1['vehicle_id'], 'incident: trip On Hold, one repair record for the trip truck');
fails(fn() => Trips::action($wvd, 'resume', ['trip_id' => $T1]), 'resume refused before any assessment', 409);
fails(fn() => $clear($mp, (int)$J1['id'], 'Fit to Continue'), 'clearance refused before the Minor / Major assessment', 409);
Maintenance::action($mp, 'assess', ['job_id' => $J1['id'], 'severity' => 'Minor', 'recommendation' => 'Repair on site', 'mechanic_id' => $mech['A']]);
ok($inc($I1)['severity'] === 'Minor' && $inc($I1)['clearance'] === null, 'Minor recorded as the damage classification; no clearance yet');
fails(fn() => $clear($mp, (int)$J1['id'], 'Fit to Continue'), 'Fit to Continue refused while the on-site repair is not completed and verified', 409);
$repairAndVerify((int)$J1['id']);
ok($inc($I1)['status'] === 'Under Repair' && $inc($I1)['clearance'] === null, 'a verified repair alone does NOT clear the truck (no automatic Fit)');
ok(!str_contains($verifyMsg[(int)$J1['id']], 'back in service') && str_contains($verifyMsg[(int)$J1['id']], 'Record the vehicle clearance'), 'verify message does not claim "back in service" while the trip waits for clearance', $verifyMsg[(int)$J1['id']]);
fails(fn() => Trips::action($wvd, 'resume', ['trip_id' => $T1]), 'resume still refused without a recorded clearance', 409);
$_FILES = ['evidence' => $file('clearance.png')];
try { $clear($mp, (int)$J1['id'], 'Fit to Continue', 'Road test 2 km, temperature normal, no leaks'); } finally { $_FILES = []; }
$i1 = $inc($I1);
ok($i1['clearance'] === 'Fit to Continue' && $i1['status'] === 'Repaired - Cleared to Continue' && (int)$i1['cleared_by'] === $mp['id'] && $i1['cleared_at'] !== null
   && $i1['clearance_findings'] === 'Road test 2 km, temperature normal, no leaks', 'Fit to Continue recorded with findings, Motorpool assessor and time');
ok($count("SELECT COUNT(*) AS n FROM documents WHERE entity_type='incident' AND entity_id=? AND category='Repair Evidence'", [$I1]) === 1, 'supporting evidence linked to the incident');
fails(fn() => $clear($mp, (int)$J1['id'], 'Fit to Continue'), 'duplicate Fit to Continue refused', 409);
fails(fn() => $clear($mp, (int)$J1['id'], 'Not Fit to Continue'), 'a recorded Fit to Continue is not overwritten', 409);
fails(fn() => Trips::action($ccd, 'resume', ['trip_id' => $T1]), 'a Dispatcher of the other group cannot resume', 403);
fails(fn() => Trips::action($mp, 'resume', ['trip_id' => $T1]), 'Motorpool cannot resume the trip', 403);
Trips::action($wvd, 'resume', ['trip_id' => $T1]);
$i1 = $inc($I1);
ok($trip($T1)['status'] === 'In Transit' && $i1['status'] === 'Resolved' && (int)$i1['resumed_by'] === $wvd['id'] && $i1['resumed_at'] !== null, 'Dispatcher resumes: In Transit, resumption actor and time recorded, incident history kept');
ok($truck((int)$t1['vehicle_id']) === 'On Trip', 'truck is On Trip again (same trip, no new trip created)');
ok($count('SELECT COUNT(*) AS n FROM trips') === 1 && (int)$trip($T1)['attempt_number'] === 1, 'no rescheduled trip or second attempt was created');
fails(fn() => Trips::action($wvd, 'resume', ['trip_id' => $T1]), 'resume twice refused', 409);

section('E. Stale clearance: a new incident needs its own clearance');
[$I1b, $J1b] = $report($T1, 'Brake noise');
ok($trip($T1)['status'] === 'On Hold - Incident Reported', 'second incident puts the trip On Hold again');
fails(fn() => Trips::action($wvd, 'resume', ['trip_id' => $T1]), 'the earlier Fit to Continue does not authorize the new incident', 409);
fails(fn() => $clear($mp, (int)$J1['id'], 'Fit to Continue'), 'clearance on the earlier incident record is refused', 409);
Maintenance::action($mp, 'assess', ['job_id' => $J1b['id'], 'severity' => 'Minor', 'recommendation' => 'Inspect brakes on site', 'mechanic_id' => $mech['A']]);
$repairAndVerify((int)$J1b['id']);
$clear($mp, (int)$J1b['id'], 'Fit to Continue', 'Brake pads within limits; noise from loose shield, fixed');
Trips::action($wvd, 'resume', ['trip_id' => $T1]);
ok($trip($T1)['status'] === 'In Transit' && $inc($I1b)['clearance'] === 'Fit to Continue' && $inc($I1)['clearance'] === 'Fit to Continue', 'each incident keeps its own clearance; trip resumed on the current one');

// ---------------------------------------------------------------------
section('B. Minor -> Not Fit to Continue -> trip stays blocked');
$T2 = $dispatchTrip(2); $t2 = $trip($T2);
[$I2, $J2] = $report($T2, 'Steering vibration');
Maintenance::action($mp, 'assess', ['job_id' => $J2['id'], 'severity' => 'Minor', 'recommendation' => 'Check steering on site', 'mechanic_id' => $mech['B']]);
fails(fn() => $clear($wvd, (int)$J2['id'], 'Fit to Continue'), 'the Dispatcher cannot record a clearance', 403);
$clear($mp, (int)$J2['id'], 'Not Fit to Continue', 'Tie-rod end loose; unsafe to drive until replaced');
$i2 = $inc($I2);
ok($i2['clearance'] === 'Not Fit to Continue' && $i2['severity'] === 'Minor' && $i2['status'] === 'Under Repair', 'Not Fit recorded; Minor classification kept; incident still open');
ok($trip($T2)['status'] === 'On Hold - Incident Reported', 'trip stays On Hold (no automatic reschedule)');
ok($truck((int)$t2['vehicle_id']) === 'Under Maintenance', 'Not Fit: the truck shows Under Maintenance (actual condition)');
fails(fn() => Trips::action($wvd, 'resume', ['trip_id' => $T2]), 'resume refused on Not Fit to Continue', 409);
fails(fn() => $clear($mp, (int)$J2['id'], 'Not Fit to Continue'), 'duplicate Not Fit refused', 409);
fails(fn() => $clear($mp, (int)$J2['id'], 'Fit to Continue'), 'Fit after Not Fit still needs the verified repair', 409);
$repairAndVerify((int)$J2['id']);
$clear($mp, (int)$J2['id'], 'Fit to Continue', 'Tie-rod end replaced; steering test OK');
ok($inc($I2)['clearance'] === 'Fit to Continue' && $truck((int)$t2['vehicle_id']) === 'On Trip', 'after the verified repair Motorpool records Fit to Continue; truck back with its trip');
ok($count("SELECT COUNT(*) AS n FROM trip_events WHERE trip_id=? AND event_type LIKE 'vehicle clearance:%'", [$T2]) === 2, 'both decisions stay in the trip history');
$aud2 = one($p, "SELECT e.id, e.actor_id, c.changes_json FROM audit_events e JOIN audit_event_changes c ON c.audit_event_id=e.id WHERE e.record_ref=? AND e.action='vehicle clearance recorded' ORDER BY e.id DESC LIMIT 1", ['incident ' . $I2]);
ok($aud2 && (int)$aud2['actor_id'] === $mp['id'] && str_contains($aud2['changes_json'], 'Not Fit to Continue') && str_contains($aud2['changes_json'], '"Fit to Continue"'), 'audit keeps the change Not Fit -> Fit with its actor', $aud2['changes_json'] ?? '');
Trips::action($wvd, 'resume', ['trip_id' => $T2]);
ok($trip($T2)['status'] === 'In Transit', 'trip resumes only after the Fit decision');

// ---------------------------------------------------------------------
section('C. Major -> Not Fit -> For Reschedule -> linked repair -> Operations reschedules');
$T3 = $dispatchTrip(3); $t3 = $trip($T3);
[$I3, $J3] = $report($T3, 'Gearbox failure');
Maintenance::action($mp, 'assess', ['job_id' => $J3['id'], 'severity' => 'Major', 'recommendation' => 'Tow to garage; gearbox overhaul', 'mechanic_id' => $mech['C']]);
$i3 = $inc($I3);
ok($i3['severity'] === 'Major' && $i3['clearance'] === 'Not Fit to Continue' && (int)$i3['cleared_by'] === $mp['id'] && $i3['clearance_findings'] === 'Tow to garage; gearbox overhaul', 'Major kept; Not Fit to Continue recorded by the assessor');
ok($trip($T3)['status'] === 'For Reschedule' && $truck((int)$t3['vehicle_id']) === 'Under Maintenance', 'trip For Reschedule; truck Under Maintenance');
ok((int)$J3['incident_id'] === $I3 && (int)$J3['trip_id'] === $T3 && $count('SELECT COUNT(*) AS n FROM maintenance_jobs WHERE incident_id=?', [$I3]) === 1, 'one repair record linked to the incident and trip');
fails(fn() => $clear($mp, (int)$J3['id'], 'Fit to Continue'), 'Fit to Continue refused for Major damage', 409);
fails(fn() => Trips::action($wvd, 'resume', ['trip_id' => $T3]), 'a For Reschedule trip cannot be resumed', 409);
$when = date('Y-m-d\TH:i', time() + 2 * 86400);
fails(fn() => Trips::action($mp, 'reschedule', ['trip_id' => $T3, 'scheduled_at' => $when]), 'Motorpool cannot reschedule the trip', 403);
fails(fn() => Trips::action($wvd, 'reschedule', ['trip_id' => $T3, 'scheduled_at' => $when]), 'rescheduling waits for the verified repair (truck still Under Maintenance)', 409);
Maintenance::action($mp, 'inspect', ['job_id' => $J3['id'], 'findings' => 'Gear teeth broken', 'diagnosis' => 'Overhaul']);
$_FILES = ['evidence' => $file('gearbox.png')];
try { Maintenance::action($mp, 'complete', ['job_id' => $J3['id'], 'repair_performed' => 'Gearbox overhauled', 'completed_on' => date('Y-m-d')]); } finally { $_FILES = []; }
ok($truck((int)$t3['vehicle_id']) === 'Under Maintenance', 'completed but unverified: still Under Maintenance');
Maintenance::action($mp, 'verify', ['job_id' => $J3['id'], 'result' => 'Passed', 'verification_notes' => 'Road test OK']);
ok($truck((int)$t3['vehicle_id']) === 'Assigned' && $inc($I3)['status'] === 'Resolved' && $inc($I3)['clearance'] === 'Not Fit to Continue', 'verified: truck back to its trip (Assigned); the Major decision is preserved');
Trips::action($wvd, 'reschedule', ['trip_id' => $T3, 'scheduled_at' => $when]);
ok($trip($T3)['status'] === 'Pre-Assigned' && (int)$trip($T3)['attempt_number'] === 2, 'Operations reschedules: Pre-Assigned, attempt #2');

// ---------------------------------------------------------------------
section('D. Unauthorized clearance and trip actions');
$T4 = $dispatchTrip(4);
[$I4, $J4] = $report($T4, 'Flat tire');
Maintenance::action($mp, 'assess', ['job_id' => $J4['id'], 'severity' => 'Minor', 'recommendation' => 'Change tire', 'mechanic_id' => $mech['D']]);
$repairAndVerify((int)$J4['id']);
foreach (['container_wingvan_dispatcher' => $wvd, 'car_carrier_dispatcher' => $ccd, 'operations_head' => $ops, 'admin (Head Management)' => $adm, 'audit' => $aud, 'finance' => $fin] as $label => $u)
    fails(fn() => $clear($u, (int)$J4['id'], 'Fit to Continue'), "$label cannot record a vehicle clearance", 403);
ok($inc($I4)['clearance'] === null, 'refused attempts changed nothing');
foreach (['admin (Head Management)' => $adm, 'audit' => $aud, 'operations_head' => $ops] as $label => $u)
    fails(fn() => Trips::action($u, 'resume', ['trip_id' => $T4]), "$label cannot resume a trip", 403);

// ---------------------------------------------------------------------
// ---------------------------------------------------------------------
section('G. Minor + Not Fit -> Operations Head authorizes For Reschedule (classification kept)');
$T5 = $dispatchTrip(5); $t5 = $trip($T5);
[$I5, $J5] = $report($T5, 'Clutch slipping');
Maintenance::action($mp, 'assess', ['job_id' => $J5['id'], 'severity' => 'Minor', 'recommendation' => 'Check clutch on site', 'mechanic_id' => $mech['E']]);
fails(fn() => Trips::action($ops, 'incident_reschedule', ['trip_id' => $T5, 'reason' => 'No clearance yet']), 'Ops Head cannot reschedule before Motorpool records Not Fit', 409);
$clear($mp, (int)$J5['id'], 'Not Fit to Continue', 'Clutch plate worn; cannot be fixed on site');
ok($truck((int)$t5['vehicle_id']) === 'Under Maintenance', 'Not Fit: truck Under Maintenance');
fails(fn() => Trips::action($wvd, 'incident_reschedule', ['trip_id' => $T5, 'reason' => 'x']), 'the Dispatcher cannot authorize For Reschedule', 403);
fails(fn() => Trips::action($mp, 'incident_reschedule', ['trip_id' => $T5, 'reason' => 'x']), 'Motorpool cannot move the trip to For Reschedule', 403);
fails(fn() => Trips::action($ops, 'incident_reschedule', ['trip_id' => $T5]), 'a reason is required', 400);
Trips::action($ops, 'incident_reschedule', ['trip_id' => $T5, 'reason' => 'Truck not fit; client agreed to a later delivery']);
ok($trip($T5)['status'] === 'For Reschedule' && $inc($I5)['severity'] === 'Minor' && $inc($I5)['clearance'] === 'Not Fit to Continue', 'For Reschedule; Minor classification and Not Fit decision preserved (no automatic Major)');
fails(fn() => Trips::action($ops, 'incident_reschedule', ['trip_id' => $T5, 'reason' => 'again']), 'duplicate For Reschedule refused', 409);
fails(fn() => $clear($mp, (int)$J5['id'], 'Fit to Continue'), 'no clearance once the trip is For Reschedule', 409);
fails(fn() => Trips::action($wvd, 'reschedule', ['trip_id' => $T5, 'scheduled_at' => date('Y-m-d\TH:i', time() + 2 * 86400)]), 'rescheduling waits for the verified repair', 409);
$repairAndVerify((int)$J5['id']);
ok($inc($I5)['status'] === 'Resolved' && $truck((int)$t5['vehicle_id']) === 'Assigned', 'verified repair: incident Resolved, truck back to its trip (Assigned)');
Trips::action($wvd, 'reschedule', ['trip_id' => $T5, 'scheduled_at' => date('Y-m-d\TH:i', time() + 2 * 86400)]);
ok($trip($T5)['status'] === 'Pre-Assigned' && (int)$trip($T5)['attempt_number'] === 2, 'the responsible Dispatcher completes the reschedule (attempt #2)');
ok($count("SELECT COUNT(*) AS n FROM audit_events WHERE action='for reschedule after not fit' AND actor_id=?", [$ops['id']]) === 1, 'Ops Head decision audited with its actor');

// ---------------------------------------------------------------------
section('H. Inspection-only Fit to Continue (no repair needed; nothing invented)');
$T6 = $dispatchTrip(6); $t6 = $trip($T6);
[$I6, $J6] = $report($T6, 'Warning light on dashboard');
Maintenance::action($mp, 'assess', ['job_id' => $J6['id'], 'severity' => 'Minor', 'recommendation' => 'Inspect sensor', 'mechanic_id' => $mech['F']]);
fails(fn() => $clear($mp, (int)$J6['id'], 'Fit to Continue'), 'without the inspection-only flag Fit still needs a verified repair', 409);
$_FILES = ['evidence' => $file('scan.png')];
try { Maintenance::action($mp, 'clearance', ['job_id' => $J6['id'], 'decision' => 'Fit to Continue', 'inspection_only' => '1', 'findings' => 'Loose sensor connector reseated by hand; scan shows no fault codes']); } finally { $_FILES = []; }
$j6 = one($p, 'SELECT * FROM maintenance_jobs WHERE id=?', [$J6['id']]);
ok($j6['status'] === 'Completed' && $j6['repair_performed'] === null && (int)$j6['verified_by'] === $mp['id'] && $j6['verified_at'] !== null && str_starts_with((string)$j6['verification_notes'], 'Inspection only'),
   'record closed as an inspection: no repair text, Motorpool accountability and time recorded');
ok($count('SELECT COUNT(*) AS n FROM maintenance_costs WHERE maintenance_job_id=?', [$J6['id']]) === 0 && $count('SELECT COUNT(*) AS n FROM part_requests WHERE maintenance_job_id=?', [$J6['id']]) === 0
   && $count("SELECT COUNT(*) AS n FROM expenses WHERE source='Maintenance' AND reference_number=?", [$j6['job_ref']]) === 0, 'no parts, costs, labor or expenses invented');
ok($inc($I6)['clearance'] === 'Fit to Continue' && $inc($I6)['status'] === 'Repaired - Cleared to Continue', 'Fit to Continue recorded on the incident');
ok($count("SELECT COUNT(*) AS n FROM mechanics WHERE id=? AND availability='Available'", [$mech['F']]) === 1, 'the mechanic is free again');
Trips::action($wvd, 'resume', ['trip_id' => $T6]);
ok($trip($T6)['status'] === 'In Transit', 'Dispatcher resumes after the inspection-only clearance');
// an inspection-only claim is refused once costs exist
[$I8, $J8] = (function () use ($dispatchTrip, $report) { $t = $dispatchTrip(8); return $report($t, 'Mirror loose'); })();
Maintenance::action($mp, 'assess', ['job_id' => $J8['id'], 'severity' => 'Minor', 'recommendation' => 'Fix mirror', 'mechanic_id' => $mech['A']]);
Maintenance::action($mp, 'add_cost', ['job_id' => $J8['id'], 'cost_type' => 'Labor', 'description' => 'Mirror bracket', 'amount' => '150', 'incurred_on' => date('Y-m-d')]);
fails(fn() => Maintenance::action($mp, 'clearance', ['job_id' => $J8['id'], 'decision' => 'Fit to Continue', 'inspection_only' => '1', 'findings' => 'claim no repair']), 'inspection-only refused once a cost was recorded (a repair took place)', 409);

section('F. Head Management and Audit read the outcome; schema rules');
foreach (['admin_operations', 'admin_maintenance', 'admin_overview'] as $pg) {
    $dd = Oversight::read($adm, $pg, []); ok(empty($dd['_errors']), "$pg loads with the clearance records");
}
$det = Trips::action($ops, 'details', ['trip_id' => $T1]);
$seen = array_column($det['incidents'], 'clearance', 'incident_ref');
ok(count($seen) === 2 && !in_array(null, $seen, true) && isset($det['incidents'][0]['cleared_by_name'], $det['incidents'][0]['resumed_by_name']), 'Operations Head sees each incident with clearance, assessor and resumption');
$md = Maintenance::action($mp, 'details', ['job_id' => $J2['id']]);
ok(($md['incident']['clearance'] ?? null) === 'Fit to Continue' && ($md['incident']['cleared_by_name'] ?? '') === $mp['full_name'], 'Motorpool repair details show the clearance and its assessor');
$h = Admin::read($aud, 'audit_history', ['record' => 'incident ' . $I2]);
ok(empty($h['_errors']), 'Audit reads the clearance history of the incident');
ok($count("SELECT COUNT(*) AS n FROM audit_events WHERE action='vehicle clearance recorded' AND actor_id=?", [$mp['id']]) === 6, 'every clearance action is audit-logged with the Motorpool actor (A, E, B x2, G Not Fit, H inspection-only = 6)');
ok($count("SELECT COUNT(*) AS n FROM audit_events WHERE action='incident assessed' AND record_ref=? AND actor_id=?", ['job ' . $J3['id'], $mp['id']]) === 1, 'the Major Not Fit decision is audited with its assessment');
try { run($p, "UPDATE incidents SET clearance='Fit to Continue' WHERE id=?", [$I4]); ok(false, 'DB refuses a clearance without assessor, time and findings'); }
catch (PDOException $e) { ok(true, 'DB refuses a clearance without assessor, time and findings'); }
try { run($p, "UPDATE incidents SET resumed_by=?, resumed_at=NOW() WHERE id=?", [$wvd['id'], $I3]); ok(false, 'DB refuses a resumption on a Not Fit clearance'); }
catch (PDOException $e) { ok(true, 'DB refuses a resumption on a Not Fit clearance'); }

array_map('unlink', glob($tmpDir . '/*') ?: []); @rmdir($tmpDir);
echo "\nPASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL ? 1 : 0);
