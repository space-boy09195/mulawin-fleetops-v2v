<?php
/*
 * =====================================================================
 *  tests/integration_workflow_tests.php  —  INTEGRATION_WORKFLOW_V1 (luis-maintenance-ui + kenneth 894e709)
 * =====================================================================
 *  Do NOT run this file directly: run tests/run_integration_workflow_tests.php (temporary copy, own .env, *_test database).
 *  One trip and one repair are driven through the REAL actions of every department (no status is hand-edited):
 *    A. Operations -> Dispatcher: Ops Head plans (approved route, group:shift, responsible account); Dispatcher times in
 *       the fixed crew and assigns the truck (tests/_launcher.php test_create_planned_trip)
 *    B. Operations -> Finance: fund request -> Ops Head approval -> Finance cash release (one voucher, one expense)
 *    C. Dispatch -> Billing draft: checklist, Confirm Dispatch, one frozen fixed-route draft
 *    D. Completion -> Billing -> collection: documents, For Billing, verify, invoice, partial + final collection, arrival
 *    E. Operations -> Motorpool: second trip, incident, Major assessment (trip For Reschedule, truck out of service)
 *    F. Motorpool -> Purchasing: parts request, Not Available, linked PO, Finance approval, receiving, release
 *    G. Purchasing -> Finance: PO payment (budget reserved -> spent), repair cost, completion, verification -> Available
 *    H. Money / stock / audit invariants; I. Head Management reads; J. Audit is read-only
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

section('Setup: disposable database and master data');
test_recreate($DB);
$p = db();
ok($p->query('SELECT DATABASE()')->fetchColumn() === $DB, 'db() is the disposable test database');
test_import($p, test_sql('database/schema.sql'));
test_import($p, test_sql('database/extension.sql'));
$users = [];
foreach (['admin' => null, 'operations_head' => null, 'container_wingvan_dispatcher' => 'Day', 'finance' => null, 'billing' => null,
          'motorpool' => null, 'purchasing' => null, 'audit' => null] as $role => $shift) {
    $id = insert($p, 'users', ['username' => 'e2e_' . $role, 'email' => "e2e_$role@example.test", 'password_hash' => 'x', 'full_name' => 'E2E ' . $role, 'role' => $role, 'shift' => $shift]);
    $users[$role] = ['id' => $id, 'role' => $role, 'shift' => $shift, 'full_name' => 'E2E ' . $role, 'username' => 'e2e_' . $role, 'email' => "e2e_$role@example.test"];
}
['admin' => $adm, 'operations_head' => $ops, 'container_wingvan_dispatcher' => $wvd, 'finance' => $fin, 'billing' => $bil,
 'motorpool' => $mp, 'purchasing' => $pur, 'audit' => $aud] = $users;
foreach (['Trips', 'Funds', 'Billing', 'Finance', 'Purchasing', 'Maintenance', 'Mechanics', 'Oversight', 'Admin', 'Attendance'] as $m) Actions::load($m);
$cid = insert($p, 'clients', ['company_name' => 'E2E CLIENT CORPORATION', 'status' => 'Active', 'created_by' => $ops['id'], 'payment_terms_days' => 15]);
for ($i = 1; $i <= 2; $i++) {
    insert($p, 'vehicles', ['plate_number' => "E2E-00$i", 'truck_type' => 'Wing Van']);
    insert($p, 'personnel', ['full_name' => "E2E Driver $i", 'person_type' => 'Driver', 'license_number' => "E2E-LIC-$i", 'license_expiry' => '2099-12-31']);
    insert($p, 'personnel', ['full_name' => "E2E Helper $i", 'person_type' => 'Helper']);
}
// Isolated configuration through the Admin setting action (checklists can be submitted at once; dispatch up to 4 h early).
Admin::action($adm, 'setting_save', ['key' => 'inspection_min_minutes', 'value' => '0']);
Admin::action($adm, 'setting_save', ['key' => 'dispatch_early_minutes', 'value' => '240']);
Settings::clearCache();
Finance::action($fin, 'budget_set', ['amount' => '100000', 'period_start' => date('Y-m-01'), 'period_end' => date('Y-m-t')]);
// kenneth fixed-route pricing: fuel needs effective diesel price, truck km/L and a surveyed garage (real Admin action).
foreach (['diesel' => '60', 'Wing Van' => '3.5'] as $k => $v) Admin::action($adm, 'pricing_save', ['setting_key' => $k, 'value' => $v, 'effective_date' => date('Y-m-d'), 'reference' => 'E2E verified fixture']);
Admin::action($adm, 'pricing_save', ['setting_key' => 'garage', 'lat' => 14.12, 'lng' => 121.07, 'verified' => '1', 'effective_date' => date('Y-m-d'), 'reference' => 'E2E surveyed fixture']);
// Fixture route (test DB only): map-measured 40 km, pickup at the garage, so no map service is called; Flat rate approved.
insert($p, 'client_rates', ['client_id' => $cid, 'truck_type' => 'Wing Van', 'rate_per_km' => '55', 'minimum_charge' => '0', 'updated_by' => $ops['id']]);
$route = insert($p, 'client_routes', ['route_ref' => 'E2E-ROUTE', 'client_id' => $cid, 'truck_type' => 'Wing Van', 'origin_label' => 'E2E garage pickup', 'destination_label' => 'E2E destination',
    'origin_key' => 'e2e origin', 'destination_key' => 'e2e destination', 'origin_lat' => 14.12, 'origin_lng' => 121.07, 'destination_lat' => 14.30, 'destination_lng' => 121.10,
    'distance_km' => '40.00', 'distance_source' => 'Map', 'status' => 'Approved', 'requested_by' => $ops['id']]);
insert($p, 'client_route_rates', ['route_id' => $route, 'version_no' => 1, 'rate_type' => 'Flat', 'rate_amount' => '4000', 'status' => 'Approved', 'entered_by' => $ops['id'], 'effective_from' => date('Y-m-d H:i:s', time() - 60)]);
$tmpDir = sys_get_temp_dir() . '/fleet_e2e_' . bin2hex(random_bytes(4)); mkdir($tmpDir);
$file = function (string $name, bool $png = false) use ($tmpDir): array {
    $path = $tmpDir . '/' . bin2hex(random_bytes(6));
    $bytes = $png ? base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==') : "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    file_put_contents($path, $bytes);
    return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
};
$items = [];
foreach (Checklist::sections() as $s) foreach ($s['items'] as $i => $label) $items[$s['key'] . '_' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT)] = ['condition' => 'OK', 'remarks' => ''];
$trip = fn(int $id) => one($p, 'SELECT * FROM trips WHERE id=?', [$id]);
$count = fn(string $sql, array $a = []) => (int)one($p, $sql, $a)['n'];

/** Plans, assigns, funds and dispatches one trip through the real actions; returns [trip id, fund id]. */
$dispatchTrip = function (int $n) use ($ops, $wvd, $fin, $cid, $items, $trip): array {
    $r = test_create_planned_trip($ops, ['client_id' => $cid, 'truck_type' => 'Wing Van', 'pickup_point' => "E2E pickup $n", 'delivery_point' => "E2E delivery $n",
        'scheduled_at' => date('Y-m-d\TH:i', time() + 3600), 'distance_km' => '40', 'request_key' => key36()]);
    $tid = (int)$r['trip_id'];
    $f = Funds::action($wvd, 'submit', ['trip_id' => $tid, 'categories' => ['Driver Meal Allowance', 'Helper Meal Allowance']]);
    Funds::action($ops, 'decide', ['fund_id' => $f['fund_id'], 'decision' => 'approve']);
    Funds::action($fin, 'release', ['fund_id' => $f['fund_id']]);
    Trips::action($wvd, 'checklist_start', ['trip_id' => $tid, 'kind' => 'Pre-Dispatch']);
    Trips::action($wvd, 'checklist', ['trip_id' => $tid, 'items' => $items, 'meta' => [], 'odometer_km' => '1000', 'fuel_level' => 'Full']);
    Trips::action($wvd, 'dispatch', ['trip_id' => $tid]);
    return [$tid, (int)$f['fund_id']];
};

