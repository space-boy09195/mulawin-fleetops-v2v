<?php
/*
 * =====================================================================
 *  tests/uat_browser_scenario.php  —  data for browser UAT (synthetic, isolated *_test database only)
 * =====================================================================
 *  Run through tests/run_uat_browser_scenario.php (keeps the database). Every record is created with the REAL actions;
 *  each flow STOPS where one role must act in the browser:
 *    Dispatcher: UAT-ASSIGN Awaiting Resources (assign fixed pair)       Ops Head: UAT-FUND fund Pending Approval
 *    Ops Head:   UAT-NOTFIT Minor + Not Fit, On Hold (For Reschedule)     Finance:  UAT-RELEASE fund Ready for Release
 *    Billing:    UAT-BILL completed, For Billing                          Motorpool: UAT-INSPECT Minor assessed (inspection-only)
 *    Purchasing: parts request Requested; PO Approved to receive          Finance:  PO Pending Approval; PO received unpaid
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/backend/Actions.php';
require_once __DIR__ . '/_launcher.php';
core('Access'); core('Settings');
require_once dirname(__DIR__) . '/backend/Modules/Operations/Inspections/Checklist.php';
$DB = envValue('DB_NAME');
test_guard([$DB]);
function key36(): string { $h = bin2hex(random_bytes(16)); return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12); }
test_recreate($DB);
$p = db();
test_import($p, test_sql('database/schema.sql'));
test_import($p, test_sql('database/extension.sql'));
$users = [];
foreach (['admin' => null, 'operations_head' => null, 'container_wingvan_dispatcher' => 'Day', 'finance' => null, 'billing' => null, 'motorpool' => null, 'purchasing' => null, 'audit' => null] as $role => $shift) {
    $id = insert($p, 'users', ['username' => 'uat_' . $role, 'email' => "uat_$role@example.test", 'password_hash' => 'x', 'full_name' => 'UAT ' . $role, 'role' => $role, 'shift' => $shift]);
    $users[$role] = ['id' => $id, 'role' => $role, 'shift' => $shift, 'full_name' => 'UAT ' . $role, 'username' => 'uat_' . $role, 'email' => "uat_$role@example.test"];
}
['admin' => $adm, 'operations_head' => $ops, 'container_wingvan_dispatcher' => $wvd, 'finance' => $fin, 'billing' => $bil, 'motorpool' => $mp, 'purchasing' => $pur] = $users;
foreach (['Trips', 'Funds', 'Billing', 'Finance', 'Purchasing', 'Maintenance', 'Mechanics', 'Admin', 'Attendance'] as $m) Actions::load($m);
$cid = insert($p, 'clients', ['company_name' => 'UAT CLIENT CORPORATION', 'status' => 'Active', 'created_by' => $ops['id'], 'payment_terms_days' => 15]);
for ($i = 1; $i <= 8; $i++) {
    insert($p, 'vehicles', ['plate_number' => "UAT-00$i", 'truck_type' => 'Wing Van']);
    insert($p, 'personnel', ['full_name' => "UAT Driver $i", 'person_type' => 'Driver', 'license_number' => "UAT-LIC-$i", 'license_expiry' => '2099-12-31']);
    insert($p, 'personnel', ['full_name' => "UAT Helper $i", 'person_type' => 'Helper']);
}
Admin::action($adm, 'setting_save', ['key' => 'inspection_min_minutes', 'value' => '0']);
Admin::action($adm, 'setting_save', ['key' => 'dispatch_early_minutes', 'value' => '240']);
foreach (['diesel' => '60', 'Wing Van' => '3.5'] as $k => $v) Admin::action($adm, 'pricing_save', ['setting_key' => $k, 'value' => $v, 'effective_date' => date('Y-m-d'), 'reference' => 'UAT fixture']);
Admin::action($adm, 'pricing_save', ['setting_key' => 'garage', 'lat' => 14.12, 'lng' => 121.07, 'verified' => '1', 'effective_date' => date('Y-m-d'), 'reference' => 'UAT fixture']);
Settings::clearCache();
Finance::action($fin, 'budget_set', ['amount' => '100000', 'period_start' => date('Y-m-01'), 'period_end' => date('Y-m-t')]);
insert($p, 'client_rates', ['client_id' => $cid, 'truck_type' => 'Wing Van', 'rate_per_km' => '55', 'minimum_charge' => '0', 'updated_by' => $ops['id']]);
$route = insert($p, 'client_routes', ['route_ref' => 'UAT-ROUTE', 'client_id' => $cid, 'truck_type' => 'Wing Van', 'origin_label' => 'UAT garage pickup', 'destination_label' => 'UAT destination',
    'origin_key' => 'uat origin', 'destination_key' => 'uat destination', 'origin_lat' => 14.12, 'origin_lng' => 121.07, 'destination_lat' => 14.30, 'destination_lng' => 121.10,
    'distance_km' => '40.00', 'distance_source' => 'Map', 'status' => 'Approved', 'requested_by' => $ops['id']]);
insert($p, 'client_route_rates', ['route_id' => $route, 'version_no' => 1, 'rate_type' => 'Flat', 'rate_amount' => '4000', 'status' => 'Approved', 'entered_by' => $ops['id'], 'effective_from' => date('Y-m-d H:i:s', time() - 60)]);
$mech = [];
foreach (['A', 'B'] as $n) { $mech[$n] = (int)Mechanics::action($mp, 'save', ['full_name' => "UAT Mechanic $n"])['id']; Mechanics::action($mp, 'time_in', ['id' => $mech[$n]]); }
$tmp = sys_get_temp_dir() . '/uat_' . bin2hex(random_bytes(3)); mkdir($tmp);
$file = function (string $name, bool $png = false) use ($tmp): array { $path = $tmp . '/' . bin2hex(random_bytes(5));
    $bytes = $png ? base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==') : "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    file_put_contents($path, $bytes); return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)]; };
$items = [];
foreach (Checklist::sections() as $s) foreach ($s['items'] as $i => $label) $items[$s['key'] . '_' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT)] = ['condition' => 'OK', 'remarks' => ''];
$plan = fn(string $tag, bool $assign = true) => (int)test_create_planned_trip($ops, ['client_id' => $cid, 'truck_type' => 'Wing Van', 'pickup_point' => "$tag pickup", 'delivery_point' => "$tag delivery",
    'scheduled_at' => date('Y-m-d\TH:i', time() + 3600), 'distance_km' => '40', 'request_key' => key36(), 'booking_reference' => $tag], $assign)['trip_id'];
$fundUpTo = function (int $tid, string $stop) use ($wvd, $ops, $fin): int {
    $f = (int)Funds::action($wvd, 'submit', ['trip_id' => $tid, 'categories' => ['Driver Meal Allowance', 'Helper Meal Allowance']])['fund_id'];
    if ($stop === 'pending') return $f;
    Funds::action($ops, 'decide', ['fund_id' => $f, 'decision' => 'approve']);
    if ($stop === 'ready') return $f;
    Funds::action($fin, 'release', ['fund_id' => $f]); return $f;
};
$dispatch = function (int $tid) use ($wvd, $items): void {
    Trips::action($wvd, 'checklist_start', ['trip_id' => $tid, 'kind' => 'Pre-Dispatch']);
    Trips::action($wvd, 'checklist', ['trip_id' => $tid, 'items' => $items, 'meta' => [], 'odometer_km' => '1000', 'fuel_level' => 'Full']);
    Trips::action($wvd, 'dispatch', ['trip_id' => $tid]);
};
$incident = function (int $tid, string $problem) use ($wvd, $p, $file): array {
    $t = one($p, 'SELECT dispatched_at FROM trips WHERE id=?', [$tid]);
    while (date('Y-m-d H:i:00') < $t['dispatched_at']) sleep(1);
    $_FILES = ['photos' => $file('damage.png', true)];
    try { $r = Trips::action($wvd, 'incident', ['trip_id' => $tid, 'incident_type' => 'Breakdown', 'incident_at' => date('Y-m-d\TH:i'), 'location' => 'UAT road', 'problem' => $problem, 'details' => 'UAT']); } finally { $_FILES = []; }
    return [(int)$r['incident_id'], (int)one($p, 'SELECT id FROM maintenance_jobs WHERE incident_id=?', [$r['incident_id']])['id']];
};

$plan('UAT-ASSIGN', false);                                              // Dispatcher assigns in the browser
$fundUpTo($plan('UAT-FUND'), 'pending');                                 // Ops Head approves the fund
$fundUpTo($plan('UAT-RELEASE'), 'ready');                                // Finance releases
$tb = $plan('UAT-BILL'); $fundUpTo($tb, 'released'); $dispatch($tb);     // Billing: completed trip
$_FILES = ['waybill' => $file('wb.pdf'), 'ack_receipt' => $file('ar.pdf'), 'delivery_receipt' => $file('dr.pdf')];
try { Trips::action($wvd, 'complete', ['trip_id' => $tb, 'completed_at' => date('Y-m-d\TH:i'), 'waybill_number' => 'WB-UAT-1']); } finally { $_FILES = []; }
Trips::action($wvd, 'arrival', ['trip_id' => $tb, 'items' => $items, 'fuel_level' => 'Full']);
$tn = $plan('UAT-NOTFIT'); $fundUpTo($tn, 'released'); $dispatch($tn);   // Ops Head: Minor + Not Fit
[$in, $jn] = $incident($tn, 'Clutch slipping');
Maintenance::action($mp, 'assess', ['job_id' => $jn, 'severity' => 'Minor', 'recommendation' => 'Check clutch on site', 'mechanic_id' => $mech['A']]);
Maintenance::action($mp, 'clearance', ['job_id' => $jn, 'decision' => 'Not Fit to Continue', 'findings' => 'Clutch plate worn; not repairable on site']);
$ti = $plan('UAT-INSPECT'); $fundUpTo($ti, 'released'); $dispatch($ti);  // Motorpool: inspection-only clearance
[$ii, $ji] = $incident($ti, 'Dashboard warning light');
Maintenance::action($mp, 'assess', ['job_id' => $ji, 'severity' => 'Minor', 'recommendation' => 'Inspect sensor', 'mechanic_id' => $mech['B']]);
// Purchasing / Finance pending work
Purchasing::action($pur, 'part_save', ['part_number' => 'UAT-FILTER', 'description' => 'Oil filter', 'category' => 'Engine', 'unit' => 'pc', 'unit_cost' => '450', 'reorder_level' => '2']);
$part = (int)one($p, "SELECT id FROM parts WHERE part_number='UAT-FILTER'")['id'];
$po = function (int $qty, string $purpose) use ($pur, $part, $p): int { Purchasing::action($pur, 'po_create', ['supplier_name' => 'UAT SUPPLIER', 'supplier_address' => 'UAT', 'purpose' => $purpose, 'lines' => [['part_id' => $part, 'quantity' => $qty, 'unit_price' => '450']]]); return (int)one($p, 'SELECT MAX(id) AS id FROM purchase_orders')['id']; };
$poPending = $po(2, 'UAT: PO waiting for Finance approval');
$poReceive = $po(4, 'UAT: approved PO to receive'); Purchasing::action($fin, 'po_decide', ['po_id' => $poReceive, 'decision' => 'approve']);
$poPay = $po(3, 'UAT: received PO to pay'); Purchasing::action($fin, 'po_decide', ['po_id' => $poPay, 'decision' => 'approve']);
Purchasing::action($pur, 'po_receive', ['po_id' => $poPay, 'receive' => [['line_id' => (int)one($p, 'SELECT id FROM purchase_order_lines WHERE purchase_order_id=?', [$poPay])['id'], 'qty' => 3]], 'supplier_invoice_no' => 'SI-UAT-1']);
$freeTruck = (int)one($p, "SELECT id FROM vehicles WHERE status='Available' AND active=1 LIMIT 1")['id'];
$_FILES = ['photos' => $file('report.png', true)];
try { $gr = Maintenance::action($mp, 'report', ['vehicle_id' => $freeTruck, 'problem' => 'Oil leak (UAT garage report)', 'reported_on' => date('Y-m-d')]); } finally { $_FILES = []; }
$gj = (int)one($p, 'SELECT id FROM maintenance_jobs WHERE vehicle_id=? AND open_key=1', [$freeTruck])['id'];
$mc = (int)Mechanics::action($mp, 'save', ['full_name' => 'UAT Mechanic C'])['id']; Mechanics::action($mp, 'time_in', ['id' => $mc]);
Maintenance::action($mp, 'assign_mechanic', ['job_id' => $gj, 'mechanic_id' => $mc]);   // parts are requested for a record Under Inspection / Repair
Purchasing::action($mp, 'request_create', ['vehicle_id' => $freeTruck, 'maintenance_job_id' => (int)one($p, 'SELECT id FROM maintenance_jobs WHERE vehicle_id=? AND open_key=1', [$freeTruck])['id'], 'purpose' => 'UAT: oil filter for the garage repair', 'lines' => [['part_id' => $part, 'quantity' => 2]]]);
array_map('unlink', glob($tmp . '/*') ?: []); @rmdir($tmp);
echo "SCENARIO READY in $DB\n";
foreach (many($p, 'SELECT t.booking_reference AS tag, t.trip_ref, t.status FROM trips t ORDER BY t.id') as $r) echo "  {$r['tag']}: {$r['trip_ref']} {$r['status']}\n";
echo "  POs: " . json_encode(many($p, 'SELECT po_ref, status FROM purchase_orders ORDER BY id')) . "\n";
exit(0);
