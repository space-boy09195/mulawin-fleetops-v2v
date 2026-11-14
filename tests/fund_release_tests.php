<?php
/*
 * =====================================================================
 *  tests/fund_release_tests.php  —  Cash-only trip fund release (#36) and Trip Fund tabs (#37)
 * =====================================================================
 *  Do NOT run this file directly: run tests/run_fund_release_tests.php (temporary copy, own .env,
 *  disposable *_test database only).
 *
 *  Groups:
 *    A. Cash release: voucher, expense, audit, server date
 *    B. Non-Cash payment and payment details (direct API)
 *    C. Backdated / future / changed release date (direct API)
 *    D. Double-click / repeated release
 *    E. Two Finance users at the same time (two PHP processes)
 *    F. Release window (#40) and closed trips
 *    G. Roles
 *    H. Nothing written by failed attempts
 *    I. Shared payment methods unchanged (Billing / Finance / Purchasing)
 *    J. Trip Fund tabs (#37) and screen data
 *    K. Regression: liquidation, dispatch after release
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/backend/Actions.php';
require_once __DIR__ . '/_launcher.php';
core('Access');
core('Settings');
core('Money');

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
$mk = function (string $username, string $role) use ($p, &$users) {
    $shift = roleIsDispatcher($role) ? 'Day' : null;
    $id = insert($p, 'users', ['username' => $username, 'email' => "$username@example.test", 'password_hash' => 'x', 'full_name' => strtoupper($username) . ' USER', 'role' => $role, 'shift' => $shift]);
    $users[$username] = ['id' => $id, 'username' => $username, 'email' => "$username@example.test", 'full_name' => strtoupper($username) . ' USER', 'role' => $role, 'shift' => $shift];
};
foreach (array_keys(ROLE_LABELS) as $role) $mk($role, $role);
$mk('finance2', 'finance');
$U = fn(string $r) => $users[$r];
$ops = $U('operations_head'); $wvd = $U('container_wingvan_dispatcher'); $fin = $U('finance'); $fin2 = $U('finance2');
Actions::load('Trips'); Actions::load('Funds');
$cid = insert($p, 'clients', ['company_name' => 'FUND CLIENT', 'status' => 'Active', 'created_by' => $ops['id']]);
insert($p, 'client_rates', ['client_id' => $cid, 'truck_type' => 'Wing Van', 'rate_per_km' => '50.00', 'minimum_charge' => '0.00', 'updated_by' => $U('billing')['id']]);
$routeId = insert($p, 'client_routes', ['route_ref'=>'FUNDS-TEST-WV', 'client_id'=>$cid, 'truck_type'=>'Wing Van',
    'origin_label'=>'FUND PICKUP', 'destination_label'=>'FUND DELIVERY', 'origin_key'=>'fund pickup', 'destination_key'=>'fund delivery',
    'distance_km'=>'25', 'status'=>'Approved', 'requested_by'=>$ops['id']]);
insert($p, 'client_route_rates', ['route_id'=>$routeId, 'version_no'=>1, 'rate_type'=>'Flat', 'rate_amount'=>'1250',
    'status'=>'Approved', 'entered_by'=>$U('billing')['id'], 'effective_from'=>date('Y-m-d H:i:s')]);
for ($i = 1; $i <= 30; $i++) {
    insert($p, 'vehicles', ['plate_number' => "WV-$i", 'truck_type' => 'Wing Van']);
    insert($p, 'personnel', ['full_name' => "DRIVER, NO $i", 'person_type' => 'Driver']);
    insert($p, 'personnel', ['full_name' => "HELPER, NO $i", 'person_type' => 'Helper']);
}
$T = fn(int $id) => one($p, 'SELECT * FROM trips WHERE id=?', [$id]);
$F = fn(int $id) => one($p, 'SELECT * FROM fund_requests WHERE id=?', [$id]);
$n = 0;
/** Pre-Assigned trip with an approved (Ready for Release) fund request; schedule set directly. */
$approvedFund = function (int $minutesAhead = 60) use ($ops, $wvd, $cid, $routeId, $p, &$n): array {
    $n++;
    $r = test_create_planned_trip($ops, ['client_id' => $cid, 'truck_type' => 'Wing Van', 'pickup_point' => "PICKUP $n", 'delivery_point' => "DELIVERY $n",
        'scheduled_at' => date('Y-m-d\TH:i', time() + 3 * 86400), 'distance_km' => '25.00', 'dispatcher_assignment'=>'container_wingvan_dispatcher:Day',
        'responsible_dispatcher_id'=>$wvd['id'], 'client_route_id'=>$routeId]);
    // This isolated Funds fixture starts at the post-assignment state.
    // Dispatcher resource assignment is exercised by the turnover suite.
    run($p, "UPDATE trips SET status='Pre-Assigned', scheduled_at=? WHERE id=?", [date('Y-m-d H:i:s', time() + $minutesAhead * 60), $r['trip_id']]);
    $f = Funds::action($wvd, 'submit', ['trip_id' => $r['trip_id'], 'categories' => ['Driver Meal Allowance', 'Helper Meal Allowance']]);
    Funds::action($ops, 'decide', ['fund_id' => $f['fund_id'], 'decision' => 'approve']);
    return [(int)$r['trip_id'], (int)$f['fund_id']];
};
$counts = fn() => [
    'disbursements' => (int)$p->query('SELECT COUNT(*) FROM disbursements')->fetchColumn(),
    'expenses' => (int)$p->query('SELECT COUNT(*) FROM expenses')->fetchColumn(),
    'released_audit' => (int)$p->query("SELECT COUNT(*) FROM audit_events WHERE action='fund released'")->fetchColumn(),
];
$today = date('Y-m-d');