// ---------------------------------------------------------------------
section('A. Operations -> Dispatcher (planning and fixed-pair assignment)');
$r1 = test_create_planned_trip($ops, ['client_id' => $cid, 'truck_type' => 'Wing Van', 'pickup_point' => 'E2E pickup 1', 'delivery_point' => 'E2E delivery 1',
    'scheduled_at' => date('Y-m-d\TH:i', time() + 3600), 'distance_km' => '40', 'request_key' => key36()]);
$T1 = (int)$r1['trip_id']; $t1 = $trip($T1);
ok($t1['status'] === 'Pre-Assigned' && $t1['vehicle_id'] && $t1['driver_id'] && $t1['helper_id'], 'Ops Head plan + Dispatcher assignment: Pre-Assigned with truck, driver and helper', json_encode([$t1['status'], $t1['vehicle_id']]));
ok($t1['dispatcher_role'] === 'container_wingvan_dispatcher' && $t1['dispatcher_shift'] === 'Day' && $t1['client_route_id'], 'trip keeps its dispatcher group, shift and approved route');
ok((int)one($p, 'SELECT assigned_driver_id FROM vehicles WHERE id=?', [$t1['vehicle_id']])['assigned_driver_id'] === (int)$t1['driver_id'], 'driver is the truck\'s fixed driver');
ok($count('SELECT COUNT(*) AS n FROM dispatcher_attendance_sessions WHERE time_out IS NULL AND personnel_id IN (?,?)', [$t1['driver_id'], $t1['helper_id']]) === 2, 'crew has open Dispatcher Time In sessions');
fails(fn() => Trips::action($wvd, 'create', ['client_id' => $cid, 'truck_type' => 'Wing Van']), 'a Dispatcher cannot create trips (kenneth rule)', 403);

