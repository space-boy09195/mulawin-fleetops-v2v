<?php
/*
 * =====================================================================
 *  tests/billing_adjustment_tests.php  —  Billing Rate Approval
 * =====================================================================
 *  Do NOT run this file directly: run tests/run_billing_adjustment_tests.php (temporary copy,
 *  own .env, disposable *_test databases only).
 *
 *    A. Migration: old charges grandfathered as Approved, totals unchanged, fresh = upgrade
 *    B. New additional charge: Pending until Admin approves; notifications; audit
 *    C. Roles and separation of duties
 *    D. Per-trip rate adjustments (increase / discount) and rejection
 *    E. Change of an approved line (atomic supersede; rejected change keeps the old line)
 *    F. Removal of an approved line; withdrawing a pending request
 *    G. Total must stay above zero
 *    H. Document verification needs the Delivery Receipt; finalize blocked while pending
 *    I. Finalized / paid invoices untouched; requests refused after finalizing
 *    J. Database constraints, details() and read() views
 *    K. Trip operations do not depend on billing approvals
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/backend/Actions.php';
require_once __DIR__ . '/_launcher.php';
core('Access');
core('Settings');
core('Files');

$FRESH = envValue('DB_NAME'); $UPGRADE = envValue('TEST_DB_UPGRADE');
test_guard([$FRESH, $UPGRADE]);
const MIG = '2026-11-09_09_billing_adjustments';

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
function dbRefuses(PDO $p, string $sql, array $args, string $name): void {
    try { run($p, $sql, $args); ok(false, $name, 'database accepted it'); }
    catch (PDOException $e) { ok(true, $name); }
}
function section(string $t): void { echo "\n== $t ==\n"; }

section('Setup: disposable fresh database');
test_recreate($FRESH);
$p = db();
ok($p->query('SELECT DATABASE()')->fetchColumn() === $FRESH, 'db() is the disposable test database');
test_import($p, test_sql('database/schema.sql'));
test_import($p, test_sql('database/extension.sql'));
$users = [];
$mk = function (string $username, string $role) use ($p, &$users): array {
    $id = insert($p, 'users', ['username' => $username, 'email' => "$username@example.test", 'password_hash' => 'x', 'full_name' => strtoupper($username) . ' USER', 'role' => $role]);
    return $users[$username] = ['id' => $id, 'username' => $username, 'email' => "$username@example.test", 'full_name' => strtoupper($username) . ' USER', 'role' => $role, 'shift' => null];
};
foreach (array_keys(ROLE_LABELS) as $role) $mk($role, $role);
$mk('admin2', 'admin'); $mk('billing2', 'billing');
$U = fn(string $n) => $users[$n];
$bil = $U('billing'); $bil2 = $U('billing2'); $adm = $U('admin'); $adm2 = $U('admin2'); $ops = $U('operations_head');
Actions::load('Trips'); Actions::load('Billing');
$cid = insert($p, 'clients', ['company_name' => 'BILL CLIENT', 'status' => 'Active', 'created_by' => $ops['id']]);
insert($p, 'client_rates', ['client_id' => $cid, 'truck_type' => 'Wing Van', 'rate_per_km' => '50.00', 'minimum_charge' => '0.00', 'updated_by' => $bil['id']]);
$rateBefore = $p->query('CHECKSUM TABLE client_rates')->fetch()['Checksum'];

$n = 0;
/** A completed trip with a billing record in $status (base = 20 km x PHP 50 = PHP 1,000). */
$billing = function (string $status = 'For Billing', bool $docs = true) use ($p, $ops, $cid, &$n): int {
    $n++;
    $t = (int)test_create_planned_trip($ops, ['client_id' => $cid, 'truck_type' => 'Wing Van', 'pickup_point' => "P$n", 'delivery_point' => "D$n",
        'scheduled_at' => date('Y-m-d\TH:i', time() + 3600), 'distance_km' => '20'])['trip_id'];
    $tripStatus = $status === 'Ongoing' ? 'In Transit' : 'Completed';
    run($p, "UPDATE trips SET status=?, dispatched_at=DATE_SUB(NOW(), INTERVAL 5 HOUR), completed_at=IF(?='Completed', DATE_SUB(NOW(), INTERVAL 1 HOUR), NULL) WHERE id=?", [$tripStatus, $tripStatus, $t]);
    $b = insert($p, 'billings', ['trip_id' => $t, 'client_id' => $cid, 'status' => $status, 'distance_km' => '20.00', 'rate_per_km' => '50.00', 'minimum_charge' => '0.00',
        'base_amount' => '1000.00', 'charges_total' => '0.00', 'total_amount' => '1000.00']);
    if ($docs) foreach (['Waybill', 'Delivery Receipt', 'Acknowledgement Receipt'] as $cat) insert($p, 'documents', ['category' => $cat, 'entity_type' => 'trip', 'entity_id' => $t,
        'original_filename' => "$cat.pdf", 'storage_name' => bin2hex(random_bytes(20)) . '.pdf', 'mime_type' => 'application/pdf', 'byte_size' => 10, 'uploaded_by' => $ops['id']]);
    return $b;
};
$B = fn(int $id) => one($p, 'SELECT * FROM billings WHERE id=?', [$id]);
$C = fn(int $id) => one($p, 'SELECT * FROM billing_charges WHERE id=?', [$id]);
$act = fn(array $u, string $a, array $d) => Billing::action($u, $a, $d);
$total = fn(int $id) => $B($id)['total_amount'];
$note = fn(int $userId, string $key) => one($p, 'SELECT * FROM notifications WHERE user_id=? AND dedupe_key=?', [$userId, $key]);
$audit = fn(string $action, int $bid) => (int)one($p, 'SELECT COUNT(*) AS n FROM audit_events WHERE action=? AND record_ref=?', [$action, 'billing ' . $bid])['n'];

