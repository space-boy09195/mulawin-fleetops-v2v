<?php
/*
 * =====================================================================
 *  tests/motorpool_workspace_tests.php  —  Motorpool workspace, Phase 1 (runtime)
 * =====================================================================
 *  Do NOT run this file directly: run tests/run_motorpool_workspace_tests.php (temporary copy,
 *  own .env, disposable *_test database only).
 *
 *  REAL DISPATCH PATH: currentUser() caches the user per PHP process, so each API call runs in a
 *  short child process (tests/_mpws_dispatch_child.php, written inside the temporary copy only) that
 *  sets $_SESSION['uid'] and calls the real Actions::pageData() / Actions::run(): login lookup,
 *  first-login password gate, Access::canOpen, module dispatch. Not covered: the HTTP layer
 *  (POST method + CSRF in public/api.php; source-checked in mechanic_attendance_tests.php).
 *  Module-level requireRole checks are ALSO called directly (defence in depth).
 *
 *  Groups:
 *    A. Runtime access per role through the real dispatcher (+ direct module refusals)
 *    C. Parts request round trip: create -> partial release -> Not Available -> cancel; summary = list; audit; notification pages
 *    B. mp_parts_requests exposes no cost, price, stock, budget, supplier, PO or payment data (recursive key + value scan)
 *    D. PO notification target: po_create notifies the approver with page purchase_orders
 *    E. Mechanic Attendance read: board = masterlist counts, daily mark Admin only, date range filter and clamp
 *    F. Time In / Time Out refusals (roles, duplicate Time In, Time Out during an active repair); availability unchanged after a refusal
 *    G. Read-only: both page reads write nothing
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/backend/Actions.php';
require_once __DIR__ . '/_launcher.php';
core('Access');
core('Settings');

$FRESH = envValue('DB_NAME');
test_guard([$FRESH]);   // refuses to continue unless this is the temporary copy (APP_ENV=test) and the database ends with _test

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
/** Every string key of a nested array, at any depth. */
function allKeys(array $a): array {
    $out = [];
    foreach ($a as $k => $v) { if (is_string($k)) $out[] = $k; if (is_array($v)) array_push($out, ...allKeys($v)); }
    return $out;
}
/** Keys Motorpool must never receive on its Parts Requests page (financial, inventory level, supplier, purchase order, payment). */
const RESTRICTED_KEY = '/(cost|price|amount|budget|supplier|^po_|purchase|payment|expense|disburse|on_hand|stock|reorder|valuation)/i';

// ---------------------------------------------------------------------
section('Setup: disposable fresh database');
test_recreate($FRESH);
$p = db();
ok($p->query('SELECT DATABASE()')->fetchColumn() === $FRESH, 'db() is the disposable test database');
test_import($p, test_sql('database/schema.sql'));
test_import($p, test_sql('database/extension.sql'));
// Realistic staff names per role (fictional people; addresses use the reserved, non-routable .example domain; no mail is sent).
$staff = [
    'admin' => ['tvillanueva', 'Teresita Villanueva'], 'operations_head' => ['rmanalo', 'Ricardo Manalo'],
    'car_carrier_dispatcher' => ['jcastillo', 'Jomar Castillo'], 'container_wingvan_dispatcher' => ['rgarcia', 'Rowena Garcia'],
    'motorpool' => ['ebautista', 'Ernesto Bautista'], 'purchasing' => ['msantos', 'Maricel Santos'],
    'finance' => ['laquino', 'Lourdes Aquino'], 'billing' => ['jramos', 'Jocelyn Ramos'], 'audit' => ['amendoza', 'Arnel Mendoza'],
];
$users = [];
foreach (array_keys(ROLE_LABELS) as $role) {
    [$uname, $full] = $staff[$role] ?? [$role, ucwords(str_replace('_', ' ', $role)) . ' Staff'];
    $email = "$uname@mulawinfleet.example";
    $id = insert($p, 'users', ['username' => $uname, 'email' => $email, 'password_hash' => 'x', 'full_name' => $full, 'role' => $role]);
    $users[$role] = ['id' => $id, 'username' => $uname, 'email' => $email, 'full_name' => $full, 'role' => $role, 'shift' => null];
}
$U = fn(string $r) => $users[$r];
$mp = $U('motorpool'); $pur = $U('purchasing'); $ops = $U('operations_head'); $adm = $U('admin'); $aud = $U('audit');
foreach (['Maintenance', 'Mechanics', 'Purchasing'] as $m) Actions::load($m);