// ---------------------------------------------------------------------
section('A. Cash release: voucher, expense, audit, server date');
[$tid, $fid] = $approvedFund();
$c0 = $counts();
$r = Funds::action($fin, 'release', ['fund_id' => $fid]);
$f = $F($fid);
$dis = one($p, 'SELECT * FROM disbursements WHERE id=?', [$f['disbursement_id']]);
$exp = one($p, "SELECT * FROM expenses WHERE source='Fund Release' AND source_id=?", [$fid]);
ok($f['status'] === 'Released' && (int)$f['released_by'] === $fin['id'] && substr((string)$f['released_at'], 0, 10) === $today, 'released by Finance, release time = now (server)');
ok($dis && $dis['payment_method'] === 'Cash' && $dis['released_on'] === $today && $dis['cheque_number'] === null && $dis['bank_name'] === null && $dis['reference_number'] === null && $dis['amount'] === $f['amount'],
   'disbursement: Cash, dated today, no cheque/bank/reference, same amount', json_encode($dis));
ok(preg_match('/^PV-\d{4}-\d{4,}$/', (string)$dis['voucher_number']) === 1 && str_contains($r['message'], $dis['voucher_number']), 'payment voucher generated and shown', (string)$dis['voucher_number']);
ok($exp && $exp['category'] === 'Trip Allowance' && $exp['amount'] === $f['amount'] && $exp['incurred_on'] === $today && (int)$exp['trip_id'] === $tid, 'expense recorded: Trip Allowance, same amount, dated today');
$aud = one($p, "SELECT * FROM audit_events WHERE action='fund released' ORDER BY id DESC LIMIT 1");
ok($aud && str_contains((string)$aud['details'], 'via Cash') && (int)$aud['actor_id'] === $fin['id'], 'audit trail: fund released via Cash by Finance', json_encode($aud ? [$aud['details'], $aud['actor_id']] : null));
ok($counts() == ['disbursements' => $c0['disbursements'] + 1, 'expenses' => $c0['expenses'] + 1, 'released_audit' => $c0['released_audit'] + 1], 'exactly one voucher, one expense, one audit row');
[, $fid] = $approvedFund();
Funds::action($fin, 'release', ['fund_id' => $fid, 'payment_method' => 'Cash', 'released_on' => $today]);
ok($F($fid)['status'] === 'Released', 'explicit Cash with today\'s date (what an old form would send) accepted');
[, $fid] = $approvedFund();
Funds::action($fin, 'release', ['fund_id' => $fid, 'payment_method' => '', 'released_on' => '', 'cheque_number' => '', 'bank_name' => '  ', 'reference_number' => '']);
ok($F($fid)['status'] === 'Released', 'blank optional fields accepted (treated as not sent)');