// ---------------------------------------------------------------------
section('A. Migration: safe order, recovery, grandfathered charges, fresh install = upgrade');
$MIGSQL = test_sql('database/migrations/' . MIG . '.sql');
$FINISH = test_sql('database/migrations/recovery/' . MIG . '_finish.sql');
$CHECK = trim((string)preg_replace('/^\s*--.*$/m', '', test_sql('database/migrations/checks/' . MIG . '_check.sql')));
$stmts = fn(string $sql) => array_values(array_filter(array_map('trim', preg_split('/;\s*\n/', (string)preg_replace('/^\s*--.*$/m', '', $sql)))));
$state = fn(PDO $c) => $c->query(rtrim($CHECK, "; \n"))->fetch();
$mst = $stmts($MIGSQL);
ok(count($mst) === 4 && str_starts_with($mst[0], 'ALTER TABLE billing_charges') && str_starts_with($mst[3], 'INSERT INTO schema_migrations'), 'migration = 1 ALTER (all schema changes), backfill, default, and the schema_migrations marker LAST', json_encode(array_map(fn($x) => substr($x, 0, 40), $mst)));
ok(substr_count($MIGSQL, 'ALTER TABLE billing_charges') === 2 && !str_contains($mst[1] . $mst[2], 'DROP') && !str_contains($mst[1] . $mst[2], 'ADD COLUMN'), 'every column / constraint change is inside the single step-1 ALTER');
ok($state($p)['state'] === 'COMPLETE', 'check file: fresh install is COMPLETE', json_encode($state($p)));
$baseSchema = __DIR__ . '/_base_schema.sql'; $baseExt = __DIR__ . '/_base_extension.sql';
if (!is_file($baseSchema) || !is_file($baseExt)) {
    echo "  NOTE  git history not available: upgrade checks skipped (run from a git clone where the migration is committed)\n";
} else {
    $tA = $billing('For Billing'); $tB = $billing('Invoiced'); $tC = $billing('Paid');
    $oldCharges = [[$tA, 'Toll fees', '250.00'], [$tA, 'Waiting time', '300.00'], [$tB, 'Extra drop', '500.00'], [$tC, 'Toll', '120.00']];
    /** Old database (schema before this migration) with For Billing, Invoiced and Paid records and old charges. */
    $oldDb = function () use ($UPGRADE, $FRESH, $baseSchema, $baseExt, $oldCharges, $bil): PDO {
        test_recreate($UPGRADE);
        $up = test_conn($UPGRADE);
        test_import($up, (string)file_get_contents($baseSchema));
        test_import($up, (string)file_get_contents($baseExt));
        foreach (test_migrations_from('2026-11-08_08_attendance') as $m) if (strcmp($m, MIG) < 0 && !one($up, 'SELECT id FROM schema_migrations WHERE id=?', [$m])) test_import($up, test_sql("database/migrations/$m.sql"));
        // Later migrations (e.g. kenneth 10/11 on trips) add columns the pre-09 schema lacks: copy the shared columns only.
        foreach (['users', 'clients', 'client_rates', 'client_routes', 'trips', 'billings'] as $tbl) {
            $cols = implode(',', array_map(fn($c) => '`' . $c . '`', array_column(many($up, 'SELECT a.column_name AS c FROM information_schema.columns a JOIN information_schema.columns b ON b.table_name=a.table_name AND b.column_name=a.column_name AND b.table_schema=? WHERE a.table_schema=? AND a.table_name=? AND a.extra NOT LIKE "%GENERATED%" ORDER BY a.ordinal_position', [$FRESH, $UPGRADE, $tbl]), 'c')));
            $up->exec("INSERT INTO `$UPGRADE`.$tbl ($cols) SELECT $cols FROM `$FRESH`.$tbl");
        }
        foreach ($oldCharges as [$bid, $desc, $amt]) {
            insert($up, 'billing_charges', ['billing_id' => $bid, 'description' => $desc, 'amount' => $amt, 'added_by' => $bil['id']]);
            $up->prepare('UPDATE billings SET charges_total=charges_total+?, total_amount=total_amount+? WHERE id=?')->execute([$amt, $amt, $bid]);
        }
        $up->exec("UPDATE billings SET invoice_number=CONCAT('SI-OLD-', id), invoice_date=CURRENT_DATE(), due_date=CURRENT_DATE(), finalized_by={$bil['id']}, finalized_at=NOW() WHERE status IN ('Invoiced','Paid')");
        $up->exec("UPDATE billings SET amount_paid=total_amount WHERE status='Paid'");
        return $up;
    };
    $sum = fn(PDO $c) => $c->query('CHECKSUM TABLE billings')->fetch()['Checksum'];
    $oldCols = 'id, billing_id, description, amount, added_by, created_at';
    $fa = test_fingerprint($p, $FRESH);
    $sameAsFresh = function (PDO $c, string $label) use ($fa, $UPGRADE): void {
        // Later migrations (other features) are part of a fresh install: apply them first (each only once).
        foreach (test_migrations_from(MIG) as $later) if (strcmp($later, MIG) > 0 && !one($c, 'SELECT id FROM schema_migrations WHERE id=?', [$later])) test_import($c, test_sql("database/migrations/$later.sql"));
        $fb = test_fingerprint($c, $UPGRADE);
        foreach ($fa as $k => $v) ok($v === $fb[$k], "$label: identical $k to a fresh install", json_encode([array_values(array_diff($v, $fb[$k])), array_values(array_diff($fb[$k], $v))]));
    };

    // A1. Normal upgrade: the whole file at once.
    $up = $oldDb();
    ok($state($up)['state'] === 'NOT APPLIED', 'check file: old database is NOT APPLIED', json_encode($state($up)));
    $billSum = $sum($up); $oldRows = many($up, "SELECT $oldCols FROM billing_charges ORDER BY id");
    test_import($up, $MIGSQL);
    ok($state($up)['state'] === 'COMPLETE', 'normal upgrade: check file says COMPLETE');
    ok($sum($up) === $billSum, 'normal upgrade: billings (For Billing, Invoiced, Paid) unchanged: same totals and invoice data');
    ok(many($up, "SELECT $oldCols FROM billing_charges ORDER BY id") === $oldRows, 'normal upgrade: existing charge rows kept as they were');
    $st = many($up, 'SELECT DISTINCT status, kind, request_type, replaces_id, decided_by, decision_notes FROM billing_charges');
    ok(count($st) === 1 && $st[0]['status'] === 'Approved' && $st[0]['kind'] === 'Additional Charge' && $st[0]['request_type'] === 'New' && $st[0]['replaces_id'] === null
        && $st[0]['decided_by'] === null && str_contains((string)$st[0]['decision_notes'], 'before the approval workflow'), 'existing charges are Approved (grandfathered) and noted', json_encode($st));
    ok(one($up, 'SELECT COALESCE(SUM(amount),0) AS s FROM billing_charges WHERE billing_id=? AND ' . Billing::COUNTED, [$tA])['s'] === '550.00'
        && one($up, 'SELECT charges_total FROM billings WHERE id=?', [$tA])['charges_total'] === '550.00', 'approved-only sum equals the stored charges total');
    $sameAsFresh($up, 'normal upgrade');
    $fb = test_fingerprint($up, $UPGRADE);
    try { test_import($up, $MIGSQL); ok(false, 'second import refused'); }
    catch (PDOException $e) { ok(str_contains($e->getMessage(), "Duplicate column name 'kind'"), 'second import stops at step 1 (Duplicate column) before changing anything', $e->getMessage()); }
    ok(test_fingerprint($up, $UPGRADE) === $fb && $sum($up) === $billSum && $state($up)['state'] === 'COMPLETE', 'refused second import changed nothing (old CHECK not dropped twice)');
    try { test_import($up, $FINISH); ok(false, 'recovery on a complete database refused'); }
    catch (PDOException $e) { ok(str_contains($e->getMessage(), 'Duplicate entry'), 'recovery file on a COMPLETE database stops at the marker', $e->getMessage()); }
    ok(test_fingerprint($up, $UPGRADE) === $fb && $sum($up) === $billSum, 'recovery file on a complete database changed nothing');

    // A2. Step 1 fails (simulated name clash): nothing applied, nothing recorded.
    $up = $oldDb();
    $billSum = $sum($up); $before = test_fingerprint($up, $UPGRADE);
    $up->exec('CREATE TABLE zz_block (id INT PRIMARY KEY, u INT, CONSTRAINT fk_bcharge_decider FOREIGN KEY (u) REFERENCES users(id))');
    $blocked = test_fingerprint($up, $UPGRADE);
    try { test_import($up, $MIGSQL); ok(false, 'step 1 failure simulated'); }
    catch (PDOException $e) { ok(true, 'step 1 failed (simulated constraint name clash)'); }
    ok(test_fingerprint($up, $UPGRADE) === $blocked, 'failed step 1 left the table exactly as before (old CHECK kept, no new column)');
    ok(!one($up, 'SELECT id FROM schema_migrations WHERE id=?', [MIG]) && $state($up)['state'] === 'NOT APPLIED', 'failed migration NOT recorded; check file says NOT APPLIED');
    $up->exec('DROP TABLE zz_block');
    ok(test_fingerprint($up, $UPGRADE) === $before, 'cause removed: back to the old schema');
    test_import($up, $MIGSQL);
    ok($state($up)['state'] === 'COMPLETE' && $sum($up) === $billSum, 'after fixing the cause, importing the migration again completes it; totals unchanged');
    $sameAsFresh($up, 'retry after a failed step 1');

    // A3. Stopped after step 1 (connection lost): PARTIAL -> recovery file.
    $up = $oldDb();
    $billSum = $sum($up);
    $up->exec($mst[0]);                                     // only step 1 ran
    $s3 = $state($up);
    ok($s3['state'] === 'PARTIAL' && (int)$s3['recorded'] === 0, 'stopped after step 1: check file says PARTIAL, not recorded', json_encode($s3));
    ok($sum($up) === $billSum && (int)one($up, "SELECT COUNT(*) AS n FROM billing_charges WHERE status<>'Approved'")['n'] === 0, 'PARTIAL state: old charges already Approved, totals unchanged (safe meanwhile)');
    $part = test_fingerprint($up, $UPGRADE);
    try { test_import($up, $MIGSQL); ok(false, 'migration re-import refused in PARTIAL state'); }
    catch (PDOException $e) { ok(str_contains($e->getMessage(), 'Duplicate column'), 'importing the migration again is refused at step 1 (nothing re-run)', $e->getMessage()); }
    ok(test_fingerprint($up, $UPGRADE) === $part && $state($up)['state'] === 'PARTIAL', 'refused re-import changed nothing');
    test_import($up, $FINISH);
    ok($state($up)['state'] === 'COMPLETE' && $sum($up) === $billSum, 'recovery file finished steps 2-4: COMPLETE, totals unchanged');
    ok((int)one($up, "SELECT COUNT(*) AS n FROM billing_charges WHERE decision_notes LIKE '%before the approval workflow%'")['n'] === count($oldCharges), 'recovery backfilled the grandfather note');
    $sameAsFresh($up, 'PARTIAL + recovery');

    // A4. Recovery file used by mistake on a database where step 1 never ran.
    $up = $oldDb();
    $before = test_fingerprint($up, $UPGRADE);
    try { test_import($up, $FINISH); ok(false, 'recovery on an old database refused'); }
    catch (PDOException $e) { ok(str_contains($e->getMessage(), 'Unknown column'), 'recovery file on a NOT APPLIED database fails at its first statement', $e->getMessage()); }
    ok(test_fingerprint($up, $UPGRADE) === $before && $state($up)['state'] === 'NOT APPLIED', 'mistaken recovery changed and recorded nothing');

    // The same old records in the fresh database (used by section I).
    foreach ($oldCharges as [$bid, $desc, $amt]) insert($p, 'billing_charges', ['billing_id' => $bid, 'description' => $desc, 'amount' => $amt, 'status' => 'Approved', 'added_by' => $bil['id']]);
    run($p, "UPDATE billings b SET charges_total=(SELECT SUM(amount) FROM billing_charges c WHERE c.billing_id=b.id), total_amount=base_amount+(SELECT SUM(amount) FROM billing_charges c WHERE c.billing_id=b.id) WHERE b.id IN (?,?,?)", [$tA, $tB, $tC]);
    run($p, "UPDATE billings SET invoice_number=CONCAT('SI-OLD-', id), invoice_date=CURRENT_DATE(), due_date=CURRENT_DATE(), finalized_by=?, finalized_at=NOW() WHERE id IN (?,?)", [$bil['id'], $tB, $tC]);
    run($p, "UPDATE billings SET amount_paid=total_amount WHERE id=?", [$tC]);
}

