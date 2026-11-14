<?php
/*
 * =====================================================================
 *  tests/uat_gap_tests.php  —  actions the role-to-task matrix found without an automated check
 * =====================================================================
 *  Do NOT run this file directly: run tests/run_uat_gap_tests.php. Builds the end-to-end scenario, then calls each
 *  action through its real module as the owning role, checks the stored result, and checks a wrong-role refusal.
 * =====================================================================
 */
declare(strict_types=1);
define('E2E_KEEP_RUNNING', true);
require __DIR__ . '/integration_workflow_tests.php';
foreach (['Clients', 'Fleet', 'Routes'] as $m) Actions::load($m);
$png = function (string $name) use ($tmpDir): array {   // valid 200x60 grayscale PNG built without GD (the CLI has no GD)
    $chunk = fn(string $t, string $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
    $raw = str_repeat("\0" . str_repeat("\x40", 200), 60);
    $bytes = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 200, 60, 8, 0, 0, 0, 0)) . $chunk('IDAT', gzcompress($raw)) . $chunk('IEND', '');
    if (!is_dir($tmpDir)) mkdir($tmpDir);
    $path = $tmpDir . '/' . bin2hex(random_bytes(6)); file_put_contents($path, $bytes);
    return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
};
if (!is_dir($tmpDir)) mkdir($tmpDir);

section('Admin');
$r = Admin::action($adm, 'backup_create', []);
$bk = dirname(__DIR__) . '/storage/backups/' . ($r['file'] ?? $r['name'] ?? '');
ok(isset($r['message']) && glob(dirname(__DIR__) . '/storage/backups/mulawin_backup_*.sql'), 'Admin creates a database backup file', json_encode($r));
fails(fn() => Admin::action($aud, 'backup_create', []), 'Audit cannot create a backup', 403);
Admin::action($adm, 'fund_category_save', ['name' => 'UAT Toll Fee', 'amount' => '150', 'active' => '1', 'sort_order' => '9']);
ok((float)one($p, "SELECT amount FROM fund_categories WHERE name='UAT Toll Fee' AND active=1")['amount'] === 150.0, 'Admin adds a fund category');
fails(fn() => Admin::action($adm, 'fund_category_save', ['name' => 'UAT Toll Fee', 'amount' => '200']), 'duplicate fund category name refused', 409);
fails(fn() => Admin::action($fin, 'fund_category_save', ['name' => 'X', 'amount' => '1']), 'Finance cannot change fund categories', 403);
$unread = fn(int $uid) => (int)one($p, "SELECT COUNT(*) AS n FROM notifications WHERE user_id=? AND state='Unread'", [$uid])['n'];
$otherBefore = $unread($ops['id']);
Admin::action($wvd, 'notifications_read', []);
ok($unread($wvd['id']) === 0 && $unread($ops['id']) === $otherBefore, 'mark all as read touches only the user\'s own notifications');
$_FILES = ['signature' => $png('sig.png')];
try { Admin::action($fin, 'signature_upload', []); } finally { $_FILES = []; }
ok((bool)one($p, "SELECT 1 AS x FROM users u JOIN documents d ON d.id=u.signature_document_id WHERE u.id=? AND d.category='User E-Signature'", [$fin['id']]), 'a user saves an own e-signature image (linked document)');
$_FILES = ['signature' => $file('not-image.pdf')];
fails(fn() => (function () use ($fin) { try { return Admin::action($fin, 'signature_upload', []); } finally { $_FILES = []; } })(), 'a non-image signature is refused');
$hashBefore = one($p, 'SELECT password_hash FROM users WHERE id=?', [$bil['id']])['password_hash'];
$res = Admin::action($adm, 'user_password', ['user_id' => $bil['id']]);
$after = one($p, 'SELECT password_hash, must_change_password FROM users WHERE id=?', [$bil['id']]);
ok($after['password_hash'] !== $hashBefore && (int)$after['must_change_password'] === 1 && !str_contains(json_encode(one($p, "SELECT details FROM audit_events WHERE action LIKE '%password%' ORDER BY id DESC LIMIT 1")), (string)($res['password'] ?? 'zz-none')),
   'Admin reset: new hash, change forced at next login, temporary password not written to the audit log');