// ---------------------------------------------------------------------
section('B. Non-Cash payment and payment details (direct API)');
[, $fid] = $approvedFund();
foreach (['Cheque' => ['cheque_number' => '000123', 'bank_name' => 'BDO'], 'Bank Transfer' => ['reference_number' => 'BT-1'], 'E-wallet' => ['reference_number' => 'GC-1'], 'cash' => [], 'CASH' => [], 'Card' => []] as $m => $extra)
    fails(fn() => Funds::action($fin, 'release', ['fund_id' => $fid, 'payment_method' => $m, 'released_on' => $today] + $extra), "payment method '$m' refused", 400, 'cash only');
fails(fn() => Funds::action($fin, 'release', ['fund_id' => $fid, 'payment_method' => ['Cash']]), 'payment method sent as a list refused', 400);
foreach (['cheque_number' => '9', 'bank_name' => 'BPI', 'reference_number' => 'OR-1'] as $k => $v)
    fails(fn() => Funds::action($fin, 'release', ['fund_id' => $fid, $k => $v]), "Cash with $k filled in refused", 400, 'does not apply');
ok($F($fid)['status'] === 'Ready for Release', 'fund still Ready for Release after the refused attempts');

// ---------------------------------------------------------------------
section('C. Backdated / future / changed release date (direct API)');
foreach (['yesterday' => date('Y-m-d', strtotime('-1 day')), 'last month' => date('Y-m-d', strtotime('-30 days')), 'tomorrow' => date('Y-m-d', strtotime('+1 day')),
          'next year' => date('Y-m-d', strtotime('+1 year')), 'datetime today' => $today . ' 08:00:00', 'not a date' => 'abc'] as $label => $date)
    fails(fn() => Funds::action($fin, 'release', ['fund_id' => $fid, 'payment_method' => 'Cash', 'released_on' => $date]), "release date '$label' refused", 400, 'recorded automatically');
fails(fn() => Funds::action($fin, 'release', ['fund_id' => $fid, 'released_on' => [$today]]), 'release date sent as a list refused', 400);
ok($F($fid)['status'] === 'Ready for Release', 'fund still Ready for Release');
Funds::action($fin, 'release', ['fund_id' => $fid, 'released_at' => '2020-01-01 00:00:00', 'released_by' => $fin2['id'], 'status' => 'Liquidated']);
$f = $F($fid);
ok($f['status'] === 'Released' && substr((string)$f['released_at'], 0, 10) === $today && (int)$f['released_by'] === $fin['id'], 'unknown fields (released_at, released_by, status) ignored: server values recorded');

// ---------------------------------------------------------------------
section('D. Double-click / repeated release');
[, $fid] = $approvedFund();
$c0 = $counts();
Funds::action($fin, 'release', ['fund_id' => $fid]);
fails(fn() => Funds::action($fin, 'release', ['fund_id' => $fid]), 'second click refused', 409, 'Only approved requests');
fails(fn() => Funds::action($fin, 'release', ['fund_id' => $fid]), 'third click refused', 409);
ok($counts() == ['disbursements' => $c0['disbursements'] + 1, 'expenses' => $c0['expenses'] + 1, 'released_audit' => $c0['released_audit'] + 1], 'only one voucher, one expense, one audit row after repeated clicks');
ok((int)one($p, "SELECT COUNT(*) AS n FROM disbursements WHERE source_type='fund_request' AND source_id=?", [$fid])['n'] === 1, 'one voucher for this fund');