// ---------------------------------------------------------------------
section('B. New additional charge: pending until the Admin approves');
$b1 = $billing();
$r = $act($bil, 'charge_add', ['billing_id' => $b1, 'description' => 'Toll fees', 'amount' => '350']);
$c1 = (int)$r['charge_id'];
$c = $C($c1);
ok($c['status'] === 'Pending Approval' && $c['kind'] === 'Additional Charge' && $c['request_type'] === 'New' && $c['amount'] === '350.00', 'request saved as Pending Approval', json_encode($c));
ok($total($b1) === '1000.00' && $B($b1)['charges_total'] === '0.00', 'pending charge is NOT in the billing total');
ok(str_contains($r['message'], 'Admin'), 'message says it needs Admin approval', $r['message']);
ok((bool)$note($adm['id'], "billing-adj-$c1") && (bool)$note($adm2['id'], "billing-adj-$c1"), 'every Admin is notified (dedupe key billing-adj-ID)');
ok(!$note($bil['id'], "billing-adj-$c1"), 'Billing (requester) is not sent the approval task');
ok($audit('billing adjustment requested', $b1) === 1, 'request is audited');
$r = $act($adm, 'charge_decide', ['charge_id' => $c1, 'decision' => 'approve', 'notes' => 'OK per toll receipts']);
$c = $C($c1);
ok($c['status'] === 'Approved' && (int)$c['decided_by'] === $adm['id'] && $c['decided_at'] !== null && $c['decision_notes'] === 'OK per toll receipts', 'Admin approved: decider, time and notes recorded');
ok($total($b1) === '1350.00' && $B($b1)['charges_total'] === '350.00', 'approved charge counts in the total (1000 + 350)');
ok($note($adm2['id'], "billing-adj-$c1")['state'] === 'Resolved' && $note($adm['id'], "billing-adj-$c1")['state'] === 'Resolved', 'approval task resolved for every Admin');
ok((bool)one($p, "SELECT id FROM notifications WHERE user_id=? AND category='Billing approval' AND message LIKE '%approved%'", [$bil['id']]), 'requester told about the approval');
ok($audit('billing adjustment approved', $b1) === 1, 'approval is audited');
fails(fn() => $act($adm2, 'charge_decide', ['charge_id' => $c1, 'decision' => 'approve']), 'deciding twice refused', 409, 'already decided');
ok($p->query('CHECKSUM TABLE client_rates')->fetch()['Checksum'] === $rateBefore, 'global client rates not changed');