fails(fn() => Admin::action($adm, 'user_password', ['user_id' => $adm['id']]), 'Admin cannot reset the own password here');
fails(fn() => Admin::action($ops, 'user_password', ['user_id' => $bil['id']]), 'Operations Head cannot reset passwords', 403);

section('Dispatcher attendance history');
$crewId = (int)$t1['driver_id'];
$h = Attendance::action($wvd, 'dispatcher_history', ['personnel_id' => $crewId]);
ok(count($h['sessions']) >= 1 && (int)$h['person']['id'] === $crewId, 'Dispatcher reads the Time In history of own-group crew');
fails(fn() => Attendance::action($aud, 'dispatcher_history', ['personnel_id' => $crewId]), 'Audit cannot use the Dispatcher attendance history action', 403);

section('Billing statement of account');
$soa = Billing::action($bil, 'soa', ['client_id' => $cid]);
ok($soa['client']['id'] == $cid && $soa['invoices'] === [], 'Billing statement of account lists open invoices only (the fully paid invoice is not outstanding)');
fails(fn() => Billing::action($mp, 'soa', ['client_id' => $cid]), 'Motorpool cannot read statements of account', 403);

section('Clients');
$c = Clients::action($ops, 'details', ['id' => $cid])['client'];
$info = array_intersect_key($c, array_flip(['company_name', 'trade_name', 'nature_of_business', 'tin', 'business_address', 'contact_person', 'contact_position', 'contact_number', 'email', 'notes']));
$info = array_merge(array_map(fn($v) => $v ?? '', $info), ['business_address' => 'UAT Business Park, Tanauan', 'contact_person' => 'UAT Contact', 'contact_number' => '09171234567']);
Clients::action($ops, 'update', ['id' => $cid] + $info);
ok(one($p, 'SELECT contact_person FROM clients WHERE id=?', [$cid])['contact_person'] === 'UAT Contact', 'Operations Head updates client details');
fails(fn() => Clients::action($fin, 'update', ['id' => $cid] + $info), 'Finance cannot edit clients', 403);
$_FILES = []; try { $docRes = Clients::action($ops, 'documents', ['id' => $cid]); ok(isset($docRes['message']), 'client documents action answers for an Operations Head'); } catch (BusinessRuleException $e) { ok($e->httpStatus !== 403, 'client documents: Operations Head passes the permission check (needs files)', $e->getMessage()); }
$spare = insert($p, 'clients', ['company_name' => 'UAT SPARE CLIENT', 'status' => 'Active', 'created_by' => $ops['id']]);
Clients::action($adm, 'set_active', ['id' => $spare, 'active' => '']);
ok(one($p, 'SELECT status FROM clients WHERE id=?', [$spare])['status'] === 'Inactive', 'Admin deactivates a client');
fails(fn() => Clients::action($aud, 'set_active', ['id' => $spare, 'active' => '1']), 'Audit cannot change client status', 403);

section('Finance manual expense');
$n0 = (int)one($p, "SELECT COUNT(*) AS n FROM expenses WHERE source='Manual'")['n'];
$_FILES = [];
Finance::action($fin, 'expense_add', ['category' => 'Office', 'amount' => '350', 'incurred_on' => date('Y-m-d'), 'payee' => 'UAT Office Supply', 'reference_number' => 'OR-UAT-1', 'description' => 'Printer ink']);
ok((int)one($p, "SELECT COUNT(*) AS n FROM expenses WHERE source='Manual'")['n'] === $n0 + 1, 'Finance records a manual expense (source Manual)');
fails(fn() => Finance::action($bil, 'expense_add', ['category' => 'Office', 'amount' => '1', 'incurred_on' => date('Y-m-d'), 'description' => 'x']), 'Billing cannot record expenses', 403);
fails(fn() => Finance::action($fin, 'expense_add', ['category' => 'Office', 'amount' => '1', 'incurred_on' => date('Y-m-d', strtotime('+2 days')), 'description' => 'future']), 'future-dated expense refused');

