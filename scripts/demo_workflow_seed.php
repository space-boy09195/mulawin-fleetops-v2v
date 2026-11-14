<?php
/*
 * =====================================================================
 *  scripts/demo_workflow_seed.php  —  linked Maintenance -> Purchasing -> Finance demonstration records in mulawin_v2
 * =====================================================================
 *  RUN (project folder, ONLY after the verified backup and your approval):
 *    C:\xampp\php\php.exe scripts\demo_workflow_seed.php --target=mulawin_v2 --backup=C:\xampp\fleetops_demo\<dump>.sql
 *
 *  SAFETY (all checked BEFORE any write; any failure = refused, nothing written):
 *    - CLI only; --target must be mulawin_v2; the project .env and the live connection (SELECT DATABASE()) must be mulawin_v2.
 *    - --backup must be a complete mysqldump of mulawin_v2 ("-- Dump completed" footer), made in the last 3 hours, stored
 *      outside the project folder.
 *    - The database must still be in the audited state: no repairs, parts, parts requests, POs, budgets, stock movements,
 *      expenses or disbursements; no Finance account; none of the demo plates or mechanic names present.
 *    - It runs once: an existing manifest in C:\xampp\fleetops_demo refuses a second run.
 *  WHAT IT DOES
 *    - Every record is created through the existing module actions, called as the role that does the step:
 *      Admin.user_save (Finance account), Fleet.truck_save (6 NEW trucks), Mechanics.save / time_in / time_out (5 NEW mechanics),
 *      Purchasing.part_save / adjust / request_* / po_*, Finance.budget_set / po_pay, Maintenance.report / inspect / add_cost /
 *      complete / verify / cancel. No status column is written, nothing is deleted, no DROP / TRUNCATE.
 *    - The existing 29 trucks and 8 mechanics are never used or changed.
 *    - A refused step is reported as BLOCKED (with the rule's message) and the run continues; it is never forced.
 *    - No email: none of these actions sends mail (Admin.user_save returns the temporary password; it is not emailed).
 *      No map service call (no trips are created).
 *  OUTPUT (outside the repository, C:\xampp\fleetops_demo\):
 *    - demo_manifest_<stamp>.json: highest id of every table before the run and EVERY new id per table afterwards.
 *    - finance_temporary_password_<stamp>.txt: the Finance account's one-time password (not printed). It must be changed at first login.
 *  Exit codes: 0 done, 2 refused (nothing written), 3 done with BLOCKED steps (listed), other = crashed (see the log).
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

const DEMO_DB = 'mulawin_v2';
const DEMO_DIR = 'C:\\xampp\\fleetops_demo';
function demo_refuse(string $why): never { fwrite(STDERR, "REFUSED: $why\nNothing was written.\n"); exit(2); }

$ROOT = dirname(__DIR__);
$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (!preg_match('/^--(target|backup)=(.+)$/', $a, $m)) demo_refuse("unknown argument $a (allowed: --target=mulawin_v2 --backup=<file>).");
    $opt[$m[1]] = $m[2];
}
if (($opt['target'] ?? '') !== DEMO_DB) demo_refuse('--target=' . DEMO_DB . ' is required.');

// ---------------------------------------------------------------- backup check (complete, recent, outside the project)
$backup = (string)realpath($opt['backup'] ?? '');
if ($backup === '' || !is_file($backup)) demo_refuse('--backup must point to the mulawin_v2 dump file.');
if (str_starts_with(strtolower($backup), strtolower((string)realpath($ROOT)))) demo_refuse('the backup must be stored outside the project folder.');
if (filesize($backup) < 10000) demo_refuse('the backup file is too small to be a complete dump.');
if (time() - filemtime($backup) > 3 * 3600) demo_refuse('the backup is older than 3 hours. Make a new one first.');
$fh = fopen($backup, 'rb'); $head = (string)fread($fh, 4096); fseek($fh, -400, SEEK_END); $tail = (string)fread($fh, 400); fclose($fh);
if (!str_contains($head, 'Database: ' . DEMO_DB)) demo_refuse('the backup is not a dump of ' . DEMO_DB . '.');
if (!str_contains($tail, '-- Dump completed')) demo_refuse('the backup has no "-- Dump completed" footer (incomplete dump).');

// ---------------------------------------------------------------- the project .env and the live connection
$envDb = '';
foreach (file($ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) if (preg_match('/^\s*DB_NAME\s*=\s*["\']?([^"\'\s]*)/', $l, $m)) $envDb = $m[1];
if ($envDb !== DEMO_DB) demo_refuse('the project .env DB_NAME is not ' . DEMO_DB . '.');
require $ROOT . '/backend/Actions.php';
if (envValue('DB_NAME') !== DEMO_DB) demo_refuse('the application does not resolve DB_NAME=' . DEMO_DB . '.');
$p = db();
if ((string)$p->query('SELECT DATABASE()')->fetchColumn() !== DEMO_DB) demo_refuse('the live connection is not ' . DEMO_DB . '.');

// ---------------------------------------------------------------- still the audited state; single run
if (!is_dir(DEMO_DIR)) demo_refuse(DEMO_DIR . ' does not exist (it holds the backup and the manifest).');
if (glob(DEMO_DIR . '/demo_manifest_*.json')) demo_refuse('a demo manifest already exists in ' . DEMO_DIR . ': the demonstration data was already created once.');
foreach (['maintenance_jobs', 'parts', 'part_requests', 'purchase_orders', 'budgets', 'stock_movements', 'expenses', 'disbursements'] as $t)
    if ((int)$p->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() > 0) demo_refuse("table $t is no longer empty; the database changed since the audit. Review before seeding.");
if ((int)$p->query("SELECT COUNT(*) FROM users WHERE role='finance' AND active=1")->fetchColumn() > 0) demo_refuse('a Finance account already exists.');
$PLATES = ['NDX4821', 'NDX5307', 'NDX6192', 'NDX7318', 'NDX8604', 'NDX9247'];
$MECHS = ['MAGBANUA, ROLANDO', 'OCAMPO, JERRY', 'FLORES, MARCELINO', 'LIM, NESTOR', 'DIZON, ARNOLD'];
$in = fn(array $a) => implode(',', array_fill(0, count($a), '?'));
$s = $p->prepare('SELECT COUNT(*) FROM vehicles WHERE plate_number IN (' . $in($PLATES) . ')'); $s->execute($PLATES);
if ((int)$s->fetchColumn() > 0) demo_refuse('a demo plate number is already registered.');
$s = $p->prepare('SELECT COUNT(*) FROM mechanics WHERE full_name IN (' . $in($MECHS) . ')'); $s->execute($MECHS);
if ((int)$s->fetchColumn() > 0) demo_refuse('a demo mechanic name is already in the masterlist.');

// ---------------------------------------------------------------- baseline: highest id of every table with a single auto-increment key
$pkCols = $p->query("SELECT c.table_name t, c.column_name k FROM information_schema.columns c
    WHERE c.table_schema='" . DEMO_DB . "' AND c.extra LIKE '%auto_increment%' ORDER BY c.table_name")->fetchAll(PDO::FETCH_KEY_PAIR);
$baseline = [];
foreach ($pkCols as $t => $k) $baseline[$t] = (int)$p->query("SELECT COALESCE(MAX(`$k`),0) FROM `$t`")->fetchColumn();
$stamp = date('Ymd_His');
$started = date('Y-m-d H:i:s');
echo "Checks passed: target " . DEMO_DB . ", backup " . basename($backup) . ", audited state, first run.\n";

// ---------------------------------------------------------------- application, accounts, helpers
core('Access'); core('Settings'); core('FleetState');
foreach (['Admin', 'Fleet', 'Maintenance', 'Mechanics', 'Purchasing', 'Finance'] as $mod) Actions::load($mod);
$U = [];
foreach (['admin', 'operations_head', 'motorpool', 'purchasing'] as $role) {
    $r = one($p, 'SELECT id, username, email, full_name, role, shift FROM users WHERE role=? AND active=1 ORDER BY id LIMIT 1', [$role]);
    if (!$r) demo_refuse("no active $role account.");
    $r['id'] = (int)$r['id']; $U[$role] = $r;
}
$BLOCKED = [];
$section = function (string $t): void { echo "\n== $t ==\n"; };
$step = function (string $label, callable $fn) use (&$BLOCKED) {
    try { $r = $fn(); echo "  ok       $label\n"; return $r; }
    catch (BusinessRuleException $e) { $BLOCKED[] = "$label — {$e->httpStatus}: {$e->getMessage()}"; echo "  BLOCKED  $label -> {$e->httpStatus}: {$e->getMessage()}\n"; return null; }
};
$day = fn(int $offset) => date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' days'));
$TMP = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fleetops_demo_' . bin2hex(random_bytes(4)); mkdir($TMP, 0700);
/** A real PNG photo (plain colour, zlib only). */
$png = function (string $name, array $rgb) use ($TMP): array {
    [$w, $h] = [480, 320];
    $raw = str_repeat("\0" . str_repeat(pack('C3', ...$rgb), $w), $h);
    $chunk = fn(string $t, string $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
    $bytes = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress($raw, 9)) . $chunk('IEND', '');
    $path = $TMP . DIRECTORY_SEPARATOR . bin2hex(random_bytes(6)) . '.png'; file_put_contents($path, $bytes);
    return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
};
$withFiles = function (array $files, callable $fn) { $_FILES = $files; try { return $fn(); } finally { $_FILES = []; } };
$adm = $U['admin']; $ops = $U['operations_head']; $mp = $U['motorpool']; $pur = $U['purchasing'];
$fin = null; $T = []; $M = [];
/** Lists every id above the baseline (= every row this run created) and writes the manifest. */
$writeManifest = function () use ($p, $pkCols, &$baseline, $started, $backup, $stamp, &$BLOCKED, &$U, &$fin, &$T, &$M, $PLATES, $MECHS): array {
    $new = [];
    foreach ($pkCols as $t => $k) {
        $ids = array_map('intval', $p->query("SELECT `$k` FROM `$t` WHERE `$k` > " . (int)$baseline[$t] . " ORDER BY `$k`")->fetchAll(PDO::FETCH_COLUMN));
        if ($ids) $new[$t] = $ids;
    }
    // audit_event_changes has no auto-increment key: its rows belong to the new audit events (key audit_event_id).
    $chg = array_map('intval', $p->query('SELECT audit_event_id FROM audit_event_changes WHERE audit_event_id > ' . (int)($baseline['audit_events'] ?? PHP_INT_MAX) . ' ORDER BY audit_event_id')->fetchAll(PDO::FETCH_COLUMN));
    if ($chg) $new['audit_event_changes'] = $chg;
    $manifest = ['database' => DEMO_DB, 'started_at' => $started, 'finished_at' => date('Y-m-d H:i:s'), 'backup' => $backup,
        'note' => 'Rows created by scripts/demo_workflow_seed.php = every id listed under new_ids (all ids above baseline_max_id at the end of the run).',
        'actors' => ['admin' => $U['admin']['id'], 'operations_head' => $U['operations_head']['id'], 'motorpool' => $U['motorpool']['id'],
                     'purchasing' => $U['purchasing']['id'], 'finance (new)' => $fin['id'] ?? null],
        'demo_trucks' => $T ? array_combine(array_slice($PLATES, 0, count($T)), array_values($T)) : [],
        'demo_mechanics' => $M ? array_combine(array_slice($MECHS, 0, count($M)), array_values($M)) : [],
        'baseline_max_id' => $baseline, 'new_ids' => $new, 'blocked' => $BLOCKED];
    $file = DEMO_DIR . DIRECTORY_SEPARATOR . "demo_manifest_$stamp.json";
    file_put_contents($file, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return [$file, $new];
};
/** Stops AFTER records were written: saves the manifest first so every created row stays identifiable. */
$abort = function (string $why) use ($writeManifest): never {
    [$file] = $writeManifest();
    fwrite(STDERR, "STOPPED PART-WAY: $why\nRecords created so far are listed in $file (nothing was deleted).\n");
    exit(4);
};

// =====================================================================
$section('1. Finance account (Admin.user_save, as Admin)');
$finRes = $step('Admin creates the Finance account (Lourdes Aquino)', fn() => Admin::action($adm, 'user_save', ['role' => 'finance', 'full_name' => 'Lourdes Aquino', 'email' => 'laquino@mulawinfleet.example', 'username' => 'laquino']));
if (!$finRes) demo_refuse('the Finance account could not be created (see above); stopped before any other record.');
$pwFile = DEMO_DIR . DIRECTORY_SEPARATOR . "finance_temporary_password_$stamp.txt";
file_put_contents($pwFile, "Mulawin FleetOps (mulawin_v2) - Finance account created by the demonstration script on $started\n"
    . "Username: laquino\nTemporary password: {$finRes['temporary_password']}\nThe app asks for a new password at the first login.\n");
echo "           temporary password saved only to $pwFile\n";
$fin = one($p, 'SELECT id, username, email, full_name, role, shift FROM users WHERE id=?', [(int)$finRes['id']]); $fin['id'] = (int)$fin['id'];

// =====================================================================
$section('2. Six NEW trucks (Fleet.truck_save, as Operations Head)');
$TRUCKS = [['NDX4821', 'MLW-D01', 'Wing Van', 'Hino', '500 Series FG8J'], ['NDX5307', 'MLW-D02', 'Wing Van', 'Isuzu', 'Forward FVR 34'],
           ['NDX6192', 'MLW-D03', 'Wing Van', 'Fuso', 'Fighter FK61'], ['NDX7318', 'MLW-D04', 'Wing Van', 'Isuzu', 'Giga FVM'],
           ['NDX8604', 'MLW-D05', 'Container', 'Hino', '700 Series SH'], ['NDX9247', 'MLW-D06', 'Container', 'Isuzu', 'Giga EXZ']];
foreach ($TRUCKS as $i => [$plate, $no, $type, $make, $model]) {
    $step("add truck $plate ($no, $type)", fn() => Fleet::action($ops, 'truck_save', ['plate_number' => $plate, 'truck_number' => $no, 'truck_type' => $type,
        'make_brand' => $make, 'model' => $model, 'registration_expiry' => $day(150 + $i * 20), 'insurance_provider' => 'Pioneer Insurance & Surety Corp.',
        'insurance_policy_number' => sprintf('CV-%06d-26', 41020 + $i * 77), 'insurance_expiry' => $day(180 + $i * 15)]));
    $T[$i + 1] = (int)(one($p, 'SELECT id FROM vehicles WHERE plate_number=?', [$plate])['id'] ?? 0);
}
if (in_array(0, $T, true)) $abort('a demo truck could not be created (see above).');

// =====================================================================
$section('3. Five NEW mechanics and their attendance (Mechanics.*, as Motorpool)');
foreach ([['MAGBANUA, ROLANDO', 'Air brake and pneumatic systems'], ['OCAMPO, JERRY', 'Cooling system and radiator'], ['FLORES, MARCELINO', 'Transmission and clutch'],
          ['LIM, NESTOR', 'Diesel engine and fuel system'], ['DIZON, ARNOLD', 'Auto electrical and lighting']] as $i => [$name, $spec]) {
    $r = $step("add mechanic $name", fn() => Mechanics::action($mp, 'save', ['full_name' => $name, 'specialization' => $spec, 'contact_number' => sprintf('0919 %03d %04d', 431 + $i * 17, 2210 + $i * 143)]));
    $M[$i + 1] = (int)($r['id'] ?? 0);
}
foreach ([1, 2, 3, 4, 5] as $k) if ($M[$k]) $step("Time In {$MECHS[$k - 1]}", fn() => Mechanics::action($mp, 'time_in', ['id' => $M[$k]]));
if ($M[5]) $step("Time Out {$MECHS[4]} (end of shift)", fn() => Mechanics::action($mp, 'time_out', ['id' => $M[5]]));

// =====================================================================
$section('4. Parts inventory (Purchasing.part_save / adjust) and budget (Finance.budget_set)');
$PARTS = [   // part no., description, category, unit, unit cost, reorder level, opening qty
    ['RAD-HOSE-U-H500', 'Upper radiator hose, Hino 500', 'Cooling system', 'pc', '1450', 2, 6], ['HOSE-CLMP-50', 'Hose clamp, stainless 50 mm', 'Cooling system', 'pc', '85', 10, 2],
    ['COOL-LL-4L', 'Long-life coolant, 4 L', 'Fluids', 'gal', '780', 4, 9], ['BRK-PAD-F-FVR', 'Front brake pad set, Isuzu FVR', 'Brakes', 'set', '3250', 2, 4],
    ['BRK-SHOE-R-H700', 'Rear brake shoe set, Hino 700', 'Brakes', 'set', '2150', 2, 1], ['AIR-DRY-CART', 'Air dryer cartridge, Wabco type', 'Air system', 'pc', '4850', 1, 0],
    ['FUEL-FLT-J08E', 'Fuel filter element, Hino J08E', 'Filters', 'pc', '640', 6, 14], ['OIL-FLT-6HK1', 'Oil filter, Isuzu 6HK1', 'Filters', 'pc', '520', 6, 11],
    ['ENG-OIL-1540', 'Engine oil 15W-40, 18 L pail', 'Fluids', 'pail', '1950', 3, 2], ['ALT-24V-ASSY', 'Alternator assembly, 24 V 60 A', 'Electrical', 'pc', '16800', 0, 0],
    ['HUB-SEAL-R', 'Rear wheel hub oil seal', 'Wheels and hubs', 'pc', '385', 6, 3], ['WIPER-24IN', 'Wiper blade 24 in', 'Body and cab', 'pc', '320', 4, 10],
];
$P = [];
foreach ($PARTS as [$no, $desc, $cat, $unit, $cost, $reorder, $qty]) {
    $step("add part $no", fn() => Purchasing::action($pur, 'part_save', ['part_number' => $no, 'description' => $desc, 'category' => $cat, 'unit' => $unit,
        'unit_cost' => $cost, 'reorder_level' => (string)$reorder, 'warranty_info' => (float)$cost >= 3000 ? '6 months supplier warranty' : '']));
    $P[$no] = (int)(one($p, 'SELECT id FROM parts WHERE part_number=?', [$no])['id'] ?? 0);
    // STOCK_COUNT_RECONCILIATION_V1: part_save starts every part at 0; the demo opening stock is set here, labelled as demo setup.
    if ($qty > 0 && !empty($P[$no])) $step("opening stock $no ($qty, demo setup)", function () use ($p, $pur, $P, $no, $qty) {
        $p->prepare('UPDATE parts SET quantity_on_hand=quantity_on_hand+? WHERE id=?')->execute([$qty, $P[$no]]);
        $p->prepare("INSERT INTO stock_movements (part_id, movement_type, quantity_delta, unit_cost, reason, reference_type, actor_id) SELECT id, 'Adjustment', ?, unit_cost, 'Opening balance (demo setup)', 'adjustment', ? FROM parts WHERE id=?")->execute([$qty, $pur['id'], $P[$no]]);
    });
}
// STOCK_COUNT_RECONCILIATION_V1: the direct adjustment is disabled. When migration 13 exists, the damaged unit is recorded as a
// stock count waiting for Admin review (stock unchanged until posted); otherwise the step is skipped.
if (Purchasing::countsReady($p)) $step('stock count: one damaged unit of COOL-LL-4L (waits for Admin review)', fn() => Purchasing::action($pur, 'count_submit', ['part_id' => $P['COOL-LL-4L'],
    'counted_qty' => (string)max(0, (int)one($p, 'SELECT quantity_on_hand FROM parts WHERE id=?', [$P['COOL-LL-4L']])['quantity_on_hand'] - 1), 'reason' => 'Container cracked in storage']));
$step('Finance sets the purchasing budget for this month (PHP 150,000)', fn() => Finance::action($fin, 'budget_set', ['amount' => '150000', 'period_start' => date('Y-m-01'), 'period_end' => date('Y-m-t')]));

// ---------------------------------------------------------------- workflow helpers (each one = one real action)
$report = function (int $vid, string $problem, int $daysAgo, ?int $mech = null) use ($step, $withFiles, $png, $mp, $day, $p) {
    $plate = one($p, 'SELECT plate_number FROM vehicles WHERE id=?', [$vid])['plate_number'];
    $r = $step("report $plate: " . substr($problem, 0, 55), fn() => $withFiles(['photos' => $png('truck_condition.png', [96, 104, 112])],
        fn() => Maintenance::action($mp, 'report', ['vehicle_id' => $vid, 'problem' => $problem, 'reported_on' => $day(-$daysAgo)] + ($mech ? ['mechanic_id' => $mech] : []))));
    return $r ? (int)$r['job_id'] : 0;
};
$inspect = fn(int $job, string $findings, string $diagnosis) => $job ? $step("inspection recorded (job $job)", fn() => Maintenance::action($mp, 'inspect', ['job_id' => $job, 'findings' => $findings, 'diagnosis' => $diagnosis])) : null;
$cost = fn(int $job, string $type, string $desc, string $amount, int $daysAgo, ?string $provider = null) => $job ? $step("repair cost (job $job): $desc PHP $amount", fn() => Maintenance::action($mp, 'add_cost',
    ['job_id' => $job, 'cost_type' => $type, 'description' => $desc, 'amount' => $amount, 'incurred_on' => $day(-$daysAgo)] + ($provider ? ['provider' => $provider] : []))) : null;
$complete = fn(int $job, string $done, int $daysAgo) => $job ? $step("repair completed (job $job)", fn() => $withFiles(['evidence' => $png('repair_done.png', [74, 120, 92])],
    fn() => Maintenance::action($mp, 'complete', ['job_id' => $job, 'repair_performed' => $done, 'completed_on' => $day(-$daysAgo)]))) : null;
$verify = fn(int $job, string $result, string $notes, ?int $mech = null) => $job ? $step("verification $result (job $job)", fn() => Maintenance::action($mp, 'verify',
    ['job_id' => $job, 'result' => $result, 'verification_notes' => $notes] + ($mech ? ['mechanic_id' => $mech] : []))) : null;
$request = function (int $vid, ?int $job, string $purpose, array $lines) use ($step, $mp, $p) {
    $r = $step("parts request: $purpose", fn() => Purchasing::action($mp, 'request_create', ['vehicle_id' => $vid, 'purpose' => $purpose, 'lines' => $lines] + ($job ? ['maintenance_job_id' => $job] : [])));
    return $r ? (int)one($p, 'SELECT id FROM part_requests WHERE purpose=? ORDER BY id DESC LIMIT 1', [$purpose])['id'] : 0;
};
$lineIds = fn(int $rid) => array_column(many($p, 'SELECT id, part_id FROM part_request_lines WHERE part_request_id=? ORDER BY id', [$rid]), 'id', 'part_id');
$release = fn(int $rid, array $qtyByPart, string $label, ?string $notes = null) => $rid ? $step($label, function () use ($rid, $qtyByPart, $lineIds, $pur, $notes) {
    $l = $lineIds($rid);
    return Purchasing::action($pur, 'request_process', ['request_id' => $rid, 'release' => array_map(fn($pid, $q) => ['line_id' => $l[$pid], 'qty' => $q], array_keys($qtyByPart), $qtyByPart)] + ($notes ? ['notes' => $notes] : []));
}) : null;
$supA = ['supplier_name' => 'Bagong Pag-asa Truck Parts Trading', 'supplier_address' => 'Km. 17 MacArthur Highway, Malinta, Valenzuela City', 'supplier_contact' => 'Rogelio Tan', 'supplier_phone' => '0917 815 2234'];
$supB = ['supplier_name' => 'Silangan Diesel & Brake Supply', 'supplier_address' => 'National Highway, Brgy. Parian, Calamba, Laguna', 'supplier_contact' => 'Liza Mercado', 'supplier_phone' => '0918 402 7716'];
$supC = ['supplier_name' => 'Tri-Star Auto Electrical Center', 'supplier_address' => '1187 Rizal Avenue Extension, Caloocan City', 'supplier_contact' => 'Benjamin Uy', 'supplier_phone' => '0916 550 3381'];
$approverOf = fn(array $o) => $o['approver_role'] === 'finance' ? $fin : $adm;
$po = function (array $sup, string $purpose, array $lines, ?int $reqId = null) use ($step, $pur, $p) {
    $r = $step("PO request: $purpose", fn() => Purchasing::action($pur, 'po_create', $sup + ['purpose' => $purpose, 'lines' => $lines] + ($reqId ? ['part_request_id' => $reqId] : [])));
    return $r ? one($p, 'SELECT id, po_ref, approver_role, total_amount FROM purchase_orders WHERE purpose=? ORDER BY id DESC LIMIT 1', [$purpose]) : null;
};
$decide = fn(?array $o, string $decision, string $notes = '') => $o ? $step(($decision === 'approve' ? 'approve ' : 'reject ') . $o['po_ref'] . ' (' . ($o['approver_role'] === 'finance' ? 'Finance' : 'Admin') . ')',
    fn() => Purchasing::action($approverOf($o), 'po_decide', ['po_id' => $o['id'], 'decision' => $decision] + ($notes !== '' ? ['notes' => $notes] : []))) : null;
$receive = function (?array $o, array $qtyByPart, string $dr) use ($step, $pur, $p, $withFiles, $png) {
    if (!$o) return null;
    $rcv = [];
    foreach (many($p, 'SELECT id, part_id FROM purchase_order_lines WHERE purchase_order_id=?', [$o['id']]) as $l)
        if (isset($qtyByPart[(int)$l['part_id']])) $rcv[] = ['line_id' => $l['id'], 'qty' => $qtyByPart[(int)$l['part_id']]];
    return $step('receive ' . $o['po_ref'] . " (delivery receipt $dr)", fn() => $withFiles(['receipt' => $png('delivery_receipt.png', [228, 226, 220])],
        fn() => Purchasing::action($pur, 'po_receive', ['po_id' => $o['id'], 'receive' => $rcv, 'supplier_invoice_no' => $dr])));
};
$pay = fn(?array $o, string $amount, string $invoice, string $ref) => $o ? $step('Finance pays ' . $o['po_ref'] . " PHP $amount", fn() => $withFiles(['or_doc' => $png('official_receipt.png', [236, 234, 228])],
    fn() => Finance::action($fin, 'po_pay', ['po_id' => $o['id'], 'amount' => $amount, 'supplier_invoice_no' => $invoice, 'payment_method' => 'Bank Transfer',
        'reference_number' => $ref, 'released_on' => date('Y-m-d')]))) : null;

// =====================================================================
$section('5. Repairs in each status (Maintenance.*, as Motorpool)');
$J = [];
$J['reported1'] = $report($T[1], 'Air leak at the rear brake chamber; air pressure drops to zero within 10 minutes of parking', 1);
$J['reported2'] = $report($T[2], 'Left headlamp and left signal light not working; wiring fault suspected behind the bumper', 0);
$J['inspection'] = $report($T[3], 'Hard starting every morning with white smoke for the first five minutes', 2, $M[1]);
$J['repair'] = $report($T[4], 'Engine overheats on uphill runs at STAR Tollway; coolant leaking at the upper radiator hose', 3, $M[2]);
$inspect($J['repair'], 'Upper radiator hose cracked near the thermostat housing; two hose clamps corroded; coolant level low.', 'Replace the upper radiator hose and both clamps, flush and refill the coolant.');
$cost($J['repair'], 'Labor', 'Radiator hose replacement and cooling system flush', '1800', 1);
$J['verify'] = $report($T[5], 'Clutch slipping under load; burning smell after the Batangas run', 5, $M[3]);
$inspect($J['verify'], 'Clutch disc worn to the rivets; pressure plate glazed.', 'Machine shop to resurface the flywheel; replace the clutch disc and pressure plate.');
$cost($J['verify'], 'Outside Service', 'Flywheel resurfacing', '3500', 2, 'JMR Machine Shop, Valenzuela');
$complete($J['verify'], 'Clutch disc and pressure plate replaced; flywheel resurfaced; 15 km road test passed.', 1);
$J['cancelled'] = $report($T[6], 'Wiper motor not working', 0);
if ($J['cancelled']) $step('cancel a duplicate garage report', fn() => Maintenance::action($mp, 'cancel', ['job_id' => $J['cancelled'], 'reason' => 'Duplicate report; the wiper motor was replaced last week']));

// =====================================================================
$section('6. Parts requests (Motorpool) and releases (Purchasing)');
$R = [];
$R['partial'] = $request($T[4], $J['repair'], 'Replace leaking upper radiator hose and clamps', [['part_id' => $P['RAD-HOSE-U-H500'], 'quantity' => 1], ['part_id' => $P['HOSE-CLMP-50'], 'quantity' => 4], ['part_id' => $P['COOL-LL-4L'], 'quantity' => 2]]);
$release($R['partial'], [$P['RAD-HOSE-U-H500'] => 1, $P['HOSE-CLMP-50'] => 2, $P['COOL-LL-4L'] => 2], 'Purchasing releases what is in stock (clamps short: Partially Released)', 'Only 2 clamps on hand; the rest are on order.');
$R['requested'] = $request($T[3], $J['inspection'], 'Fuel filters for the hard-starting diagnosis', [['part_id' => $P['FUEL-FLT-J08E'], 'quantity' => 2]]);
$R['pms'] = $request($T[1], null, 'Oil filters for the next preventive maintenance', [['part_id' => $P['OIL-FLT-6HK1'], 'quantity' => 2]]);
$R['cancelled'] = $request($T[2], null, 'Spare wiper blades for the road kit', [['part_id' => $P['WIPER-24IN'], 'quantity' => 2]]);
if ($R['cancelled']) $step('Motorpool cancels its own request', fn() => Purchasing::action($mp, 'request_cancel', ['request_id' => $R['cancelled']]));

// =====================================================================
$section('7. END-TO-END: shortage -> PO -> Finance approval -> delivery -> release -> payment -> repair -> return to service');
$J['flagship'] = $report($T[6], 'Air dryer purges continuously; water drained from the air tanks every morning', 3, $M[4]);
$inspect($J['flagship'], 'Air dryer cartridge saturated; purge valve sticking; moisture in all air tanks.', 'Replace the air dryer cartridge and clean the purge valve.');
$R['shortage'] = $request($T[6], $J['flagship'], 'Air dryer cartridge for the continuous purging', [['part_id' => $P['AIR-DRY-CART'], 'quantity' => 1]]);
if ($R['shortage']) $step('Purchasing marks the request Not Available (no stock)', fn() => Purchasing::action($pur, 'request_process', ['request_id' => $R['shortage'], 'not_available' => 1, 'notes' => 'Out of stock; PO raised.']));
$O = [];
$O['flagship'] = $po($supB, 'Air dryer cartridges for the purging air dryer', [['part_id' => $P['AIR-DRY-CART'], 'quantity' => 2, 'unit_price' => '4850']], $R['shortage'] ?: null);
$decide($O['flagship'], 'approve');
$receive($O['flagship'], [$P['AIR-DRY-CART'] => 2], 'SDB-DR-08812');
$release($R['shortage'], [$P['AIR-DRY-CART'] => 1], 'Purchasing releases the delivered cartridge to Motorpool (Not Available -> Released)', 'Released from PO delivery SDB-DR-08812.');
$pay($O['flagship'], '9700.00', 'SDB-SI-08812', 'BDO-TRF-55120418');
$cost($J['flagship'], 'Labor', 'Air dryer cartridge replacement and purge valve cleaning', '1200', 0);
$complete($J['flagship'], 'Air dryer cartridge replaced; purge valve cleaned; air tanks drained and checked dry after 2 hours.', 0);
$verify($J['flagship'], 'Passed', 'Air pressure holds overnight; no water in the tanks; brake test passed.');
// The same truck, now back in service, then gets a brake rework whose first verification fails.
$J['failed'] = $report($T[6], 'Brakes pulling to the right at speed; grinding noise from the front axle', 0, $M[4]);
$inspect($J['failed'], 'Front right brake pads worn to the backing plate; caliper slide pin seized.', 'Replace the front brake pads and service the caliper slide pins.');
$R['released'] = $request($T[6], $J['failed'], 'Front brake pads for the brake rework', [['part_id' => $P['BRK-PAD-F-FVR'], 'quantity' => 1]]);
$release($R['released'], [$P['BRK-PAD-F-FVR'] => 1], 'Purchasing releases the full request');
$complete($J['failed'], 'Front brake pads replaced; slide pins cleaned and greased.', 0);
$verify($J['failed'], 'Failed', 'Still pulling to the right on the road test; check the brake chamber push rod adjustment.', $M[4]);

// =====================================================================
$section('8. Other purchase orders: Admin approval, partial delivery, closed balance, pending, rejected, insufficient budget');
$O['admin'] = $po($supC, 'Alternator assembly for the charging fault', [['part_id' => $P['ALT-24V-ASSY'], 'quantity' => 1, 'unit_price' => '16800']]);
$decide($O['admin'], 'approve');
$receive($O['admin'], [$P['ALT-24V-ASSY'] => 1], 'TSE-DR-30471');
$pay($O['admin'], '16800.00', 'TSE-SI-30471', 'BPI-TRF-77310925');
$O['closed'] = $po($supA, 'Engine oil 15W-40 pails for the monthly PMS', [['part_id' => $P['ENG-OIL-1540'], 'quantity' => 6, 'unit_price' => '1950']]);
$decide($O['closed'], 'approve');
$receive($O['closed'], [$P['ENG-OIL-1540'] => 4], 'BPT-DR-14522');
if ($O['closed']) $step('close ' . $O['closed']['po_ref'] . ' balance (supplier cannot deliver the last 2 pails)', fn() => Purchasing::action($pur, 'po_close', ['po_id' => $O['closed']['id'], 'reason' => 'Supplier cannot deliver the remaining 2 pails this month']));
$pay($O['closed'], '7800.00', 'BPT-SI-14522', 'BDO-TRF-55120577');
$O['awaiting'] = $po($supB, 'Rear brake shoe sets for the Hino 700 units', [['part_id' => $P['BRK-SHOE-R-H700'], 'quantity' => 4, 'unit_price' => '2150']]);
$decide($O['awaiting'], 'approve');
$O['pendingFin'] = $po($supA, 'Rear wheel hub oil seals (low stock)', [['part_id' => $P['HUB-SEAL-R'], 'quantity' => 10, 'unit_price' => '385']]);
$O['pendingAdm'] = $po($supA, 'Oil filters for the quarterly PMS of the whole fleet', [['part_id' => $P['OIL-FLT-6HK1'], 'quantity' => 24, 'unit_price' => '520']]);
$O['rejected'] = $po($supC, 'Wiper blades, 24 in', [['part_id' => $P['WIPER-24IN'], 'quantity' => 20, 'unit_price' => '360']]);
$decide($O['rejected'], 'reject', 'Price is higher than the last purchase (PHP 320). Canvass two more suppliers.');
$O['nobudget'] = $po($supC, 'Alternator assemblies for the whole Wing Van fleet', [['part_id' => $P['ALT-24V-ASSY'], 'quantity' => 8, 'unit_price' => '16800']]);
$decide($O['nobudget'], 'approve');   // the existing budget check rejects it automatically when the budget is short

// =====================================================================
$section('Manifest (every new id per table) and summary');
[$mfFile, $new] = $writeManifest();
foreach ($new as $t => $ids) printf("  %-24s %4d new row(s)  ids %d-%d\n", $t, count($ids), $ids[0], end($ids));
echo "  manifest: $mfFile\n";
$c = fn(string $sql) => implode(' | ', array_map(fn($r) => $r['k'] . ': ' . $r['n'], many($p, $sql)));
echo "\n  Repairs          " . $c("SELECT CASE WHEN verified_at IS NOT NULL THEN 'Returned to service' WHEN status='Completed' THEN 'Awaiting verification' ELSE status END k, COUNT(*) n FROM maintenance_jobs GROUP BY k ORDER BY k") . "\n";
echo "  Parts requests   " . $c('SELECT status k, COUNT(*) n FROM part_requests GROUP BY status ORDER BY status') . "\n";
echo "  Purchase orders  " . $c('SELECT status k, COUNT(*) n FROM purchase_orders GROUP BY status ORDER BY status') . "\n";
echo "  PO payment       " . $c("SELECT COALESCE(payment_status,'(none)') k, COUNT(*) n FROM purchase_orders GROUP BY k ORDER BY k") . "\n";
echo "  Budget           " . $c("SELECT CONCAT('amount ', amount, ' / reserved ', reserved_amount, ' / spent') k, spent_amount n FROM budgets WHERE status='Active'") . "\n";
echo "  Expenses         " . $c('SELECT category k, SUM(amount) n FROM expenses GROUP BY category ORDER BY category') . "\n";
echo "  Demo trucks      " . $c('SELECT status k, COUNT(*) n FROM vehicles WHERE id IN (' . implode(',', array_map('intval', $T)) . ') GROUP BY status') . "\n";
echo "  Demo mechanics   " . $c('SELECT availability k, COUNT(*) n FROM mechanics WHERE id IN (' . implode(',', array_map('intval', array_filter($M))) . ') GROUP BY availability') . "\n";
@array_map('unlink', glob($TMP . DIRECTORY_SEPARATOR . '*') ?: []); @rmdir($TMP);
echo "\nBLOCKED steps (existing rules refused them; nothing was forced): " . count($BLOCKED) . "\n";
foreach ($BLOCKED as $b) echo "  - $b\n";
exit($BLOCKED ? 3 : 0);