// ---------------------------------------------------------------------
section('E. Two Finance users at the same time (two PHP processes)');
[, $fid] = $approvedFund();
$c0 = $counts();
$child = __DIR__ . '/_release_child.php';
file_put_contents($child, '<?php
declare(strict_types=1);
require dirname(__DIR__) . "/backend/Actions.php";
Actions::load("Funds");
$u = json_decode($argv[1], true);
try { $r = Funds::action($u, "release", ["fund_id" => (int)$argv[2]]); echo json_encode(["ok" => true, "message" => $r["message"]]); }
catch (BusinessRuleException $e) { echo json_encode(["ok" => false, "code" => $e->httpStatus, "message" => $e->getMessage()]); }
catch (Throwable $e) { echo json_encode(["ok" => false, "code" => 0, "message" => get_class($e) . ": " . $e->getMessage()]); }
');
// Hold the fund row so both releases start and wait on the same lock, then let them go together.
$hold = test_conn($FRESH);
$hold->beginTransaction();
$hold->query('SELECT id FROM fund_requests WHERE id=' . $fid . ' FOR UPDATE')->fetchAll();
$procs = [];
foreach ([$fin, $fin2] as $who) {
    $pipes = [];
    $procs[] = [proc_open([PHP_BINARY, $child, json_encode($who), (string)$fid], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes];
}
usleep(1500000);
$waiting = (int)$p->query("SELECT COUNT(*) FROM information_schema.processlist WHERE db='$FRESH' AND command IN ('Query','Execute') AND info LIKE '%FROM fund_requests f JOIN trips%' AND id<>CONNECTION_ID()")->fetchColumn();
$hold->commit();
$out = [];
foreach ($procs as [$proc, $pipes]) { $out[] = json_decode((string)stream_get_contents($pipes[1]), true) ?: ['ok' => false, 'message' => stream_get_contents($pipes[2])]; proc_close($proc); }
@unlink($child);
$wins = array_values(array_filter($out, fn($o) => $o['ok']));
$loses = array_values(array_filter($out, fn($o) => !$o['ok']));
ok($waiting === 2, 'both releases were waiting on the same fund at the same time', "waiting=$waiting");
ok(count($wins) === 1 && count($loses) === 1 && ($loses[0]['code'] ?? 0) === 409 && str_contains($loses[0]['message'], 'Released'), 'exactly one release succeeds; the other gets "already Released" (409)', json_encode($out));
ok($counts() == ['disbursements' => $c0['disbursements'] + 1, 'expenses' => $c0['expenses'] + 1, 'released_audit' => $c0['released_audit'] + 1], 'one voucher, one expense, one audit row');

// ---------------------------------------------------------------------
section('F. Release window (#40) and closed trips');
[$tid, $fid] = $approvedFund(180);
$c0 = $counts();
fails(fn() => Funds::action($fin, 'release', ['fund_id' => $fid]), 'release 3 hours before dispatch refused (2-hour window)', 409, 'Too early');
$setting = function (string $key, $value) use ($p, $U) {
    if ($value === null) run($p, 'DELETE FROM app_settings WHERE setting_key=?', [$key]);
    else run($p, 'INSERT INTO app_settings (setting_key, setting_value, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)', [$key, (string)$value, $U('admin')['id']]);
    Settings::clearCache();
};
$setting('fund_release_lead_hours', 4);
Funds::action($fin, 'release', ['fund_id' => $fid]);
ok($F($fid)['status'] === 'Released', 'Admin window 4 hours: same release now allowed (configurable window unchanged)');
$setting('fund_release_lead_hours', null);
foreach (['Cancelled', 'Rejected', 'Completed'] as $st) {
    [$tid, $fid] = $approvedFund();
    run($p, 'UPDATE trips SET status=? WHERE id=?', [$st, $tid]);
    fails(fn() => Funds::action($fin, 'release', ['fund_id' => $fid]), "release for a $st trip refused", 409, $st);
}
[$tid, $fid] = $approvedFund();
Trips::action($ops, 'cancel', ['trip_id' => $tid, 'reason' => 'Client cancelled']);
fails(fn() => Funds::action($fin, 'release', ['fund_id' => $fid]), 'trip cancelled through the system: release refused', 409);
[, $fid] = $approvedFund();
run($p, "UPDATE fund_requests SET status='Pending Approval' WHERE id=?", [$fid]);
fails(fn() => Funds::action($fin, 'release', ['fund_id' => $fid]), 'not yet approved (Pending Approval) refused', 409, 'Only approved');
run($p, "UPDATE fund_requests SET status='Rejected' WHERE id=?", [$fid]);
fails(fn() => Funds::action($fin, 'release', ['fund_id' => $fid]), 'rejected fund request refused', 409, 'Only approved');
fails(fn() => Funds::action($fin, 'release', ['fund_id' => 999999]), 'unknown fund refused', 404);
ok($counts() == ['disbursements' => $c0['disbursements'] + 1, 'expenses' => $c0['expenses'] + 1, 'released_audit' => $c0['released_audit'] + 1], 'only the allowed release wrote a voucher, an expense and an audit row');

// ---------------------------------------------------------------------
section('G. Roles');
[, $fid] = $approvedFund();
foreach (['operations_head', 'container_wingvan_dispatcher', 'car_carrier_dispatcher', 'billing', 'purchasing', 'audit', 'admin'] as $role)
    fails(fn() => Funds::action($U($role), 'release', ['fund_id' => $fid]), "$role cannot release", 403);
Funds::action($fin2, 'release', ['fund_id' => $fid]);
ok($F($fid)['status'] === 'Released' && (int)$F($fid)['released_by'] === $fin2['id'], 'any Finance user can release');

// ---------------------------------------------------------------------
section('H. Nothing written by failed attempts');
[, $fid] = $approvedFund();
$c0 = $counts();
$auditAll = (int)$p->query('SELECT COUNT(*) FROM audit_events')->fetchColumn();
$notes = (int)$p->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
foreach ([['payment_method' => 'Cheque', 'cheque_number' => '1', 'bank_name' => 'B'], ['released_on' => date('Y-m-d', strtotime('-2 days'))], ['reference_number' => 'X']] as $bad) {
    try { Funds::action($fin, 'release', ['fund_id' => $fid] + $bad); } catch (BusinessRuleException $e) {}
}
run($p, "UPDATE trips SET scheduled_at=DATE_ADD(NOW(), INTERVAL 5 HOUR) WHERE id=(SELECT trip_id FROM fund_requests WHERE id=?)", [$fid]);
try { Funds::action($fin, 'release', ['fund_id' => $fid]); } catch (BusinessRuleException $e) {}
ok($counts() == $c0 && (int)$p->query('SELECT COUNT(*) FROM audit_events')->fetchColumn() === $auditAll && (int)$p->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === $notes,
   'refused releases left no voucher, expense, audit row or notification');
ok($F($fid)['status'] === 'Ready for Release' && $F($fid)['disbursement_id'] === null && $F($fid)['released_at'] === null, 'fund untouched by the refused releases');

// ---------------------------------------------------------------------
section('I. Shared payment methods unchanged (Billing / Finance / Purchasing)');
ok(Money::METHODS === ['Cash', 'Cheque', 'Bank Transfer', 'E-wallet'], 'shared payment methods list unchanged');
$pay = Money::paymentFields(['payment_method' => 'Cheque', 'cheque_number' => '55', 'bank_name' => 'BDO', 'released_on' => date('Y-m-d', strtotime('-3 days'))]);
ok($pay['payment_method'] === 'Cheque' && $pay['released_on'] === date('Y-m-d', strtotime('-3 days')), 'other Finance payments still accept Cheque and an earlier date');
ok(Money::paymentFields(['payment_method' => 'Bank Transfer', 'reference_number' => 'R', 'payment_date' => $today], 'payment_date')['payment_method'] === 'Bank Transfer', 'Billing payment fields still accept Bank Transfer');
$src = (string)file_get_contents(dirname(__DIR__) . '/backend/Modules/Maintenance/Purchasing.php') . file_get_contents(dirname(__DIR__) . '/backend/Modules/Accounting/Finance.php') . file_get_contents(dirname(__DIR__) . '/backend/Modules/Accounting/Billing.php');
ok(!str_contains($src, 'cashReleaseFields'), 'cash-only rule used only by the trip fund release');

// ---------------------------------------------------------------------
section('J. Trip Fund tabs (#37) and screen data');
$d = Funds::read($fin, 'funds', []);
ok($d['methods'] === ['Cash'], 'Trip Funds page offers Cash only');
$js = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/modules/accounting/funds.js');
ok(str_contains($js, 'CHECKLIST_36_CASH_ONLY_TRIP_FUND_RELEASE_V1') && !str_contains($js, 'UI.payFields'), 'release form has no payment method / date fields');
ok(str_contains($js, "label:'To Release'") && !str_contains($js, "label:'Ready for release'"), 'To Release tab exists for approved requests');
ok(str_contains($js, "label:'For Liquidation'") && str_contains($js, "UI.btn('liquidate', 'Liquidate'"), 'For Liquidation tab keeps its action');
ok(!preg_match('/All requests\\s*\\(/i', $js) && !preg_match('/To Liquidate/i', $js), 'no retired fund tab labels');
preg_match_all("/\\{value:'(pending|release|liquidate|revision|history)',label:/", $js, $m);
ok($m[1] === ['pending', 'release', 'liquidate', 'revision', 'history'], 'fund tabs retain Pending Approval, To Release, For Liquidation, Needs Revision, History order', json_encode($m[1]));

// ---------------------------------------------------------------------
section('K. Regression: liquidation, dispatch after release');
[$tid, $fid] = $approvedFund(10);
Funds::action($fin, 'release', ['fund_id' => $fid]);
// A Released fund does not bypass the Dispatcher resource and checklist gates.
fails(fn() => Trips::action($wvd, 'dispatch', ['trip_id' => $tid]),
    'Released fund cannot dispatch a trip without manual Truck and crew assignment', 409, 'Confirm Dispatch is not enabled');
ok($T($tid)['status'] === 'Pre-Assigned' && $T($tid)['vehicle_id'] === null,
    'refused dispatch keeps the fund fixture trip unchanged');
echo "  NOTE  full assigned-crew dispatch regression is covered by the Dispatcher Turnover suite; this Funds fixture does not assign resources.\n";
fails(fn() => Funds::action($fin, 'liquidate', ['fund_id' => $fid, 'liquidated_amount' => '100.00']), 'liquidation still waits for the trip to finish', 409, 'after the trip');
run($p, "UPDATE trips SET status='Completed' WHERE id=?", [$tid]);
$amount = $F($fid)['amount'];
Funds::action($fin, 'liquidate', ['fund_id' => $fid, 'liquidated_amount' => number_format((float)$amount - 100, 2, '.', '')]);
$f = $F($fid);
ok($f['liquidation_status'] === 'Liquidated' && $f['returned_amount'] === '100.00', 'liquidation unchanged: spent and returned recorded');
ok((float)one($p, 'SELECT SUM(amount) AS s FROM expenses WHERE trip_id=?', [$tid])['s'] === (float)$amount - 100, 'trip allowance expense = released minus returned');

echo "\n----------------------------------------\n";
echo "RESULT: $PASS passed, $FAIL failed\n";
if ($FAILED) { echo "Failed:\n"; foreach ($FAILED as $f) echo "  - $f\n"; }
test_drop($FRESH);
exit($FAIL === 0 ? 0 : 1);