// Child process for the real dispatcher (written into the temporary copy, removed at the end).
$child = __DIR__ . '/_mpws_dispatch_child.php';
file_put_contents($child, '<?php
declare(strict_types=1);
ini_set("display_errors", "stderr");
require dirname(__DIR__) . "/backend/Actions.php";
require __DIR__ . "/_launcher.php";
test_guard([envValue("DB_NAME")]);
$_SESSION = ["uid" => (int)$argv[1], "login_at" => time(), "last_seen" => time()];
$payload = json_decode($argv[4], true) ?: [];
try {
    $r = $argv[2] === "page" ? Actions::pageData($argv[3], $payload) : Actions::run($argv[3], $payload);
    echo json_encode(["ok" => true, "data" => $r]);
} catch (BusinessRuleException $e) { echo json_encode(["ok" => false, "code" => $e->httpStatus, "message" => $e->getMessage()]); }
catch (Throwable $e) { echo json_encode(["ok" => false, "code" => 0, "message" => get_class($e) . ": " . $e->getMessage()]); }
');
$call = function (array $u, string $mode, string $name, array $payload = []) use ($child): array {
    $pipes = [];
    $proc = proc_open([PHP_BINARY, $child, (string)$u['id'], $mode, $name, json_encode($payload)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = (string)stream_get_contents($pipes[1]); $err = (string)stream_get_contents($pipes[2]);
    proc_close($proc);
    return json_decode($out, true) ?: ['ok' => false, 'code' => -1, 'message' => trim($out . ' ' . $err)];
};
$apiPage = fn(array $u, string $page, array $q = []) => $call($u, 'page', $page, $q);
$apiRun = fn(array $u, string $api, array $d) => $call($u, 'run', $api, $d);
$denied = fn(array $r, int $code = 403) => $r['ok'] === false && ($r['code'] ?? null) === $code;

$V = [];
foreach ([1 => ['NBC 4821', 'MLW-021'], 2 => ['ABJ 7305', 'MLW-034'], 3 => ['CAH 2196', 'MLW-047']] as $i => [$plate, $no])
    $V[$i] = insert($p, 'vehicles', ['plate_number' => $plate, 'truck_number' => $no, 'truck_type' => 'Wing Van']);
$M = [];
foreach ([['RODEL PASCUAL', 'Diesel engine'], ['ARNOLD DIZON', 'Brakes and suspension'], ['VIRGILIO TORRES', 'Auto electrical'], ['NESTOR LIM', 'Hydraulics']] as [$name, $spec])
    $M[] = Mechanics::action($mp, 'save', ['full_name' => $name, 'specialization' => $spec])['id'];

$tmpDir = sys_get_temp_dir() . '/fleet_mpws_' . bin2hex(random_bytes(4)); mkdir($tmpDir);
$png = function (string $name) use ($tmpDir): array {
    $path = $tmpDir . '/' . bin2hex(random_bytes(6));
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    file_put_contents($path, $bytes);
    return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
};
$parts = fn(?array $u = null) => $apiPage($u ?? $mp, 'mp_parts_requests');
$att = fn(array $u, array $q = []) => $apiPage($u, 'mechanic_attendance', $q);
$notes = fn(int $uid) => many($p, 'SELECT category, message, page, dedupe_key, state FROM notifications WHERE user_id=? ORDER BY id', [$uid]);

// ---------------------------------------------------------------------
section('A. Runtime access per role (real Actions::pageData / Actions::run)');
foreach (array_keys(ROLE_LABELS) as $r) {
    $u = $U($r);
    $res = $parts($u);
    if ($r === 'motorpool') {
        $d = $res['data'] ?? [];
        ok($res['ok'] && isset($d['requests'], $d['summary'], $d['trucks'], $d['jobs']) && ($d['can']['act'] ?? null) === true, 'motorpool opens mp_parts_requests (requests, summary, trucks, jobs, can.act)', json_encode($res));
    } else {
        ok($denied($res), "$r is refused mp_parts_requests by the dispatcher (403)", json_encode($res));
        fails(fn() => Purchasing::read($u, 'mp_parts_requests', []), "$r is refused mp_parts_requests by the module itself (requireRole)", 403);
    }
    $canAtt = in_array($r, ['admin', 'operations_head', 'motorpool', 'audit'], true);
    $edit = $r === 'motorpool';   // HEAD_MANAGEMENT_V1: Admin monitors mechanic attendance read-only
    $res = $att($u);
    if ($canAtt) ok($res['ok'] && isset($res['data']['board'], $res['data']['sessions'], $res['data']['range']) && ($res['data']['can']['edit'] ?? null) === $edit,
        "$r opens mechanic_attendance, can.edit = " . ($edit ? 'true' : 'false'), json_encode($res['data']['can'] ?? $res));
    else ok($denied($res), "$r is refused mechanic_attendance by the dispatcher (403)", json_encode($res));
}
foreach (['parts_requests', 'purchase_orders', 'inventory', 'stock_movements', 'purchasing_overview', 'purchasing_reports', 'finance', 'funds'] as $pg)
    ok($denied($apiPage($mp, $pg)), "motorpool is refused $pg by the dispatcher");
ok($denied($apiRun($mp, 'Purchasing.request_process', ['request_id' => 1])), 'motorpool cannot release parts (Purchasing.request_process)');
ok($denied($apiRun($mp, 'Purchasing.po_details', ['po_id' => 1])), 'motorpool cannot read PO details');
ok($denied($apiRun($pur, 'Purchasing.request_create', ['vehicle_id' => $V[1], 'purpose' => 'x', 'lines' => [['part_id' => 1, 'quantity' => 1]]])), 'purchasing cannot create a Motorpool request');
ok($denied($apiRun($pur, 'Purchasing.request_cancel', ['request_id' => 1])), 'purchasing cannot cancel a Motorpool request');
$p->prepare('UPDATE users SET must_change_password=1 WHERE id=?')->execute([$mp['id']]);
ok($denied($parts()), 'the first-login password gate still applies to the new page');
$p->prepare('UPDATE users SET must_change_password=0 WHERE id=?')->execute([$mp['id']]);

// ---------------------------------------------------------------------
section('C. Parts request round trip');
// STOCK_COUNT_RECONCILIATION_V1: part_save no longer accepts an opening quantity. Test fixtures put opening stock in
// directly (one labelled 'Adjustment' movement, as the former opening balance did).
$fixtureStock = function (string $partNo, int $qty) use ($p, $pur): void {
    $id = (int)one($p, 'SELECT id FROM parts WHERE part_number=?', [$partNo])['id'];
    $p->prepare('UPDATE parts SET quantity_on_hand=quantity_on_hand+? WHERE id=?')->execute([$qty, $id]);
    $p->prepare("INSERT INTO stock_movements (part_id, movement_type, quantity_delta, unit_cost, reason, reference_type, actor_id) SELECT id, 'Adjustment', ?, unit_cost, 'Opening balance (test fixture)', 'adjustment', ? FROM parts WHERE id=?")->execute([$qty, $pur['id'], $id]);
};
Purchasing::action($pur, 'part_save', ['part_number' => 'RAD-HOSE-U-H500', 'description' => 'Upper radiator hose, Hino 500', 'unit' => 'pc', 'unit_cost' => '1450', 'reorder_level' => '2']);
$fixtureStock('RAD-HOSE-U-H500', 10);
Purchasing::action($pur, 'part_save', ['part_number' => 'BRK-PAD-F-FVR', 'description' => 'Front brake pad set, Isuzu FVR', 'unit' => 'set', 'unit_cost' => '3250', 'reorder_level' => '1']);
$hose = (int)one($p, "SELECT id FROM parts WHERE part_number='RAD-HOSE-U-H500'")['id'];
$pad = (int)one($p, "SELECT id FROM parts WHERE part_number='BRK-PAD-F-FVR'")['id'];
ok($apiRun($mp, 'Mechanics.time_in', ['id' => $M[0]])['ok'], 'motorpool Time In through the dispatcher');
// A report made with a mechanic starts Under Inspection (Maintenance::report), which request_create accepts.
$_FILES = ['photos' => $png('radiator_hose_leak.png')];
$r1 = Maintenance::action($mp, 'report', ['vehicle_id' => $V[1], 'problem' => 'Engine overheating after the NLEX run; coolant leaking at the upper radiator hose clamp',
    'reported_on' => date('Y-m-d'), 'mechanic_id' => $M[0]]);
$_FILES = [];
$j1 = (int)$r1['job_id'];
$d = $parts()['data'];
ok(in_array($j1, array_map('intval', array_column($d['jobs'], 'id')), true), 'the open repair is offered to the Request parts form', json_encode($d['jobs']));
ok(count($d['trucks']) === 3 && array_keys($d['trucks'][0]) === ['id', 'plate_number', 'truck_number'], 'trucks carry identity fields only', json_encode($d['trucks'][0] ?? null));

$create = fn(int $vid, array $lines, ?int $job, string $purpose) =>
    $apiRun($mp, 'Purchasing.request_create', ['vehicle_id' => $vid, 'purpose' => $purpose, 'lines' => $lines] + ($job ? ['maintenance_job_id' => $job] : []));
$P1 = 'Replace leaking upper radiator hose'; $P2 = 'Front brake pads worn below the limit'; $P3 = 'Spare radiator hose for the road kit';
ok($create($V[1], [['part_id' => $hose, 'quantity' => 4]], $j1, $P1)['ok'], 'request_create (linked to the repair) through the dispatcher');
ok($create($V[2], [['part_id' => $pad, 'quantity' => 1]], null, $P2)['ok'], 'request_create (no repair link)');
ok($create($V[3], [['part_id' => $hose, 'quantity' => 1]], null, $P3)['ok'], 'request_create (to be cancelled)');
$wrongJob = $create($V[2], [['part_id' => $hose, 'quantity' => 1]], $j1, 'Coolant hose for another unit');
ok(!$wrongJob['ok'] && str_contains($wrongJob['message'] ?? '', 'does not belong to this truck'), 'unchanged validation: the repair must belong to the truck', json_encode($wrongJob));
$req1 = one($p, 'SELECT * FROM part_requests WHERE purpose=?', [$P1]);
$req2 = one($p, 'SELECT * FROM part_requests WHERE purpose=?', [$P2]);
$req3 = one($p, 'SELECT * FROM part_requests WHERE purpose=?', [$P3]);
$d = $parts()['data'];
ok($d['summary'] == ['requested' => 3, 'partially_released' => 0, 'not_available' => 0, 'released' => 0, 'cancelled' => 0], 'summary after 3 requests', json_encode($d['summary']));
$row1 = array_values(array_filter($d['requests'], fn($r) => (int)$r['id'] === (int)$req1['id']))[0] ?? null;
ok($row1 && (int)$row1['vehicle_id'] === $V[1] && $row1['job_ref'] === $r1['job_ref'] && (int)$row1['requested_units'] === 4 && (int)$row1['released_units'] === 0, 'list row: truck id (for the truck filter), repair ref, requested / released units', json_encode($row1));
ok(count(array_filter($notes($pur['id']), fn($n) => $n['page'] === 'parts_requests' && $n['dedupe_key'] === 'parts-req-' . $req1['id'])) === 1, 'new request notifies Purchasing with page parts_requests (unchanged)');

$l1 = many($p, 'SELECT id FROM part_request_lines WHERE part_request_id=?', [$req1['id']]);
Purchasing::action($pur, 'request_process', ['request_id' => $req1['id'], 'release' => [['line_id' => $l1[0]['id'], 'qty' => 1]]]);
Purchasing::action($pur, 'request_process', ['request_id' => $req2['id'], 'not_available' => 1]);
ok($apiRun($mp, 'Purchasing.request_cancel', ['request_id' => $req3['id']])['ok'], 'request_cancel through the dispatcher');
$st = fn(array $r) => one($p, 'SELECT status FROM part_requests WHERE id=?', [$r['id']])['status'];
ok($st($req1) === 'Partially Released' && $st($req2) === 'Not Available' && $st($req3) === 'Cancelled', 'statuses: Partially Released, Not Available, Cancelled', json_encode([$st($req1), $st($req2), $st($req3)]));
ok($denied($apiRun($mp, 'Purchasing.request_cancel', ['request_id' => $req1['id']]), 409), 'a Partially Released request cannot be cancelled (unchanged rule)');
ok($denied($apiRun($mp, 'Purchasing.request_cancel', ['request_id' => $req3['id']]), 409), 'a Cancelled request cannot be cancelled again');
$d = $parts()['data'];
ok($d['summary'] == ['requested' => 0, 'partially_released' => 1, 'not_available' => 1, 'released' => 0, 'cancelled' => 1], 'summary after processing', json_encode($d['summary']));
$byStatus = array_count_values(array_column($d['requests'], 'status'));
ok(($byStatus['Partially Released'] ?? 0) === 1 && ($byStatus['Not Available'] ?? 0) === 1 && ($byStatus['Cancelled'] ?? 0) === 1 && count($d['requests']) === 3, 'every KPI count equals the rows of that status in the list', json_encode($byStatus));
$row1 = array_values(array_filter($d['requests'], fn($r) => (int)$r['id'] === (int)$req1['id']))[0] ?? null;
ok($row1 && (int)$row1['released_units'] === 1, 'released units shown on the list row', json_encode($row1));
$mpNotes = array_values(array_filter($notes($mp['id']), fn($n) => $n['category'] === 'Parts request'));
ok(count($mpNotes) === 2 && count(array_filter($mpNotes, fn($n) => $n['page'] === 'mp_parts_requests')) === 2, 'processed-request notifications to Motorpool open mp_parts_requests', json_encode($mpNotes));
$aud1 = array_column(many($p, 'SELECT action FROM audit_events WHERE record_ref=? ORDER BY id', ['request ' . $req1['id']]), 'action');
$aud3 = array_column(many($p, 'SELECT action FROM audit_events WHERE record_ref=? ORDER BY id', ['request ' . $req3['id']]), 'action');
ok($aud1 === ['parts request submitted', 'parts request processed'] && $aud3 === ['parts request submitted', 'parts request cancelled'], 'audit rows unchanged (submitted / processed / cancelled)', json_encode([$aud1, $aud3]));
ok(one($p, 'SELECT state FROM notifications WHERE dedupe_key=? AND user_id=?', ['parts-req-' . $req3['id'], $pur['id']])['state'] === 'Resolved', 'cancel resolves the Purchasing notification (unchanged)');
ok((int)one($p, 'SELECT quantity_on_hand FROM parts WHERE id=?', [$hose])['quantity_on_hand'] === 9, 'stock moved only by the Purchasing release (10 - 1)');

// ---------------------------------------------------------------------
section('D. PO notification target');
$poPurpose = 'Restock front brake pads, Isuzu FVR';
Purchasing::action($pur, 'po_create', ['supplier_name' => 'Bagong Pag-asa Truck Parts Trading', 'supplier_address' => 'Km. 17 MacArthur Highway, Valenzuela City',
    'purpose' => $poPurpose, 'part_request_id' => $req2['id'], 'lines' => [['part_id' => $pad, 'quantity' => 2, 'unit_price' => '3250']]]);
$po = one($p, 'SELECT id, po_ref, approver_role FROM purchase_orders WHERE purpose=?', [$poPurpose]);
$approver = $U($po['approver_role']);
$poNote = one($p, 'SELECT page FROM notifications WHERE user_id=? AND dedupe_key=?', [$approver['id'], 'po-approve-' . $po['id']]);
ok($poNote && $poNote['page'] === 'purchase_orders', 'po_create notifies the approver (' . $po['approver_role'] . ') with page purchase_orders', json_encode($poNote));
ok($apiPage($approver, 'purchase_orders')['ok'], 'the approver can open that page through the dispatcher');
ok((int)one($p, "SELECT COUNT(*) AS n FROM notifications WHERE page IN ('purchasing','maintenance') AND category IN ('Parts request','PO approval','PO approved','PO rejected')")['n'] === 0, "no new notification points at the old 'purchasing' / 'maintenance' targets");

// ---------------------------------------------------------------------
section('B. mp_parts_requests exposes no cost, price, stock, budget, supplier, PO or payment data');
// Runs after parts, stock movements, a release (with unit cost) and a PO linked to a Motorpool request exist.
$res = $parts();
$bad = array_values(array_unique(array_filter(allKeys($res['data']), fn($k) => preg_match(RESTRICTED_KEY, $k))));
ok($res['ok'] && $bad === [], 'no restricted key at any depth of the payload', json_encode($bad));
$flat = json_encode($res['data']);
foreach (['1450.00' => 'hose unit cost', '3250.00' => 'pad unit cost / PO unit price', '6500.00' => 'PO total', 'PAG-ASA' => 'supplier name', 'MacArthur' => 'supplier address', $po['po_ref'] => 'PO reference'] as $needle => $what)
    ok(!str_contains($flat, (string)$needle), "payload does not contain the $what");
ok(!preg_match('/"(9|10)"|:(9|10)[,}]/', json_encode(array_column($res['data']['requests'], 'items'))), 'request items list requested / released quantities only, no on-hand count');
$keysAtt = allKeys($att($mp)['data']);
ok(!array_filter($keysAtt, fn($k) => preg_match(RESTRICTED_KEY, $k)), 'Mechanic Attendance payload has no financial, stock, supplier or PO key either');

// ---------------------------------------------------------------------
section('E. Mechanic Attendance read');
ok($apiRun($mp, 'Mechanics.time_in', ['id' => $M[1]])['ok'], 'second mechanic timed in through the dispatcher');
$masterCounts = function () use ($mp) {
    $all = Mechanics::read($mp, 'mechanics', [])['mechanics'];
    return array_count_values(array_column(array_filter($all, fn($m) => (int)$m['active'] === 1), 'availability'));
};
$d = $att($mp)['data'];
ok(count($d['board']) === 4, 'board lists the 4 active mechanics', (string)count($d['board']));
ok(array_count_values(array_column($d['board'], 'availability')) == $masterCounts(), 'board availability counts equal the Mechanic Masterlist counts', json_encode([array_count_values(array_column($d['board'], 'availability')), $masterCounts()]));
$onDuty = array_values(array_filter($d['board'], fn($m) => $m['open_time_in'] !== null));
ok(count($onDuty) === 2 && count(array_filter($onDuty, fn($m) => in_array((int)$m['id'], [$M[0], $M[1]], true))) === 2, 'On duty now = the 2 mechanics with an open Time In');
ok($d['board'][0]['open_time_in'] !== null && $d['board'][1]['open_time_in'] !== null, 'timed-in mechanics are listed first');
$alpha = array_values(array_filter($d['board'], fn($m) => (int)$m['id'] === $M[0]))[0];
ok($alpha['availability'] === 'Assigned' && (int)$alpha['job_id'] === $j1 && $alpha['job_truck'] === 'NBC 4821', 'current repair shown for the assigned mechanic', json_encode($alpha));
ok((int)$alpha['minutes_on_duty'] >= 0 && (int)$alpha['minutes_on_duty'] < 5, 'minutes on duty is about 0 right after Time In (PHP and MySQL agree on the time zone)', (string)$alpha['minutes_on_duty']);

// Daily Present / Absent mark (Admin-only record): CURRENT_DATE() uses the session time zone db() sets from PHP.
$p->prepare('INSERT INTO attendance_daily (duty_date, mechanic_id, status, marked_by) VALUES (CURRENT_DATE(), ?, ?, ?)')->execute([$M[0], 'Present', $adm['id']]);
$alphaOf = fn(array $u) => array_values(array_filter($att($u)['data']['board'], fn($m) => (int)$m['id'] === $M[0]))[0];
ok(($alphaOf($adm)['daily_mark'] ?? null) === 'Present', 'Admin sees the daily mark of today');
foreach (['motorpool', 'operations_head', 'audit'] as $r) ok(!in_array('daily_mark', allKeys($att($U($r))['data']), true), "$r receives no daily_mark key anywhere in the payload");

// Sessions and date range. Fixture: one closed session 10 days ago (respects chk_mattn_order and the one-open-session key).
$old = (new DateTimeImmutable('today'))->modify('-10 days');
$p->prepare('INSERT INTO mechanic_attendance (mechanic_id, time_in, time_out, time_in_by, time_out_by) VALUES (?,?,?,?,?)')
  ->execute([$M[2], $old->format('Y-m-d') . ' 08:00:00', $old->format('Y-m-d') . ' 17:00:00', $mp['id'], $mp['id']]);
$today = date('Y-m-d');
$d = $att($mp)['data'];
ok($d['range']['from'] === $today && $d['range']['to'] === $today, 'default range is today', json_encode($d['range']));
ok(count($d['sessions']) === 2 && !in_array($M[2], array_map('intval', array_column($d['sessions'], 'mechanic_id')), true), "today's sessions only: the old session is excluded", json_encode(array_column($d['sessions'], 'mechanic_id')));
$d = $att($mp, ['from' => $old->format('Y-m-d'), 'to' => $old->format('Y-m-d')])['data'];
ok(count($d['sessions']) === 1 && (int)$d['sessions'][0]['mechanic_id'] === $M[2] && (int)$d['sessions'][0]['minutes'] === 540, 'the old session is found on its own date with 540 minutes', json_encode($d['sessions']));
ok(($d['sessions'][0]['time_in_by'] ?? '') === $mp['full_name'], 'session shows who timed in');
$d = $att($mp, ['from' => $today, 'to' => $old->format('Y-m-d')])['data'];
ok($d['range']['from'] === $old->format('Y-m-d') && $d['range']['to'] === $today && count($d['sessions']) === 3, 'reversed dates are swapped', json_encode($d['range']));
$d = $att($mp, ['from' => (new DateTimeImmutable('today'))->modify('-200 days')->format('Y-m-d'), 'to' => $today])['data'];
ok($d['range']['from'] === (new DateTimeImmutable('today'))->modify('-61 days')->format('Y-m-d') && (int)$d['range']['max_days'] === 62, 'a range longer than 62 days is clamped to 62 days', json_encode($d['range']));
$d = $att($mp, ['from' => '2026-02-30', 'to' => 'not-a-date'])['data'];
ok($d['range']['from'] === $today && $d['range']['to'] === $today, 'invalid dates fall back to today', json_encode($d['range']));
$d = $att($mp, ['from' => "2026-01-01' OR 1=1 -- "])['data'];
ok($d['range']['from'] === $today, 'a malformed date is ignored, not passed to SQL');

// ---------------------------------------------------------------------
section('F. Time In / Time Out refusals');
$avail = fn(int $id) => one($p, 'SELECT availability FROM mechanics WHERE id=?', [$id])['availability'];
$openCount = fn() => (int)one($p, 'SELECT COUNT(*) AS n FROM mechanic_attendance WHERE time_out IS NULL')['n'];
$before = [$avail($M[3]), $avail($M[1]), $openCount()];
foreach (['operations_head', 'audit'] as $r) {   // these two can OPEN the page, so the API refusal matters most
    ok($denied($apiRun($U($r), 'Mechanics.time_in', ['id' => $M[3]])), "$r cannot Time In through the API (403)");
    ok($denied($apiRun($U($r), 'Mechanics.time_out', ['id' => $M[1]])), "$r cannot Time Out through the API (403)");
}
foreach (['purchasing', 'finance', 'billing', 'car_carrier_dispatcher', 'container_wingvan_dispatcher'] as $r) {
    fails(fn() => Mechanics::action($U($r), 'time_in', ['id' => $M[3]]), "$r cannot Time In", 403);
    fails(fn() => Mechanics::action($U($r), 'time_out', ['id' => $M[1]]), "$r cannot Time Out", 403);
}
ok([$avail($M[3]), $avail($M[1]), $openCount()] === $before, 'refused role calls changed no availability and no session', json_encode([$before, [$avail($M[3]), $avail($M[1]), $openCount()]]));
$dup = $apiRun($mp, 'Mechanics.time_in', ['id' => $M[1]]);
ok($denied($dup, 409) && str_contains($dup['message'], 'already timed in'), 'duplicate Time In is refused (409)', json_encode($dup));
$busy = $apiRun($mp, 'Mechanics.time_out', ['id' => $M[0]]);
ok($denied($busy, 409) && str_contains($busy['message'], 'cannot time out'), 'Time Out during an active repair is refused (409)', json_encode($busy));
ok($denied($apiRun($mp, 'Mechanics.time_out', ['id' => $M[3]]), 409), 'Time Out without a Time In is refused (409)');
ok($avail($M[0]) === 'Assigned' && $avail($M[1]) === 'Available' && $avail($M[3]) === 'Unavailable' && $openCount() === 2, 'availability unchanged after the refusals');
ok($denied($apiRun($adm, 'Mechanics.time_out', ['id' => $M[1]]), 403) && $avail($M[1]) === 'Available', 'Admin cannot Time Out (Head Management monitors only)');
$r = $apiRun($mp, 'Mechanics.time_out', ['id' => $M[1]]);
ok($r['ok'] && $r['data']['availability'] === 'Unavailable' && $avail($M[1]) === 'Unavailable', 'Motorpool can Time Out', json_encode($r));
$r = $apiRun($mp, 'Mechanics.time_in', ['id' => $M[1]]);
ok($r['ok'] && $r['data']['availability'] === 'Available', 'Motorpool can Time In again', json_encode($r));
$d = $att($ops)['data'];
ok(count(array_filter($d['board'], fn($m) => $m['open_time_in'] !== null)) === 2 && $d['can']['edit'] === false, 'Operations Head sees the same board, read only');

// ---------------------------------------------------------------------
section('G. Read-only: page reads write nothing');
$tables = ['part_requests', 'part_request_lines', 'parts', 'stock_movements', 'purchase_orders', 'mechanics', 'mechanic_attendance', 'attendance_daily', 'maintenance_jobs', 'audit_events', 'notifications'];
$snap = function () use ($p, $tables) { $o = []; foreach ($tables as $t) $o[$t] = md5(json_encode(many($p, "SELECT * FROM `$t` ORDER BY id"))); return $o; };
$s0 = $snap();
foreach (['motorpool', 'admin', 'operations_head', 'audit'] as $r) { $att($U($r)); $att($U($r), ['from' => $old->format('Y-m-d'), 'to' => $today]); }
$parts();
ok($snap() === $s0, 'reading both pages as every allowed role changed no row');

// ---------------------------------------------------------------------
@unlink($child);
@array_map('unlink', glob($tmpDir . '/*') ?: []); @rmdir($tmpDir);
echo "\n$PASS passed, $FAIL failed\n";
if ($FAILED) echo "Failed:\n  - " . implode("\n  - ", $FAILED) . "\n";
exit($FAIL ? 1 : 0);