// ---------------------------------------------------------------------
section('B. Operations -> Finance (trip fund)');
$f1 = Funds::action($wvd, 'submit', ['trip_id' => $T1, 'categories' => ['Driver Meal Allowance', 'Helper Meal Allowance']]);
$F1 = (int)$f1['fund_id'];
fails(fn() => Funds::action($fin, 'release', ['fund_id' => $F1]), 'Finance cannot release before the Ops Head approves', 409);
Funds::action($ops, 'decide', ['fund_id' => $F1, 'decision' => 'approve']);
fails(fn() => Funds::action($aud, 'release', ['fund_id' => $F1]), 'Audit cannot release funds', 403);
Funds::action($fin, 'release', ['fund_id' => $F1]);
$fund = one($p, 'SELECT * FROM fund_requests WHERE id=?', [$F1]);
ok($fund['status'] === 'Released', 'fund Released by Finance');
fails(fn() => Funds::action($fin, 'release', ['fund_id' => $F1]), 'a second release is refused (no duplicate disbursement)', 409);
ok($count("SELECT COUNT(*) AS n FROM disbursements WHERE source_type='fund_request' AND source_id=?", [$F1]) === 1, 'exactly one voucher for the fund');
ok((float)one($p, "SELECT SUM(amount) AS s FROM expenses WHERE source='Fund Release' AND source_id=?", [$F1])['s'] === (float)$fund['amount'], 'one fund expense equal to the released amount');

// ---------------------------------------------------------------------
section('C. Dispatch -> Billing draft');
Trips::action($wvd, 'checklist_start', ['trip_id' => $T1, 'kind' => 'Pre-Dispatch']);
Trips::action($wvd, 'checklist', ['trip_id' => $T1, 'items' => $items, 'meta' => [], 'odometer_km' => '1000', 'fuel_level' => 'Full']);
$d = Trips::action($wvd, 'dispatch', ['trip_id' => $T1]);
ok($trip($T1)['status'] === 'Dispatched', 'Confirm Dispatch: trip Dispatched', $d['message'] ?? '');
$b1 = one($p, 'SELECT * FROM billings WHERE trip_id=?', [$T1]);
ok($b1 && $b1['status'] === 'Ongoing' && (float)$b1['total_amount'] > 0, 'one Ongoing billing draft with a positive frozen amount', json_encode($b1['total_amount'] ?? null));
ok($count('SELECT COUNT(*) AS n FROM billing_pricing WHERE billing_id=?', [$b1['id']]) === 1, 'dispatch pricing snapshot stored once');

