<?php
/*
 * =====================================================================
 *  tests/accounting_workspace_tests.php  —  Finance / Billing / Audit workspace reads (FINANCE_, BILLING_, AUDIT_WORKSPACE_V1)
 * =====================================================================
 *  Do NOT run this file directly: run tests/run_accounting_workspace_tests.php (temporary copy, own .env, *_test database).
 *    A. Billing through the real actions (verify -> finalize -> collect): Overview / Collections / Receivables figures
 *       equal sums over the stored invoices; overdue and aging buckets as of today
 *    B. Finance Overview: PO payments due and payables due equal the Payables & Payments rows; page payloads per page
 *    C. Audit pages: events of A/B appear with actor, type and field changes; record timeline; reports counts = log count
 *    D. Roles: real dispatcher (child process) refuses the new pages to the wrong roles; module checks refuse too
 *    E. All new reads write nothing
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/backend/Actions.php';
require_once __DIR__ . '/_launcher.php';
core('Access'); core('Settings');

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
$near = fn($a, $b) => abs((float)$a - (float)$b) < 0.005;

section('Setup: disposable database');
test_recreate($DB);
$p = db();
ok($p->query('SELECT DATABASE()')->fetchColumn() === $DB, 'db() is the disposable test database');
test_import($p, test_sql('database/schema.sql'));
test_import($p, test_sql('database/extension.sql'));
$staff = ['admin' => ['tvillanueva', 'Teresita Villanueva'], 'operations_head' => ['rmanalo', 'Ricardo Manalo'], 'motorpool' => ['ebautista', 'Ernesto Bautista'],
    'purchasing' => ['msantos', 'Maricel Santos'], 'finance' => ['laquino', 'Lourdes Aquino'], 'billing' => ['jramos', 'Jocelyn Ramos'], 'audit' => ['amendoza', 'Arnel Mendoza'],
    'car_carrier_dispatcher' => ['jcastillo', 'Jomar Castillo']];
$users = [];
foreach ($staff as $role => [$uname, $full]) {
    $id = insert($p, 'users', ['username' => $uname, 'email' => "$uname@mulawinfleet.example", 'password_hash' => 'x', 'full_name' => $full, 'role' => $role]);
    $users[$role] = ['id' => $id, 'username' => $uname, 'email' => "$uname@mulawinfleet.example", 'full_name' => $full, 'role' => $role, 'shift' => null];
}
$U = fn($r) => $users[$r];
foreach (['Trips', 'Billing', 'Finance', 'Admin', 'Purchasing'] as $m) Actions::load($m);
$tmpDir = sys_get_temp_dir() . '/fleet_acct_' . bin2hex(random_bytes(4)); mkdir($tmpDir);
$pdf = function (string $name) use ($tmpDir): array {
    $path = $tmpDir . '/' . bin2hex(random_bytes(6)); $bytes = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"; file_put_contents($path, $bytes);
    return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
};
$ops = $U('operations_head'); $bil = $U('billing'); $fin = $U('finance'); $aud = $U('audit');
$cid = insert($p, 'clients', ['company_name' => 'TANAUAN AGRI-PACK CORPORATION', 'status' => 'Active', 'created_by' => $ops['id'], 'payment_terms_days' => 15]);
insert($p, 'client_rates', ['client_id' => $cid, 'truck_type' => 'Wing Van', 'rate_per_km' => '55.00', 'minimum_charge' => '0.00', 'updated_by' => $bil['id']]);
$n = 0;
$billing = function (float $km) use ($p, $ops, $cid, &$n): int {
    $n++;
    $t = (int)test_create_planned_trip($ops, ['client_id' => $cid, 'truck_type' => 'Wing Van', 'pickup_point' => "Tanauan warehouse $n", 'delivery_point' => "Calamba DC $n",
        'scheduled_at' => date('Y-m-d\TH:i', time() + 3600), 'distance_km' => (string)$km])['trip_id'];
    run($p, "UPDATE trips SET status='Completed', dispatched_at=DATE_SUB(NOW(), INTERVAL 5 HOUR), completed_at=DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id=?", [$t]);   // test database only
    $amt = number_format($km * 55, 2, '.', '');
    $b = insert($p, 'billings', ['trip_id' => $t, 'client_id' => $cid, 'status' => 'For Billing', 'distance_km' => number_format($km, 2, '.', ''), 'rate_per_km' => '55.00', 'minimum_charge' => '0.00',
        'base_amount' => $amt, 'charges_total' => '0.00', 'total_amount' => $amt]);
    foreach (['Waybill', 'Delivery Receipt', 'Acknowledgement Receipt'] as $cat) insert($p, 'documents', ['category' => $cat, 'entity_type' => 'trip', 'entity_id' => $t,
        'original_filename' => "$cat.pdf", 'storage_name' => bin2hex(random_bytes(20)) . '.pdf', 'mime_type' => 'application/pdf', 'byte_size' => 10, 'uploaded_by' => $ops['id']]);
    return $b;
};
$collect = function (int $id, float $amount, string $ar) use ($bil, $pdf) {
    $_FILES = ['ack_receipt' => $pdf('acknowledgement_receipt.pdf')];
    try { return Billing::action($bil, 'collect', ['billing_id' => $id, 'amount' => $amount, 'ar_number' => $ar, 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d')]); } finally { $_FILES = []; }
};

// ---------------------------------------------------------------------
section('A. Billing workspace through real actions');
$B1 = $billing(120); $B2 = $billing(80); $B3 = $billing(40);   // 6,600 / 4,400 / 2,200
foreach ([$B1, $B2] as $b) Billing::action($bil, 'verify', ['billing_id' => $b]);
Billing::action($bil, 'finalize', ['billing_id' => $B1, 'invoice_date' => date('Y-m-d', strtotime('-40 days'))]);   // due 25 days ago
Billing::action($bil, 'finalize', ['billing_id' => $B2, 'invoice_date' => date('Y-m-d')]);                          // due in 15 days
$collect($B1, 2600, 'AR-1001');
$o = Billing::read($bil, 'billing_overview', []);
ok(empty($o['_errors']), 'Billing Overview: no section error on the full schema', json_encode($o['_errors'] ?? []));
ok((int)$o['counts']['for_billing'] === 1 && (int)$o['counts']['unpaid'] === 2, 'counts: 1 trip to bill, 2 unpaid invoices');
ok($near($o['counts']['outstanding'], 4000 + 4400), 'outstanding = 4,000 (6,600 - 2,600 paid) + 4,400', (string)$o['counts']['outstanding']);
ok($near($o['counts']['overdue'], 4000) && (int)$o['counts']['overdue_n'] === 1, 'overdue = the invoice due 25 days ago (4,000)');
ok($near($o['collectedMonth']['total'], 2600) && (int)$o['collectedMonth']['n'] === 1, 'collected this month = 2,600 (1 payment)');
ok($near($o['counts']['billed_month'], 4400), 'billed this month = invoices dated this month only (4,400)');
ok(count($o['forBilling']) === 1 && (int)$o['forBilling'][0]['docs_complete'] === 1 && $o['forBilling'][0]['docs_verified_at'] === null, 'trip to bill listed with complete, not yet verified documents');
ok(count($o['overdue']) === 1 && (int)$o['overdue'][0]['days_overdue'] === 25, 'overdue follow-up list: 25 days overdue');
$ag = $o['aging'][0] ?? [];
ok($near($ag['balance'], 8400) && $near($ag['d1_30'], 4000) && $near($ag['not_due'], 4400) && $near($ag['d31_60'], 0) && $near($ag['d60'], 0), 'aging buckets: 4,000 in 1-30 days, 4,400 not yet due', json_encode($ag));
$r = Billing::read($bil, 'billing_receivables', []);
ok(count($r['open']) === 2 && $near(array_sum(array_column($r['open'], 'balance')), $o['counts']['outstanding']), 'Receivables open invoices sum = Overview outstanding');
ok($r['open'][0]['last_payment'] === date('Y-m-d') && $r['collections'] === [], 'Receivables shows the last payment date and no collection list');
$c = Billing::read($bil, 'billing_collections', []);
ok(count($c['collections']) === 1 && $c['collections'][0]['ar_number'] === 'AR-1001' && (int)$c['collections'][0]['billing_id'] === $B1, 'Collections lists the payment with its invoice link');
$full = Billing::read($bil, 'billing', []);
ok(empty($full['_errors']) && count($full['billings']) === 3, 'Billing & Invoices keeps its full payload (3 billing records)');
$collect($B1, 4000, 'AR-1002');
ok(Billing::read($bil, 'billing_overview', [])['counts']['overdue_n'] == 0 && one($p, 'SELECT status FROM billings WHERE id=?', [$B1])['status'] === 'Paid', 'paying the balance clears the overdue invoice (Paid)');

// ---------------------------------------------------------------------
section('B. Finance workspace reads');
Finance::action($fin, 'budget_set', ['amount' => 80000, 'period_start' => date('Y-m-01'), 'period_end' => date('Y-m-t')]);
$_FILES = []; Finance::action($fin, 'payable_add', ['payee' => 'MERALCO', 'category' => 'Utilities', 'description' => 'Garage electricity, October', 'amount' => 5400, 'due_on' => date('Y-m-d', strtotime('-3 days'))]);
Finance::action($fin, 'payable_add', ['payee' => 'MAYNILAD', 'category' => 'Utilities', 'description' => 'Garage water, October', 'amount' => 1250, 'due_on' => date('Y-m-d', strtotime('+10 days'))]);
$pur = $U('purchasing');
Purchasing::action($pur, 'part_save', ['part_number' => 'OIL-FLT-6HK1', 'description' => 'Oil filter, Isuzu 6HK1', 'category' => 'Filters', 'unit' => 'pc', 'unit_cost' => 850, 'reorder_level' => 2]);
$part = (int)one($p, "SELECT id FROM parts WHERE part_number='OIL-FLT-6HK1'")['id'];
Purchasing::action($pur, 'po_create', ['supplier_name' => 'Bagong Pag-asa Truck Parts Trading', 'purpose' => 'Oil filters for PMS', 'lines' => [['part_id' => $part, 'quantity' => 6, 'unit_price' => 850]]]);
$po = (int)one($p, 'SELECT MAX(id) AS id FROM purchase_orders')['id'];
$fo = Finance::read($fin, 'finance_overview', []);
ok(empty($fo['_errors']) && (int)$fo['counts']['po_approvals'] === 1 && count($fo['approvals']) === 1, 'Finance Overview: 1 PO waiting for Finance approval');
Purchasing::action($fin, 'po_decide', ['po_id' => $po, 'decision' => 'approve']);
$line = one($p, 'SELECT id FROM purchase_order_lines WHERE purchase_order_id=?', [$po]);
Purchasing::action($pur, 'po_receive', ['po_id' => $po, 'receive' => [['line_id' => $line['id'], 'qty' => 4]]]);
$fo = Finance::read($fin, 'finance_overview', []); $pp = Finance::read($fin, 'finance', []);
$poDue = array_sum(array_map(fn($o) => max(0, $o['received_value'] - $o['paid_amount']), $pp['poPayables']));
ok($near($fo['counts']['po_due']['total'], 3400) && $near($poDue, 3400), 'PO payments due = received not paid (4 x 850 = 3,400) on both pages');
$apDue = array_sum(array_map(fn($x) => $x['status'] !== 'Paid' ? $x['balance'] : 0, $pp['payables']));
ok($near($fo['counts']['ap_due']['total'], 6650) && $near($apDue, 6650) && (int)$fo['counts']['ap_due']['overdue'] === 1, 'payables due 6,650 (1 overdue) on both pages');
ok(count($fo['paymentsDue']) === 3 && $fo['paymentsDue'][0]['ref'] !== null, 'payments due list: 1 PO + 2 payables');
ok((int)$fo['budget']['in_period'] === 1 && $near($fo['budget']['reserved_amount'], 5100), 'budget: in period, 5,100 reserved by the approved PO');
ok(array_keys(array_diff_key($pp, ['_errors' => 1])) === ['poPayables', 'payables', 'disbursements', 'suppliers', 'trucks', 'period', 'categories', 'methods', 'can'], 'Payables & Payments payload: payables, vouchers and form lists only');
$fb = Finance::read($fin, 'finance_budgets', []);
ok(isset($fb['budgets'], $fb['expenses'], $fb['expenseMix'], $fb['cashflow']) && !isset($fb['poPayables']), 'Budgets & Expenses payload: budgets, expenses, mix, cash flow only');

// ---------------------------------------------------------------------
section('C. Audit pages');
$lg = Admin::read($aud, 'audit_logs', []);
$acts = array_column($lg['events'], 'action');
$billingEvents = array_values(array_filter($lg['events'], fn($e) => $e['area'] === 'Billing'));
ok(empty($lg['_errors']) && count($billingEvents) >= 6, 'Audit Logs include the billing events (2 verified, 2 finalized, 2 payments)', json_encode(array_column($billingEvents, 'action')));
$ap = array_values(array_filter($lg['events'], fn($e) => $e['action'] === 'purchase order approved'));
ok(count($ap) === 1 && $ap[0]['type'] === 'Approval' && $ap[0]['actor'] === 'Lourdes Aquino' && $ap[0]['has_changes'] === true, 'PO approval: type Approval, actor Finance, field changes recorded');
ok(count(Admin::read($aud, 'audit_logs', ['area' => 'Finance'])['events']) === count(array_filter($lg['events'], fn($e) => $e['area'] === 'Finance')), 'area filter = the same events');
$h = Admin::read($aud, 'audit_history', ['record' => 'po ' . $po]);
ok(count($h['timeline']) === 3 && in_array('po ' . $po, array_column($h['records'], 'record_ref'), true), 'Transaction History: the PO timeline has its 3 events (created, approved, received)');
$rp = Admin::read($aud, 'audit_reports', []);
ok($rp['summary']['total'] === count($lg['events']) && array_sum($rp['summary']['byType']) === $rp['summary']['total'] && !isset($rp['events']), 'Audit Reports: counts = log events; no event details sent');
$ov = Admin::read($aud, 'audit_overview', []);
ok(empty($ov['_errors']) && (int)$ov['totals']['all_time'] === (int)one($p, 'SELECT COUNT(*) AS n FROM audit_events')['n'], 'Audit Overview totals = audit_events');
ok(!str_contains(json_encode($lg), 'password_hash'), 'no password hash in the audit payload');

// ---------------------------------------------------------------------
section('D. Roles through the real dispatcher');
$child = __DIR__ . '/_acct_dispatch_child.php';
file_put_contents($child, '<?php
declare(strict_types=1);
require dirname(__DIR__) . "/backend/Actions.php";
require __DIR__ . "/_launcher.php";
test_guard([envValue("DB_NAME")]);
$_SESSION = ["uid" => (int)$argv[1], "login_at" => time(), "last_seen" => time()];
try { Actions::pageData($argv[2], []); echo "OK"; }
catch (BusinessRuleException $e) { echo "E" . $e->httpStatus; } catch (Throwable $e) { echo "X " . $e->getMessage(); }
');
$page = function (array $u, string $pg) use ($child): string { return trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($child) . ' ' . (int)$u['id'] . ' ' . escapeshellarg($pg))); };
$expect = ['finance_overview' => ['finance'], 'finance_budgets' => ['admin', 'finance', 'audit'], 'billing_overview' => ['billing'], 'billing_collections' => ['admin', 'billing', 'finance', 'audit'],
    'billing_receivables' => ['admin', 'billing', 'finance', 'audit'], 'audit_overview' => ['audit'], 'audit_logs' => ['admin', 'audit'], 'audit_history' => ['admin', 'audit'], 'audit_reports' => ['admin', 'audit']];
foreach ($expect as $pg => $allowed) {
    $bad = [];
    foreach ($users as $r => $u) { $got = $page($u, $pg); if (($got === 'OK') !== in_array($r, $allowed, true) || ($got !== 'OK' && $got !== 'E403')) $bad[] = "$r:$got"; }
    ok(!$bad, "$pg: opened only by " . implode(', ', $allowed) . '; others get 403', implode(' ', $bad));
}
@unlink($child);
fails(fn() => Admin::read($fin, 'audit_logs', []), 'module check: Finance cannot read audit pages', 403);

// ---------------------------------------------------------------------
section('E. Reads write nothing');
$sum = fn() => array_map(fn($t) => $p->query("CHECKSUM TABLE `$t`")->fetch()['Checksum'], array_combine($t = ['billings', 'collections', 'payables', 'purchase_orders', 'budgets', 'audit_events', 'notifications'], $t));
$before = $sum();
foreach (['billing_overview', 'billing_collections', 'billing_receivables'] as $pg) Billing::read($bil, $pg, []);
foreach (['finance_overview', 'finance', 'finance_budgets'] as $pg) Finance::read($fin, $pg, []);
foreach (['audit_overview', 'audit_logs', 'audit_history', 'audit_reports'] as $pg) Admin::read($aud, $pg, []);
ok($before === $sum(), 'billing, collections, payables, POs, budgets, audit and notifications unchanged by the new reads');

@array_map('unlink', glob($tmpDir . '/*') ?: []); @rmdir($tmpDir);
echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL ? 1 : 0);