// ---------------------------------------------------------------------
section('C. Roles and separation of duties');
$c2 = (int)$act($bil, 'charge_add', ['billing_id' => $b1, 'description' => 'Waiting time', 'amount' => '200'])['charge_id'];
foreach (['admin', 'finance', 'operations_head', 'audit', 'container_wingvan_dispatcher'] as $role)
    fails(fn() => $act($U($role), 'charge_add', ['billing_id' => $b1, 'description' => 'X', 'amount' => '1']), "$role cannot request charges", 403);
foreach (['billing', 'billing2', 'finance', 'operations_head', 'audit'] as $who)
    fails(fn() => $act($U($who), 'charge_decide', ['charge_id' => $c2, 'decision' => 'approve']), "$who cannot approve", 403);
fails(fn() => $act($adm, 'charge_withdraw', ['charge_id' => $c2]), 'Admin cannot withdraw a Billing request', 403);
run($p, 'UPDATE billing_charges SET added_by=? WHERE id=?', [$adm['id'], $c2]);   // forged: request entered by this Admin
fails(fn() => $act($adm, 'charge_decide', ['charge_id' => $c2, 'decision' => 'approve']), 'no self-approval (separation of duties)', 403, 'separation of duties');
fails(fn() => $act($adm, 'charge_decide', ['charge_id' => $c2, 'decision' => 'reject', 'reason' => 'x']), 'no self-rejection either', 403);
run($p, 'UPDATE billing_charges SET added_by=? WHERE id=?', [$bil['id'], $c2]);
ok($C($c2)['status'] === 'Pending Approval' && $total($b1) === '1350.00', 'refused decisions changed nothing');
fails(fn() => $act($adm, 'charge_decide', ['charge_id' => $c2, 'decision' => 'reject']), 'rejecting needs a reason', 400);
$act($adm, 'charge_decide', ['charge_id' => $c2, 'decision' => 'reject', 'reason' => 'No waiting time on the waybill']);
ok($C($c2)['status'] === 'Rejected' && $C($c2)['decision_notes'] === 'No waiting time on the waybill' && $total($b1) === '1350.00', 'rejected charge never enters the total');
ok($note($adm['id'], "billing-adj-$c2")['state'] === 'Resolved', 'rejection resolves the approval task');
ok((bool)one($p, "SELECT id FROM notifications WHERE user_id=? AND message LIKE '%rejected%No waiting time%'", [$bil['id']]), 'requester told about the rejection with the reason');
ok($audit('billing adjustment rejected', $b1) === 1, 'rejection is audited');
fails(fn() => $act($adm, 'charge_decide', ['charge_id' => $c2, 'decision' => 'approve']), 'a rejected request cannot be approved later', 409);

// ---------------------------------------------------------------------
section('D. Per-trip rate adjustments (peso amounts, plus or minus)');
fails(fn() => $act($bil, 'charge_add', ['billing_id' => $b1, 'kind' => 'Rate Adjustment', 'description' => 'Special rate', 'amount' => '100', 'direction' => 'Decrease']), 'rate adjustment needs a reason', 400);
fails(fn() => $act($bil, 'charge_add', ['billing_id' => $b1, 'kind' => 'Rate Adjustment', 'description' => 'Special rate', 'amount' => '100', 'reason' => 'x']), 'rate adjustment needs a direction', 400);
fails(fn() => $act($bil, 'charge_add', ['billing_id' => $b1, 'kind' => 'Discount', 'description' => 'x', 'amount' => '1']), 'unknown kind refused', 400);
fails(fn() => $act($bil, 'charge_add', ['billing_id' => $b1, 'description' => 'Neg', 'amount' => '-50']), 'negative additional charge refused', 400);
fails(fn() => $act($bil, 'charge_add', ['billing_id' => $b1, 'description' => 'Zero', 'amount' => '0']), 'zero amount refused', 400);
$d1 = (int)$act($bil, 'charge_add', ['billing_id' => $b1, 'kind' => 'Rate Adjustment', 'description' => 'Loyalty discount', 'amount' => '150', 'direction' => 'Decrease', 'reason' => 'Approved by owner for this trip'])['charge_id'];
ok($C($d1)['amount'] === '-150.00' && $C($d1)['kind'] === 'Rate Adjustment' && $total($b1) === '1350.00', 'discount stored as -150.00, pending, total unchanged');
$d2 = (int)$act($bil2, 'charge_add', ['billing_id' => $b1, 'kind' => 'Rate Adjustment', 'description' => 'Special handling', 'amount' => '400', 'direction' => 'Increase', 'reason' => 'Fragile cargo'])['charge_id'];
ok($C($d2)['amount'] === '400.00', 'increase stored as +400.00');
$act($adm, 'charge_decide', ['charge_id' => $d1, 'decision' => 'approve']);
ok($total($b1) === '1200.00', 'approved discount: 1000 + 350 - 150 = 1200');
$act($adm2, 'charge_decide', ['charge_id' => $d2, 'decision' => 'reject', 'reason' => 'Not agreed with client']);
ok($total($b1) === '1200.00' && $C($d2)['status'] === 'Rejected', 'rejected increase not in the total');
ok($B($b1)['rate_per_km'] === '50.00' && $B($b1)['base_amount'] === '1000.00', 'base amount and rate per km of the trip unchanged (adjustment is a separate line)');

