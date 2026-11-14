<?php
/*
 * =====================================================================
 *  tests/stock_count_tests.php  —  STOCK_COUNT_RECONCILIATION_V1 (inventory control)
 * =====================================================================
 *  Do NOT run this file directly: run tests/run_stock_count_tests.php (temporary copy, own .env, *_test database).
 *    A. Direct stock changes are refused on the server: Purchasing.adjust (every role), part_save opening quantity
 *    B. Normal stock-in: PO receiving creates exactly one Stock In movement per received line; over-receiving refused
 *    C. Stock count submit: Purchasing only; system quantity captured; stock NOT changed; zero / negative / second open
 *       count refused; evidence stored as a stock_count document; audit + Admin notification
 *    D. Review: Purchasing cannot review; Admin rejects (reason required, stock unchanged) or posts (one Adjustment movement
 *       linked to the count, before/after kept); double posting refused; a count made stale by a later stock-in is refused
 *    E. Database guards: one open count per part, reviewer <> counter, Posted needs a movement
 *    F. Reads: Inventory page counts per role, count_details permissions; ledger = movements for every part
 *    G. Purchasing Reports analytics: figures equal the ledger, reorder rule with explanation, forecast honesty, roles, no writes
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
function dbFails(callable $fn, string $name): void {
    try { $fn(); ok(false, $name, 'the database accepted it'); } catch (PDOException $e) { ok(true, $name); }
}
function section(string $t): void { echo "\n== $t ==\n"; }

section('Setup: disposable database');
test_recreate($DB);
$p = db();
ok($p->query('SELECT DATABASE()')->fetchColumn() === $DB, 'db() is the disposable test database');
test_import($p, test_sql('database/schema.sql'));
test_import($p, test_sql('database/extension.sql'));
$staff = ['admin' => ['tvillanueva', 'Teresita Villanueva'], 'motorpool' => ['ebautista', 'Ernesto Bautista'], 'purchasing' => ['msantos', 'Maricel Santos'],
    'finance' => ['laquino', 'Lourdes Aquino'], 'audit' => ['amendoza', 'Arnel Mendoza']];
$users = [];
foreach ($staff as $role => [$uname, $full]) {
    $id = insert($p, 'users', ['username' => $uname, 'email' => "$uname@mulawinfleet.example", 'password_hash' => 'x', 'full_name' => $full, 'role' => $role]);
    $users[$role] = ['id' => $id, 'username' => $uname, 'email' => "$uname@mulawinfleet.example", 'full_name' => $full, 'role' => $role, 'shift' => null];
}
['purchasing' => $pur, 'finance' => $fin, 'admin' => $adm, 'motorpool' => $mp, 'audit' => $aud] = $users;
foreach (['Purchasing', 'Finance'] as $m) Actions::load($m);
ok(Purchasing::countsReady($p), 'stock_counts exists in a fresh install (extension.sql)');
$tmpDir = sys_get_temp_dir() . '/fleet_sc_' . bin2hex(random_bytes(4)); mkdir($tmpDir);
$pdf = function (string $name) use ($tmpDir): array {
    $path = $tmpDir . '/' . bin2hex(random_bytes(6)); $bytes = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"; file_put_contents($path, $bytes);
    return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
};
$qty = fn(int $id) => (int)one($p, 'SELECT quantity_on_hand FROM parts WHERE id=?', [$id])['quantity_on_hand'];
$moves = fn(int $id, string $type = '') => many($p, 'SELECT * FROM stock_movements WHERE part_id=?' . ($type ? ' AND movement_type=?' : '') . ' ORDER BY id', $type ? [$id, $type] : [$id]);

// ---------------------------------------------------------------------
section('A. Direct stock changes are refused on the server');
Purchasing::action($pur, 'part_save', ['part_number' => 'BRK-PAD-F-FVR', 'description' => 'Front brake pad set, Isuzu FVR', 'category' => 'Brakes', 'unit' => 'set', 'unit_cost' => '3250', 'reorder_level' => '2']);
$P1 = (int)one($p, "SELECT id FROM parts WHERE part_number='BRK-PAD-F-FVR'")['id'];
ok($qty($P1) === 0 && !$moves($P1), 'a new part starts at 0 on hand with no movement');
fails(fn() => Purchasing::action($pur, 'part_save', ['part_number' => 'HUB-SEAL-R', 'description' => 'Rear wheel hub oil seal', 'unit' => 'pc', 'unit_cost' => '385', 'reorder_level' => '6', 'opening_qty' => '5']),
    'part_save with an opening quantity is refused', 403);
ok(!one($p, "SELECT id FROM parts WHERE part_number='HUB-SEAL-R'"), 'the refused part was not created');
foreach (['purchasing' => $pur, 'admin' => $adm, 'motorpool' => $mp, 'finance' => $fin] as $r => $usr)
    fails(fn() => Purchasing::action($usr, 'adjust', ['part_id' => $P1, 'quantity_delta' => '5', 'reason' => 'count correction']), "$r: Purchasing.adjust is refused", 403);
ok($qty($P1) === 0 && !$moves($P1), 'refused adjustments changed nothing');
$auditBefore = (int)one($p, 'SELECT COUNT(*) AS n FROM audit_events')['n'];

// ---------------------------------------------------------------------
section('B. Normal stock-in through PO receiving');
Finance::action($fin, 'budget_set', ['amount' => '150000', 'period_start' => date('Y-m-01'), 'period_end' => date('Y-m-t')]);
Purchasing::action($pur, 'po_create', ['supplier_name' => 'Bagong Pag-asa Truck Parts Trading', 'purpose' => 'Brake pads for stock', 'lines' => [['part_id' => $P1, 'quantity' => 6, 'unit_price' => '3250']]]);
$po = (int)one($p, 'SELECT MAX(id) AS id FROM purchase_orders')['id'];
Purchasing::action($adm, 'po_decide', ['po_id' => $po, 'decision' => 'approve']);   // PHP 19,500: above the Finance limit -> Admin
$line = one($p, 'SELECT id FROM purchase_order_lines WHERE purchase_order_id=?', [$po]);
Purchasing::action($pur, 'po_receive', ['po_id' => $po, 'receive' => [['line_id' => $line['id'], 'qty' => 4]], 'supplier_invoice_no' => 'SI-20831']);
$in = $moves($P1, 'Stock In');
ok($qty($P1) === 4 && count($in) === 1 && (int)$in[0]['quantity_delta'] === 4 && $in[0]['reference_type'] === 'purchase_order' && (int)$in[0]['reference_id'] === $po,
    'receiving 4 of 6: on hand 4, one Stock In movement linked to the PO');
fails(fn() => Purchasing::action($pur, 'po_receive', ['po_id' => $po, 'receive' => [['line_id' => $line['id'], 'qty' => 3]]]), 'receiving more than the 2 still due is refused');
ok($qty($P1) === 4 && count($moves($P1, 'Stock In')) === 1, 'the refused receipt added no stock and no movement');

// ---------------------------------------------------------------------
section('C. Stock count submit');
foreach (['motorpool' => $mp, 'finance' => $fin, 'admin' => $adm, 'audit' => $aud] as $r => $usr)
    fails(fn() => Purchasing::action($usr, 'count_submit', ['part_id' => $P1, 'counted_qty' => '3', 'reason' => 'One set damaged']), "$r cannot submit a stock count", 403);
fails(fn() => Purchasing::action($pur, 'count_submit', ['part_id' => $P1, 'counted_qty' => '4', 'reason' => 'Recount']), 'a count equal to the system quantity is refused (nothing to reconcile)', 400, 'nothing to reconcile');
fails(fn() => Purchasing::action($pur, 'count_submit', ['part_id' => $P1, 'counted_qty' => '-1', 'reason' => 'x']), 'a negative count is refused', 400);
fails(fn() => Purchasing::action($pur, 'count_submit', ['part_id' => $P1, 'counted_qty' => '3', 'reason' => '']), 'a reason is required', 400);
$_FILES = ['evidence' => $pdf('count_sheet_brk_pad.pdf')];
try { $r = Purchasing::action($pur, 'count_submit', ['part_id' => $P1, 'counted_qty' => '3', 'reason' => 'One set damaged by water in storage']); } finally { $_FILES = []; }
$c1 = one($p, 'SELECT * FROM stock_counts WHERE id=?', [(int)$r['id']]);
ok($c1 && $c1['status'] === 'Submitted' && (int)$c1['system_qty'] === 4 && (int)$c1['counted_qty'] === 3 && (int)$c1['variance'] === -1 && preg_match('/^SC-/', $c1['count_ref']),
    'count recorded: Submitted, system 4, counted 3, variance -1, SC- reference');
ok($qty($P1) === 4 && !$moves($P1, 'Adjustment'), 'submitting a count does NOT change the stock');
ok((int)one($p, "SELECT COUNT(*) AS n FROM documents WHERE entity_type='stock_count' AND entity_id=?", [$c1['id']])['n'] === 1, 'evidence file stored as a stock_count document');
ok((bool)one($p, "SELECT id FROM audit_events WHERE record_ref=? AND action='stock count submitted'", ['stock_count ' . $c1['id']]), 'audit event for the submitted count');
ok((bool)one($p, "SELECT n.id FROM notifications n JOIN users u ON u.id=n.user_id WHERE n.dedupe_key=? AND u.role='admin'", ['stock-count-' . $c1['id']]), 'the Admin is notified to review');
fails(fn() => Purchasing::action($pur, 'count_submit', ['part_id' => $P1, 'counted_qty' => '2', 'reason' => 'Second count']), 'a second open count for the same part is refused', 409);

// ---------------------------------------------------------------------
section('D. Review: reject, post, double post, stale count');
fails(fn() => Purchasing::action($pur, 'count_review', ['count_id' => $c1['id'], 'decision' => 'post']), 'Purchasing cannot review its own count', 403);
fails(fn() => Purchasing::action($fin, 'count_review', ['count_id' => $c1['id'], 'decision' => 'post']), 'Finance cannot review a count', 403);
fails(fn() => Purchasing::action($adm, 'count_review', ['count_id' => $c1['id'], 'decision' => 'reject']), 'rejecting needs a reason', 400);
fails(fn() => Purchasing::action($adm, 'count_review', ['count_id' => $c1['id'], 'decision' => 'approve-all']), 'an unknown decision is refused', 400);
$r = Purchasing::action($adm, 'count_review', ['count_id' => $c1['id'], 'decision' => 'post', 'notes' => 'Checked the damaged set in the stock room']);
$c1 = one($p, 'SELECT * FROM stock_counts WHERE id=?', [$c1['id']]);
$adj = $moves($P1, 'Adjustment');
ok($qty($P1) === 3, 'posting sets on hand to the counted quantity (4 -> 3)');
ok(count($adj) === 1 && (int)$adj[0]['quantity_delta'] === -1 && $adj[0]['reference_type'] === 'stock_count' && (int)$adj[0]['reference_id'] === (int)$c1['id'] && (int)$adj[0]['actor_id'] === (int)$adm['id'],
    'one Adjustment movement of -1, linked to the count, by the Admin');
ok($c1['status'] === 'Posted' && (int)$c1['qty_before'] === 4 && (int)$c1['qty_after'] === 3 && (int)$c1['stock_movement_id'] === (int)$adj[0]['id'] && (int)$c1['reviewed_by'] === (int)$adm['id'],
    'count Posted with before/after quantities, reviewer and movement id');
ok(one($p, 'SELECT state FROM notifications WHERE dedupe_key=? ORDER BY id DESC LIMIT 1', ['stock-count-' . $c1['id']])['state'] === 'Resolved', 'the review notification is resolved');
fails(fn() => Purchasing::action($adm, 'count_review', ['count_id' => $c1['id'], 'decision' => 'post']), 'posting the same count twice is refused', 409);
ok($qty($P1) === 3 && count($moves($P1, 'Adjustment')) === 1, 'no double adjustment');

$r = Purchasing::action($pur, 'count_submit', ['part_id' => $P1, 'counted_qty' => '5', 'reason' => 'Found two sets on the wrong shelf']);
$c2 = (int)$r['id'];
Purchasing::action($adm, 'count_review', ['count_id' => $c2, 'decision' => 'reject', 'notes' => 'Those sets belong to PO receiving still in progress']);
ok(one($p, 'SELECT status FROM stock_counts WHERE id=?', [$c2])['status'] === 'Rejected' && $qty($P1) === 3 && count($moves($P1, 'Adjustment')) === 1, 'reject: status Rejected, stock and movements unchanged');
fails(fn() => Purchasing::action($adm, 'count_review', ['count_id' => $c2, 'decision' => 'post']), 'a rejected count cannot be posted later', 409);

$r = Purchasing::action($pur, 'count_submit', ['part_id' => $P1, 'counted_qty' => '2', 'reason' => 'One more set damaged']);
$c3 = (int)$r['id'];
Purchasing::action($pur, 'po_receive', ['po_id' => $po, 'receive' => [['line_id' => $line['id'], 'qty' => 2]]]);   // stock 3 -> 5 after the count
fails(fn() => Purchasing::action($adm, 'count_review', ['count_id' => $c3, 'decision' => 'post']), 'a count made before a later stock-in cannot be posted (recount needed)', 409, 'changed after the count');
ok($qty($P1) === 5 && one($p, 'SELECT status FROM stock_counts WHERE id=?', [$c3])['status'] === 'Submitted', 'the stale count stays Submitted; stock untouched by the refusal');
Purchasing::action($adm, 'count_review', ['count_id' => $c3, 'decision' => 'reject', 'notes' => 'Stock changed after the count; count again']);

// ---------------------------------------------------------------------
section('E. Database guards');
$sc = fn(array $x) => insert($p, 'stock_counts', $x + ['count_ref' => 'SC-T-' . bin2hex(random_bytes(3)), 'part_id' => $P1, 'system_qty' => 5, 'counted_qty' => 4, 'reason' => 'db test',
    'status' => 'Submitted', 'counted_by' => $pur['id'], 'counted_at' => date('Y-m-d H:i:s')]);
$open = $sc([]);
dbFails(fn() => $sc([]), 'database: a second Submitted count for one part is rejected (uq_sc_one_open)');
dbFails(fn() => $p->prepare("UPDATE stock_counts SET status='Rejected', reviewed_by=counted_by, reviewed_at=NOW() WHERE id=?")->execute([$open]), 'database: reviewer = counter is rejected');
dbFails(fn() => $p->prepare("UPDATE stock_counts SET status='Posted', reviewed_by=?, reviewed_at=NOW() WHERE id=?")->execute([$adm['id'], $open]), 'database: Posted without a movement is rejected');
dbFails(fn() => $sc(['part_id' => $P1, 'counted_qty' => 5, 'status' => 'Rejected', 'reviewed_by' => $adm['id'], 'reviewed_at' => date('Y-m-d H:i:s')]), 'database: counted = system is rejected');
$p->prepare("UPDATE stock_counts SET status='Rejected', reviewed_by=?, reviewed_at=NOW(), review_notes='db test cleanup' WHERE id=?")->execute([$adm['id'], $open]);

// ---------------------------------------------------------------------
section('F. Reads and ledger');
$inv = fn(array $usr) => Actions::pageData('inventory', []);
$asUser = function (array $usr, string $page) { $_SESSION['uid'] = $usr['id']; try { return Purchasing::read($usr, $page, []); } finally { unset($_SESSION['uid']); } };
$rp = $asUser($pur, 'inventory');
ok($rp['countsReady'] === true && $rp['can']['count'] === true && $rp['can']['reviewCount'] === false && count($rp['counts']) >= 4, 'Purchasing: Inventory lists counts; can count, cannot review');
$ra = $asUser($adm, 'inventory');
ok($ra['can']['count'] === false && $ra['can']['reviewCount'] === true, 'Admin: can review, cannot count');
$rau = $asUser($aud, 'inventory');
ok($rau['can']['count'] === false && $rau['can']['reviewCount'] === false && count($rau['counts']) === count($rp['counts']), 'Audit: read-only list of the same counts');
ok(!Access::canOpen($mp, 'inventory') && !Access::canOpen($fin, 'inventory'), 'Motorpool and Finance cannot open Inventory');
fails(fn() => Purchasing::action($mp, 'count_details', ['count_id' => $c1['id']]), 'Motorpool cannot read a count', 403);
$cd = Purchasing::action($aud, 'count_details', ['count_id' => $c1['id']]);
ok($cd['count']['count_ref'] === $c1['count_ref'] && count($cd['documents']) === 1, 'Audit reads the count with its evidence file');
foreach (many($p, 'SELECT id, part_number, quantity_on_hand FROM parts') as $pt) {
    $sum = (int)one($p, 'SELECT COALESCE(SUM(quantity_delta),0) AS s FROM stock_movements WHERE part_id=?', [$pt['id']])['s'];
    ok($sum === (int)$pt['quantity_on_hand'], "ledger: movements of {$pt['part_number']} add up to on hand ($sum)", 'on hand ' . $pt['quantity_on_hand']);
}
ok((int)one($p, 'SELECT COUNT(*) AS n FROM audit_events')['n'] > $auditBefore, 'every change above was audited');

// ---------------------------------------------------------------------
section('G. Purchasing Reports analytics (read only, SQL figures, explained rules)');
$writesBefore = (int)one($p, 'SELECT COUNT(*) AS n FROM audit_events')['n'] + (int)one($p, 'SELECT COUNT(*) AS n FROM stock_movements')['n'];
$an = Purchasing::analytics($pur, ['from' => date('Y-m-01'), 'to' => date('Y-m-d')]);
ok(empty($an['_errors']), 'analytics: every section loads', json_encode($an['_errors'] ?? []));
$mvBy = array_column($an['movements'], null, 'movement_type');
$sqlIn = (int)one($p, "SELECT COALESCE(SUM(quantity_delta),0) AS n FROM stock_movements WHERE movement_type='Stock In'")['n'];
ok((int)$mvBy['Stock In']['units'] === $sqlIn && $sqlIn === 6, 'analytics: stock-in units equal the movement ledger (6)');
ok((float)$an['stockNow']['value'] === round($qty($P1) * 3250, 2), 'analytics: inventory value = on hand x recorded unit cost');
$rec = array_values(array_filter($an['recommendations'], fn($r) => $r['part_id'] === $P1));
ok(!$rec, 'no reorder recommendation while on hand (5) is above the reorder level (2)');
Purchasing::action($pur, 'part_save', ['id' => $P1, 'part_number' => 'BRK-PAD-F-FVR', 'description' => 'Front brake pad set, Isuzu FVR', 'category' => 'Brakes', 'unit' => 'set', 'unit_cost' => '3250', 'reorder_level' => '8']);
$rec = array_values(array_filter(Purchasing::analytics($pur, [])['recommendations'], fn($r) => $r['part_id'] === $P1));
ok($rec && $rec[0]['rule'] === 'reorder' && $rec[0]['suggest'] === 3 && str_contains($rec[0]['why'], 'reorder level 8') && str_contains($rec[0]['why'], 'on hand 5'),
    'reorder rule: level 8, on hand 5 -> suggest 3 with the explanation', json_encode($rec));
ok(Purchasing::analytics($pur, [])['forecast']['status'] === 'insufficient', 'forecast: insufficient history is reported, no estimate shown');
fails(fn() => Purchasing::analytics($mp, []), 'Motorpool cannot read Purchasing Reports', 403);
ok(empty(Purchasing::analytics($fin, [])['_errors']), 'Finance reads Purchasing Reports');
ok((int)one($p, 'SELECT COUNT(*) AS n FROM stock_movements')['n'] + (int)one($p, 'SELECT COUNT(*) AS n FROM audit_events')['n'] === $writesBefore + 1, 'analytics reads wrote nothing (only the part edit was audited)');

array_map('unlink', glob($tmpDir . '/*') ?: []); @rmdir($tmpDir);
echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL ? 1 : 0);
