<?php
/*
 * =====================================================================
 *  tests/purchasing_finance_tests.php  —  Purchasing -> Finance money integrity (ACCOUNTING_INTEGRITY_G5/G7/G8)
 * =====================================================================
 *  Do NOT run this file directly: run tests/run_purchasing_finance_tests.php (temporary copy, own .env, *_test database).
 *    A. Full PO path: create -> Finance approval (budget reserve) -> partial receive -> payment of received value
 *       -> close the balance; budget invariant amount = available + reserved + spent at every step
 *    B. G5: a PO closed before anything was received leaves the payables (payment_status NULL), its pay notice is resolved
 *    C. G7: an Active budget whose period ended is not "available" (Purchasing read in_period=0, Finance dashboard),
 *       and approval is still auto-rejected (unchanged rule)
 *    D. G8: a new budget carries the open POs and their reserve; later payment draws on the new budget; old spent kept
 *    E. Purchasing read additions: linked POs, PO activity history, part history; role refusals
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
function fails(callable $fn, string $name, ?int $code = null, string $contains = ''): void {
    try { $fn(); ok(false, $name, 'no error was raised'); }
    catch (BusinessRuleException $e) { ok(($code === null || $e->httpStatus === $code) && ($contains === '' || stripos($e->getMessage(), $contains) !== false), $name, "got {$e->httpStatus}: {$e->getMessage()}"); }
    catch (Throwable $e) { ok(false, $name, get_class($e) . ': ' . $e->getMessage()); }
}
function section(string $t): void { echo "\n== $t ==\n"; }

section('Setup: disposable database');
test_recreate($DB);
$p = db();
ok($p->query('SELECT DATABASE()')->fetchColumn() === $DB, 'db() is the disposable test database');
test_import($p, test_sql('database/schema.sql'));
test_import($p, test_sql('database/extension.sql'));
$staff = ['admin' => ['tvillanueva', 'Teresita Villanueva'], 'motorpool' => ['ebautista', 'Ernesto Bautista'], 'purchasing' => ['msantos', 'Maricel Santos'],
    'finance' => ['laquino', 'Lourdes Aquino'], 'audit' => ['amendoza', 'Arnel Mendoza'], 'billing' => ['jramos', 'Jocelyn Ramos']];
$users = [];
foreach ($staff as $role => [$uname, $full]) {
    $id = insert($p, 'users', ['username' => $uname, 'email' => "$uname@mulawinfleet.example", 'password_hash' => 'x', 'full_name' => $full, 'role' => $role]);
    $users[$role] = ['id' => $id, 'username' => $uname, 'email' => "$uname@mulawinfleet.example", 'full_name' => $full, 'role' => $role, 'shift' => null];
}
$pur = $users['purchasing']; $fin = $users['finance'];
foreach (['Purchasing', 'Finance', 'Analytics'] as $m) Actions::load($m);
$tmpDir = sys_get_temp_dir() . '/fleet_pf_' . bin2hex(random_bytes(4)); mkdir($tmpDir);
$pdf = function (string $name) use ($tmpDir): array {
    $path = $tmpDir . '/' . bin2hex(random_bytes(6)); $bytes = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"; file_put_contents($path, $bytes);
    return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
};
$part = fn(string $no, string $desc, float $cost) => (int)one($p, 'SELECT id FROM parts WHERE part_number=?',
    [Purchasing::action($pur, 'part_save', ['part_number' => $no, 'description' => $desc, 'category' => 'Brakes', 'unit' => 'pc', 'unit_cost' => $cost, 'reorder_level' => 2]) ? $no : $no])['id'];
$P1 = $part('BRK-PAD-F-FVR', 'Front brake pad set, Isuzu FVR', 3250);
$P2 = $part('RAD-HOSE-U-H500', 'Upper radiator hose, Hino 500', 1450);
$budget = fn() => one($p, "SELECT id, amount, reserved_amount, spent_amount, status FROM budgets WHERE status='Active' AND category='Purchasing' ORDER BY id DESC LIMIT 1");
$invariant = function (string $when) use ($p, $budget): void {
    foreach (many($p, 'SELECT * FROM budgets') as $b) {
        $res = (float)one($p, 'SELECT COALESCE(SUM(reserved_amount),0) AS v FROM purchase_orders WHERE budget_id=?', [$b['id']])['v'];
        ok(abs($res - (float)$b['reserved_amount']) < 0.005, "$when: budget {$b['id']} reserved = sum of its POs' reserved (" . number_format($res, 2) . ')', "budget says {$b['reserved_amount']}");
    }
};
$po = function (array $lines, string $purpose = 'Brake overhaul parts for NBC 4821') use ($pur, $p): int {
    Purchasing::action($pur, 'po_create', ['supplier_name' => 'Bagong Pag-asa Truck Parts Trading', 'supplier_address' => 'Km 18 MacArthur Hwy, Valenzuela City', 'purpose' => $purpose, 'lines' => $lines]);
    return (int)one($p, 'SELECT MAX(id) AS id FROM purchase_orders')['id'];
};
$row = fn(int $id) => one($p, 'SELECT * FROM purchase_orders WHERE id=?', [$id]);
$lines = fn(int $id) => many($p, 'SELECT id, quantity, received_qty FROM purchase_order_lines WHERE purchase_order_id=? ORDER BY id', [$id]);
$pay = function (int $id, float $amount, string $inv) use ($fin, $pdf) {
    $_FILES = ['or_doc' => $pdf('official_receipt.pdf')];
    try { return Finance::action($fin, 'po_pay', ['po_id' => $id, 'amount' => $amount, 'supplier_invoice_no' => $inv, 'payment_method' => 'Cash', 'released_on' => date('Y-m-d')]); }
    finally { $_FILES = []; }
};
$payNotice = fn(int $id) => one($p, "SELECT state FROM notifications WHERE dedupe_key=? ORDER BY id DESC LIMIT 1", ['po-pay-' . $id])['state'] ?? null;

// ---------------------------------------------------------------------
section('A. Full PO path and budget invariant');
Finance::action($fin, 'budget_set', ['amount' => 100000, 'period_start' => date('Y-m-01'), 'period_end' => date('Y-m-t')]);
$b1 = (int)$budget()['id'];
$A = $po([['part_id' => $P1, 'quantity' => 2, 'unit_price' => 3250], ['part_id' => $P2, 'quantity' => 2, 'unit_price' => 1450]]);   // 9,400 <= threshold: Finance
ok($row($A)['approver_role'] === 'finance' && $row($A)['status'] === 'Pending Approval', 'PO routed to Finance (PHP 9,400 <= limit)');
fails(fn() => Purchasing::action($users['admin'], 'po_decide', ['po_id' => $A, 'decision' => 'approve']), 'Admin cannot approve a Finance-routed PO', 403);
Purchasing::action($fin, 'po_decide', ['po_id' => $A, 'decision' => 'approve']);
ok($row($A)['status'] === 'Approved' && $row($A)['payment_status'] === 'Pending Payment' && (float)$budget()['reserved_amount'] === 9400.0, 'approved: Pending Payment, 9,400 reserved');
$invariant('after approval');
fails(fn() => $pay($A, 100, 'SI-1001'), 'nothing to pay before any item is received', 409, 'Nothing to pay');
$l = $lines($A);
Purchasing::action($pur, 'po_receive', ['po_id' => $A, 'receive' => [['line_id' => $l[0]['id'], 'qty' => 2]], 'supplier_invoice_no' => 'SI-1001']);
ok($row($A)['status'] === 'Partially Received', 'partial delivery: Partially Received');
fails(fn() => $pay($A, 6501, 'SI-1001'), 'cannot pay more than the received value (6,500)', 400, 'more than');
$pay($A, 6500, 'SI-1001');
$b = $budget();
ok($row($A)['payment_status'] === 'Partially Paid' && (float)$b['spent_amount'] === 6500.0 && (float)$b['reserved_amount'] === 2900.0, 'paid 6,500: Partially Paid; budget reserved 2,900, spent 6,500');
$invariant('after payment');
Purchasing::action($pur, 'po_close', ['po_id' => $A, 'reason' => 'Supplier has no stock of the radiator hose']);
$b = $budget();
ok($row($A)['status'] === 'Closed' && $row($A)['payment_status'] === 'Paid' && (float)$b['reserved_amount'] === 0.0, 'closed balance: Paid, 2,900 released from the budget');
ok($payNotice($A) === 'Resolved', 'the PO pending-payment notice is resolved once nothing is owed');
ok(abs((float)$b['amount'] - (float)$b['reserved_amount'] - (float)$b['spent_amount'] - 93500) < 0.005, 'available = 100,000 - 0 - 6,500 = 93,500');
$invariant('after close');

// ---------------------------------------------------------------------
section('B. G5: closed before anything was received');
$B = $po([['part_id' => $P2, 'quantity' => 3, 'unit_price' => 1450]], 'Radiator hoses for stock');
Purchasing::action($fin, 'po_decide', ['po_id' => $B, 'decision' => 'approve']);
ok($payNotice($B) === 'Unread', 'Finance was notified of the pending payment');
Purchasing::action($pur, 'po_close', ['po_id' => $B, 'reason' => 'Order cancelled by the supplier']);
ok($row($B)['status'] === 'Closed' && $row($B)['payment_status'] === null, 'payment_status is cleared (nothing received, nothing paid)');
ok($payNotice($B) === 'Resolved', 'its pending-payment notice is resolved');
$payables = array_column(Finance::read($fin, 'finance', [])['poPayables'], 'id');
ok(!in_array($B, array_map('intval', $payables), true), 'it is no longer in the Finance PO payables');
fails(fn() => $pay($B, 1, 'SI-2002'), 'paying it is refused (not waiting for payment)', 409);
$invariant('after G5 close');

// ---------------------------------------------------------------------
section('C. G7: expired budget period');
$p->exec("UPDATE budgets SET period_start=DATE_SUB(CURRENT_DATE(), INTERVAL 40 DAY), period_end=DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY) WHERE id=$b1");   // test database only
$pb = Purchasing::read($pur, 'purchasing_overview', [])['budget'];
ok((int)$pb['id'] === $b1 && (int)$pb['in_period'] === 0, 'Purchasing overview budget carries in_period = 0');
$dash = Analytics::read($fin, 'dashboard', []);
$card = array_values(array_filter($dash['cards'] ?? [], fn($c) => $c['label'] === 'Purchasing budget available'))[0] ?? null;
ok($card !== null && (float)$card['value'] === 0.0 && str_contains(json_encode($dash['alerts'] ?? []), 'period ended'), 'Finance dashboard: expired budget shows 0 available and the alert', json_encode($card));
$C = $po([['part_id' => $P1, 'quantity' => 1, 'unit_price' => 3250]], 'Spare brake pads');
Purchasing::action($fin, 'po_decide', ['po_id' => $C, 'decision' => 'approve']);
ok($row($C)['status'] === 'Rejected - Insufficient Budget', 'approval with an expired budget is auto-rejected (unchanged rule)');
$p->exec("UPDATE budgets SET period_start=DATE_FORMAT(CURRENT_DATE(),'%Y-%m-01'), period_end=LAST_DAY(CURRENT_DATE()) WHERE id=$b1");

// ---------------------------------------------------------------------
section('D. G8: budget renewal carries open POs');
$D = $po([['part_id' => $P1, 'quantity' => 2, 'unit_price' => 3250]], 'Brake pads for ABJ 7305');
Purchasing::action($fin, 'po_decide', ['po_id' => $D, 'decision' => 'approve']);
$ld = $lines($D);
Purchasing::action($pur, 'po_receive', ['po_id' => $D, 'receive' => [['line_id' => $ld[0]['id'], 'qty' => 1]]]);
ok((float)$budget()['reserved_amount'] === 6500.0, 'open PO reserves 6,500 on the first budget');
fails(fn() => Finance::action($fin, 'budget_set', ['amount' => 5000, 'period_start' => date('Y-m-01'), 'period_end' => date('Y-m-t')]), 'a new budget smaller than the reserve carried over is refused', 400, 'still reserved');
ok((int)$budget()['id'] === $b1, 'refusal changed nothing (first budget still Active)');
Finance::action($fin, 'budget_set', ['amount' => 50000, 'period_start' => date('Y-m-01'), 'period_end' => date('Y-m-t')]);
$b2 = $budget(); $old = one($p, 'SELECT * FROM budgets WHERE id=?', [$b1]);
ok((int)$b2['id'] !== $b1 && $old['status'] === 'Closed', 'new budget Active, first budget Closed');
ok((int)$row($D)['budget_id'] === (int)$b2['id'] && (float)$b2['reserved_amount'] === 6500.0 && (float)$old['reserved_amount'] === 0.0, 'PO moved to the new budget with its 6,500 reserve; old reserve 0');
ok((float)$old['spent_amount'] === 6500.0, 'the old budget keeps its spent history (6,500)');
$pay($D, 3250, 'SI-4004');
$b2 = $budget();
ok((float)$b2['spent_amount'] === 3250.0 && (float)$b2['reserved_amount'] === 3250.0, 'payment after renewal draws on the new Active budget');
$invariant('after renewal and payment');
$au = one($p, "SELECT details FROM audit_events WHERE action='purchasing budget set' ORDER BY id DESC LIMIT 1")['details'];
ok(str_contains($au, '1 open PO(s) moved') && str_contains($au, $row($D)['po_ref']), 'audit names the moved PO', $au);

// ---------------------------------------------------------------------
section('E. Purchasing read additions and refusals');
$x = Purchasing::action($pur, 'po_details', ['po_id' => $A]);
$acts = array_column($x['history'], 'action');
ok(in_array('purchase order approved', $acts, true) && in_array('purchase order payment recorded', $acts, true) && in_array('purchase order closed', $acts, true), 'PO activity history lists approval, payment and closing');
ok($x['payments'] === [], 'Purchasing gets no disbursement details (Finance only)');
ok(count(Purchasing::action($fin, 'po_details', ['po_id' => $A])['payments']) === 1, 'Finance sees the payment voucher');
$ph = Purchasing::action($pur, 'part_history', ['part_id' => $P1]);
ok(count($ph['movements']) >= 2 && $ph['movements'][0]['reference'] !== null, 'part history lists stock-ins with their PO reference');
foreach (['finance', 'motorpool', 'billing'] as $r) fails(fn() => Purchasing::action($users[$r], 'part_history', ['part_id' => $P1]), "$r cannot read part history", 403);
ok(count(Purchasing::action($users['audit'], 'part_history', ['part_id' => $P1])['movements']) >= 2, 'Audit can read part history (read-only role with Inventory access)');

@array_map('unlink', glob($tmpDir . '/*') ?: []); @rmdir($tmpDir);
echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL ? 1 : 0);