// ---------------------------------------------------------------------
section('E. Change of an approved line');
fails(fn() => $act($bil, 'charge_change', ['charge_id' => $c1, 'description' => 'Toll fees', 'amount' => '350', 'reason' => 'x']), 'change with nothing changed refused', 400, 'Nothing changed');
fails(fn() => $act($bil, 'charge_change', ['charge_id' => $c2, 'description' => 'Toll fees', 'amount' => '10', 'reason' => 'x']), 'a rejected request cannot be changed', 409);
fails(fn() => $act($bil, 'charge_change', ['charge_id' => $c1, 'description' => 'Toll fees', 'amount' => '420']), 'change needs a reason', 400);
$e1 = (int)$act($bil, 'charge_change', ['charge_id' => $c1, 'description' => 'Toll fees (NLEX + SCTEX)', 'amount' => '420', 'reason' => 'Second toll receipt'])['charge_id'];
ok($C($e1)['request_type'] === 'Change' && (int)$C($e1)['replaces_id'] === $c1 && $C($e1)['status'] === 'Pending Approval', 'change saved as a pending request pointing to the approved line');
ok($C($c1)['status'] === 'Approved' && $C($c1)['amount'] === '350.00' && $total($b1) === '1200.00', 'approved line unchanged while the change waits');
fails(fn() => $act($bil2, 'charge_change', ['charge_id' => $c1, 'description' => 'Toll', 'amount' => '999', 'reason' => 'x']), 'second change of the same line refused while one is pending', 409, 'already waiting');
fails(fn() => $act($bil2, 'charge_remove', ['charge_id' => $c1, 'reason' => 'x']), 'removal of the same line refused while a change is pending', 409, 'already waiting');
$act($adm, 'charge_decide', ['charge_id' => $e1, 'decision' => 'reject', 'reason' => 'Only one receipt']);
ok($C($c1)['status'] === 'Approved' && $C($c1)['amount'] === '350.00' && $total($b1) === '1200.00', 'rejected change: old approved line unchanged, total unchanged');
$e2 = (int)$act($bil, 'charge_change', ['charge_id' => $c1, 'description' => 'Toll fees (2 receipts)', 'amount' => '420', 'reason' => 'Second receipt found'])['charge_id'];
$act($adm2, 'charge_decide', ['charge_id' => $e2, 'decision' => 'approve']);
ok($C($c1)['status'] === 'Superseded' && $C($e2)['status'] === 'Approved' && $total($b1) === '1270.00', 'approved change: old line Superseded, new line Approved, total 1270 (one transaction)');
ok($C($c1)['amount'] === '350.00' && $C($c1)['description'] === 'Toll fees', 'superseded line kept unedited for history');
fails(fn() => $act($bil, 'charge_change', ['charge_id' => $c1, 'description' => 'x', 'amount' => '1', 'reason' => 'x']), 'superseded line cannot be changed', 409);
$dChg = (int)$act($bil, 'charge_change', ['charge_id' => $d1, 'description' => 'Loyalty discount', 'amount' => '100', 'direction' => 'Decrease', 'reason' => 'Corrected'])['charge_id'];
ok($C($dChg)['amount'] === '-100.00' && $C($dChg)['kind'] === 'Rate Adjustment', 'a discount change keeps its kind and sign');
// Atomicity: the replaced line is changed behind the request's back (simulates a broken state) -> nothing applied.
run($p, "UPDATE billing_charges SET status='Superseded' WHERE id=?", [$d1]);
fails(fn() => $act($adm, 'charge_decide', ['charge_id' => $dChg, 'decision' => 'approve']), 'approval refused when the replaced line is no longer approved', 409, 'no longer approved');
ok($C($dChg)['status'] === 'Pending Approval', 'refused approval rolled back completely');
run($p, "UPDATE billing_charges SET status='Approved' WHERE id=?", [$d1]);
$act($adm, 'charge_decide', ['charge_id' => $dChg, 'decision' => 'approve']);
ok($total($b1) === '1320.00' && $C($d1)['status'] === 'Superseded', 'discount changed 150 -> 100: total 1320');

// ---------------------------------------------------------------------
section('F. Removal of an approved line; withdrawing a pending request');
fails(fn() => $act($bil, 'charge_remove', ['charge_id' => $e2]), 'removal needs a reason', 400);
$rm = (int)$act($bil, 'charge_remove', ['charge_id' => $e2, 'reason' => 'Client paid the toll directly'])['charge_id'];
ok($C($rm)['request_type'] === 'Removal' && (int)$C($rm)['replaces_id'] === $e2 && $C($e2)['status'] === 'Approved' && $total($b1) === '1320.00', 'removal is a pending request; the line stays in the total');
$act($adm, 'charge_decide', ['charge_id' => $rm, 'decision' => 'approve']);
ok($C($e2)['status'] === 'Removed' && $C($rm)['status'] === 'Approved' && $total($b1) === '900.00', 'approved removal: line Removed (not deleted), total 1000 - 100 = 900');
ok((bool)$C($e2), 'removed line still in the database (history)');
$w = (int)$act($bil, 'charge_add', ['billing_id' => $b1, 'description' => 'Typo request', 'amount' => '75'])['charge_id'];
fails(fn() => $act($bil, 'charge_remove', ['charge_id' => $w, 'reason' => 'x']), 'a pending request is withdrawn, not removed', 409, 'Withdraw');
fails(fn() => $act($bil2, 'charge_withdraw', ['charge_id' => $w]), 'another Billing user cannot withdraw it (direct API)', 403, 'person who made');
foreach (['admin', 'admin2', 'finance', 'operations_head', 'audit'] as $who)
    fails(fn() => $act($U($who), 'charge_withdraw', ['charge_id' => $w]), "$who cannot withdraw it (direct API)", 403);
ok($C($w)['status'] === 'Pending Approval', 'refused withdrawals changed nothing');
$act($bil, 'charge_withdraw', ['charge_id' => $w]);
ok($C($w)['status'] === 'Withdrawn' && (int)$C($w)['decided_by'] === $bil['id'] && $total($b1) === '900.00', 'the requester withdrew it (kept as history), total unchanged');
ok($note($adm['id'], "billing-adj-$w")['state'] === 'Resolved', 'withdrawing resolves the Admin task');
fails(fn() => $act($adm, 'charge_decide', ['charge_id' => $w, 'decision' => 'approve']), 'withdrawn request cannot be approved', 409);
fails(fn() => $act($bil, 'charge_withdraw', ['charge_id' => $e2]), 'an approved / decided line cannot be withdrawn', 409);
ok(array_map('intval', array_column(many($p, 'SELECT id FROM billing_charges WHERE billing_id=? ORDER BY id', [$b1]), 'id')) === [$c1, $c2, $d1, $d2, $e1, $e2, $dChg, $rm, $w], 'no billing line was ever deleted (every request and line kept)');

// ---------------------------------------------------------------------
section('G. Billing total must stay above zero');
$b2 = $billing();
fails(fn() => $act($bil, 'charge_add', ['billing_id' => $b2, 'kind' => 'Rate Adjustment', 'description' => 'Free trip', 'amount' => '1000', 'direction' => 'Decrease', 'reason' => 'x']), 'discount equal to the total refused (total 0)', 409, 'above zero');
fails(fn() => $act($bil, 'charge_add', ['billing_id' => $b2, 'kind' => 'Rate Adjustment', 'description' => 'Too much', 'amount' => '1500', 'direction' => 'Decrease', 'reason' => 'x']), 'discount bigger than the total refused', 409);
$g1 = (int)$act($bil, 'charge_add', ['billing_id' => $b2, 'kind' => 'Rate Adjustment', 'description' => 'Discount A', 'amount' => '600', 'direction' => 'Decrease', 'reason' => 'x'])['charge_id'];
$g2 = (int)$act($bil, 'charge_add', ['billing_id' => $b2, 'kind' => 'Rate Adjustment', 'description' => 'Discount B', 'amount' => '600', 'direction' => 'Decrease', 'reason' => 'y'])['charge_id'];
$act($adm, 'charge_decide', ['charge_id' => $g1, 'decision' => 'approve']);
fails(fn() => $act($adm, 'charge_decide', ['charge_id' => $g2, 'decision' => 'approve']), 'approving the second discount refused (total would be -200)', 409, 'above zero');
ok($C($g2)['status'] === 'Pending Approval' && $total($b2) === '400.00', 'refused approval left the request pending and the total at 400');
ok($note($adm['id'], "billing-adj-$g2")['state'] !== 'Resolved', 'refused approval did not resolve the Admin task (rolled back)');
$act($adm, 'charge_decide', ['charge_id' => $g2, 'decision' => 'reject', 'reason' => 'Double discount']);
$g3 = (int)$act($bil, 'charge_add', ['billing_id' => $b2, 'description' => 'Extra drop', 'amount' => '300'])['charge_id'];
$act($adm, 'charge_decide', ['charge_id' => $g3, 'decision' => 'approve']);
fails(fn() => $act($bil, 'charge_change', ['charge_id' => $g1, 'description' => 'Discount A', 'amount' => '1300', 'direction' => 'Decrease', 'reason' => 'x']), 'a change that would make the total zero is refused', 409, 'above zero');
$g4 = (int)$act($bil, 'charge_remove', ['charge_id' => $g3, 'reason' => 'x'])['charge_id'];
fails(fn() => $act($bil2, 'charge_add', ['billing_id' => $b2, 'kind' => 'Rate Adjustment', 'description' => 'Discount C', 'amount' => '700', 'direction' => 'Decrease', 'reason' => 'z']), 'request checked against the approved total (700 - 700 = 0 refused)', 409);
$act($bil, 'charge_withdraw', ['charge_id' => $g4]);
ok($total($b2) === '700.00', 'total 1000 - 600 + 300 = 700');

