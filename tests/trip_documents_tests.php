<?php
/*
 * =====================================================================
 *  tests/trip_documents_tests.php  —  Checklist #44: Diesel PO and Supplier SOA attachments
 * =====================================================================
 *  Do NOT run this file directly: run tests/run_trip_documents_tests.php (temporary copy, own .env,
 *  disposable *_test database only). Uploads are simulated with temporary files (CLI).
 *
 *    A. Diesel PO from Trip Details (dispatcher of the trip's team)
 *    B. File checks (type, size, empty, too many) and nothing left behind on failure
 *    C. Permissions and statuses (direct API)
 *    D. Supplier SOA in Complete Trip (optional; required documents unchanged)
 *    E. Trip Details, Documents viewer, Finance view / download rights
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/backend/Actions.php';
require_once __DIR__ . '/_launcher.php';
core('Access');
core('Settings');
core('Files');

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

section('Setup: disposable fresh database');
test_recreate($FRESH);
$p = db();
ok($p->query('SELECT DATABASE()')->fetchColumn() === $FRESH, 'db() is the disposable test database');
test_import($p, test_sql('database/schema.sql'));
test_import($p, test_sql('database/extension.sql'));
$users = [];
foreach (array_keys(ROLE_LABELS) as $role) {
    $id = insert($p, 'users', ['username' => $role, 'email' => "$role@example.test", 'password_hash' => 'x', 'full_name' => strtoupper($role) . ' USER', 'role' => $role]);
    $users[$role] = ['id' => $id, 'username' => $role, 'email' => "$role@example.test", 'full_name' => strtoupper($role) . ' USER', 'role' => $role, 'shift' => null];
}
$U = fn(string $r) => $users[$r];
$ops = $U('operations_head'); $wvd = $U('container_wingvan_dispatcher'); $ccd = $U('car_carrier_dispatcher'); $fin = $U('finance');
Actions::load('Trips'); Actions::load('Documents');
$cid = insert($p, 'clients', ['company_name' => 'DOC CLIENT', 'status' => 'Active', 'created_by' => $ops['id']]);
insert($p, 'client_rates', ['client_id' => $cid, 'truck_type' => 'Wing Van', 'rate_per_km' => '50.00', 'minimum_charge' => '0.00', 'updated_by' => $U('billing')['id']]);
for ($i = 1; $i <= 12; $i++) { insert($p, 'vehicles', ['plate_number' => "WV-$i", 'truck_type' => 'Wing Van']); insert($p, 'personnel', ['full_name' => "DRIVER, $i", 'person_type' => 'Driver']); insert($p, 'personnel', ['full_name' => "HELPER, $i", 'person_type' => 'Helper']); }
$T = fn(int $id) => one($p, 'SELECT * FROM trips WHERE id=?', [$id]);
$n = 0;
$trip = function (string $status = 'Pre-Assigned') use ($ops, $cid, $p, &$n): int {
    $n++;
    $r = test_create_planned_trip($ops, ['client_id' => $cid, 'truck_type' => 'Wing Van', 'pickup_point' => "P$n", 'delivery_point' => "D$n", 'scheduled_at' => date('Y-m-d\TH:i', time() + 3600), 'distance_km' => '20']);
    if ($status !== 'Pre-Assigned') run($p, 'UPDATE trips SET status=?, dispatched_at=IF(? IN (\'Dispatched\',\'In Transit\',\'Completed\'), DATE_SUB(NOW(), INTERVAL 2 HOUR), dispatched_at) WHERE id=?', [$status, $status, $r['trip_id']]);
    return (int)$r['trip_id'];
};
// Upload simulation: real temporary files (Files::store accepts CLI files).
$tmpDir = sys_get_temp_dir() . '/fleet_docs_' . bin2hex(random_bytes(4)); mkdir($tmpDir);
$file = function (string $kind, string $name) use ($tmpDir): array {
    $path = $tmpDir . '/' . bin2hex(random_bytes(6));
    $bytes = match ($kind) {
        'pdf' => "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n",
        'png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
        'txt' => "just text\n",
        'php' => "<?php echo 'x';\n",
        'html' => "<html><script>alert(1)</script></html>\n",
        'empty' => '',
        'big' => "%PDF-1.4\n" . str_repeat('A', 10000001),
    };
    file_put_contents($path, $bytes);
    return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
};
$files = fn(array ...$list) => ['name' => array_column($list, 'name'), 'tmp_name' => array_column($list, 'tmp_name'), 'error' => array_column($list, 'error'), 'size' => array_column($list, 'size')];
$docs = fn(int $trip, string $cat) => many($p, 'SELECT * FROM documents WHERE entity_type=? AND entity_id=? AND category=? ORDER BY id', ['trip', $trip, $cat]);
$storage = dirname(__DIR__) . '/storage/private';
$stored = fn() => is_dir($storage) ? count(glob($storage . '/*')) : 0;

// ---------------------------------------------------------------------
section('A. Diesel PO from Trip Details');
$id = $trip();
$_FILES = ['diesel_po' => $file('pdf', 'diesel-po-001.pdf')];
$r = Trips::action($wvd, 'diesel_po', ['trip_id' => $id]);
$d = $docs($id, 'Diesel PO');
ok(count($d) === 1 && $d[0]['original_filename'] === 'diesel-po-001.pdf' && $d[0]['mime_type'] === 'application/pdf' && (int)$d[0]['uploaded_by'] === $wvd['id'], 'Diesel PO saved as a trip document (category Diesel PO)', json_encode($d));
ok(is_file($storage . '/' . $d[0]['storage_name']) && preg_match('/^[a-f0-9]{40}\.pdf$/', $d[0]['storage_name']) === 1, 'stored privately with a random name');
ok(str_contains($r['message'], 'Diesel PO attached'), 'result message', $r['message']);
ok((bool)one($p, "SELECT 1 AS x FROM trip_events WHERE trip_id=? AND event_type='diesel PO attached'", [$id]), 'trip history entry');
ok((bool)one($p, "SELECT 1 AS x FROM audit_events WHERE action='document uploaded' AND record_ref=?", ['trip ' . $id]), 'audit trail (document uploaded)');
$_FILES = ['diesel_po' => $files($file('png', 'po-page1.png'), $file('pdf', 'po-page2.pdf'))];
Trips::action($wvd, 'diesel_po', ['trip_id' => $id]);
ok(count($docs($id, 'Diesel PO')) === 3, 'more files can be attached later (multiple at once)');
ok($T($id)['status'] === 'Pre-Assigned', 'trip status not changed by the attachment');

// ---------------------------------------------------------------------
section('B. File checks; nothing left behind on failure');
$before = [$stored(), count($docs($id, 'Diesel PO'))];
foreach (['txt' => 'notes.txt', 'php' => 'po.pdf.php', 'html' => 'po.html', 'empty' => 'empty.pdf', 'big' => 'huge.pdf'] as $kind => $name) {
    $_FILES = ['diesel_po' => $file($kind, $name)];
    fails(fn() => Trips::action($wvd, 'diesel_po', ['trip_id' => $id]), "rejected: $name", 400);
}
$_FILES = ['diesel_po' => $file('txt', 'fake.pdf')];
fails(fn() => Trips::action($wvd, 'diesel_po', ['trip_id' => $id]), 'text file renamed .pdf rejected (content is checked, not the name)', 400, 'Only PDF, JPG, PNG and WebP');
$_FILES = ['diesel_po' => $files($file('pdf', 'ok.pdf'), $file('php', 'bad.php'))];
fails(fn() => Trips::action($wvd, 'diesel_po', ['trip_id' => $id]), 'one bad file in a batch rejects the whole batch', 400);
$_FILES = [];
fails(fn() => Trips::action($wvd, 'diesel_po', ['trip_id' => $id]), 'no file: refused', 400, 'Please attach');
$_FILES = ['diesel_po' => ['name' => 'x.pdf', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]];
fails(fn() => Trips::action($wvd, 'diesel_po', ['trip_id' => $id]), 'upload error reported', 400, 'Upload failed');
ok([$stored(), count($docs($id, 'Diesel PO'))] === $before, 'refused uploads left no stored file and no document row');

// ---------------------------------------------------------------------
section('C. Permissions and statuses (direct API)');
$_FILES = ['diesel_po' => $file('pdf', 'po.pdf')];
foreach (['car_carrier_dispatcher' => 403, 'operations_head' => 403, 'finance' => 403, 'billing' => 403, 'purchasing' => 403, 'admin' => 403] as $role => $code)
    fails(fn() => Trips::action($U($role), 'diesel_po', ['trip_id' => $id]), "$role cannot attach the Diesel PO to a Wing Van trip", $code);
fails(fn() => Trips::action($wvd, 'diesel_po', ['trip_id' => 999999]), 'unknown trip refused', 404);
fails(fn() => Trips::action($wvd, 'diesel_po', ['trip_id' => 'abc']), 'invalid trip id refused', 400);
foreach (['Pending Review', 'Awaiting Resources', 'Cancelled', 'Rejected'] as $st) {
    $x = $trip($st);
    $_FILES = ['diesel_po' => $file('pdf', 'po.pdf')];
    fails(fn() => Trips::action($wvd, 'diesel_po', ['trip_id' => $x]), "$st trip: refused", 409);
}
foreach (['Ready for Dispatch', 'Dispatched', 'In Transit', 'Completed'] as $st) {
    $x = $trip($st);
    $_FILES = ['diesel_po' => $file('pdf', 'po.pdf')];
    Trips::action($wvd, 'diesel_po', ['trip_id' => $x]);
    ok(count($docs($x, 'Diesel PO')) === 1, "$st trip: Diesel PO attached");
}

// ---------------------------------------------------------------------
section('D. Supplier SOA in Complete Trip');
$complete = fn(int $tid, array $f) => (function () use ($tid, $f, $wvd) { $_FILES = $f; return Trips::action($wvd, 'complete', ['trip_id' => $tid, 'completed_at' => date('Y-m-d\TH:i'), 'waybill_number' => 'WB-' . $tid]); })();
$req = fn() => ['waybill' => $file('pdf', 'wb.pdf'), 'ack_receipt' => $file('pdf', 'ar.pdf'), 'delivery_receipt' => $file('pdf', 'dr.pdf')];
$c1 = $trip('Dispatched');
$complete($c1, $req() + ['supplier_soa' => $file('pdf', 'soa-shell.pdf')]);
ok($T($c1)['status'] === 'Completed' && count($docs($c1, 'Supplier SOA')) === 1 && $docs($c1, 'Supplier SOA')[0]['original_filename'] === 'soa-shell.pdf', 'completed with the Supplier SOA attached');
ok(str_contains((string)one($p, "SELECT notes FROM trip_events WHERE trip_id=? AND event_type='trip completed'", [$c1])['notes'], 'supplier SOA attached (1)'), 'completion history mentions the SOA');
$c2 = $trip('Dispatched');
$complete($c2, $req());
ok($T($c2)['status'] === 'Completed' && count($docs($c2, 'Supplier SOA')) === 0, 'SOA is optional: completed without it');
$c3 = $trip('Dispatched');
$before = $stored();
fails(fn() => $complete($c3, ['waybill' => $file('pdf', 'wb.pdf'), 'ack_receipt' => $file('pdf', 'ar.pdf'), 'supplier_soa' => $file('pdf', 'soa.pdf')]), 'SOA does not replace the DR: still refused without the DR', 422, 'delivery receipt (DR) file');
ok($T($c3)['status'] === 'Dispatched' && count($docs($c3, 'Supplier SOA')) === 0 && $stored() === $before, 'refused completion: no SOA row and no stored file left');
fails(fn() => $complete($c3, $req() + ['supplier_soa' => $file('txt', 'soa.txt')]), 'SOA of a wrong type: completion refused', 400, 'Only PDF');
ok($T($c3)['status'] === 'Dispatched' && $stored() === $before && !$docs($c3, 'Waybill'), 'nothing saved (required files rolled back too)');
$complete($c3, $req() + ['supplier_soa' => $files($file('pdf', 'soa-1.pdf'), $file('png', 'soa-2.png'))]);
ok($T($c3)['status'] === 'Completed' && count($docs($c3, 'Supplier SOA')) === 2, 'several SOA pages accepted');
$cc = $trip('Dispatched');
fails(fn() => (function () use ($cc, $ccd, $req, $file) { $_FILES = $req() + ['supplier_soa' => $file('pdf', 's.pdf')]; return Trips::action($ccd, 'complete', ['trip_id' => $cc, 'completed_at' => date('Y-m-d\TH:i'), 'waybill_number' => 'W']); })(),
    'other dispatcher team cannot complete (and attach the SOA)', 403);

// ---------------------------------------------------------------------
section('E. Trip Details, Documents viewer, Finance view / download rights');
$det = Trips::action($fin, 'details', ['trip_id' => $c1]);
$cats = array_column($det['documents'], 'category');
ok(in_array('Supplier SOA', $cats, true) && in_array('Waybill', $cats, true), 'Finance opens Trip Details: Supplier SOA listed with the trip documents', json_encode($cats));
$det = Trips::action($wvd, 'details', ['trip_id' => $id]);
ok(count(array_filter($det['documents'], fn($x) => $x['category'] === 'Diesel PO')) === 3 && (int)$det['trip']['diesel_po_docs'] === 3, 'Trip Details: Diesel PO files and count');
ok((int)Trips::action($wvd, 'details', ['trip_id' => $c3])['trip']['soa_docs'] === 2, 'Trip Details: Supplier SOA count');
$list = Documents::read($fin, 'documents', []);
$byCat = array_count_values(array_column($list['documents'], 'category'));
ok(($byCat['Diesel PO'] ?? 0) >= 7 && ($byCat['Supplier SOA'] ?? 0) === 3, 'Documents viewer (Finance): Diesel PO and Supplier SOA listed', json_encode($byCat));
$auth = new ReflectionMethod('Documents', 'authorizedDocument'); $auth->setAccessible(true);
$po = $docs($id, 'Diesel PO')[0]; $soa = $docs($c1, 'Supplier SOA')[0];
foreach ([[$fin, true, 'Finance'], [$U('billing'), true, 'Billing'], [$U('audit'), true, 'Audit'], [$ops, true, 'Operations Head'], [$wvd, true, 'Wing Van dispatcher (own team)'],
          [$ccd, false, 'Car Carrier dispatcher (other team)'], [$U('purchasing'), false, 'Purchasing']] as [$who, $allowed, $label]) {
    $a = $auth->invoke(null, $p, $who, 'view', 'trip', (int)$po['entity_id'], 'Diesel PO');
    $b = $auth->invoke(null, $p, $who, 'view', 'trip', (int)$soa['entity_id'], 'Supplier SOA');
    ok($a === $allowed && $b === $allowed, "$label " . ($allowed ? 'can' : 'cannot') . ' view / download the Diesel PO and Supplier SOA');
}
$src = (string)file_get_contents(dirname(__DIR__) . '/public/index.php');
ok(str_contains($src, 'Documents::download'), 'downloads go through Documents::download (same permission check)');
// Required document rules unchanged.
$c4 = $trip('Dispatched');
fails(fn() => $complete($c4, ['supplier_soa' => $file('pdf', 'soa.pdf')]), 'only the SOA: still refused (waybill, AR, DR required)', 422, 'waybill file, acknowledgement receipt file, delivery receipt (DR) file');
$js = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/modules/operations/trips.js');
ok(str_contains($js, "name: 'supplier_soa'") && str_contains($js, "'Trips.diesel_po'") && str_contains($js, 'CHECKLIST_44_DIESEL_PO_SOA_ATTACHMENTS_V1'), 'screens: SOA field in Complete Trip, Diesel PO button in Trip Details');
ok(!preg_match("/\\['Supplier SOA/", $js) && str_contains($js, "['Delivery Receipt (DR) file', has('delivery_receipt')"), 'Complete Trip checklist of required files unchanged (SOA not required)');

foreach (glob($tmpDir . '/*') as $f) @unlink($f); @rmdir($tmpDir);
echo "\n----------------------------------------\n";
echo "RESULT: $PASS passed, $FAIL failed\n";
if ($FAILED) { echo "Failed:\n"; foreach ($FAILED as $f) echo "  - $f\n"; }
test_drop($FRESH);
exit($FAIL === 0 ? 0 : 1);
