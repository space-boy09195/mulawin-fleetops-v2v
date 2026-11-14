<?php
/*
 * =====================================================================
 *  tests/maintenance_overview_tests.php  —  Maintenance Overview (Phase 1) + workflow regression
 * =====================================================================
 *  Do NOT run this file directly: run tests/run_maintenance_overview_tests.php (temporary copy,
 *  own .env, disposable *_test database only).
 *
 *  Groups:
 *    A. Page registration, menu labels and permissions (no new rights)
 *    B. Empty overview
 *    C. Garage report -> assign -> inspect -> cost -> parts -> complete -> verify (counts at every step)
 *    D. Parts requests: one count per request, Not Available follow-up, role-limited parts data
 *    E. Trip incident flow (assess Minor) and cancel
 *    F. Counts equal the Repair Management records (same rules as the ?view= filters)
 *    G. Recent activity: real events, plain labels, newest first, no duplicates, limit
 *    H. Read-only: the overview writes nothing; Repair Management data unchanged; ONLY_FULL_GROUP_BY
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/backend/Actions.php';
require_once __DIR__ . '/_launcher.php';
core('Access');
core('Settings');

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

// ---------------------------------------------------------------------
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
$mp = $U('motorpool'); $pur = $U('purchasing'); $ops = $U('operations_head'); $adm = $U('admin'); $aud = $U('audit'); $wvd = $U('container_wingvan_dispatcher');
foreach (['Maintenance', 'Mechanics', 'Purchasing', 'Trips'] as $m) Actions::load($m);

$cid = insert($p, 'clients', ['company_name' => 'MAINT CLIENT', 'status' => 'Active', 'created_by' => $ops['id']]);
insert($p, 'client_rates', ['client_id' => $cid, 'truck_type' => 'Wing Van', 'rate_per_km' => '50.00', 'minimum_charge' => '0.00', 'updated_by' => $U('billing')['id']]);
$V = [];
for ($i = 1; $i <= 6; $i++) $V[$i] = insert($p, 'vehicles', ['plate_number' => "MNT-$i", 'truck_number' => (string)(100 + $i), 'truck_type' => 'Wing Van']);
for ($i = 1; $i <= 3; $i++) { insert($p, 'personnel', ['full_name' => "DRIVER, NO $i", 'person_type' => 'Driver']); insert($p, 'personnel', ['full_name' => "HELPER, NO $i", 'person_type' => 'Helper']); }
$M = [];
foreach (['ALPHA MECH', 'BRAVO MECH', 'CHARLIE MECH'] as $name) $M[] = Mechanics::action($mp, 'save', ['full_name' => $name, 'specialization' => 'Engine'])['id'];
// Availability follows Mechanic Attendance: the test mechanics Time In before they can be assigned.
foreach ($M as $mid) Mechanics::action($mp, 'time_in', ['id' => $mid]);

// Upload simulation: real temporary files (Files::store accepts CLI files).
$tmpDir = sys_get_temp_dir() . '/fleet_maint_' . bin2hex(random_bytes(4)); mkdir($tmpDir);
$png = function (string $name) use ($tmpDir): array {
    $path = $tmpDir . '/' . bin2hex(random_bytes(6));
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    file_put_contents($path, $bytes);
    return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
};
$ov = fn(array $u) => Maintenance::read($u, 'maintenance_overview', []);
$sum = fn(array $u = null) => $ov($u ?? $mp)['summary'];
$job = fn(int $id) => one($p, 'SELECT * FROM maintenance_jobs WHERE id=?', [$id]);
$attFor = fn(array $o, string $ref) => array_values(array_filter($o['attention'], fn($r) => $r['job_ref'] === $ref || $r['request_ref'] === $ref));
$report = function (int $vid, ?int $mech = null, string $problem = 'Engine overheating') use ($mp, $png) {
    $_FILES = ['photos' => $png('damage.png')];
    $r = Maintenance::action($mp, 'report', ['vehicle_id' => $vid, 'problem' => $problem, 'reported_on' => date('Y-m-d')] + ($mech ? ['mechanic_id' => $mech] : []));
    $_FILES = [];
    return $r;
};

// ---------------------------------------------------------------------
section('A. Page registration, menu labels and permissions');
$pages = Access::pages();
ok(isset($pages['maintenance_overview']) && $pages['maintenance_overview'][3] === 'Maintenance' && $pages['maintenance_overview'][4] === 'maintenance/maintenance', 'overview page registered on the Maintenance module and screen file');
ok($pages['maintenance_overview'][5] === $pages['maintenance'][5], 'overview roles are exactly the Corrective Maintenance roles (no new rights)', json_encode($pages['maintenance_overview'][5]));
foreach (['admin' => true, 'operations_head' => true, 'motorpool' => true, 'audit' => true, 'purchasing' => false, 'finance' => false, 'billing' => false, 'car_carrier_dispatcher' => false, 'container_wingvan_dispatcher' => false] as $role => $allowed)
    ok(Access::canOpen($U($role), 'maintenance_overview') === $allowed, "$role " . ($allowed ? 'can' : 'cannot') . ' open the overview');
foreach (['maintenance', 'mechanics', 'trucks', 'maint_history'] as $pg)
    ok(Access::canOpen($mp, $pg), "motorpool still opens $pg");
// Role workspaces: the combined Parts & Purchasing page is split; Motorpool requests parts inside Repair Management only.
ok(!isset($pages['purchasing']), 'the combined Parts & Purchasing page no longer exists');
foreach (['parts_requests', 'purchase_orders', 'inventory', 'stock_movements', 'purchasing_reports', 'purchasing_overview'] as $pg)
    ok(!Access::canOpen($mp, $pg), "motorpool cannot open $pg");
ok(!Access::canOpen($pur, 'maintenance') && Access::canOpen($pur, 'parts_requests'), 'purchasing: Parts Requests yes, Repair Management no');
$nav = array_column(Access::navFor($mp), null, 'id');
ok(($nav['maintenance_overview']['label'] ?? '') === 'Overview' && ($nav['maintenance']['label'] ?? '') === 'Repair Management' && !isset($nav['purchasing']), 'motorpool labels: Overview, Repair Management; no Parts & Purchasing page', json_encode(array_map(fn($n) => $n['label'], $nav)));
ok(($nav['maintenance_overview']['section'] ?? '') === 'Maintenance', 'overview is in the Maintenance section for motorpool');
$navAdm = array_column(Access::navFor($adm), null, 'id');
ok(($navAdm['maintenance']['label'] ?? '') === 'Maintenance Monitoring', 'Head Management keeps its approved Maintenance Monitoring label');
ok(!isset(array_column(Access::navFor($pur), null, 'id')['maintenance_overview']), 'purchasing menu has no overview');
// Direct calls of motorpool to purchasing-only actions are still refused.
fails(fn() => Purchasing::action($mp, 'request_process', ['request_id' => 1]), 'motorpool cannot release parts (Purchasing only)', 403);
fails(fn() => Purchasing::action($mp, 'adjust', ['part_id' => 1, 'quantity_delta' => 1, 'reason' => 'x']), 'motorpool cannot adjust stock', 403);
fails(fn() => Maintenance::action($pur, 'report', ['vehicle_id' => $V[1], 'problem' => 'x', 'reported_on' => date('Y-m-d')]), 'purchasing cannot report a truck problem', 403);
fails(fn() => Maintenance::action($ops, 'report', ['vehicle_id' => $V[1], 'problem' => 'x', 'reported_on' => date('Y-m-d')]), 'operations head cannot report (view only, unchanged)', 403);

// ---------------------------------------------------------------------
section('B. Empty overview');
$o = $ov($mp);
ok($o['summary'] === ['new_reports' => 0, 'new_incidents' => 0, 'under_inspection' => 0, 'under_repair' => 0, 'for_verification' => 0,
    'parts_requested' => 0, 'parts_partially_released' => 0, 'pending_parts' => 0], 'all counts are zero', json_encode($o['summary']));
ok($o['attention'] === [] && $o['activity'] === [], 'no attention items and no activity');
ok($o['can'] === ['act' => true, 'parts' => true, 'requestParts' => true], 'motorpool: can act, sees parts data', json_encode($o['can']));
ok(isset($o['trucks'], $o['mechanics']) && count($o['trucks']) === 6 && count($o['mechanics']) === 3, 'report form data (trucks, mechanics) included');
ok(($o['_errors'] ?? []) === [], 'no failed read section');
$oo = $ov($ops);
ok(!isset($oo['summary']['pending_parts']) && $oo['can']['parts'] === false && $oo['can']['act'] === false, 'operations head: view only, no parts data (cannot open Parts & Purchasing)');

// ---------------------------------------------------------------------
section('C. Garage workflow with counts at every step');
$_FILES = [];
fails(fn() => Maintenance::action($mp, 'report', ['vehicle_id' => $V[1], 'problem' => 'No photo', 'reported_on' => date('Y-m-d')]), 'report without a photo is still refused', 400, 'Please attach');
$r1 = $report($V[1]);
$j1 = (int)$r1['job_id'];
ok($job($j1)['status'] === 'Reported' && one($p, 'SELECT status FROM vehicles WHERE id=?', [$V[1]])['status'] === 'Under Maintenance', 'report: record Reported, truck Under Maintenance (unchanged rule)');
ok((int)one($p, "SELECT COUNT(*) AS n FROM documents WHERE entity_type='maintenance' AND entity_id=?", [$j1])['n'] === 1, 'issue photo stored');
$s = $sum();
ok($s['new_reports'] === 1 && $s['under_inspection'] === 0 && $s['for_verification'] === 0, 'New reports = 1');
$a = $attFor($ov($mp), $r1['job_ref']);
ok(count($a) === 1 && $a[0]['next_action'] === 'assign' && $a[0]['plate_number'] === 'MNT-1' && $a[0]['truck_number'] === '101' && $a[0]['issue'] === 'Engine overheating', 'needs attention: assign a mechanic, with plate, truck no. and issue', json_encode($a));
fails(fn() => $report($V[1]), 'second report on the same truck refused (one open record per truck)', 409);

$r2 = $report($V[2], $M[1], 'Brake noise');
$j2 = (int)$r2['job_id'];
$s = $sum();
ok($s['new_reports'] === 1 && $s['under_inspection'] === 1, 'report with a mechanic goes straight to Under Inspection (not a new report)');
ok($attFor($ov($mp), $r2['job_ref']) === [], 'Under Inspection is not listed in Needs attention (not every active repair)');

Maintenance::action($mp, 'assign_mechanic', ['job_id' => $j1, 'mechanic_id' => $M[0]]);
$s = $sum();
ok($job($j1)['status'] === 'Under Inspection' && $s['new_reports'] === 0 && $s['under_inspection'] === 2, 'assign mechanic: Under Inspection, New reports = 0');
ok($attFor($ov($mp), $r1['job_ref']) === [], 'assigned record leaves Needs attention');
$_FILES = [];
Maintenance::action($mp, 'inspect', ['job_id' => $j1, 'findings' => 'Radiator leak', 'diagnosis' => 'Replace radiator hose']);
$s = $sum();
ok($job($j1)['status'] === 'Under Repair' && $s['under_inspection'] === 1 && $s['under_repair'] === 1, 'inspect: Under Repair counted once');
Maintenance::action($mp, 'add_cost', ['job_id' => $j1, 'cost_type' => 'Labor', 'description' => 'Hose replacement', 'amount' => '500', 'incurred_on' => date('Y-m-d')]);
ok((int)one($p, 'SELECT COUNT(*) AS n FROM maintenance_costs WHERE maintenance_job_id=?', [$j1])['n'] === 1, 'repair cost still recorded');

// Parts
// STOCK_COUNT_RECONCILIATION_V1: part_save no longer accepts an opening quantity. Test fixtures put opening stock in
// directly (one labelled 'Adjustment' movement, as the former opening balance did).
$fixtureStock = function (string $partNo, int $qty) use ($p, $pur): void {
    $id = (int)one($p, 'SELECT id FROM parts WHERE part_number=?', [$partNo])['id'];
    $p->prepare('UPDATE parts SET quantity_on_hand=quantity_on_hand+? WHERE id=?')->execute([$qty, $id]);
    $p->prepare("INSERT INTO stock_movements (part_id, movement_type, quantity_delta, unit_cost, reason, reference_type, actor_id) SELECT id, 'Adjustment', ?, unit_cost, 'Opening balance (test fixture)', 'adjustment', ? FROM parts WHERE id=?")->execute([$qty, $pur['id'], $id]);
};
Purchasing::action($pur, 'part_save', ['part_number' => 'HOSE-1', 'description' => 'Radiator hose', 'unit' => 'pc', 'unit_cost' => '150', 'reorder_level' => '1']);
Purchasing::action($pur, 'part_save', ['part_number' => 'CLAMP-1', 'description' => 'Hose clamp', 'unit' => 'pc', 'unit_cost' => '20', 'reorder_level' => '1']);
$fixtureStock('HOSE-1', 10); $fixtureStock('CLAMP-1', 10);
$hose = (int)one($p, "SELECT id FROM parts WHERE part_number='HOSE-1'")['id']; $clamp = (int)one($p, "SELECT id FROM parts WHERE part_number='CLAMP-1'")['id'];
Purchasing::action($mp, 'request_create', ['vehicle_id' => $V[1], 'maintenance_job_id' => $j1, 'purpose' => 'Radiator repair',
    'lines' => [['part_id' => $hose, 'quantity' => 2], ['part_id' => $clamp, 'quantity' => 4]]]);
$req1 = one($p, 'SELECT * FROM part_requests WHERE maintenance_job_id=? ORDER BY id DESC LIMIT 1', [$j1]);
ok((int)$req1['maintenance_job_id'] === $j1, 'parts request is linked to the maintenance record');
$s = $sum();
ok($s['pending_parts'] === 1 && $s['parts_requested'] === 1, 'Pending parts requests = 1 for a request with 2 lines (no double count)', json_encode($s));
$lines = many($p, 'SELECT id, part_id FROM part_request_lines WHERE part_request_id=? ORDER BY id', [$req1['id']]);
Purchasing::action($pur, 'request_process', ['request_id' => $req1['id'], 'release' => [['line_id' => $lines[0]['id'], 'qty' => 2], ['line_id' => $lines[1]['id'], 'qty' => 2]]]);
$s = $sum();
ok(one($p, 'SELECT status FROM part_requests WHERE id=?', [$req1['id']])['status'] === 'Partially Released' && $s['pending_parts'] === 1 && $s['parts_partially_released'] === 1, 'partial release: still 1 pending (Partially Released)');
ok((int)one($p, 'SELECT quantity_on_hand AS q FROM parts WHERE id=?', [$hose])['q'] === 8, 'stock released (inventory unchanged behaviour)');
Purchasing::action($pur, 'request_process', ['request_id' => $req1['id'], 'release' => [['line_id' => $lines[1]['id'], 'qty' => 2]]]);
ok($sum()['pending_parts'] === 0, 'fully released request is no longer pending');
$det = Maintenance::action($mp, 'details', ['job_id' => $j1]);
ok(count($det['parts']) === 2 && (int)$det['parts'][0]['released_qty'] === 2, 'parts used still shown on the repair record');
ok(count($det['costs']) === 1 && count($det['documents']) >= 1 && count($det['events']) >= 3, 'costs, photos and status history still shown');

// Complete + verify
$_FILES = [];
fails(fn() => Maintenance::action($mp, 'complete', ['job_id' => $j1, 'repair_performed' => 'Replaced hose', 'completed_on' => date('Y-m-d')]), 'complete without evidence still refused', 422);
$_FILES = ['evidence' => $png('done.png')];
Maintenance::action($mp, 'complete', ['job_id' => $j1, 'repair_performed' => 'Replaced hose', 'completed_on' => date('Y-m-d')]);
$_FILES = [];
$s = $sum();
ok($job($j1)['status'] === 'Completed' && $s['for_verification'] === 1 && $s['under_repair'] === 0, 'complete: For verification = 1 (status Completed, not verified)');
ok(one($p, 'SELECT status FROM vehicles WHERE id=?', [$V[1]])['status'] === 'Under Maintenance', 'truck stays out of service until verification (unchanged rule)');
$a = $attFor($ov($mp), $r1['job_ref']);
ok(count($a) === 1 && $a[0]['next_action'] === 'verify' && $a[0]['status'] === 'Completed', 'needs attention: verify and return to service');
Maintenance::action($mp, 'verify', ['job_id' => $j1, 'result' => 'Failed', 'verification_notes' => 'Still leaking']);
$s = $sum();
ok($job($j1)['status'] === 'Under Repair' && $s['for_verification'] === 0 && $s['under_repair'] === 1, 'verification failed: back to Under Repair');
$_FILES = ['evidence' => $png('done2.png')];
Maintenance::action($mp, 'complete', ['job_id' => $j1, 'repair_performed' => 'Replaced hose and clamp', 'completed_on' => date('Y-m-d')]);
$_FILES = [];
Maintenance::action($mp, 'verify', ['job_id' => $j1, 'result' => 'Passed', 'verification_notes' => 'Road test OK']);
$s = $sum();
ok($job($j1)['verified_at'] !== null && $s['for_verification'] === 0 && $s['under_repair'] === 0, 'verified: removed from every count');
ok(one($p, 'SELECT status FROM vehicles WHERE id=?', [$V[1]])['status'] === 'Available', 'truck Available after verification (unchanged rule)');
ok($attFor($ov($mp), $r1['job_ref']) === [], 'verified record leaves Needs attention');

// ---------------------------------------------------------------------
section('D. Parts: Not Available follow-up and role-limited parts data');
Maintenance::action($mp, 'inspect', ['job_id' => $j2, 'findings' => 'Worn pads', 'diagnosis' => 'Replace brake pads']);
Purchasing::action($pur, 'part_save', ['part_number' => 'PAD-1', 'description' => 'Brake pad', 'unit' => 'set', 'unit_cost' => '900', 'reorder_level' => '0']);
$pad = (int)one($p, "SELECT id FROM parts WHERE part_number='PAD-1'")['id'];
Purchasing::action($mp, 'request_create', ['vehicle_id' => $V[2], 'maintenance_job_id' => $j2, 'purpose' => 'Brake pads', 'lines' => [['part_id' => $pad, 'quantity' => 1]]]);
$req2 = one($p, 'SELECT * FROM part_requests WHERE maintenance_job_id=?', [$j2]);
ok($sum()['pending_parts'] === 1, 'new request pending');
Purchasing::action($pur, 'request_process', ['request_id' => $req2['id'], 'not_available' => 1]);
$o = $ov($mp);
ok($o['summary']['pending_parts'] === 0, 'Not Available is not counted as pending (as approved)');
$a = $attFor($o, $req2['request_ref']);
ok(count($a) === 1 && $a[0]['kind'] === 'parts' && $a[0]['next_action'] === 'parts_follow_up' && (int)$a[0]['job_id'] === $j2 && $a[0]['job_ref'] === $r2['job_ref'], 'needs attention: parts follow-up, linked to its repair', json_encode($a));
ok(count(array_filter($o['attention'], fn($r) => (int)$r['job_id'] === $j2)) === 1, 'the repair appears once (no duplicate job + request row)');
$oo = $ov($ops);
ok(!array_filter($oo['attention'], fn($r) => $r['kind'] === 'parts') && !array_filter($oo['activity'], fn($e) => $e['kind'] !== 'job'), 'operations head: no parts rows in attention or activity');
$oa = $ov($aud);
ok(isset($oa['summary']['pending_parts']) && $oa['can']['act'] === false, 'audit: sees parts data (can open Parts & Purchasing), cannot act');
// A request with no repair record, Not Available.
Purchasing::action($mp, 'request_create', ['vehicle_id' => $V[3], 'purpose' => 'Spare pads', 'lines' => [['part_id' => $pad, 'quantity' => 2]]]);
$req3 = one($p, 'SELECT * FROM part_requests WHERE vehicle_id=? ORDER BY id DESC LIMIT 1', [$V[3]]);
Purchasing::action($pur, 'request_process', ['request_id' => $req3['id'], 'not_available' => 1]);
$a = $attFor($ov($mp), $req3['request_ref']);
ok(count($a) === 1 && $a[0]['job_id'] === null && $a[0]['plate_number'] === 'MNT-3', 'Not Available request without a repair record is listed (View request)');

// ---------------------------------------------------------------------
section('E. Trip incident (assess Minor) and cancel');
$t = test_create_planned_trip($ops, ['client_id' => $cid, 'truck_type' => 'Wing Van', 'pickup_point' => 'P1', 'delivery_point' => 'D1', 'scheduled_at' => date('Y-m-d\TH:i', time() + 3600), 'distance_km' => '20']);
$tid = (int)$t['trip_id'];
$trip = one($p, 'SELECT * FROM trips WHERE id=?', [$tid]);
ok($trip['status'] === 'Pre-Assigned' && $trip['vehicle_id'], 'trip pre-assigned to a free truck', json_encode([$trip['status'], $trip['vehicle_id']]));
run($p, "UPDATE trips SET status='Dispatched', dispatched_at=DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id=?", [$tid]);
$_FILES = [];
$inc = Trips::action($wvd, 'incident', ['trip_id' => $tid, 'incident_type' => 'Flat Tire', 'incident_at' => date('Y-m-d\TH:i', time() - 600), 'location' => 'SLEX KM 50', 'problem' => 'Flat tire', 'details' => 'Rear left tire']);
$ij = one($p, 'SELECT * FROM maintenance_jobs WHERE incident_id=?', [$inc['incident_id']]);
$o = $ov($mp);
ok($o['summary']['new_reports'] === 1 && $o['summary']['new_incidents'] === 1, 'incident counted as a new report from a trip incident');
$a = $attFor($o, $ij['job_ref']);
ok(count($a) === 1 && $a[0]['next_action'] === 'assess' && $a[0]['source'] === 'Incident' && $a[0]['incident_ref'] !== null, 'needs attention: assess the incident (Minor or Major)');
Maintenance::action($mp, 'assess', ['job_id' => $ij['id'], 'severity' => 'Minor', 'recommendation' => 'Change tire on site', 'mechanic_id' => $M[2]]);
ok($job((int)$ij['id'])['status'] === 'Under Inspection' && one($p, 'SELECT status FROM trips WHERE id=?', [$tid])['status'] === 'On Hold - Incident Reported', 'assess Minor: record Under Inspection, trip stays On Hold (unchanged)');
$pj = one($p, 'SELECT severity, priority FROM maintenance_jobs WHERE id=?', [$ij['id']]);
ok($pj['severity'] === 'Minor' && $pj['priority'] === $ij['priority'], 'assess keeps the recorded repair plan (Minor) and derives no Critical / High priority (UNSUPPORTED_CLASSIFICATION_CLEANUP)', json_encode([$pj, $ij['priority'] ?? null]));
ok($sum()['new_incidents'] === 0, 'assessed incident leaves New reports');
// Cancel a garage report made by mistake.
$r4 = $report($V[4], null, 'Reported by mistake');
Maintenance::action($mp, 'cancel', ['job_id' => $r4['job_id'], 'reason' => 'Wrong truck']);
$o = $ov($mp);
ok($o['summary']['new_reports'] === 0 && $attFor($o, $r4['job_ref']) === [], 'cancelled record is not counted and not listed');

// ---------------------------------------------------------------------
section('F. Overview counts equal the Repair Management records');
$r5 = $report($V[5], null, 'Lights not working');
$jobs = Maintenance::read($mp, 'maintenance', [])['jobs'];
$open = array_filter($jobs, fn($j) => $j['status'] !== 'Cancelled' && !$j['verified_at']);
$s = $sum();
ok($s['new_reports'] === count(array_filter($open, fn($j) => $j['status'] === 'Reported')), 'New reports = Open records with ?view=reported');
ok($s['under_inspection'] + $s['under_repair'] === count(array_filter($open, fn($j) => in_array($j['status'], ['Under Inspection', 'Under Repair'], true))), 'Under repair card = Open records with ?view=in_progress');
ok($s['for_verification'] === count(array_filter($open, fn($j) => $j['status'] === 'Completed')), 'For verification = Open records with ?view=verify');
$pend = (int)one($p, "SELECT COUNT(*) AS n FROM part_requests WHERE status IN ('Requested','Partially Released')")['n'];
ok($s['pending_parts'] === $pend, 'Pending parts = requests Requested or Partially Released');
$o = $ov($mp);
$since = array_column($o['attention'], 'since');
$sorted = $since; sort($sorted);
ok($since === $sorted, 'Needs attention: longest waiting first');
ok(count($o['attention']) === count(array_unique(array_map(fn($r) => $r['kind'] . ':' . ($r['request_id'] ?? $r['job_id']), $o['attention']))), 'no duplicate rows');
foreach ($o['attention'] as $r) if (!in_array($r['next_action'], ['assess', 'assign', 'verify', 'parts_follow_up'], true)) ok(false, 'unknown next action ' . $r['next_action']);
ok(true, 'every attention row has a known next action');

// ---------------------------------------------------------------------
section('G. Recent activity');
$act = $ov($mp)['activity'];
$labels = array_column($act, 'label');
foreach (['Truck issue reported', 'Mechanic assigned', 'Inspection findings recorded', 'Repair completed', 'Verification failed, back to repair', 'Verified, returned to service',
          'Trip incident reported', 'Incident assessed, mechanic assigned', 'Record cancelled', 'Parts request submitted'] as $want)
    ok(in_array($want, $labels, true), "activity label present: $want", implode(' | ', $labels));
$at = array_column($act, 'at'); $desc = $at; rsort($desc);
ok($at === $desc, 'newest first');
ok(count($act) === 15, 'limited to 15 entries');
$allMaint = Maintenance::read($adm, 'maintenance_overview', [])['activity'];
ok(count($allMaint) === 15, 'admin activity also limited');
// Release grouping: a two-line release made in one action is ONE entry.
$r6 = $report($V[6], null, 'Mirror broken');
Maintenance::action($mp, 'assign_mechanic', ['job_id' => $r6['job_id'], 'mechanic_id' => $M[0]]);
Purchasing::action($mp, 'request_create', ['vehicle_id' => $V[6], 'maintenance_job_id' => $r6['job_id'], 'purpose' => 'Mirror', 'lines' => [['part_id' => $hose, 'quantity' => 1], ['part_id' => $clamp, 'quantity' => 1]]]);
$req6 = one($p, 'SELECT * FROM part_requests WHERE maintenance_job_id=?', [$r6['job_id']]);
$l6 = many($p, 'SELECT id FROM part_request_lines WHERE part_request_id=?', [$req6['id']]);
Purchasing::action($pur, 'request_process', ['request_id' => $req6['id'], 'release' => [['line_id' => $l6[0]['id'], 'qty' => 1], ['line_id' => $l6[1]['id'], 'qty' => 1]]]);
$rel = array_values(array_filter($ov($mp)['activity'], fn($e) => $e['kind'] === 'parts_released' && $e['request_ref'] === $req6['request_ref']));
ok(count($rel) === 1 && (int)$rel[0]['units'] === 2 && (int)$rel[0]['job_id'] === (int)$r6['job_id'], 'two-line release = one "Parts released" entry (2 units), linked to the repair', json_encode($rel));
$opsAct = $ov($ops)['activity'];
ok($opsAct && !array_filter($opsAct, fn($e) => $e['kind'] !== 'job'), 'operations head activity: maintenance events only');
ok(count(array_filter(array_merge(...array_map(fn($e) => array_keys($e), $act)), fn($k) => $k === 'from_status' || $k === 'sort_id')) === 0, 'internal fields not sent to the screen');

// ---------------------------------------------------------------------
section('H. Read-only, Repair Management unchanged, ONLY_FULL_GROUP_BY');
$tables = ['maintenance_jobs', 'maintenance_events', 'maintenance_costs', 'part_requests', 'part_request_lines', 'stock_movements', 'parts', 'vehicles', 'mechanics', 'audit_events', 'notifications', 'documents'];
$snap = fn() => array_map(fn($tb) => $p->query("SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|', id))),0) FROM $tb")->fetch(PDO::FETCH_NUM), array_combine($tables, $tables));
$snapRows = fn() => [one($p, "SELECT GROUP_CONCAT(CONCAT_WS(':', id, status, IFNULL(verified_at, '')) ORDER BY id) AS x FROM maintenance_jobs")['x'],
                     one($p, "SELECT GROUP_CONCAT(CONCAT_WS(':', id, status) ORDER BY id) AS x FROM vehicles")['x'],
                     one($p, "SELECT GROUP_CONCAT(CONCAT_WS(':', id, availability) ORDER BY id) AS x FROM mechanics")['x'],
                     one($p, "SELECT GROUP_CONCAT(CONCAT_WS(':', id, quantity_on_hand) ORDER BY id) AS x FROM parts")['x']];
$before = [$snap(), $snapRows()];
foreach ([$mp, $ops, $adm, $aud] as $u) $ov($u);
ok([$snap(), $snapRows()] === $before, 'reading the overview (4 roles) writes nothing');
$rm = Maintenance::read($mp, 'maintenance', []);
ok(array_keys($rm) === ['_errors', 'jobs', 'trucks', 'mechanics', 'helpers', 'helpersReady', 'costTypes', 'can'] && $rm['can'] === ['act' => true], 'Repair Management page data: same keys plus helpers / helpersReady; no priority list (UNSUPPORTED_CLASSIFICATION_CLEANUP)', json_encode(array_keys($rm)));
ok(array_key_exists('other_cost', $rm['jobs'][0]) && array_key_exists('parts_cost', $rm['jobs'][0]) && array_key_exists('open_job', $rm['trucks'][0]) && array_key_exists('on_road', $rm['trucks'][0]), 'Repair Management rows keep their fields');
$mode = (string)$p->query('SELECT @@SESSION.sql_mode')->fetchColumn();
$p->exec("SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'ONLY_FULL_GROUP_BY')");
try {
    $o = $ov($mp);
    ok(($o['_errors'] ?? []) === [] && count($o['activity']) > 0, 'overview works with ONLY_FULL_GROUP_BY (MySQL 8 default)', json_encode($o['_errors'] ?? []));
} finally { $p->exec('SET SESSION sql_mode = ' . $p->quote($mode)); }

// ---------------------------------------------------------------------
foreach (glob($tmpDir . '/*') ?: [] as $f) @unlink($f); @rmdir($tmpDir);
echo "\nRESULT: $PASS passed, $FAIL failed\n";
if ($FAILED) { echo "Failed:\n"; foreach ($FAILED as $f) echo "  - $f\n"; }
exit($FAIL ? 1 : 0);
