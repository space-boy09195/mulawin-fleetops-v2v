<?php
/*
 * =====================================================================
 *  tests/trip_batch_tests.php  —  Batch trip planning
 * =====================================================================
 *  Do NOT run this file directly: run tests/run_trip_batch_tests.php (temporary copy, own .env,
 *  disposable *_test database only). Includes a two-process duplicate-submission test.
 *
 *    A. Operations Head batch: separate trips, refs, auto-assignment, results, audit
 *    B. Not enough trucks / crew: Awaiting Resources, earliest schedule first
 *    C. All-or-nothing validation with per-row reasons
 *    D. Duplicate submissions (resend, keys, two processes at once); identical-looking trips allowed
 *    D2. Partial failure during creation and retry of the failed row only
 *    E. Limits and malformed input (direct API)
 *    F. Dispatcher batch (Scenario B requests), team rules, roles
 *    G. Approved routes in a batch
 *    H. Existing trips untouched; a batch trip continues individually (checklist, fund, dispatch, billing)
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
function uuid(): string { $h = bin2hex(random_bytes(16)); return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12); }

section('Setup: disposable fresh database');
test_recreate($FRESH);
$p = db();
ok($p->query('SELECT DATABASE()')->fetchColumn() === $FRESH, 'db() is the disposable test database');
test_import($p, test_sql('database/schema.sql'));
test_import($p, test_sql('database/extension.sql'));
$users = [];
foreach (array_keys(ROLE_LABELS) as $role) {
    $shift = roleIsDispatcher($role) ? 'Day' : null;
    $id = insert($p, 'users', ['username' => $role, 'email' => "$role@example.test", 'password_hash' => 'x', 'full_name' => strtoupper($role) . ' USER', 'role' => $role, 'shift' => $shift]);
    $users[$role] = ['id' => $id, 'username' => $role, 'email' => "$role@example.test", 'full_name' => strtoupper($role) . ' USER', 'role' => $role, 'shift' => $shift];
}
$U = fn(string $r) => $users[$r];
$ops = $U('operations_head'); $wvd = $U('container_wingvan_dispatcher'); $ccd = $U('car_carrier_dispatcher');
Actions::load('Trips');
$cid = insert($p, 'clients', ['company_name' => 'BATCH CLIENT A', 'status' => 'Active', 'created_by' => $ops['id']]);
$cid2 = insert($p, 'clients', ['company_name' => 'BATCH CLIENT B', 'status' => 'Active', 'created_by' => $ops['id']]);
$cidOff = insert($p, 'clients', ['company_name' => 'INACTIVE CLIENT', 'status' => 'Inactive', 'created_by' => $ops['id']]);
foreach ([[$cid, 'Wing Van'], [$cid, 'Car Carrier'], [$cid2, 'Wing Van'], [$cidOff, 'Wing Van']] as [$c, $type])
    insert($p, 'client_rates', ['client_id' => $c, 'truck_type' => $type, 'rate_per_km' => '50.00', 'minimum_charge' => '0.00', 'updated_by' => $U('billing')['id']]);
$plate = 0;
$truck = function (string $type, int $n) use ($p, &$plate) { $ids = []; for ($i = 0; $i < $n; $i++) { $plate++; $ids[] = insert($p, 'vehicles', ['plate_number' => 'BT-' . $plate, 'truck_type' => $type]); } return $ids; };
$crew = fn(int $n) => array_map(fn($i) => [insert($p, 'personnel', ['full_name' => "DRIVER, B$i", 'person_type' => 'Driver']), insert($p, 'personnel', ['full_name' => "HELPER, B$i", 'person_type' => 'Helper'])], range(1, $n));
$truck('Wing Van', 2); $crew(40);
$at = fn(int $minutes) => date('Y-m-d\TH:i', time() + $minutes * 60);
// Modern Operations Head creation requires an explicit Dispatcher account and
// an approved client route. These are fixture-only records in a disposable test DB.
$testRoutes = [];
foreach ([[$cid,'Wing Van'],[$cid,'Car Carrier'],[$cid2,'Wing Van'],[$cidOff,'Wing Van']] as [$clientId,$truckType]) {
    $routeId = insert($p,'client_routes',[
        'route_ref'=>'BATCH-'.(string)$clientId.'-'.str_replace(' ','',$truckType),
        'client_id'=>$clientId,'truck_type'=>$truckType,
        'origin_label'=>'TANAUAN WAREHOUSE','destination_label'=>'MALVAR PLANT',
        'origin_key'=>'tanauan warehouse','destination_key'=>'malvar plant',
        'distance_km'=>'25.50','status'=>'Approved','requested_by'=>$ops['id']
    ]);
    insert($p,'client_route_rates',[
        'route_id'=>$routeId,'version_no'=>1,'rate_type'=>'Flat',
        'rate_amount'=>'1275','status'=>'Approved',
        'entered_by'=>$U('billing')['id'],'effective_from'=>date('Y-m-d H:i:s')
    ]);
    $testRoutes[$clientId][$truckType]=$routeId;
}
$row = function(array $o=[]) use($cid,$at,$testRoutes,$wvd,$ccd):array {
    $defaults=['request_key'=>uuid(),'client_id'=>(string)$cid,'truck_type'=>'Wing Van',
        'pickup_point'=>'TANAUAN WAREHOUSE','delivery_point'=>'MALVAR PLANT',
        'scheduled_at'=>$at(180),'distance_km'=>'25.50'];
    $data=$o+$defaults;
    $type=$data['truck_type'];$client=(int)$data['client_id'];
    $data+=['client_route_id'=>$testRoutes[$client][$type]??null,
        'dispatcher_assignment'=>$type==='Car Carrier'?'car_carrier_dispatcher:Day':'container_wingvan_dispatcher:Day',
        'responsible_dispatcher_id'=>$type==='Car Carrier'?$ccd['id']:$wvd['id']];
    return $data;
};
$T = fn(int $id) => one($p, 'SELECT * FROM trips WHERE id=?', [$id]);
$tripCount = fn() => (int)$p->query('SELECT COUNT(*) FROM trips')->fetchColumn();
$batch = fn(array $u, array $rows) => Trips::action($u, 'create_batch', ['rows' => $rows]);

// ---------------------------------------------------------------------
section('A. Operations Head batch');
$rows = [$row(['delivery_point' => 'DROP ONE', 'scheduled_at' => $at(120)]), $row(['delivery_point' => 'DROP TWO', 'scheduled_at' => $at(240), 'consignee' => 'ABC STORE'])];
$r = $batch($ops, $rows);
$res = $r['results'];
ok(count($res) === 2 && $res[0]['row'] === 1 && $res[1]['row'] === 2 && $res[0]['result'] === 'Created' && $res[1]['result'] === 'Created', 'one result per row, in the order entered');
ok($res[0]['trip_ref'] !== $res[1]['trip_ref'] && preg_match('/^TRP-\d{4}-\d{4}$/', (string)$res[0]['trip_ref']) === 1, 'each row is its own trip with its own reference', json_encode(array_column($res, 'trip_ref')));
$t1 = $T($res[0]['trip_id']); $t2 = $T($res[1]['trip_id']);
ok($t1['status'] === 'Awaiting Resources' && $t2['status'] === 'Awaiting Resources' && !$t1['vehicle_id'] && !$t2['vehicle_id'], 'both created without auto-assigning a truck (Dispatcher selects resources)');
ok($t1['delivery_point'] === 'DROP ONE' && $t2['consignee'] === 'ABC STORE' && $t1['distance_km'] === '25.50' && $t1['distance_source'] === 'Client Route' && $t1['intake_source'] === 'Operations Head', 'row details saved like a New trip');
ok((bool)one($p, "SELECT 1 AS x FROM trip_events WHERE trip_id=? AND event_type='trip created by Operations Head (Scenario A)'", [$t1['id']]), 'same history as a single New trip');
ok(str_contains($r['message'], '2 created, 0 already created, 0 not created'), 'summary message', $r['message']);
$aud = one($p, "SELECT details FROM audit_events WHERE action='trip batch planned' ORDER BY id DESC LIMIT 1");
ok($aud && str_contains($aud['details'], $res[0]['trip_ref']) && str_contains($aud['details'], '2 row(s)'), 'batch recorded in the Activity Log');
ok($t1['request_key'] === strtolower($rows[0]['request_key']), 'row form key stored with the trip');

// ---------------------------------------------------------------------
section('B. Not enough trucks: Awaiting Resources, earliest schedule first');
$truck('Wing Van', 1);   // one free truck now
$rows = [$row(['delivery_point' => 'LATE DROP', 'scheduled_at' => $at(600)]), $row(['delivery_point' => 'EARLY DROP', 'scheduled_at' => $at(90)])];
$r = $batch($ops, $rows);
[$late, $early] = $r['results'];
ok($early['trip_status'] === 'Awaiting Resources' && $late['trip_status'] === 'Awaiting Resources', 'both wait for manual Dispatcher assignment regardless of free trucks', json_encode($r['results']));
ok($late['result'] === 'Created' && $late['trip_ref'] && $T($late['trip_id'])['vehicle_id'] === null, 'the waiting trip is still created (no truck held)');
ok(str_contains($r['message'], '2 trip(s) are Awaiting Resources'), 'message says both trips are awaiting manual resources');
ok((bool)one($p, "SELECT 1 AS x FROM trip_events WHERE trip_id=? AND event_type LIKE '%awaiting resources%'", [$late['trip_id']]) || $T($late['trip_id'])['status'] === 'Awaiting Resources', 'waiting trip uses the existing Awaiting Resources status');

// ---------------------------------------------------------------------
section('C. All-or-nothing validation');
$n0 = $tripCount(); $a0 = (int)$p->query("SELECT COUNT(*) FROM audit_events WHERE action='trip batch planned'")->fetchColumn();
$bad = [$row(), $row(['client_route_id' => '999999']), $row(['scheduled_at' => date('Y-m-d\TH:i', time() - 7200)]), $row(['client_id' => (string)$cidOff]), $row(['truck_type' => 'Car Carrier', 'client_id' => (string)$cid2]),
        $row(['pickup_point' => '']), $row(['distance_km' => '12.345']), $row(['truck_type' => 'Rocket'])];
try { $batch($ops, $bad); ok(false, 'batch with wrong rows refused'); }
catch (BusinessRuleException $e) {
    $m = $e->getMessage();
    ok($e->httpStatus === 400 && str_starts_with($m, 'Nothing was created.'), 'batch with wrong rows refused: nothing created', $m);
    foreach ([2 => 'approved route', 3 => '', 4 => 'Active client', 5 => 'no billing rate for Car Carrier', 6 => 'Pickup', 7 => '', 8 => ''] as $rowNo => $part)
        ok(str_contains($m, "Row $rowNo:") && ($part === '' || stripos($m, $part) !== false), "row $rowNo reason listed" . ($part ? " ($part)" : ''));
    ok(!str_contains($m, 'Row 1:'), 'correct row 1 not listed');
}
ok($tripCount() === $n0 && (int)$p->query("SELECT COUNT(*) FROM audit_events WHERE action='trip batch planned'")->fetchColumn() === $a0, 'no trip and no batch log written');

// ---------------------------------------------------------------------
section('D. Duplicate submissions');
$truck('Wing Van', 3);
$rows = [$row(['delivery_point' => 'DUP A', 'scheduled_at' => $at(150)]), $row(['delivery_point' => 'DUP B', 'scheduled_at' => $at(160)])];
$first = $batch($ops, $rows);
$n1 = $tripCount();
$again = $batch($ops, $rows);
ok($tripCount() === $n1 && array_column($again['results'], 'result') === ['Already created', 'Already created'] && array_column($again['results'], 'trip_id') === array_column($first['results'], 'trip_id'),
   'same batch sent again: no new trip, same trips reported "Already created"');
$mixed = $batch($ops, [$rows[0], $row(['delivery_point' => 'DUP C', 'scheduled_at' => $at(170)])]);
ok(array_column($mixed['results'], 'result') === ['Already created', 'Created'] && $tripCount() === $n1 + 1, 'resend with one new row: only the new row creates a trip');
// Two trucks for the same client, route and time are legitimate: two rows with the same details = two trips.
$truck('Wing Van', 3);
$same = ['delivery_point' => 'SAME PLANT', 'pickup_point' => 'SAME YARD', 'scheduled_at' => $at(175), 'consignee' => 'SAME CO'];
$n3 = $tripCount();
$r = $batch($ops, [$row($same), $row($same)]);
ok(array_column($r['results'], 'result') === ['Created', 'Created'] && $tripCount() === $n3 + 2 && $r['results'][0]['trip_ref'] !== $r['results'][1]['trip_ref'],
   'two identical-looking rows (same client, truck type, route, time) -> two separate trips', json_encode($r['results']));
$a1 = $T($r['results'][0]['trip_id']); $a2 = $T($r['results'][1]['trip_id']);
ok(!$a1['vehicle_id'] && !$a2['vehicle_id'], 'identical-looking trips remain separate and await manual truck assignment');
$r2 = $batch($ops, [$row($same)]);
ok($r2['results'][0]['result'] === 'Created' && $tripCount() === $n3 + 3, 'a third truck for the same route and time later: another trip (new key)');
$k = uuid();
fails(fn() => $batch($ops, [$row(['request_key' => $k, 'delivery_point' => 'K1']), $row(['request_key' => $k, 'delivery_point' => 'K2'])]), 'same form key twice refused', 400, 'Same form key as row 1');
fails(fn() => $batch($wvd, [$rows[0]]), 'Dispatcher cannot submit Operations Head batch keys', 403);
fails(fn() => $batch($ops, [$row(['request_key' => 'not-a-key'])]), 'malformed key refused', 400, 'Invalid form key');
// Two processes send the same batch at the same moment.
$truck('Wing Van', 3);
$rows = [$row(['delivery_point' => 'RACE A', 'scheduled_at' => $at(200)]), $row(['delivery_point' => 'RACE B', 'scheduled_at' => $at(210)])];
$child = __DIR__ . '/_batch_child.php';
file_put_contents($child, '<?php
declare(strict_types=1);
require dirname(__DIR__) . "/backend/Actions.php";
Actions::load("Trips");
$u = json_decode($argv[1], true); $rows = json_decode($argv[2], true);
try { $r = Trips::action($u, "create_batch", ["rows" => $rows]); echo json_encode(["ok" => true, "results" => $r["results"]]); }
catch (BusinessRuleException $e) { echo json_encode(["ok" => false, "code" => $e->httpStatus, "message" => $e->getMessage()]); }
catch (Throwable $e) { echo json_encode(["ok" => false, "code" => 0, "message" => get_class($e) . ": " . $e->getMessage()]); }
');
$n2 = $tripCount();
$procs = [];
for ($i = 0; $i < 2; $i++) { $pipes = []; $procs[] = [proc_open([PHP_BINARY, $child, json_encode($ops), json_encode($rows)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes]; }
$outs = [];
foreach ($procs as [$proc, $pipes]) { $outs[] = json_decode((string)stream_get_contents($pipes[1]), true) ?: ['ok' => false, 'message' => stream_get_contents($pipes[2])]; proc_close($proc); }
@unlink($child);
$ids = [];
foreach ($outs as $o) if ($o['ok']) foreach ($o['results'] as $x) $ids[] = $x['trip_id'];
ok($outs[0]['ok'] && $outs[1]['ok'], 'both simultaneous sends answered without an error', json_encode($outs));
ok($tripCount() === $n2 + 2 && count(array_unique($ids)) === 2, 'only two trips exist (one per row) after two simultaneous sends', json_encode($ids));

// ---------------------------------------------------------------------
section('D2. Partial failure during creation, then retry of the failed row only');
$truck('Wing Van', 4);
// Simulate an unexpected database failure for one row (a trigger refuses that one insert).
$triggerReady = true;
try { $p->exec("CREATE TRIGGER test_fail_one BEFORE INSERT ON trips FOR EACH ROW BEGIN IF NEW.delivery_point='FAIL ME' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='simulated failure'; END IF; END"); }
catch (PDOException $ex) {
    if ((int)($ex->errorInfo[1]??0) !== 1419) throw $ex;
    $triggerReady = false;
    echo "  SKIP  trigger-only partial-rollback test (MySQL binary logging requires elevated TRIGGER privilege)\n";
}
if ($triggerReady) {
$rows = [$row(['delivery_point' => 'PART A', 'scheduled_at' => $at(220)]), $row(['delivery_point' => 'FAIL ME', 'scheduled_at' => $at(225)]), $row(['delivery_point' => 'PART C', 'scheduled_at' => $at(230)])];
$n4 = $tripCount();
$r = $batch($ops, $rows);
ok(array_column($r['results'], 'result') === ['Created', 'Not created', 'Created'] && $tripCount() === $n4 + 2, 'row 2 fails while saving: rows 1 and 3 created, row 2 "Not created"', json_encode(array_column($r['results'], 'result')));
ok(str_contains($r['results'][1]['message'], 'could not be saved (server error)') && !str_contains($r['results'][1]['message'], 'simulated') && $r['results'][1]['request_key'] === strtolower($rows[1]['request_key']),
   'failed row: plain message (no database detail), its key returned for the retry');
ok(str_contains($r['message'], '2 created, 0 already created, 1 not created'), 'summary counts the three outcomes', $r['message']);
$p->exec('DROP TRIGGER test_fail_one');
$retry = $batch($ops, [$rows[1] + ['row_no' => '2']]);
ok($retry['results'][0]['result'] === 'Created' && $retry['results'][0]['row'] === 2 && $tripCount() === $n4 + 3, 'retry of only the failed row (same key, same row number): created');
$all = $batch($ops, $rows);
ok(array_column($all['results'], 'result') === ['Already created', 'Already created', 'Already created'] && $tripCount() === $n4 + 3, 'sending all three again: nothing duplicated');
ok(count(array_unique(array_column($all['results'], 'trip_id'))) === 3, 'three different trips, one per row');
// kenneth: every Ops Head row needs an approved route (which also supplies the km), so the invalid row is one without a route.
fails(fn() => $batch($ops, [$row(['client_route_id' => '', 'row_no' => '5'])]), 'retry with a row problem names the screen row number', 400, 'Row 5:');
// kenneth: batch planning moved to the Operations Head workspace (ops-head.js); retry resends only pending drafts with their keys.
$js = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/modules/operations/ops-head.js');
ok(str_contains($js, "App.api('Trips.create_batch', { rows: pending.map(") && str_contains($js, 'request_key: x.key, row_no:') && str_contains($js, 'Retry with the same request keys.'), 'screen: Retry sends only not-created rows with their original keys');
ok(!str_contains($js, 'Nothing is created until every row is correct'), 'old all-or-nothing wording removed');

}
// ---------------------------------------------------------------------
section('E. Limits and malformed input');
fails(fn() => $batch($ops, []), 'empty batch refused', 400, 'at least one');
fails(fn() => Trips::action($ops, 'create_batch', []), 'missing rows refused', 400);
fails(fn() => Trips::action($ops, 'create_batch', ['rows' => 'abc']), 'rows as text refused', 400);
fails(fn() => Trips::action($ops, 'create_batch', ['rows' => ['a' => $row()]]), 'rows as an object refused', 400);
fails(fn() => $batch($ops, array_map(fn() => $row(), range(1, 21))), '21 rows refused (max 20)', 400, 'at most 20');
fails(fn() => $batch($ops, ['text']), 'a row that is not an entry refused', 400, 'Row 1: Invalid trip entry');

// ---------------------------------------------------------------------
section('F. Dispatcher batch is retired; only Operations Head creates trips');
fails(fn() => $batch($wvd, [$row()]), 'Dispatcher cannot create batch trip requests', 403);
fails(fn() => Trips::action($wvd, 'create', $row()), 'Dispatcher cannot directly create trips', 403);
foreach (['finance', 'billing', 'motorpool', 'purchasing', 'audit', 'admin'] as $role)
    fails(fn() => $batch($U($role), [$row()]), "$role cannot plan trips", 403);
ok($tripCount() > 0, 'retired Dispatcher request endpoint did not remove existing trips');

// ---------------------------------------------------------------------
section('G. Approved routes in a batch');
$route = insert($p, 'client_routes', ['route_ref' => 'RTE-B-0001', 'client_id' => $cid, 'truck_type' => 'Wing Van', 'origin_label' => 'TANAUAN', 'destination_label' => 'MALVAR',
    'origin_key' => 'tanauan', 'destination_key' => 'malvar', 'distance_km' => '9.50', 'distance_source' => 'Manual', 'status' => 'Approved', 'requested_by' => $ops['id']]);
insert($p, 'client_route_rates', ['route_id' => $route, 'version_no' => 1, 'rate_type' => 'Flat', 'rate_amount' => '5000', 'status' => 'Approved', 'entered_by' => $U('billing')['id'], 'effective_from' => date('Y-m-d H:i:s')]);
$truck('Wing Van', 2);
$r = $batch($ops, [$row(['client_route_id' => (string)$route, 'distance_km' => '', 'pickup_point' => 'TANAUAN', 'delivery_point' => 'MALVAR', 'scheduled_at' => $at(300)])]);
$t = $T($r['results'][0]['trip_id']);
ok($t['distance_source'] === 'Client Route' && $t['distance_km'] === '9.50' && (int)$t['client_route_id'] === $route, 'row with an approved route: route km used (same rules as New trip)');
fails(fn() => $batch($ops, [$row(['client_id' => (string)$cid2, 'client_route_id' => (string)$route])]), 'route of another client refused', 400, 'approved route cannot be used');
run($p, "UPDATE client_routes SET status='Inactive' WHERE id=?", [$route]);
fails(fn() => $batch($ops, [$row(['client_route_id' => (string)$route, 'distance_km' => ''])]), 'inactive route refused', 400, 'approved route cannot be used');

// ---------------------------------------------------------------------
section('H. Existing trips and account ownership remain untouched by new batches');
$before = many($p, 'SELECT id,status,vehicle_id,driver_id,helper_id,scheduled_at,distance_km,updated_at FROM trips ORDER BY id');
$beforeOwners = many($p, 'SELECT trip_id,initial_dispatcher_id,current_dispatcher_id,version FROM trip_responsibilities ORDER BY trip_id');
$r = $batch($ops, [$row(['delivery_point' => 'AFTER', 'scheduled_at' => $at(320)])]);
ok(($r['results'][0]['result']??null)==='Created', 'new batch creates its own trip');
$existingIds = array_map('intval',array_column($before,'id'));
$after = many($p, 'SELECT id,status,vehicle_id,driver_id,helper_id,scheduled_at,distance_km,updated_at FROM trips ORDER BY id');
ok(array_values(array_filter($after,fn($t)=>in_array((int)$t['id'],$existingIds,true)))==$before,
    'existing trip rows are not modified by new planning');
$ownerAfter = many($p, 'SELECT trip_id,initial_dispatcher_id,current_dispatcher_id,version FROM trip_responsibilities ORDER BY trip_id');
ok(array_values(array_filter($ownerAfter,fn($row)=>in_array((int)$row['trip_id'],$existingIds,true)))==$beforeOwners,
    'existing Dispatcher responsibility records are not overwritten');
$newId = (int)$r['results'][0]['trip_id'];
$newOwner = one($p, 'SELECT initial_dispatcher_id,current_dispatcher_id,assigned_by FROM trip_responsibilities WHERE trip_id=?',[$newId]);
ok($newOwner && (int)$newOwner['initial_dispatcher_id']===$wvd['id'] && (int)$newOwner['current_dispatcher_id']===$wvd['id'] && (int)$newOwner['assigned_by']===$ops['id'],
    'newly planned trip commits the explicitly selected Dispatcher and assigning Operations Head');
ok($T($newId)['status']==='Awaiting Resources' && $T($newId)['vehicle_id']===null,
    'batch leaves truck assignment to the responsible Dispatcher');

echo "\n----------------------------------------\n";
echo "RESULT: $PASS passed, $FAIL failed\n";
if ($FAILED) { echo "Failed:\n"; foreach ($FAILED as $f) echo "  - $f\n"; }
test_drop($FRESH);
exit($FAIL === 0 ? 0 : 1);