section('Fleet status and activation');
$spareHelper = insert($p, 'personnel', ['full_name' => 'UAT Spare Helper', 'person_type' => 'Helper']);
Fleet::action($ops, 'crew_status', ['id' => $spareHelper, 'availability' => 'Unavailable', 'reason' => 'Medical leave']);
ok(one($p, 'SELECT availability FROM personnel WHERE id=?', [$spareHelper])['availability'] === 'Unavailable', 'Operations Head marks a helper Unavailable with a reason');
fails(fn() => Fleet::action($ops, 'crew_status', ['id' => $spareHelper, 'availability' => 'Unavailable']), 'Unavailable needs a reason');
Fleet::action($ops, 'crew_status', ['id' => $spareHelper, 'availability' => 'Available']);
Fleet::action($adm, 'crew_active', ['id' => $spareHelper, 'active' => '']);
ok((int)one($p, 'SELECT active FROM personnel WHERE id=?', [$spareHelper])['active'] === 0, 'Admin deactivates a free helper');
fails(fn() => Fleet::action($aud, 'crew_status', ['id' => $spareHelper, 'availability' => 'Available']), 'Audit cannot change crew status', 403);
$spareTruck = insert($p, 'vehicles', ['plate_number' => 'UAT-SPARE', 'truck_type' => 'Wing Van']);
Fleet::action($ops, 'truck_active', ['id' => $spareTruck, 'active' => '']);
ok((int)one($p, 'SELECT active FROM vehicles WHERE id=?', [$spareTruck])['active'] === 0, 'Operations Head deactivates a free truck');
fails(fn() => Fleet::action($mp, 'truck_active', ['id' => $spareTruck, 'active' => '1']), 'Motorpool cannot activate trucks (masterlist owner is Operations / Admin)', 403);

section('Parts requests (catalog, details, cancel)');
$cat = Purchasing::action($mp, 'request_catalog', []);
ok($cat['parts'] && !array_key_exists('unit_cost', $cat['parts'][0]) && !array_key_exists('quantity_on_hand', $cat['parts'][0]), 'Motorpool catalog shows availability only (no cost or stock count)');
fails(fn() => Purchasing::action($pur, 'request_catalog', []), 'the Motorpool catalog is Motorpool only', 403);
$det = Purchasing::action($pur, 'request_details', ['request_id' => $req['id']]);
ok($det['request']['request_ref'] === $req['request_ref'] && count($det['lines']) === 1, 'Purchasing reads parts request details');
fails(fn() => Purchasing::action($bil, 'request_details', ['request_id' => $req['id']]), 'Billing cannot read parts requests', 403);
$freeTruck = (int)one($p, "SELECT id FROM vehicles WHERE active=1 AND status='Available' LIMIT 1")['id'];
Purchasing::action($mp, 'request_create', ['vehicle_id' => $freeTruck, 'purpose' => 'Spare bulbs', 'lines' => [['part_id' => $part, 'quantity' => 1]]]);
$rid = (int)one($p, 'SELECT MAX(id) AS id FROM part_requests')['id'];
fails(fn() => Purchasing::action($pur, 'request_cancel', ['request_id' => $rid]), 'Purchasing cannot cancel a Motorpool request', 403);
Purchasing::action($mp, 'request_cancel', ['request_id' => $rid]);
ok(one($p, 'SELECT status FROM part_requests WHERE id=?', [$rid])['status'] === 'Cancelled', 'Motorpool cancels its own Requested parts request');
fails(fn() => Purchasing::action($mp, 'request_cancel', ['request_id' => $rid]), 'a cancelled request cannot be cancelled again', 409);

section('Route pricing estimate');
$est = Routes::action($ops, 'pricing_estimate', ['route_id' => $route]);
ok((string)$est['fixed'] === '4000.00' && $est['fuel'] !== null, 'Operations Head sees the fixed rate and fuel component of a route', json_encode([$est['fixed'] ?? null, $est['fuel'] ?? null]));
fails(fn() => Routes::action($mp, 'pricing_estimate', ['route_id' => $route]), 'Motorpool cannot read route pricing', 403);

echo "\nPASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL ? 1 : 0);
