<?php
/*
 * =====================================================================
 *  tests/head_management_tests.php  —  HEAD_MANAGEMENT_V1 / V2 (Admin workspace)
 * =====================================================================
 *  Do NOT run this file directly: run tests/run_head_management_tests.php (temporary copy, own .env, *_test database).
 *    A. Access: only the Admin reads the oversight pages; every page loads without a failed section; the module has no write action
 *    B. Approval queue: only Admin-routed decisions (PO above the limit, stock counts); Finance-limit POs are not listed
 *    C. Decisions through the EXISTING actions: Admin approves a PO; a second decision is refused (stale state); queue moves it to Decided
 *    D. Stock count: Admin rejects with a reason (stock unchanged) and posts another (one adjustment); Purchasing cannot decide
 *    E. Figures: overview approval count = actionable queue rows; executive items requiring attention (each record once, grouped, no priority labels); analytics topics
 *       (chart totals = source rows); weekly finance (Mon-Sun week, commitments = reserved, payment moves commitment -> paid,
 *       starting / available budget not invented); purchasing issues (budget-rejected PO is an issue, never a pending approval)
 *    E2. Rule-based suggestions: weekly rule (only with a recorded starting budget), monthly budget, payables, receivables, decisions
 *    F. Reads write nothing
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

section('Setup: disposable database');
test_recreate($DB);
$p = db();
ok($p->query('SELECT DATABASE()')->fetchColumn() === $DB, 'db() is the disposable test database');
test_import($p, test_sql('database/schema.sql'));
test_import($p, test_sql('database/extension.sql'));
$staff = ['admin' => ['hadmin', 'Head Admin'], 'motorpool' => ['mpool', 'Motor Pool'], 'purchasing' => ['pofficer', 'Purchasing Officer'],
    'finance' => ['fofficer', 'Finance Officer'], 'audit' => ['auditor', 'Audit Reviewer'], 'billing' => ['bofficer', 'Billing Officer'], 'operations_head' => ['ophead', 'Ops Head']];
$users = [];
foreach ($staff as $role => [$uname, $full]) {
    $id = insert($p, 'users', ['username' => $uname, 'email' => "$uname@mulawinfleet.example", 'password_hash' => 'x', 'full_name' => $full, 'role' => $role]);
    $users[$role] = ['id' => $id, 'username' => $uname, 'email' => "$uname@mulawinfleet.example", 'full_name' => $full, 'role' => $role, 'shift' => null];
}
['purchasing' => $pur, 'finance' => $fin, 'admin' => $adm] = $users;
foreach (['Purchasing', 'Finance', 'Oversight', 'Mechanics'] as $m) Actions::load($m);
Finance::action($fin, 'budget_set', ['amount' => '500000', 'period_start' => date('Y-m-01'), 'period_end' => date('Y-m-t')]);
Purchasing::action($pur, 'part_save', ['part_number' => 'BRK-PAD-F-FVR', 'description' => 'Front brake pad set', 'category' => 'Brakes', 'unit' => 'set', 'unit_cost' => '3250', 'reorder_level' => '2']);
$P1 = (int)one($p, "SELECT id FROM parts WHERE part_number='BRK-PAD-F-FVR'")['id'];
$limit = (float)Settings::value('purchase_approval_threshold');
$po = function (int $qty) use ($pur, $P1, $p): int {
    Purchasing::action($pur, 'po_create', ['supplier_name' => 'TEST SUPPLIER', 'purpose' => 'Test stock', 'lines' => [['part_id' => $P1, 'quantity' => $qty, 'unit_price' => '3250']]]);
    return (int)one($p, 'SELECT MAX(id) AS id FROM purchase_orders')['id'];
};
$small = $po(1);                                                   // within the Finance limit
$bigQty = (int)floor($limit / 3250) + 2; $big = $po($bigQty);      // above the limit -> Admin
ok(one($p, 'SELECT approver_role FROM purchase_orders WHERE id=?', [$small])['approver_role'] === 'finance', 'small PO is routed to Finance');
ok(one($p, 'SELECT approver_role FROM purchase_orders WHERE id=?', [$big])['approver_role'] === 'admin', 'PO above the limit is routed to the Admin');
Purchasing::action($fin, 'po_decide', ['po_id' => $small, 'decision' => 'approve']);
Purchasing::action($pur, 'po_receive', ['po_id' => $small, 'receive' => [['line_id' => (int)one($p, 'SELECT id FROM purchase_order_lines WHERE purchase_order_id=?', [$small])['id'], 'qty' => 1]]]);
$qty = fn() => (int)one($p, 'SELECT quantity_on_hand FROM parts WHERE id=?', [$P1])['quantity_on_hand'];
ok($qty() === 1, 'setup: one set received into stock');

// ---------------------------------------------------------------------
section('A. Access and read-only module');
foreach (['motorpool', 'purchasing', 'finance', 'audit', 'billing', 'operations_head'] as $r)
    fails(fn() => Oversight::read($users[$r], 'admin_overview', []), "$r cannot read the Head Management pages", 403);
foreach (['admin_overview', 'admin_approvals', 'admin_operations', 'admin_maintenance', 'admin_purchasing', 'admin_finance', 'admin_reports', 'admin_analytics'] as $pg) {
    try { $d = Oversight::read($adm, $pg, []); ok(empty($d['_errors']), "$pg loads for the Admin without a failed section", json_encode($d['_errors'] ?? [])); }
    catch (Throwable $e) { ok(false, "$pg loads for the Admin", $e->getMessage()); }
    ok(Access::canOpen($adm, $pg), "Access: Admin opens $pg");
    foreach (['finance', 'purchasing', 'audit'] as $r) ok(!Access::canOpen($users[$r], $pg), "Access: $r cannot open $pg");
}
fails(fn() => Oversight::action($adm, 'approve', []), 'the Oversight module has no write action', 404);

// ---------------------------------------------------------------------
section('B. Approval queue lists only Admin-routed decisions');
$q = Oversight::read($adm, 'admin_approvals', [])['queue'];
$keys = array_column($q, 'key');
ok(in_array('po:' . $big, $keys, true), 'the PO above the limit is in the Admin queue');
ok(!in_array('po:' . $small, $keys, true), 'the PO decided by Finance (within the limit) is not in the Admin queue');
$row = $q[array_search('po:' . $big, $keys, true)];
ok($row['actionable'] === true && $row['status'] === 'Pending' && $row['next'] === 'Admin', 'the pending PO is actionable by the Admin');

// ---------------------------------------------------------------------
section('C. Decision through Purchasing.po_decide (existing action)');
fails(fn() => Purchasing::action($fin, 'po_decide', ['po_id' => $big, 'decision' => 'approve']), 'Finance cannot decide a PO routed to the Admin', 403);
Purchasing::action($adm, 'po_decide', ['po_id' => $big, 'decision' => 'approve']);
ok(one($p, 'SELECT status FROM purchase_orders WHERE id=?', [$big])['status'] === 'Approved', 'the Admin approval is recorded by the existing workflow');
fails(fn() => Purchasing::action($adm, 'po_decide', ['po_id' => $big, 'decision' => 'reject', 'notes' => 'late']), 'a second decision on the same PO is refused (stale state)', 409);
$q = Oversight::read($adm, 'admin_approvals', [])['queue'];
$row = $q[array_search('po:' . $big, array_column($q, 'key'), true)];
ok($row['status'] === 'Approved' && $row['actionable'] === false, 'the decided PO moves to the decided list and is no longer actionable');

// ---------------------------------------------------------------------
section('D. Stock counts through Purchasing.count_review (existing action)');
Purchasing::action($pur, 'count_submit', ['part_id' => $P1, 'counted_qty' => 0, 'reason' => 'damaged on the shelf']);
$C1 = (int)one($p, 'SELECT MAX(id) AS id FROM stock_counts')['id'];
$q = Oversight::read($adm, 'admin_approvals', [])['queue'];
$crow = $q[array_search('count:' . $C1, array_column($q, 'key'), true)] ?? null;
ok($crow && $crow['actionable'] && $crow['type'] === 'Stock count adjustment', 'the submitted stock count is in the queue and actionable by the Admin');
fails(fn() => Purchasing::action($pur, 'count_review', ['count_id' => $C1, 'decision' => 'post']), 'Purchasing cannot decide a stock count', 403);
fails(fn() => Purchasing::action($adm, 'count_review', ['count_id' => $C1, 'decision' => 'reject']), 'a rejection needs a reason');
Purchasing::action($adm, 'count_review', ['count_id' => $C1, 'decision' => 'reject', 'notes' => 'recount the shelf']);
ok($qty() === 1 && one($p, 'SELECT status FROM stock_counts WHERE id=?', [$C1])['status'] === 'Rejected', 'rejecting leaves the stock unchanged');
Purchasing::action($pur, 'count_submit', ['part_id' => $P1, 'counted_qty' => 0, 'reason' => 'confirmed damaged']);
$C2 = (int)one($p, 'SELECT MAX(id) AS id FROM stock_counts')['id'];
Purchasing::action($adm, 'count_review', ['count_id' => $C2, 'decision' => 'post']);
ok($qty() === 0 && (int)one($p, "SELECT COUNT(*) AS n FROM stock_movements WHERE reference_type='stock_count' AND reference_id=?", [$C2])['n'] === 1, 'posting records exactly one adjustment');
fails(fn() => Purchasing::action($adm, 'count_review', ['count_id' => $C2, 'decision' => 'post']), 'posting the same count again is refused', 409);

// ---------------------------------------------------------------------
section('E. Figures match their source records');
Purchasing::action($pur, 'count_submit', ['part_id' => $P1, 'counted_qty' => 3, 'reason' => 'found in the back store']);
$ov = Oversight::read($adm, 'admin_overview', []);
$q = Oversight::read($adm, 'admin_approvals', [])['queue'];
ok($ov['kpis']['approvals'] === count(array_filter($q, fn($r) => $r['actionable'])), 'overview "awaiting your approval" = actionable queue rows (' . $ov['kpis']['approvals'] . ')');
ok(count($ov['decisions']) === count(array_filter($q, fn($r) => $r['status'] === 'Pending')), 'pending decisions panel = pending queue rows');
ok($ov['kpis']['open_pos'] === (int)one($p, "SELECT COUNT(*) AS n FROM purchase_orders WHERE status IN ('Pending Approval','Approved','Partially Received')")['n'], 'open purchase orders = PO rows awaiting approval or delivery');
// Executive Reports: items requiring attention / performance / recent decisions (no historical series, no priority)
$ex = Oversight::read($adm, 'admin_reports', []);
ok(isset($ex['items'], $ex['performance'], $ex['recent'], $ex['weekly']) && !isset($ex['buckets'], $ex['priorities'], $ex['risks']), 'Executive Reports: items, department performance, recent decisions and weekly position (no trend series, no priorities or risks)');
ok(!array_filter($ex['items'], fn($x) => isset($x['sev']) || preg_match('/high|medium|critical|risk|urgent/i', $x['issue'] . ' ' . $x['status'])), 'no item carries a priority, severity, risk or urgency label');
ok(array_diff(array_unique(array_column($ex['items'], 'group')), ['waiting', 'issues']) === [], 'items are grouped by workflow (waiting for Head Management / open department issues)');
$ord = array_map(fn($x) => [$x['group'] === 'waiting' ? 0 : 1, (string)$x['since']], $ex['items']); $sorted = $ord; sort($sorted);
ok($ord === $sorted, 'items are ordered by workflow group, then by recorded date (oldest first)');
ok($ex['waiting'] === count(array_filter($q, fn($r) => $r['status'] === 'Pending')), 'waiting-for-Head-Management count = pending queue rows');
ok($ex['decisions'] === count(array_filter($q, fn($r) => $r['actionable'])), 'Executive Reports: "decisions requiring your action" = actionable queue rows (' . $ex['decisions'] . ')');
$refs = array_column($ex['items'], 'ref');
ok(count($refs) === count(array_unique($refs)), 'Executive Reports: every record appears once in the items list');
ok(count(array_filter($ex['items'], fn($x) => $x['decide'] && !str_starts_with($x['go'], 'admin_approvals'))) === 0, 'only Admin-owned approvals carry a decide action, and it opens the Approvals drawer');
ok(count($ex['performance']) === 4 && count(array_unique(array_column($ex['performance'], 'dept'))) === 4 && array_diff(array_column($ex['performance'], 'unit'), ['count', 'money']) === [], 'Department performance: 4 departments, each in its own unit (count or money)');
ok(array_values(array_filter($ex['performance'], fn($x) => $x['dept'] === 'Billing'))[0]['unit'] === 'money', 'Billing collections are money; the other measures are counts (units never mixed)');

// Analytics: four topics, each chart total = its source rows
foreach (['operations', 'maintenance', 'purchasing', 'finance'] as $t) { $a = Oversight::read($adm, 'admin_analytics', ['topic' => $t]); ok($a['topic'] === $t && empty($a['_errors']) && isset($a['suggestions']['main']), "Analytics topic $t loads with its rule-based suggestions"); }
ok(Oversight::read($adm, 'admin_analytics', ['topic' => 'x'])['topic'] === 'operations', 'an unknown analytics topic falls back to Operations');
$an = Oversight::read($adm, 'admin_analytics', ['topic' => 'purchasing']);
ok(abs(array_sum($an['report']['flow']['in']) - (float)one($p, "SELECT COALESCE(SUM(quantity_delta*unit_cost),0) AS v FROM stock_movements WHERE movement_type='Stock In' AND DATE(created_at) BETWEEN ? AND ?", [$an['period']['from'], $an['period']['to']])['v']) < 0.01, 'Analytics purchasing: stock-in line total = Stock In movement value');
ok(abs(array_sum(array_column($an['inventory'], 'value')) - (float)one($p, 'SELECT COALESCE(SUM(quantity_on_hand*unit_cost),0) AS v FROM parts WHERE active=1')['v']) < 0.01, 'Analytics purchasing: inventory composition total = stock value on hand');
$ao = Oversight::read($adm, 'admin_analytics', ['topic' => 'operations']);
ok(array_sum($ao['activity']['created']) === $ao['totals']['created'] && $ao['status'] === [] && $ao['incidents'] === [] && $ao['dispatch'] === [], 'Analytics operations: no trip or incident recorded -> empty series, nothing invented');
$af = Oversight::read($adm, 'admin_analytics', ['topic' => 'finance']);
ok(count($af['aging']) === 4 && array_sum(array_column($af['aging'], 'value')) == 0 && $af['weeks'] !== [] && array_sum(array_column($af['weeks'], 'amount')) == 0, 'Analytics finance: no invoice / collection -> zero aging and zero weekly collections');

// Weekly budget policy: Monday-Sunday weeks; known parts only; no double counting
ok(Oversight::weekOf('2026-11-13') === ['from' => '2026-11-09', 'to' => '2026-11-15'] && Oversight::weekOf('2026-11-09')['from'] === '2026-11-09'
    && Oversight::weekOf('2026-11-15')['from'] === '2026-11-09' && Oversight::weekOf('2026-11-16')['from'] === '2026-11-16', 'week = Monday to Sunday (Fri, Mon and Sun of one week; next Monday starts a new week)');
$fi2 = Oversight::read($adm, 'admin_finance', []);
$mb = $fi2['monthlyBudget'];
ok($fi2['week'] === Oversight::weekOf(date('Y-m-d')) && $fi2['prevWeek']['to'] === date('Y-m-d', strtotime($fi2['week']['from'] . ' -1 day')), 'Financial Oversight: current week and the week before it');
ok(abs($fi2['weekly']['commitments'] - (float)$mb['reserved_amount']) < 0.01, 'outstanding commitments = reserved amount of approved, unpaid POs (' . $fi2['weekly']['commitments'] . ')');
ok($fi2['weekly']['starting_budget'] === null && $fi2['weekly']['available'] === null && $fi2['weekly']['carry_forward'] === null, 'starting budget, carry-forward and available are not invented (no weekly allocation recorded)');
ok($fi2['weekly']['prev_collections'] == 0 && $fi2['prevCollections'] === [], 'previous week collections = 0 with no collection recorded');
$tmpDir = sys_get_temp_dir() . '/fleet_hm_' . bin2hex(random_bytes(4)); mkdir($tmpDir);
$orf = $tmpDir . '/or.pdf'; file_put_contents($orf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
$_FILES = ['or_doc' => ['name' => 'official_receipt.pdf', 'tmp_name' => $orf, 'error' => UPLOAD_ERR_OK, 'size' => filesize($orf)]];
try { Finance::action($fin, 'po_pay', ['po_id' => $small, 'amount' => '3250', 'supplier_invoice_no' => 'INV-HM-1', 'payment_method' => 'Cash', 'released_on' => date('Y-m-d')]); } finally { $_FILES = []; }
$fi3 = Oversight::read($adm, 'admin_finance', []);
ok(abs($fi3['weekly']['payments'] - 3250) < 0.01 && count($fi3['payments']) === 1 && $fi3['payments'][0]['source_ref'] !== null, 'a PO payment this week is listed once under payments, with its PO reference');
ok(abs(($fi2['weekly']['commitments'] - $fi3['weekly']['commitments']) - 3250) < 0.01, 'the paid amount left commitments (moved, not added): no double counting');
ok(abs($fi3['weekly']['paid_committed'] - ($fi3['weekly']['payments'] + $fi3['weekly']['commitments'])) < 0.01, 'paid & committed = payments + commitments');
ok(Oversight::read($adm, 'admin_finance', ['week' => date('Y-m-d', strtotime('-14 days'))])['weekly']['payments'] == 0, 'a week with no payment shows 0 payments');

// Purchasing issues: a budget-rejected PO is an issue, never a pending approval
$huge = $po(200);  // 650,000 > available budget -> Admin decides -> auto-rejected
Purchasing::action($adm, 'po_decide', ['po_id' => $huge, 'decision' => 'approve']);
ok(one($p, 'SELECT status FROM purchase_orders WHERE id=?', [$huge])['status'] === 'Rejected - Insufficient Budget', 'setup: PO above the available budget is rejected by the existing rule');
$hugeRef = one($p, 'SELECT po_ref FROM purchase_orders WHERE id=?', [$huge])['po_ref'];
$pu = Oversight::read($adm, 'admin_purchasing', []);
ok(count(array_filter($pu['issues'], fn($x) => $x['ref'] === $hugeRef)) === 1, 'Purchasing issues: the budget-rejected PO is listed once, with its next department');
ok(!in_array($hugeRef, array_column(array_filter(Oversight::read($adm, 'admin_approvals', [])['queue'], fn($r) => $r['status'] === 'Pending'), 'ref'), true), 'the rejected PO is not a pending approval');
$ex2 = Oversight::read($adm, 'admin_reports', []);
ok(count(array_filter($ex2['items'], fn($x) => $x['ref'] === $hugeRef)) === 1 && !array_values(array_filter($ex2['items'], fn($x) => $x['ref'] === $hugeRef))[0]['decide'], 'Executive items: the rejected PO appears once, without an approve action');
$op = Oversight::read($adm, 'admin_operations', []); $mt = Oversight::read($adm, 'admin_maintenance', []);
ok($op['attention'] === [] && $mt['attention'] === [], 'no trip on hold and no repair waiting: attention lists are empty (nothing invented)');

section('E2. Rule-based financial suggestions (Oversight::financeRules)');
$today = date('Y-m-d');
$B = fn($amount, $res, $spent, $in = 1) => ['amount' => $amount, 'reserved_amount' => $res, 'spent_amount' => $spent, 'in_period' => $in, 'period_end' => '2026-12-31'];
$r = Oversight::financeRules(['budget' => $B(100000, 30000, 55000)], $today);
ok(count($r['budget']) === 1 && str_contains($r['budget'][0]['text'], '85% paid or committed'), 'budget at 85% (rule: 80%) -> suggestion with the percentage');
$r = Oversight::financeRules(['budget' => $B(100000, 10000, 20000)], $today);
ok($r['budget'] === [], 'budget at 30% -> no budget suggestion');
ok(str_contains(Oversight::financeRules(['budget' => []], $today)['budget'][0]['text'] ?? '', 'No active monthly Purchasing budget'), 'no monthly budget -> suggestion');
$W = fn($start, $paid, $com) => ['budget' => $B(100, 0, 0), 'weekly' => ['starting_budget' => $start, 'payments' => $paid, 'commitments' => $com]];
ok(Oversight::financeRules($W(null, 9000, 9000), $today)['weekly'] === [], 'weekly rule: no recorded starting budget -> no weekly warning (neutral)');
ok(count(Oversight::financeRules($W(1000, 800, 300), $today)['weekly']) === 1 && str_contains(Oversight::financeRules($W(1000, 800, 300), $today)['weekly'][0]['text'], 'by 100.00'), 'weekly rule: paid + committed above the starting budget -> warning with the amount');
ok(Oversight::financeRules($W(1000, 500, 300), $today)['weekly'] === [], 'weekly rule: within the starting budget -> no warning');
ok(str_contains(Oversight::financeRules(['budget' => $B(100, 0, 0, 0)], $today)['budget'][0]['text'] ?? '', 'period ended'), 'budget period ended -> suggestion');
$r = Oversight::financeRules(['budget' => $B(100, 0, 0), 'payables' => [['payable_ref' => 'AP-1', 'payee' => 'X', 'balance' => 500, 'days_overdue' => 5, 'due_on' => '2026-01-01'], ['payable_ref' => 'AP-2', 'payee' => 'Y', 'balance' => 50, 'days_overdue' => -3, 'due_on' => '2026-01-09'], ['payable_ref' => 'AP-3', 'payee' => 'Z', 'balance' => 50, 'days_overdue' => -20, 'due_on' => '2026-02-01']]], $today);
ok(count($r['payables']) === 2 && str_contains($r['payables'][0]['text'], 'AP-1') && str_contains($r['payables'][0]['text'], '5 day(s) past due') && str_contains($r['payables'][1]['text'], 'AP-2'), 'payables: past due and due within 7 days are named; a payable due in 20 days is not');
$r = Oversight::financeRules(['budget' => $B(100, 0, 0), 'poPayables' => [['id' => 9, 'po_ref' => 'PO-9', 'last_received' => date('Y-m-d', strtotime('-40 days'))]]], $today);
ok($r['payables'] === [], 'PO payables are not flagged by an invented 30-day threshold (POs record no payment due date)');
$r = Oversight::financeRules(['budget' => $B(100, 0, 0), 'receivables' => [['balance' => 1000, 'days_overdue' => 12], ['balance' => 500, 'days_overdue' => -4]]], $today);
ok(count($r['receivables']) === 1 && str_contains($r['receivables'][0]['text'], '1 invoice(s) have an outstanding balance past the due date (1,000.00); the oldest due date was 12 day(s) ago'), 'receivables past their recorded due date -> one factual summary line (not-yet-due invoices excluded)');
$r = Oversight::financeRules(['budget' => $B(100, 0, 0), 'dueWindow' => ['n' => 3, 'due' => 10000, 'paid' => 2000]], $today);
ok($r['receivables'] === [], 'no collection-rate threshold (removed: no company policy)');
$r = Oversight::financeRules(['budget' => $B(100, 0, 0), 'decisions' => [['kind' => 'Purchase order (Finance approval)', 'id' => 3, 'ref' => 'PO-3', 'since' => date('Y-m-d', strtotime('-9 days')), 'go_base' => 'purchase_orders?open=']]], $today);
ok(!isset($r['decisions']) && !preg_match('/waited|delayed/i', json_encode($r)), 'pending Finance decisions are not labelled delayed by an invented waiting-time threshold');
ok(!method_exists('Oversight', 'analyticsRules') && !method_exists('Oversight', 'operationalRisks'), 'the spending-concentration and fleet-risk classifications are removed');

section('E3. Head Management does not process department work');
fails(fn() => Mechanics::action($adm, 'time_in', ['id' => 1]), 'Admin cannot Time In a mechanic (Motorpool processing)', 403);
fails(fn() => Purchasing::action($adm, 'po_receive', ['po_id' => $big, 'receive' => []]), 'Admin cannot receive a purchase order', 403);
fails(fn() => Purchasing::action($adm, 'request_process', ['request_id' => 1, 'release' => []]), 'Admin cannot release parts', 403);
fails(fn() => Finance::action($adm, 'po_pay', ['po_id' => $big, 'amount' => '1']), 'Admin cannot record a payment', 403);
$fi = Oversight::read($adm, 'admin_finance', []);
ok(count($fi['receivables']) === 0 && $ov['kpis']['receivables'] == 0, 'no invoice yet: receivables are zero, not invented');
ok(abs((float)$fi['monthlyBudget']['available'] - ((float)$fi['monthlyBudget']['amount'] - (float)$fi['monthlyBudget']['reserved_amount'] - (float)$fi['monthlyBudget']['spent_amount'])) < 0.01, 'monthly budget available = amount - reserved - spent');

// ---------------------------------------------------------------------
section('F. Reads write nothing');
$snap = fn() => array_map(fn($t) => (int)one($p, "SELECT COUNT(*) AS n FROM $t")['n'], ['audit_events' => 'audit_events', 'purchase_orders' => 'purchase_orders', 'stock_movements' => 'stock_movements', 'notifications' => 'notifications', 'stock_counts' => 'stock_counts']);
$before = $snap();
foreach (['admin_overview', 'admin_approvals', 'admin_operations', 'admin_maintenance', 'admin_purchasing', 'admin_finance', 'admin_reports', 'admin_analytics'] as $pg) Oversight::read($adm, $pg, []);
ok($snap() === $before, 'reading every Head Management page changed no table');

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL ? 1 : 0);