// ---------------------------------------------------------------------
section('D. Completion -> Billing -> collections');
$_FILES = ['waybill' => $file('wb.pdf'), 'ack_receipt' => $file('ar.pdf'), 'delivery_receipt' => $file('dr.pdf')];
try { Trips::action($wvd, 'complete', ['trip_id' => $T1, 'completed_at' => date('Y-m-d\TH:i'), 'waybill_number' => 'WB-E2E-1']); } finally { $_FILES = []; }
ok($trip($T1)['status'] === 'Completed', 'trip Completed with waybill, DR and AR');
ok(one($p, 'SELECT status FROM billings WHERE id=?', [$b1['id']])['status'] === 'For Billing', 'billing moves to For Billing');
Trips::action($wvd, 'arrival', ['trip_id' => $T1, 'items' => $items, 'fuel_level' => 'Full']);
ok(one($p, 'SELECT status FROM vehicles WHERE id=?', [$t1['vehicle_id']])['status'] === 'Available', 'arrival All OK releases the truck (Available)');
fails(fn() => Billing::action($aud, 'verify', ['billing_id' => $b1['id']]), 'Audit cannot verify billing', 403);
Billing::action($bil, 'verify', ['billing_id' => $b1['id']]);
Billing::action($bil, 'finalize', ['billing_id' => $b1['id'], 'invoice_date' => date('Y-m-d')]);
$b = one($p, 'SELECT * FROM billings WHERE id=?', [$b1['id']]);
ok($b['status'] === 'Invoiced' && $b['invoice_number'], 'invoice issued');
$total = (float)$b['total_amount']; $half = round($total / 2, 2);
$collect = function (string $amount, string $ar) use ($bil, $b, $file) {
    $_FILES = ['ack_receipt' => $file('ack.pdf')];
    try { return Billing::action($bil, 'collect', ['billing_id' => $b['id'], 'amount' => $amount, 'ar_number' => $ar, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d')]); } finally { $_FILES = []; }
};
$collect(number_format($half, 2, '.', ''), 'AR-E2E-1');
$b = one($p, 'SELECT * FROM billings WHERE id=?', [$b1['id']]);
ok($b['status'] === 'Partially Paid' && abs((float)$b['amount_paid'] - $half) < 0.005, 'partial collection: Partially Paid, balance remains');
fails(fn() => $collect(number_format($total, 2, '.', ''), 'AR-E2E-X'), 'collection above the balance is refused');
$collect(number_format($total - $half, 2, '.', ''), 'AR-E2E-2');
$b = one($p, 'SELECT * FROM billings WHERE id=?', [$b1['id']]);
ok($b['status'] === 'Paid' && abs((float)$b['amount_paid'] - $total) < 0.005, 'final collection: Paid, balance 0');
ok(abs((float)one($p, 'SELECT SUM(amount) AS s FROM collections WHERE billing_id=?', [$b1['id']])['s'] - $total) < 0.005, 'collections add up to the invoice total');
ok($count('SELECT COUNT(*) AS n FROM billings WHERE trip_id=?', [$T1]) === 1, 'still exactly one billing for the trip (no duplicate billing)');

// ---------------------------------------------------------------------
section('E. Operations -> Motorpool (incident on a second trip)');
[$T2, $F2] = $dispatchTrip(2);
$t2 = $trip($T2);
ok($t2['status'] === 'Dispatched' && (int)$t2['vehicle_id'] !== 0, 'second trip dispatched through the same real flow');
// The incident time (minute precision) must not be before the dispatch time: wait for the next minute instead of back-dating.
while (date('Y-m-d H:i:00') < $t2['dispatched_at']) sleep(1);
$_FILES = ['photos' => $file('damage.png', true)];
try { $inc = Trips::action($wvd, 'incident', ['trip_id' => $T2, 'incident_type' => 'Breakdown', 'incident_at' => date('Y-m-d\TH:i'), 'location' => 'SLEX KM 50', 'problem' => 'Radiator leak', 'details' => 'Overheating']); } finally { $_FILES = []; }
$job = one($p, 'SELECT * FROM maintenance_jobs WHERE incident_id=?', [$inc['incident_id']]);
ok($trip($T2)['status'] === 'On Hold - Incident Reported' && $job && $job['source'] === 'Incident' && (int)$job['vehicle_id'] === (int)$t2['vehicle_id'], 'incident: trip On Hold and ONE repair record for the same truck');
ok($count('SELECT COUNT(*) AS n FROM maintenance_jobs WHERE incident_id=?', [$inc['incident_id']]) === 1, 'no duplicate repair record for the incident');
$mid = (int)Mechanics::action($mp, 'save', ['full_name' => 'E2E Mechanic'])['id'];
Mechanics::action($mp, 'time_in', ['id' => $mid]);
Maintenance::action($mp, 'assess', ['job_id' => $job['id'], 'severity' => 'Major', 'recommendation' => 'Tow to garage; replace radiator hose', 'mechanic_id' => $mid]);
ok($trip($T2)['status'] === 'For Reschedule', 'Major: trip For Reschedule');
ok(one($p, 'SELECT status FROM vehicles WHERE id=?', [$t2['vehicle_id']])['status'] === 'Under Maintenance', 'truck is Under Maintenance (not Available) while the Major repair is open');
Maintenance::action($mp, 'inspect', ['job_id' => $job['id'], 'findings' => 'Cracked hose', 'diagnosis' => 'Replace radiator hose']);
ok(one($p, 'SELECT status FROM maintenance_jobs WHERE id=?', [$job['id']])['status'] === 'Under Repair', 'inspected: Under Repair');

// ---------------------------------------------------------------------
section('F. Motorpool -> Purchasing (shortage, linked PO, receiving, release)');
Purchasing::action($pur, 'part_save', ['part_number' => 'E2E-HOSE', 'description' => 'Radiator hose', 'category' => 'Cooling', 'unit' => 'pc', 'unit_cost' => '1500', 'reorder_level' => '0']);
$part = (int)one($p, "SELECT id FROM parts WHERE part_number='E2E-HOSE'")['id'];
$onHand = fn() => (int)one($p, 'SELECT quantity_on_hand FROM parts WHERE id=?', [$part])['quantity_on_hand'];
Purchasing::action($mp, 'request_create', ['vehicle_id' => (int)$t2['vehicle_id'], 'maintenance_job_id' => (int)$job['id'], 'purpose' => 'Radiator repair', 'lines' => [['part_id' => $part, 'quantity' => 2]]]);
$req = one($p, 'SELECT * FROM part_requests WHERE maintenance_job_id=?', [$job['id']]);
fails(fn() => Purchasing::action($mp, 'request_process', ['request_id' => $req['id'], 'not_available' => 1]), 'Motorpool cannot process its own request', 403);
Purchasing::action($pur, 'request_process', ['request_id' => $req['id'], 'not_available' => 1]);
ok(one($p, 'SELECT status FROM part_requests WHERE id=?', [$req['id']])['status'] === 'Not Available', 'shortage recorded: Not Available');
$mpView = json_encode(Purchasing::read($mp, 'mp_parts_requests', []));
ok(!str_contains($mpView, 'unit_cost') && !str_contains($mpView, 'quantity_on_hand') && !str_contains($mpView, 'budget'), 'Motorpool payload has no cost, stock count or budget data');
Purchasing::action($pur, 'po_create', ['supplier_name' => 'E2E SUPPLIER', 'supplier_address' => 'E2E address', 'purpose' => 'Shortage for ' . $req['request_ref'],
    'part_request_id' => (int)$req['id'], 'lines' => [['part_id' => $part, 'quantity' => 2, 'unit_price' => '1500']]]);
$po = one($p, 'SELECT * FROM purchase_orders WHERE part_request_id=?', [$req['id']]);
ok($po && $po['status'] === 'Pending Approval' && $po['approver_role'] === 'finance', 'PO linked to the request, routed to Finance (within the limit)');
$budget = fn() => one($p, "SELECT * FROM budgets WHERE status='Active' ORDER BY id DESC LIMIT 1");
$b0 = $budget();
fails(fn() => Purchasing::action($aud, 'po_decide', ['po_id' => $po['id'], 'decision' => 'approve']), 'Audit cannot approve a PO', 403);
Purchasing::action($fin, 'po_decide', ['po_id' => $po['id'], 'decision' => 'approve']);
ok(abs((float)$budget()['reserved_amount'] - (float)$b0['reserved_amount'] - 3000) < 0.005, 'approval reserves 3,000 of the budget');
$pol = (int)one($p, 'SELECT id FROM purchase_order_lines WHERE purchase_order_id=?', [$po['id']])['id'];
Purchasing::action($pur, 'po_receive', ['po_id' => $po['id'], 'receive' => [['line_id' => $pol, 'qty' => 2]], 'supplier_invoice_no' => 'SI-E2E-1']);
ok($onHand() === 2, 'receiving adds 2 to stock');
$rl = (int)one($p, 'SELECT id FROM part_request_lines WHERE part_request_id=?', [$req['id']])['id'];
Purchasing::action($pur, 'request_process', ['request_id' => $req['id'], 'release' => [['line_id' => $rl, 'qty' => 2]]]);
ok($onHand() === 0 && one($p, 'SELECT status FROM part_requests WHERE id=?', [$req['id']])['status'] === 'Released', 'release to the repair: request Released, stock back to 0');
fails(fn() => Purchasing::action($pur, 'request_process', ['request_id' => $req['id'], 'release' => [['line_id' => $rl, 'qty' => 2]]]), 'duplicate release refused', 409);

// ---------------------------------------------------------------------
section('G. Purchasing -> Finance (payment) and repair completion -> availability');
fails(fn() => Finance::action($aud, 'po_pay', ['po_id' => $po['id'], 'amount' => '3000', 'supplier_invoice_no' => 'SI-E2E-1', 'payment_method' => 'Cash', 'released_on' => date('Y-m-d')]), 'Audit cannot pay a PO', 403);
fails(fn() => Finance::action($pur, 'po_pay', ['po_id' => $po['id'], 'amount' => '3000', 'supplier_invoice_no' => 'SI-E2E-1', 'payment_method' => 'Cash', 'released_on' => date('Y-m-d')]), 'Purchasing cannot pay a PO', 403);
$b1b = $budget();
$_FILES = ['or_doc' => $file('official_receipt.pdf')];
try { Finance::action($fin, 'po_pay', ['po_id' => $po['id'], 'amount' => '3000', 'supplier_invoice_no' => 'SI-E2E-1', 'payment_method' => 'Cash', 'released_on' => date('Y-m-d')]); } finally { $_FILES = []; }
$b2 = $budget();
ok(abs((float)$b1b['reserved_amount'] - (float)$b2['reserved_amount'] - 3000) < 0.005 && abs((float)$b2['spent_amount'] - (float)$b1b['spent_amount'] - 3000) < 0.005, 'payment moves 3,000 from reserved to spent');
fails(fn() => (function () use ($fin, $po, $file) { $_FILES = ['or_doc' => $file('or2.pdf')]; try { return Finance::action($fin, 'po_pay', ['po_id' => $po['id'], 'amount' => '1', 'supplier_invoice_no' => 'SI-E2E-1', 'payment_method' => 'Cash', 'released_on' => date('Y-m-d')]); } finally { $_FILES = []; } })(), 'payment above the unpaid received value refused');
Maintenance::action($mp, 'add_cost', ['job_id' => $job['id'], 'cost_type' => 'Labor', 'description' => 'Hose replacement labor', 'amount' => '800', 'incurred_on' => date('Y-m-d')]);
$_FILES = ['evidence' => $file('done.png', true)];
try { Maintenance::action($mp, 'complete', ['job_id' => $job['id'], 'repair_performed' => 'Replaced radiator hose', 'completed_on' => date('Y-m-d')]); } finally { $_FILES = []; }
ok(one($p, 'SELECT status FROM vehicles WHERE id=?', [$t2['vehicle_id']])['status'] === 'Under Maintenance', 'completed but not yet verified: truck still Under Maintenance');
Maintenance::action($mp, 'verify', ['job_id' => $job['id'], 'result' => 'Passed', 'verification_notes' => 'Road test OK']);
ok(one($p, 'SELECT verified_at FROM maintenance_jobs WHERE id=?', [$job['id']])['verified_at'] !== null, 'repair verified (Passed)');
// FleetState::reconcile: no open blocking repair -> the truck returns to the trip that still holds it (For Reschedule).
ok(one($p, 'SELECT status FROM vehicles WHERE id=?', [$t2['vehicle_id']])['status'] === 'Assigned' && $trip($T2)['status'] === 'For Reschedule'
   && $count("SELECT COUNT(*) AS n FROM trip_assignments WHERE resource_type='vehicle' AND resource_id=? AND trip_id=?", [$t2['vehicle_id'], $T2]) === 1,
   'verified: truck leaves Under Maintenance and is held only by its For Reschedule trip (Assigned, not offered to other trips)');

// ---------------------------------------------------------------------
section('H. Money, stock and audit invariants');
$exp = fn(string $src) => $count('SELECT COUNT(*) AS n FROM expenses WHERE source=?', [$src]);
ok($exp('Fund Release') === 2 && $exp('PO Payment') === 1 && $exp('Maintenance') === 1, 'one expense per fund release (2), PO payment (1) and repair cost (1); parts are not counted twice', json_encode([$exp('Fund Release'), $exp('PO Payment'), $exp('Maintenance')]));
ok(abs((float)one($p, "SELECT SUM(amount) AS s FROM disbursements")['s'] - (float)one($p, "SELECT SUM(amount) AS s FROM expenses WHERE source IN ('Fund Release','PO Payment','Payable')")['s']) < 0.005, 'cash out (vouchers) = cash-based expenses');
$bud = $budget();
$openReserved = (float)one($p, "SELECT COALESCE(SUM(reserved_amount),0) AS s FROM purchase_orders WHERE budget_id=?", [$bud['id']])['s'];
ok(abs((float)$bud['reserved_amount'] - $openReserved) < 0.005, 'budget reserved = sum of its POs\' reserved amounts');
$ledger = (int)one($p, 'SELECT COALESCE(SUM(quantity_delta),0) AS s FROM stock_movements WHERE part_id=?', [$part])['s'];
ok($ledger === $onHand(), 'stock ledger = quantity on hand', json_encode([$ledger, $onHand()]));
foreach (['Funds' => 'fund released', 'Purchasing' => null, 'Finance' => null, 'Maintenance' => null, 'Billing' => null] as $area => $action) {
    $n = $count('SELECT COUNT(*) AS n FROM audit_events WHERE area=?' . ($action ? ' AND action=?' : ''), $action ? [$area, $action] : [$area]);
    ok($n > 0, "$area actions are audit-logged ($n)");
}
ok($count('SELECT COUNT(*) AS n FROM audit_events WHERE actor_id IS NULL AND area NOT IN (\'Billing\')') === 0, 'every non-system audit event has an actor');

// ---------------------------------------------------------------------
section('I. Head Management reads the integrated records');
foreach (['admin_overview', 'admin_approvals', 'admin_operations', 'admin_maintenance', 'admin_purchasing', 'admin_finance', 'admin_reports', 'admin_analytics'] as $pg) {
    try { $dd = Oversight::read($adm, $pg, []); ok(empty($dd['_errors']), "$pg loads with the integrated data", json_encode($dd['_errors'] ?? [])); }
    catch (Throwable $e) { ok(false, "$pg loads with the integrated data", $e->getMessage()); }
}
$ov = Oversight::read($adm, 'admin_overview', []);
ok($ov['kpis']['open_pos'] === $count("SELECT COUNT(*) AS n FROM purchase_orders WHERE status IN ('Pending Approval','Approved','Partially Received')"), 'open purchase orders KPI = source rows');
fails(fn() => Oversight::read($ops, 'admin_overview', []), 'Ops Head cannot read Head Management pages', 403);

// ---------------------------------------------------------------------
section('J. Audit is read-only and can trace the records');
$lg = Admin::read($aud, 'audit_logs', []);
ok(empty($lg['_errors']) && count($lg['events']) > 0, 'Audit reads the audit log');
$h = Admin::read($aud, 'audit_history', ['record' => 'po ' . $po['id']]);
ok(empty($h['_errors']), 'Audit reads the transaction history of the PO');
fails(fn() => Billing::action($aud, 'collect', ['billing_id' => $b1['id'], 'amount' => '1', 'ar_number' => 'X', 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d')]), 'Audit cannot record collections', 403);
fails(fn() => Maintenance::action($aud, 'verify', ['job_id' => $job['id'], 'result' => 'Passed', 'verification_notes' => 'x']), 'Audit cannot verify repairs', 403);
fails(fn() => Trips::action($aud, 'dispatch', ['trip_id' => $T2]), 'Audit cannot dispatch trips', 403);

array_map('unlink', glob($tmpDir . '/*') ?: []); @rmdir($tmpDir);
echo "\nPASSED: $PASS   FAILED: $FAIL\n";
if (defined('E2E_KEEP_RUNNING')) return;   // reused as the scenario of tests/role_action_probe.php
exit($FAIL ? 1 : 0);