// ---------------------------------------------------------------------
section('H. Document verification (Delivery Receipt) and finalize');
$b3 = $billing('For Billing', false);
$t3 = (int)$B($b3)['trip_id'];
$doc = fn(string $cat) => insert($p, 'documents', ['category' => $cat, 'entity_type' => 'trip', 'entity_id' => $t3, 'original_filename' => "$cat.pdf",
    'storage_name' => bin2hex(random_bytes(20)) . '.pdf', 'mime_type' => 'application/pdf', 'byte_size' => 10, 'uploaded_by' => $ops['id']]);
$doc('Waybill'); $doc('Acknowledgement Receipt');
fails(fn() => $act($bil, 'verify', ['billing_id' => $b3]), 'verify refused without the Delivery Receipt', 422, 'Delivery Receipt');
$doc('Delivery Receipt');
$act($bil, 'verify', ['billing_id' => $b3]);
ok($B($b3)['docs_verified_at'] !== null, 'verify passes with Waybill + Delivery Receipt + Acknowledgement Receipt');
$h1 = (int)$act($bil, 'charge_add', ['billing_id' => $b3, 'description' => 'Overnight parking', 'amount' => '250'])['charge_id'];
fails(fn() => $act($bil, 'finalize', ['billing_id' => $b3, 'invoice_date' => date('Y-m-d')]), 'finalize blocked while a request is pending', 409, 'waiting for Admin approval');
ok($B($b3)['status'] === 'For Billing' && $B($b3)['invoice_number'] === null, 'blocked finalize changed nothing');
$act($adm, 'charge_decide', ['charge_id' => $h1, 'decision' => 'approve']);
$act($bil, 'finalize', ['billing_id' => $b3, 'invoice_date' => date('Y-m-d')]);
$f = $B($b3);
ok($f['status'] === 'Invoiced' && $f['total_amount'] === '1250.00' && $f['charges_total'] === '250.00', 'finalized with the approved total only (1000 + 250)');
// Pending -> withdraw -> finalize, total without it.
$b4 = $billing(); $act($bil, 'verify', ['billing_id' => $b4]);
$h2 = (int)$act($bil, 'charge_add', ['billing_id' => $b4, 'description' => 'Not needed', 'amount' => '999'])['charge_id'];
$act($bil, 'charge_withdraw', ['charge_id' => $h2]);
$act($bil, 'finalize', ['billing_id' => $b4, 'invoice_date' => date('Y-m-d')]);
ok($B($b4)['total_amount'] === '1000.00', 'withdrawn request not in the invoice');
// Rejected request does not block finalize and is not in the invoice.
$b5 = $billing(); $act($bil, 'verify', ['billing_id' => $b5]);
$h3 = (int)$act($bil, 'charge_add', ['billing_id' => $b5, 'description' => 'Rejected one', 'amount' => '500'])['charge_id'];
$act($adm, 'charge_decide', ['charge_id' => $h3, 'decision' => 'reject', 'reason' => 'No basis']);
$act($bil, 'finalize', ['billing_id' => $b5, 'invoice_date' => date('Y-m-d')]);
ok($B($b5)['total_amount'] === '1000.00' && $B($b5)['status'] === 'Invoiced', 'rejected request does not block and is not in the invoice');
// Safety net: a stale stored total is recomputed from approved lines at finalize.
$b6 = $billing(); $act($bil, 'verify', ['billing_id' => $b6]);
run($p, 'UPDATE billings SET total_amount=5000 WHERE id=?', [$b6]);
$act($bil, 'finalize', ['billing_id' => $b6, 'invoice_date' => date('Y-m-d')]);
ok($B($b6)['total_amount'] === '1000.00', 'finalize uses the approved lines, not a stale stored total');

// ---------------------------------------------------------------------
section('I. Finalized / paid invoices are untouched');
$inv = $b3;
$snap = json_encode($B($inv)); $lines = json_encode(many($p, 'SELECT * FROM billing_charges WHERE billing_id=?', [$inv]));
fails(fn() => $act($bil, 'charge_add', ['billing_id' => $inv, 'description' => 'Late', 'amount' => '10']), 'no new request after finalizing', 409);
fails(fn() => $act($bil, 'charge_change', ['charge_id' => $h1, 'description' => 'X', 'amount' => '10', 'reason' => 'x']), 'no change request after finalizing', 409);
fails(fn() => $act($bil, 'charge_remove', ['charge_id' => $h1, 'reason' => 'x']), 'no removal request after finalizing', 409);
ok(json_encode($B($inv)) === $snap && json_encode(many($p, 'SELECT * FROM billing_charges WHERE billing_id=?', [$inv])) === $lines, 'invoiced record and its lines unchanged');
if (isset($tB, $tC)) {
    $legacy = json_encode([$B($tB), $B($tC)]);
    foreach ([$tB, $tC] as $bid) {
        $old = (int)one($p, 'SELECT id FROM billing_charges WHERE billing_id=?', [$bid])['id'];
        fails(fn() => $act($bil, 'charge_change', ['charge_id' => $old, 'description' => 'X', 'amount' => '1', 'reason' => 'x']), "old invoiced/paid billing $bid cannot be changed", 409);
    }
    ok(json_encode([$B($tB), $B($tC)]) === $legacy, 'old Invoiced and Paid records unchanged');
}
// Payment flow still works on an invoice made through approvals.
$_FILES = []; // collect needs the receipt upload; only the status guard is checked here
fails(fn() => $act($bil, 'collect', ['billing_id' => $b4, 'amount' => '1000', 'payment_date' => date('Y-m-d'), 'payment_method' => 'Cash', 'ar_number' => 'AR-1']), 'payment still requires the acknowledgement receipt file (unchanged)', 422, 'acknowledgement receipt');
$ongoing = $billing('Ongoing');
fails(fn() => $act($bil, 'charge_add', ['billing_id' => $ongoing, 'description' => 'Early', 'amount' => '10']), 'no request while the trip is ongoing (draft view-only, unchanged)', 409, 'view-only');

