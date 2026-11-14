<?php
/*
 * =====================================================================
 *  tests/workspace_access_tests.php  —  Role workspaces (Maintenance / Purchasing)
 * =====================================================================
 *  NO DATABASE NEEDED. Run:  php tests/workspace_access_tests.php
 *  Checks the page map in backend/Core/Access.php (who can open which page), that every page
 *  has a backend module and a screen file, and (by reading the source) that the Motorpool-only
 *  request actions and the role-scoped Purchasing / Maintenance reads exist.
 *  Behaviour against real data (queries, record IDs, actions) is NOT covered here: it needs the test
 *  database and a manual pass (see the implementation report).
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$root = dirname(__DIR__);
require_once $root . '/backend/Actions.php';
core('Access');

$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }
function section(string $t): void { echo "\n== $t ==\n"; }
$U = fn(string $role) => ['id' => 1, 'role' => $role];
$ids = fn(string $role) => array_column(Access::navFor($U($role)), 'id');

section('A. Landing pages exist and are limited to their role');
ok(Access::canOpen($U('motorpool'), 'maintenance_overview'), 'Motorpool opens Maintenance Overview (existing implementation preserved)');
ok(Access::canOpen($U('purchasing'), 'purchasing_overview'), 'Purchasing opens Purchasing Overview');
foreach (array_keys(ROLE_LABELS) as $r) {
    // Maintenance Overview keeps the Corrective Maintenance roles (admin, operations_head, motorpool, audit).
    $mo = in_array($r, ['admin', 'operations_head', 'motorpool', 'audit'], true);
    ok(Access::canOpen($U($r), 'maintenance_overview') === $mo, "$r " . ($mo ? 'can' : 'cannot') . ' open Maintenance Overview');
    if ($r !== 'purchasing') ok(!Access::canOpen($U($r), 'purchasing_overview'), "$r cannot open Purchasing Overview");
}

section('B. Company Dashboard kept, but not duplicated for Motorpool / Purchasing / Finance / Billing / Audit (own Overview pages)');
foreach (array_keys(ROLE_LABELS) as $r) {
    $expect = !in_array($r, ['motorpool', 'purchasing', 'finance', 'billing', 'audit'], true);
    ok(Access::canOpen($U($r), 'dashboard') === $expect, "dashboard for $r = " . ($expect ? 'yes' : 'no'));
}

section('B2. Finance workspace (FINANCE_WORKSPACE_V1)');
$fnav = array_column(Access::navFor($U('finance')), 'label', 'id');
ok(array_slice(array_keys($fnav), 0, 5) === ['finance_overview', 'purchase_orders', 'funds', 'finance', 'finance_budgets'], 'Finance menu starts Overview, Approvals, Trip Funds, Payables & Payments, Budgets & Expenses', json_encode(array_keys($fnav)));
ok(array_slice(array_values($fnav), 0, 5) === ['Overview', 'Approvals', 'Trip Funds', 'Payables & Payments', 'Budgets & Expenses'], 'Finance menu labels', json_encode(array_values($fnav)));
foreach (array_keys(ROLE_LABELS) as $r) {
    ok(Access::canOpen($U($r), 'finance_overview') === ($r === 'finance'), "finance_overview for $r = " . ($r === 'finance' ? 'yes' : 'no'));
    $fb = in_array($r, ['admin', 'finance', 'audit'], true);
    ok(Access::canOpen($U($r), 'finance_budgets') === $fb && Access::canOpen($U($r), 'finance') === $fb, "finance / finance_budgets for $r = " . ($fb ? 'yes' : 'no'));
}
ok(Access::moduleOf('finance_overview') === 'Finance' && Access::moduleOf('finance_budgets') === 'Finance', 'new Finance pages are served by the Finance module');

section('B3. Billing and Audit workspaces (BILLING_WORKSPACE_V1, AUDIT_WORKSPACE_V1)');
$bnav = array_column(Access::navFor($U('billing')), 'label', 'id');
ok(array_slice(array_values($bnav), 0, 5) === ['Overview', 'Billing & Invoices', 'Collections', 'Receivables', 'Billing Reports'], 'Billing menu: Overview, Billing & Invoices, Collections, Receivables, Billing Reports', json_encode(array_values($bnav)));
$anav = array_column(Access::navFor($U('audit')), 'label', 'id');
ok(array_slice(array_values($anav), 0, 4) === ['Overview', 'Audit Logs', 'Transaction History', 'Audit Reports'], 'Audit menu: Overview, Audit Logs, Transaction History, Audit Reports', json_encode(array_values($anav)));
foreach (array_keys(ROLE_LABELS) as $r) {
    ok(Access::canOpen($U($r), 'billing_overview') === ($r === 'billing'), "billing_overview only for billing ($r)");
    $bc = in_array($r, ['admin', 'billing', 'finance', 'audit'], true);
    ok(Access::canOpen($U($r), 'billing_collections') === $bc && Access::canOpen($U($r), 'billing_receivables') === $bc, "collections / receivables for $r = " . ($bc ? 'yes' : 'no'));
    ok(Access::canOpen($U($r), 'audit_overview') === ($r === 'audit'), "audit_overview only for audit ($r)");
    $al = in_array($r, ['admin', 'audit'], true);
    foreach (['audit_logs', 'audit_history', 'audit_reports'] as $pg) ok(Access::canOpen($U($r), $pg) === $al, "$pg for $r = " . ($al ? 'yes' : 'no'));
}
ok(!Access::canOpen($U('audit'), 'admin') && Access::canOpen($U('admin'), 'admin'), 'User Management is Admin only (Audit uses Audit Logs instead of the former Activity Log view)');
ok(Access::moduleOf('audit_logs') === 'Admin' && Access::moduleOf('billing_collections') === 'Billing', 'audit pages served by Admin (audit_events), billing pages by Billing');

section('C. Motorpool workspace');
$mp = $ids('motorpool');
foreach (['maintenance_overview', 'maintenance', 'trucks', 'mechanics', 'maint_history'] as $p) ok(in_array($p, $mp, true), "Motorpool menu has $p");
foreach (['purchase_orders', 'inventory', 'stock_movements', 'parts_requests', 'purchasing_reports', 'purchasing_overview', 'finance', 'funds', 'billing', 'admin', 'crew'] as $p) {
    ok(!in_array($p, $mp, true) && !Access::canOpen($U('motorpool'), $p), "Motorpool cannot open $p");
}
ok(array_column(Access::navFor($U('motorpool')), 'label', 'id')['maintenance'] === 'Repair Management', 'Motorpool menu names the maintenance page Repair Management');
ok(!isset(Access::pages()['purchasing']), 'the combined Parts & Purchasing page is gone');

section('C2. Motorpool Phase 1: Parts Requests and Mechanic Attendance pages');
foreach (array_keys(ROLE_LABELS) as $r) {
    $pr = $r === 'motorpool';
    ok(Access::canOpen($U($r), 'mp_parts_requests') === $pr, "$r " . ($pr ? 'can' : 'cannot') . ' open mp_parts_requests');
    $ma = in_array($r, ['admin', 'operations_head', 'motorpool', 'audit'], true);
    ok(Access::canOpen($U($r), 'mechanic_attendance') === $ma, "$r " . ($ma ? 'can' : 'cannot') . ' open mechanic_attendance (same roles as the Mechanic Masterlist)');
    ok(Access::canOpen($U($r), 'mechanic_attendance') === Access::canOpen($U($r), 'mechanics'), "$r: mechanic_attendance access equals mechanics access");
}
foreach (['mp_parts_requests', 'mechanic_attendance'] as $p) ok(in_array($p, $mp, true), "Motorpool menu has $p");
ok(!Access::canOpen($U('motorpool'), 'parts_requests'), 'Motorpool still cannot open the Purchasing parts_requests page');
// UI_REFERENCE_V1: Maintenance Reports replaces Motorpool's use of the general Reports page.
foreach (['admin', 'operations_head', 'motorpool', 'audit'] as $r) ok(Access::canOpen($U($r), 'maintenance_reports'), "$r opens maintenance_reports");
foreach (['purchasing', 'finance', 'billing'] as $r) ok(!Access::canOpen($U($r), 'maintenance_reports'), "$r cannot open maintenance_reports");
ok(!Access::canOpen($U('motorpool'), 'reports'), 'Motorpool no longer has the general Reports page (Maintenance Reports instead)');
ok(str_contains(file_get_contents(__DIR__ . '/../backend/Modules/General/Analytics.php'), "must(Access::canOpen(\$u, 'maintenance_reports')"), 'maintenanceReport checks page access itself');
$core = (string)file_get_contents($root . '/public/assets/js/core.js');
ok(str_contains($core, "motorpool: ['maintenance_overview', 'maintenance', 'mp_parts_requests', 'mechanics', 'mechanic_attendance', 'maint_history', 'trucks']"), 'Motorpool sidebar order: Overview, Repair Management, Parts Requests, Mechanic Masterlist, Mechanic Attendance, Maintenance History, Truck Masterlist');
ok(str_contains($core, "motorpool: 'mp_parts_requests'"), 'legacy #purchasing link sends Motorpool to mp_parts_requests');
$purC2 = (string)file_get_contents($root . '/backend/Modules/Maintenance/Purchasing.php');
ok(str_contains($purC2, "if (\$page === 'mp_parts_requests') return self::motorpoolPage(\$u, \$p);"), 'Purchasing::read routes mp_parts_requests to motorpoolPage');
ok(preg_match('/function motorpoolPage\(array \$u, PDO \$p\): array \{\s*requireRole\(\$u, \'motorpool\'\)/', $purC2) === 1, 'motorpoolPage is Motorpool only');
$mpSrc = substr($purC2, (int)strpos($purC2, 'function motorpoolPage('), (int)strpos($purC2, 'function myRequests(') - (int)strpos($purC2, 'function motorpoolPage('));
ok($mpSrc !== '' && !preg_match('/unit_cost|quantity_on_hand|stock_value|budget|supplier|purchase_orders|total_amount/', $mpSrc), 'motorpoolPage reads no cost, stock, budget, supplier or PO data');
ok(str_contains($purC2, "is ' . \$status . '.', 'mp_parts_requests')"), 'processed-request notification opens mp_parts_requests');
ok(!preg_match("/Notify::[a-z]+\([^;]*'purchasing'\)/", $purC2), "no Purchasing notification targets the removed 'purchasing' page");
$mecC2 = (string)file_get_contents($root . '/backend/Modules/Maintenance/Mechanics.php');
ok(str_contains($mecC2, "if (\$page === 'mechanic_attendance') return self::attendancePage("), 'Mechanics::read routes mechanic_attendance to attendancePage');
ok(str_contains($mecC2, "if (!hasRole(\$u, 'admin')) foreach (\$data['board'] as &\$r) unset(\$r['daily_mark']);"), 'daily Present/Absent mark is removed for every role except Admin');
$mntJs = (string)file_get_contents($root . '/public/assets/js/modules/maintenance/maintenance.js');
ok(!str_contains($mntJs, "maintenance#_=parts"), 'no link to the old Repair Management parts tab remains');
ok(substr_count($mntJs, "Purchasing.my_requests") === 0, 'the request list is not loaded a second time in Repair Management (no duplicate list)');

section('D. Purchasing workspace');
$pu = $ids('purchasing');
foreach (['purchasing_overview', 'parts_requests', 'purchase_orders', 'inventory', 'stock_movements', 'purchasing_reports'] as $p) ok(in_array($p, $pu, true), "Purchasing menu has $p");
foreach (['maintenance', 'maint_history', 'maintenance_overview', 'mechanics', 'trucks', 'reports', 'finance', 'funds', 'billing', 'admin'] as $p) ok(!Access::canOpen($U('purchasing'), $p), "Purchasing cannot open $p");

section('E. Finance / Admin / Audit keep only what they need');
foreach (['purchase_orders', 'purchasing_reports'] as $p) ok(Access::canOpen($U('finance'), $p), "Finance opens $p");
foreach (['parts_requests', 'inventory', 'stock_movements', 'purchasing_overview'] as $p) ok(!Access::canOpen($U('finance'), $p), "Finance cannot open $p");
foreach (['admin', 'audit'] as $r) foreach (['purchase_orders', 'parts_requests', 'inventory', 'stock_movements', 'purchasing_reports'] as $p) ok(Access::canOpen($U($r), $p), "$r opens $p (read access kept)");
foreach (['car_carrier_dispatcher', 'container_wingvan_dispatcher', 'billing', 'operations_head'] as $r) {
    foreach (['purchase_orders', 'parts_requests', 'inventory', 'stock_movements', 'purchasing_reports'] as $p) ok(!Access::canOpen($U($r), $p), "$r cannot open $p");
}

section('F. Every page has a backend module and a screen file that the app loads');
$idx = (string)file_get_contents($root . '/public/index.php');
foreach (Access::pages() as $id => [$label, $icon, $section, $module, $js]) {
    ok(isset(Actions::MODULES[$module]), "$id -> module $module is registered");
    ok(is_file($root . "/public/assets/js/modules/$js.js"), "$id -> screen file $js.js exists");
    ok(str_contains($idx, "'$js'"), "$id -> $js is in the script list of public/index.php");
}
$screens = '';
foreach (glob($root . '/public/assets/js/modules/*/*.js') as $f) $screens .= file_get_contents($f);
foreach (array_keys(Access::pages()) as $id) ok(str_contains($screens, "App.page('$id'"), "$id is registered by a screen file");