// ---------------------------------------------------------------------
section('J. Database constraints and views');
dbRefuses($p, "INSERT INTO billing_charges (billing_id, description, amount, status, added_by) VALUES (?, 'neg', -5, 'Approved', ?)", [$b1, $bil['id']], 'DB: negative Additional Charge refused');
dbRefuses($p, "INSERT INTO billing_charges (billing_id, kind, description, amount, status, added_by) VALUES (?, 'Rate Adjustment', 'zero', 0, 'Approved', ?)", [$b1, $bil['id']], 'DB: zero adjustment refused');
dbRefuses($p, "INSERT INTO billing_charges (billing_id, request_type, replaces_id, description, amount, added_by) VALUES (?, 'New', ?, 'x', 5, ?)", [$b1, $c1, $bil['id']], 'DB: New request with replaces_id refused');
dbRefuses($p, "INSERT INTO billing_charges (billing_id, request_type, description, amount, added_by) VALUES (?, 'Change', 'x', 5, ?)", [$b1, $bil['id']], 'DB: Change without replaces_id refused');
dbRefuses($p, "INSERT INTO billing_charges (billing_id, description, amount, status, added_by) VALUES (?, 'x', 5, 'Maybe', ?)", [$b1, $bil['id']], 'DB: unknown status refused');
dbRefuses($p, "INSERT INTO billing_charges (billing_id, description, amount, status, added_by, decided_by, decided_at) VALUES (?, 'x', 5, 'Pending Approval', ?, ?, NOW())", [$b1, $bil['id'], $adm['id']], 'DB: pending row with a decision refused');
$def = (int)insert($p, 'billing_charges', ['billing_id' => $b1, 'description' => 'default', 'amount' => '5', 'added_by' => $bil['id']]);
ok($C($def)['status'] === 'Pending Approval', 'DB default status is Pending Approval (never approved by accident)');
run($p, "UPDATE billing_charges SET status='Withdrawn', decided_by=?, decided_at=NOW() WHERE id=?", [$bil['id'], $def]);
$det = $act($adm, 'details', ['billing_id' => $b1]);
$chg = array_map(fn($x) => (int)$x['id'], $det['charges']);
sort($chg);
$expect = array_map('intval', array_column(many($p, 'SELECT id FROM billing_charges WHERE billing_id=? AND ' . Billing::COUNTED . ' ORDER BY id', [$b1]), 'id'));
ok($chg === $expect && !in_array($c2, $chg, true) && !in_array($rm, $chg, true) && !in_array($e2, $chg, true), 'details(): charges = only the lines in the total (used by the printed invoice)', json_encode([$chg, $expect]));
$sumLines = array_sum(array_map(fn($x) => (float)$x['amount'], $det['charges']));
ok(abs(1000 + $sumLines - (float)$total($b1)) < 0.001, 'printed invoice lines add up to the billing total');
ok(count($det['requests']) + count($det['charges']) === (int)one($p, 'SELECT COUNT(*) AS n FROM billing_charges WHERE billing_id=?', [$b1])['n'], 'details(): requests = every other row (pending + history)');
$rd = Billing::read($adm, 'billing', []);
ok($rd['can']['decide'] === true && $rd['can']['act'] === false, 'read(): Admin can decide, cannot act');
ok(in_array($g2, array_column($rd['adjustments'], 'id'), false) === false && count($rd['adjustments']) === (int)one($p, "SELECT COUNT(*) AS n FROM billing_charges WHERE status='Pending Approval'")['n'], 'read(): approval list = all pending requests');
$rb = Billing::read($bil, 'billing', []);
ok($rb['can']['decide'] === false && $rb['can']['act'] === true, 'read(): Billing can act, cannot decide');
ok(Access::canOpen($adm, 'billing'), 'Admin can open the Billing page (approval UI)');
$row = current(array_filter($rb['billings'], fn($x) => (int)$x['id'] === $b3));
ok(isset($row['dr_docs'], $row['pending_adjustments']) && (int)$row['dr_docs'] === 1, 'billing list shows DR documents and pending requests');

// ---------------------------------------------------------------------
section('K. Trip operations do not depend on billing approvals');
$src = (string)file_get_contents(dirname(__DIR__) . '/backend/Modules/Operations/Trips.php') . file_get_contents(dirname(__DIR__) . '/backend/Core/BillingDraft.php');
ok(!str_contains($src, 'billing_charges') && !str_contains($src, 'Pending Approval\' AND billing'), 'Trips and BillingDraft never read billing_charges (planning, dispatch, completion unaffected)');
$pend = (int)$act($bil, 'charge_add', ['billing_id' => $b1, 'description' => 'Pending during ops', 'amount' => '10'])['charge_id'];
$t9 = (int)test_create_planned_trip($ops, ['client_id' => $cid, 'truck_type' => 'Wing Van', 'pickup_point' => 'PX', 'delivery_point' => 'DX', 'scheduled_at' => date('Y-m-d\TH:i', time() + 3600), 'distance_km' => '20'])['trip_id'];
ok($t9 > 0 && in_array(one($p, 'SELECT status FROM trips WHERE id=?', [$t9])['status'], ['Pre-Assigned', 'Awaiting Resources'], true), 'trip planning works while billing requests are pending (normal planning status)');
BillingDraft::createFor($p, one($p, 'SELECT * FROM trips WHERE id=?', [$t9]));
core('TripPricing'); $q9 = TripPricing::quote($p, one($p, 'SELECT * FROM trips WHERE id=?', [$t9]), date('Y-m-d H:i:s')); $base9 = (string)($q9['total'] ?? $q9['fixed'] ?? '0.00');   // kenneth: fixed-route pricing
$b9 = one($p, 'SELECT total_amount, charges_total FROM billings WHERE trip_id=?', [$t9]);
ok((float)$b9['total_amount'] === (float)$base9 && (float)$b9['charges_total'] === 0.0, 'billing draft at dispatch unaffected (base only)', json_encode([$b9, $base9]));
ok($C($pend)['status'] === 'Pending Approval', 'pending request untouched by other trip operations');

// ---------------------------------------------------------------------
section('L. Two processes at the same moment (approval / request / finalize)');
// The parent runs a REAL Billing action inside an open transaction (transaction() reuses it), so it holds the
// billing row lock without committing. The child process runs the other action and must wait for the lock.
$child = __DIR__ . '/_billing_child.php';
file_put_contents($child, '<?php
declare(strict_types=1);
require dirname(__DIR__) . "/backend/Actions.php";
Actions::load("Billing");
$u = json_decode($argv[1], true);
try { $r = Billing::action($u, $argv[2], json_decode($argv[3], true)); echo json_encode(["ok" => true, "message" => $r["message"] ?? ""]); }
catch (BusinessRuleException $e) { echo json_encode(["ok" => false, "code" => $e->httpStatus, "message" => $e->getMessage()]); }
catch (Throwable $e) { echo json_encode(["ok" => false, "code" => 0, "message" => get_class($e) . ": " . $e->getMessage()]); }
');
$race = function (callable $parentAction, array $childUser, string $childAction, array $childData) use ($p, $child, $FRESH): array {
    $p->beginTransaction();
    try { $parentAction(); } catch (Throwable $e) { $p->rollBack(); throw $e; }
    $pipes = [];
    $proc = proc_open([PHP_BINARY, $child, json_encode($childUser), $childAction, json_encode($childData)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    usleep(1500000);
    $waiting = (int)$p->query("SELECT COUNT(*) FROM information_schema.processlist WHERE db='$FRESH' AND command IN ('Query','Execute') AND info LIKE '%FOR UPDATE%' AND id<>CONNECTION_ID()")->fetchColumn();
    $p->commit();
    $out = json_decode((string)stream_get_contents($pipes[1]), true) ?: ['ok' => false, 'code' => -1, 'message' => (string)stream_get_contents($pipes[2])];
    proc_close($proc);
    return [$waiting, $out];
};
$invoiceMatchesLines = fn(int $bid) => abs((float)$B($bid)['base_amount'] + (float)one($p, 'SELECT COALESCE(SUM(amount),0) AS s FROM billing_charges WHERE billing_id=? AND ' . Billing::COUNTED, [$bid])['s'] - (float)$B($bid)['total_amount']) < 0.001;

// L1. A charge request is being saved while Billing finalizes: finalize waits, then sees the pending request.
$l1 = $billing(); $act($bil, 'verify', ['billing_id' => $l1]);
[$w1, $o1] = $race(fn() => $act($bil2, 'charge_add', ['billing_id' => $l1, 'description' => 'Race charge', 'amount' => '777']), $bil, 'finalize', ['billing_id' => $l1, 'invoice_date' => date('Y-m-d')]);
ok($w1 >= 1, 'finalize waited for the request being saved', "waiting=$w1");
ok(!$o1['ok'] && ($o1['code'] ?? 0) === 409 && str_contains($o1['message'], 'waiting for Admin approval'), 'finalize then refused: the pending amount cannot reach an invoice', json_encode($o1));
ok($B($l1)['status'] === 'For Billing' && $B($l1)['invoice_number'] === null && $total($l1) === '1000.00', 'no invoice, total without the pending amount');

// L2. The Admin is approving while Billing finalizes: finalize waits, then invoices the approved total.
$l2 = $billing(); $act($bil, 'verify', ['billing_id' => $l2]);
$rq = (int)$act($bil, 'charge_add', ['billing_id' => $l2, 'description' => 'Approved in race', 'amount' => '300'])['charge_id'];
[$w2, $o2] = $race(fn() => $act($adm, 'charge_decide', ['charge_id' => $rq, 'decision' => 'approve']), $bil, 'finalize', ['billing_id' => $l2, 'invoice_date' => date('Y-m-d')]);
ok($w2 >= 1, 'finalize waited for the approval', "waiting=$w2");
ok($o2['ok'] && $B($l2)['status'] === 'Invoiced' && $B($l2)['total_amount'] === '1300.00' && $invoiceMatchesLines($l2), 'finalize then invoiced exactly base + approved lines (1300)', json_encode([$o2, $B($l2)['total_amount']]));

// L3. The Admin is rejecting while Billing finalizes: the rejected amount never reaches the invoice.
$l3 = $billing(); $act($bil, 'verify', ['billing_id' => $l3]);
$rq3 = (int)$act($bil, 'charge_add', ['billing_id' => $l3, 'description' => 'Rejected in race', 'amount' => '450'])['charge_id'];
[$w3, $o3] = $race(fn() => $act($adm, 'charge_decide', ['charge_id' => $rq3, 'decision' => 'reject', 'reason' => 'No basis']), $bil, 'finalize', ['billing_id' => $l3, 'invoice_date' => date('Y-m-d')]);
ok($w3 >= 1 && $o3['ok'] && $B($l3)['total_amount'] === '1000.00' && $invoiceMatchesLines($l3), 'finalize after a concurrent rejection: invoice 1000, rejected 450 not included', json_encode($o3));

// L4. Billing finalizes while another request arrives: the request waits, then is refused (invoice final).
$l4 = $billing(); $act($bil, 'verify', ['billing_id' => $l4]);
[$w4, $o4] = $race(fn() => $act($bil, 'finalize', ['billing_id' => $l4, 'invoice_date' => date('Y-m-d')]), $bil2, 'charge_add', ['billing_id' => $l4, 'description' => 'Too late', 'amount' => '50']);
ok($w4 >= 1 && !$o4['ok'] && ($o4['code'] ?? 0) === 409, 'a request arriving during finalize waited and was refused', json_encode($o4));
ok($B($l4)['total_amount'] === '1000.00' && !one($p, 'SELECT id FROM billing_charges WHERE billing_id=?', [$l4]), 'finalized invoice unchanged, no line added');

// L5. Two Admins approve the same request at once: counted once.
$l5 = $billing();
$rq5 = (int)$act($bil, 'charge_add', ['billing_id' => $l5, 'description' => 'Double approve', 'amount' => '200'])['charge_id'];
[$w5, $o5] = $race(fn() => $act($adm, 'charge_decide', ['charge_id' => $rq5, 'decision' => 'approve']), $adm2, 'charge_decide', ['charge_id' => $rq5, 'decision' => 'approve']);
ok($w5 >= 1 && !$o5['ok'] && ($o5['code'] ?? 0) === 409 && str_contains($o5['message'], 'already decided'), 'second Admin waited and got "already decided"', json_encode($o5));
ok($total($l5) === '1200.00' && (int)$C($rq5)['decided_by'] === $adm['id'], 'approved amount counted once (1200)');

// L6. A change is approved while a removal of the same line is requested: the removal is refused (line no longer approved).
$l6 = $billing();
$base6 = (int)$act($bil, 'charge_add', ['billing_id' => $l6, 'description' => 'Line', 'amount' => '100'])['charge_id'];
$act($adm, 'charge_decide', ['charge_id' => $base6, 'decision' => 'approve']);
$chg6 = (int)$act($bil, 'charge_change', ['charge_id' => $base6, 'description' => 'Line', 'amount' => '150', 'reason' => 'x'])['charge_id'];
[$w6, $o6] = $race(fn() => $act($adm, 'charge_decide', ['charge_id' => $chg6, 'decision' => 'approve']), $bil2, 'charge_remove', ['charge_id' => $base6, 'reason' => 'race']);
ok($w6 >= 1 && !$o6['ok'] && ($o6['code'] ?? 0) === 409, 'removal request of a line being superseded waited and was refused', json_encode($o6));
ok($total($l6) === '1150.00' && $C($base6)['status'] === 'Superseded' && $C($chg6)['status'] === 'Approved', 'one consistent result: changed line 150, total 1150');
@unlink($child);

echo "\n" . str_repeat('=', 60) . "\nPASSED: $PASS   FAILED: $FAIL\n";
if ($FAILED) { echo "Failed:\n - " . implode("\n - ", $FAILED) . "\n"; }
exit($FAIL ? 1 : 0);