section('G. Source checks: scoped reads and Motorpool-only actions');
$pur = (string)file_get_contents($root . '/backend/Modules/Maintenance/Purchasing.php');
foreach (['purchasing_overview', 'parts_requests', 'purchase_orders', 'inventory', 'stock_movements'] as $p) ok(str_contains($pur, "'$p' =>"), "Purchasing::read handles $p");
ok(preg_match('/function requestCatalog\(array \$u\): array \{\s*requireRole\(\$u, \'motorpool\'\)/', $pur) === 1, 'request_catalog is Motorpool only');
ok(preg_match('/function myRequests\(array \$u\): array \{\s*requireRole\(\$u, \'motorpool\'\)/', $pur) === 1, 'my_requests is Motorpool only');
ok(!preg_match('/function requestCatalog.*?unit_cost/s', substr($pur, strpos($pur, 'function requestCatalog'), 400)), 'request_catalog does not return unit cost');
ok(str_contains($pur, "Access::canOpen(\$u, 'purchase_orders')"), 'po_details requires the Purchase Orders page right');
ok(str_contains($pur, "ru.role='motorpool'"), "Motorpool request reads are limited to Motorpool's own requests");
ok(substr_count($pur, "requireRole(\$u, 'purchasing')") >= 6, "stock-changing actions still call requireRole('purchasing')");
$mnt = (string)file_get_contents($root . '/backend/Modules/Maintenance/Maintenance.php');
ok(str_contains($mnt, "'maintenance_overview') return self::overview"), 'Maintenance::read handles maintenance_overview (existing implementation)');
ok(str_contains($mnt, "hasRole(\$u, 'motorpool') || Access::canOpen(\$u, 'parts_requests')"), 'Overview parts data is limited to Motorpool and Parts Requests roles');
ok(str_contains($mnt, "'maint_history') return self::history"), 'Maintenance::read handles maint_history');
$ovSrc = substr($mnt, (int)strpos($mnt, 'function overview('), (int)strpos($mnt, 'function activityLabel') - (int)strpos($mnt, 'function overview('));
ok($ovSrc !== '' && !preg_match('/purchase_orders|budgets|suppliers|unit_cost|quantity_on_hand/', $ovSrc), 'Maintenance Overview reads no purchase-order, budget, supplier, cost or stock-level data');
$ana = (string)file_get_contents($root . '/backend/Modules/General/Analytics.php');
ok(str_contains($ana, "'purchasing_reports'"), 'Analytics serves purchasing_reports');
ok(str_contains($ana, "'filterOptions' => \$purchasing ? []"), 'Purchasing Reports returns no client / driver lists');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
