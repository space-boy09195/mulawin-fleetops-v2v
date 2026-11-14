<?php
/*
 * =====================================================================
 *  scripts/visual_test_seed.php  —  realistic fictional records for the VISUAL-TESTING copy only
 * =====================================================================
 *  Fills the separate visual-testing database (mulawin_visual_test) so every Motorpool, Purchasing,
 *  Accounting and Operations screen can be checked in a browser with populated, realistic data.
 *  Setup, sync and removal: docs/VISUAL_TEST_ENVIRONMENT.md
 *
 *  RUNS ONLY IN THE VISUAL COPY (C:\xampp\fleetops_visual_test). It refuses to run when ANY check fails:
 *    1. CLI only, and the copy is NOT inside an htdocs folder (the real app lives in htdocs);
 *    2. the .env next to this copy says APP_ENV=visual_test (read literally from the file);
 *    3. DB_NAME is exactly mulawin_visual_test (ends with _test, is not mulawin_v2);
 *    4. the open PDO connection reports SELECT DATABASE() = mulawin_visual_test — checked BEFORE any write;
 *    5. the database is empty (no user accounts yet). --fresh drops and re-creates ONLY mulawin_visual_test.
 *
 *  HOW RECORDS ARE MADE
 *    - Base master data (staff accounts, trucks, drivers / helpers, clients and billing rates) is inserted
 *      directly, the same way the automated test fixtures do it.
 *    - EVERY workflow record and status after that goes through the existing module actions, called as
 *      the role that does the step in real life (Maintenance, Mechanics, Purchasing, Finance, Funds, Trips).
 *      No status column is written by this script. When an existing rule refuses a step, the step is
 *      reported as BLOCKED with the rule's message and the script continues; it never works around it.
 *    - The trip incident follows the real path: create -> fund request, approval, release -> pre-dispatch
 *      checklist (the existing 15-minute minimum duration is WAITED for) -> Confirm Dispatch -> incident ->
 *      Major assessment. Expect the run to take about 16 minutes.
 *    - Names, plates, parts, suppliers and amounts are fictional but realistic. database/seed_masterlists.sql
 *      (real company records) and scripts/seed_test_data.php are NOT used.
 *    - Timestamps are the server time of the run (they are not rewritten). Only dates the forms let the user
 *      choose (date reported, completion date, cost date) are earlier days.
 *
 *  Passwords: one random password per account, written ONLY to VISUAL_LOGINS.txt in the visual copy
 *  (outside Git). They are not printed.
 *
 *  RUN (inside the visual copy):  C:\xampp\php\php.exe scripts\visual_test_seed.php [--fresh | --finish-trip]
 *    --fresh        drop and re-create ONLY mulawin_visual_test, then seed everything again.
 *    --finish-trip  complete the incident path of the ONE interrupted trip (after a stop during the wait):
 *                   needs a seeded database, never re-creates it, creates no other record, re-reads every status
 *                   before each step (no duplicate fund / checklist / dispatch / incident), waits for the timing rules.
 *  Exit codes: 0 done, 2 refused by a safety check (nothing written), 3 done with BLOCKED steps (listed), other = crashed.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

const VT_DB = 'mulawin_visual_test';
const VT_ENV = 'visual_test';
const VT_PROD_DB = 'mulawin_v2';

function vt_refuse(string $why): never { fwrite(STDERR, "REFUSED: $why\nNothing was written.\n"); exit(2); }

$ROOT = dirname(__DIR__);

// ---------------------------------------------------------------- guards 1-3 (before loading the application)
$realRoot = strtolower(str_replace('\\', '/', (string)realpath($ROOT)));
if ($realRoot === '' || str_contains($realRoot . '/', '/htdocs/')) vt_refuse("this copy is inside htdocs ($ROOT). Run it only in the separate visual-testing copy.");
$envFile = $ROOT . '/.env';
if (!is_file($envFile)) vt_refuse("no .env in $ROOT.");
$ENV = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $ENV[trim($k)] = trim($v, " \t\r\n\"'");
}
if (($ENV['APP_ENV'] ?? '') !== VT_ENV) vt_refuse('.env must contain APP_ENV=' . VT_ENV . '.');
if (($ENV['DB_NAME'] ?? '') !== VT_DB) vt_refuse('.env DB_NAME must be exactly ' . VT_DB . '.');
if (!preg_match('/^[a-z0-9_]+_test$/', VT_DB) || strcasecmp(VT_DB, VT_PROD_DB) === 0) vt_refuse('unsafe database name.');

$FRESH_RUN = in_array('--fresh', $argv, true);
$FINISH_TRIP = in_array('--finish-trip', $argv, true);
$unknownOpts = array_diff(array_slice($argv, 1), ['--fresh', '--finish-trip']);
if ($unknownOpts) vt_refuse('unknown option: ' . implode(' ', $unknownOpts) . ' (allowed: --fresh, --finish-trip).');
if ($FRESH_RUN && $FINISH_TRIP) vt_refuse('use --fresh OR --finish-trip, not both. --finish-trip never re-creates the database.');
if ($FRESH_RUN) {
    // Drop and re-create ONLY the visual-testing database (name is the constant checked above).
    $srv = new PDO('mysql:host=' . ($ENV['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($ENV['DB_PORT'] ?? '3306') . ';charset=utf8mb4',
        $ENV['DB_USER'] ?? 'root', $ENV['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $srv->exec('DROP DATABASE IF EXISTS `' . VT_DB . '`');
    $srv->exec('CREATE DATABASE `' . VT_DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $srv = null;
    echo "Re-created the empty database " . VT_DB . " (--fresh).\n";
}

// ---------------------------------------------------------------- load the application of THIS copy
require $ROOT . '/backend/Actions.php';
if (envValue('APP_ENV') !== VT_ENV || envValue('DB_NAME') !== VT_DB) vt_refuse('the application did not load the visual-testing .env.');
try { $p = db(); } catch (Throwable $e) { vt_refuse('cannot connect to ' . VT_DB . ' (create the empty database first): ' . $e->getMessage()); }
// ---------------------------------------------------------------- guard 4: the live connection, before any write
if ((string)$p->query('SELECT DATABASE()')->fetchColumn() !== VT_DB) vt_refuse('the open connection is not ' . VT_DB . '.');

// ---------------------------------------------------------------- guard 5: empty database, then the schema
$tables = (int)$p->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='" . VT_DB . "'")->fetchColumn();
$hasUsersTable = (int)$p->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='" . VT_DB . "' AND table_name='users'")->fetchColumn() > 0;
$userCount = $hasUsersTable ? (int)$p->query('SELECT COUNT(*) FROM users')->fetchColumn() : 0;
if ($FINISH_TRIP) {
    // --finish-trip works on an existing seeded dataset only: no schema import, no re-creation.
    if ($userCount === 0) vt_refuse('--finish-trip needs a seeded ' . VT_DB . ' (no accounts found). Run the full seeding first.');
} else {
    if ($tables > 0) {
        if ($userCount > 0) vt_refuse(VT_DB . ' already has records. Use --fresh to start again, or --finish-trip to complete an interrupted trip.');
        vt_refuse(VT_DB . ' has tables but no accounts (an earlier run stopped half-way). Use --fresh to start again.');
    }
    $import = function (string $rel) use ($p, $ROOT): void {
        $sql = (string)preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($ROOT . '/' . $rel));
        foreach (preg_split('/;\s*\n/', $sql) as $stmt) { $stmt = trim($stmt); if ($stmt !== '') $p->exec($stmt); }
    };
    $import('database/schema.sql');
    $import('database/extension.sql');
    echo "Schema installed in " . VT_DB . " (fresh install: schema.sql + extension.sql).\n";
}

core('Access'); core('Settings'); core('FleetState');
foreach (['Maintenance', 'Mechanics', 'Purchasing', 'Finance', 'Funds', 'Trips'] as $m) Actions::load($m);
require_once $ROOT . '/backend/Modules/Operations/Inspections/Checklist.php';

// ---------------------------------------------------------------- helpers
$BLOCKED = [];
$section = function (string $t): void { echo "\n== $t ==\n"; };
/** Runs one real action; a business-rule refusal is reported (BLOCKED) and the run continues. */
$step = function (string $label, callable $fn) use (&$BLOCKED) {
    try { $r = $fn(); echo "  ok       $label\n"; return $r; }
    catch (BusinessRuleException $e) { $BLOCKED[] = "$label — {$e->httpStatus}: {$e->getMessage()}"; echo "  BLOCKED  $label -> {$e->httpStatus}: {$e->getMessage()}\n"; return null; }
};
$day = fn(int $offset) => date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' days'));
$TMP = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fleetops_visual_' . bin2hex(random_bytes(4));
mkdir($TMP, 0700);
/** A real PNG photo file (plain colour; zlib only, no GD needed). */
$png = function (string $name, array $rgb = [120, 128, 136]) use ($TMP): array {
    $path = $TMP . DIRECTORY_SEPARATOR . bin2hex(random_bytes(6)) . '.png';
    if (function_exists('gzcompress')) {
        [$w, $h] = [480, 320];
        $raw = str_repeat("\0" . str_repeat(pack('C3', ...$rgb), $w), $h);
        $chunk = fn(string $t, string $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
        $bytes = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress($raw, 9)) . $chunk('IEND', '');
    } else {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }
    file_put_contents($path, $bytes);
    return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
};
/** Calls $fn with the given uploaded files (as the browser form would send them), then clears them. */
$withFiles = function (array $files, callable $fn) { $_FILES = $files; try { return $fn(); } finally { $_FILES = []; } };
/** Waits (with progress) for a time-based rule of the application; it never shortens the wait. */
$wait = function (int $seconds, string $why): void {
    $until = time() + max(0, $seconds);
    while (($left = $until - time()) > 0) { echo '  waiting  ' . intdiv($left, 60) . ' min ' . ($left % 60) . " sec ($why)\n"; sleep(min(60, $left)); }
};
$U = [];

/**
 * Completes the incident path of ONE trip from whatever state it is in, through the existing actions only.
 * Each step first re-reads the real status and runs only when that status allows it, so a step that already
 * happened is never repeated (no second fund request, checklist, dispatch or incident). Time rules (minimum
 * checklist duration, Confirm Dispatch window) are waited for, never bypassed. Used by the full run and by --finish-trip.
 */
$finishTrip = function (int $tripId) use ($p, $step, $wait, &$BLOCKED, &$U): void {
    $trip = fn() => one($p, 'SELECT * FROM trips WHERE id=?', [$tripId]);
    $block = function (string $why) use (&$BLOCKED): void { $BLOCKED[] = $why; echo "  BLOCKED  $why\n"; };
    $t = $trip();
    if (!$t) { $block("trip #$tripId not found"); return; }
    $disp = $U[$t['dispatcher_role']] ?? null;
    if (!$disp) { $block('no active ' . $t['dispatcher_role'] . ' account for ' . $t['trip_ref']); return; }
    echo '  trip     ' . $t['trip_ref'] . ' is ' . $t['status'] . " (attempt #{$t['attempt_number']})\n";
    $attempt = (int)$t['attempt_number'];

    // A. Pre-Assigned: checklist opened, fund Released, minimum checklist duration, checklist submitted.
    if ($t['status'] === 'Pre-Assigned') {
        $opened = fn() => one($p, "SELECT started_at FROM checklist_starts WHERE trip_id=? AND attempt_number=? AND kind='Pre-Dispatch'", [$tripId, $attempt]);
        if (!$opened() && !$step('dispatcher opens the pre-dispatch checklist (inspection timer starts)',
            fn() => Trips::action($disp, 'checklist_start', ['trip_id' => $tripId, 'kind' => 'Pre-Dispatch']))) return;
        $fund = fn() => one($p, 'SELECT id, fund_ref, status FROM fund_requests WHERE trip_id=? AND attempt_number=?', [$tripId, $attempt]);
        $f = $fund();
        if (!$f) { $step('dispatcher submits the trip fund request', fn() => Funds::action($disp, 'submit', ['trip_id' => $tripId,
            'categories' => ['Driver Meal Allowance', 'Helper Meal Allowance', 'Passway'], 'notes' => 'NLEX-SLEX Skyway toll and meals'])); $f = $fund(); }
        if ($f && $f['status'] === 'Pending Approval') { $step('Operations Head approves ' . $f['fund_ref'], fn() => Funds::action($U['operations_head'], 'decide', ['fund_id' => $f['id'], 'decision' => 'approve'])); $f = $fund(); }
        if ($f && $f['status'] === 'Ready for Release') { $step('Finance releases ' . $f['fund_ref'] . ' (cash)', fn() => Funds::action($U['finance'], 'release', ['fund_id' => $f['id']])); $f = $fund(); }
        if (!$f || $f['status'] !== 'Released') { $block($t['trip_ref'] . ': trip fund is ' . ($f['status'] ?? 'missing') . ' (needs Released before dispatch)'); return; }
        // Remaining minimum duration, measured with the database clock exactly like the rule (Trips::timerState).
        $left = (int)one($p, "SELECT GREATEST(0, ? * 60 - TIMESTAMPDIFF(SECOND, started_at, NOW())) AS s FROM checklist_starts WHERE trip_id=? AND attempt_number=? AND kind='Pre-Dispatch'",
            [(int)Settings::value('inspection_min_minutes'), $tripId, $attempt])['s'];
        if ($left > 0) $wait($left + 15, 'pre-dispatch checklist minimum duration, existing rule');
        $items = [];
        foreach (Checklist::sections() as $s) foreach ($s['items'] as $i => $label) $items[$s['key'] . '_' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT)] = ['condition' => 'OK', 'remarks' => ''];
        $crew = one($p, 'SELECT v.truck_number, d.full_name AS driver_name FROM trips t LEFT JOIN vehicles v ON v.id=t.vehicle_id LEFT JOIN personnel d ON d.id=t.driver_id WHERE t.id=?', [$tripId]);
        if (!$step('dispatcher submits the pre-dispatch checklist (all OK)', fn() => Trips::action($disp, 'checklist', ['trip_id' => $tripId, 'items' => $items, 'odometer_km' => '184320',
            'fuel_level' => '3/4', 'remarks' => 'Tires and lights checked at the yard.',
            'meta' => ['truck_number' => (string)$crew['truck_number'], 'driver_1' => (string)$crew['driver_name'], 'guard_on_duty' => 'S/G Reynaldo Cabrera']]))) return;
        $t = $trip();
    }

    // B. Ready for Dispatch: Confirm Dispatch once its existing time window is open.
    if ($t['status'] === 'Ready for Dispatch') {
        $opensAt = strtotime(Trips::timeGates($t)['dispatch_opens_at']);
        if ($opensAt - time() > 30 * 60) { $block($t['trip_ref'] . ': Confirm Dispatch opens at ' . date('M j, g:i A', $opensAt) . '; run --finish-trip again after that time'); return; }
        if ($opensAt > time()) $wait($opensAt - time() + 5, 'Confirm Dispatch window, existing rule');
        if (!$step('dispatcher confirms dispatch', fn() => Trips::action($disp, 'dispatch', ['trip_id' => $tripId]))) return;
        $t = $trip();
    }

    // C. On the road without an incident for this attempt: report the breakdown.
    if (in_array($t['status'], ['Dispatched', 'In Transit'], true)) {
        if (!one($p, 'SELECT id FROM incidents WHERE trip_id=? AND attempt_number=?', [$tripId, $attempt])) {
            $driver = one($p, 'SELECT d.full_name FROM trips t LEFT JOIN personnel d ON d.id=t.driver_id WHERE t.id=?', [$tripId])['full_name'] ?? '';
            if (!$step('dispatcher reports a breakdown on the road', fn() => Trips::action($disp, 'incident', ['trip_id' => $tripId, 'incident_type' => 'Breakdown',
                'incident_at' => date('Y-m-d\TH:i'), 'location' => 'SLEX Southbound KM 34, Binan, Laguna', 'persons_involved' => (string)$driver,
                'problem' => 'Engine overheated and lost power', 'details' => 'Temperature gauge in the red after the Calamba exit; coolant steam from the engine bay. Truck parked on the shoulder with early warning devices.']))) return;
        }
        $t = $trip();
    }

    // D. Incident repair still Reported while the trip is On Hold: Motorpool assesses it (Major) with a timed-in Available mechanic.
    $job = one($p, "SELECT id, job_ref, status FROM maintenance_jobs WHERE trip_id=? AND source='Incident' ORDER BY id DESC LIMIT 1", [$tripId]);
    if ($job && $job['status'] === 'Reported' && $t['status'] === 'On Hold - Incident Reported') {
        $mech = one($p, "SELECT mc.id, mc.full_name FROM mechanics mc JOIN mechanic_attendance a ON a.mechanic_id=mc.id AND a.time_out IS NULL
            WHERE mc.active=1 AND mc.availability='Available' ORDER BY mc.id LIMIT 1");
        if (!$mech) { $block($job['job_ref'] . ': no mechanic is timed in and Available for the assessment'); return; }
        $step('Motorpool assesses ' . $job['job_ref'] . ' as MAJOR (mechanic ' . $mech['full_name'] . ')', fn() => Maintenance::action($U['motorpool'], 'assess', ['job_id' => $job['id'], 'severity' => 'Major',
            'recommendation' => 'Tow to the yard; water pump and radiator core to be checked at the motorpool.', 'mechanic_id' => (int)$mech['id']]));
    }
    $t = $trip();
    $inc = one($p, 'SELECT incident_ref, status, severity FROM incidents WHERE trip_id=? ORDER BY id DESC LIMIT 1', [$tripId]);
    $job = one($p, "SELECT job_ref, status FROM maintenance_jobs WHERE trip_id=? AND source='Incident' ORDER BY id DESC LIMIT 1", [$tripId]);
    echo '  result   ' . $t['trip_ref'] . ' is ' . $t['status'] . ($inc ? '; incident ' . $inc['incident_ref'] . ' ' . $inc['status'] . ($inc['severity'] ? ' (' . $inc['severity'] . ')' : '') : '')
        . ($job ? '; repair ' . $job['job_ref'] . ' ' . $job['status'] : '') . "\n";
};

// ---------------------------------------------------------------- --finish-trip: complete ONE interrupted trip, nothing else
if ($FINISH_TRIP) {
    foreach (array_keys(ROLE_LABELS) as $role) {
        $r = one($p, 'SELECT id, username, email, full_name, role, shift FROM users WHERE role=? AND active=1 ORDER BY id LIMIT 1', [$role]);
        if ($r) { $r['id'] = (int)$r['id']; $U[$role] = $r; }
    }
    // Eligible = still on the seeded incident path: before dispatch, on the road without an incident for this attempt,
    // or On Hold with its incident repair not yet assessed. Exactly one is required.
    $eligible = [];
    foreach (many($p, "SELECT t.id, t.trip_ref, t.status, t.attempt_number FROM trips t
            WHERE t.status IN ('Pre-Assigned','Ready for Dispatch','Dispatched','In Transit','On Hold - Incident Reported') ORDER BY t.id") as $t) {
        $hasInc = (bool)one($p, 'SELECT id FROM incidents WHERE trip_id=? AND attempt_number=?', [$t['id'], $t['attempt_number']]);
        $unassessed = (bool)one($p, "SELECT id FROM maintenance_jobs WHERE trip_id=? AND source='Incident' AND status='Reported'", [$t['id']]);
        if (in_array($t['status'], ['Pre-Assigned', 'Ready for Dispatch'], true) || (!$hasInc && in_array($t['status'], ['Dispatched', 'In Transit'], true))
            || ($t['status'] === 'On Hold - Incident Reported' && $unassessed)) $eligible[] = $t;
    }
    if (!$eligible) vt_refuse('no eligible pending trip: the incident path is already complete (or no trip was created). Nothing to finish.');
    if (count($eligible) > 1) vt_refuse('more than one pending trip (' . implode(', ', array_map(fn($t) => $t['trip_ref'] . ' ' . $t['status'], $eligible)) . '); --finish-trip handles exactly one.');
    $section('Finish the interrupted trip ' . $eligible[0]['trip_ref'] . ' (--finish-trip; no other record is created)');
    $finishTrip((int)$eligible[0]['id']);
    echo "\nBLOCKED steps (existing rules refused them; nothing was worked around): " . count($BLOCKED) . "\n";
    foreach ($BLOCKED as $b) echo "  - $b\n";
    @rmdir($TMP);
    exit($BLOCKED ? 3 : 0);
}

// =====================================================================
$section('1. Staff accounts (one per role)');
$staff = [
    'admin' => ['tvillanueva', 'Teresita Villanueva', null], 'operations_head' => ['rmanalo', 'Ricardo Manalo', null],
    'car_carrier_dispatcher' => ['jcastillo', 'Jomar Castillo', 'Day'], 'container_wingvan_dispatcher' => ['rgarcia', 'Rowena Garcia', 'Day'],
    'motorpool' => ['ebautista', 'Ernesto Bautista', null], 'purchasing' => ['msantos', 'Maricel Santos', null],
    'finance' => ['laquino', 'Lourdes Aquino', null], 'billing' => ['jramos', 'Jocelyn Ramos', null], 'audit' => ['amendoza', 'Arnel Mendoza', null],
];
$U = []; $logins = [];
foreach ($staff as $role => [$uname, $full, $shift]) {
    $pw = 'Mlw-' . bin2hex(random_bytes(5));
    $email = "$uname@mulawinfleet.example";
    $id = insert($p, 'users', ['username' => $uname, 'email' => $email, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
        'full_name' => $full, 'role' => $role, 'shift' => $shift]);
    $U[$role] = ['id' => $id, 'username' => $uname, 'email' => $email, 'full_name' => $full, 'role' => $role, 'shift' => $shift];
    $logins[] = sprintf('%-30s %-14s %s', ROLE_LABELS[$role], $uname, $pw);
}
$loginFile = $ROOT . DIRECTORY_SEPARATOR . 'VISUAL_LOGINS.txt';
file_put_contents($loginFile, "FleetOps visual-testing logins (http://127.0.0.1:8081/) — local only, not in Git\n"
    . "Database: " . VT_DB . "   Created: " . date('Y-m-d H:i') . "\n\n" . sprintf('%-30s %-14s %s', 'Role', 'Username', 'Password') . "\n" . implode("\n", $logins) . "\n");
echo "  ok       9 accounts; passwords saved only to $loginFile\n";
$mp = $U['motorpool']; $pur = $U['purchasing']; $fin = $U['finance']; $adm = $U['admin']; $ops = $U['operations_head']; $wvd = $U['container_wingvan_dispatcher'];

// =====================================================================
$section('2. Masterlists: drivers, helpers, trucks, clients (fixtures)');
$people = [];
$drivers = ['DELA CRUZ, JUANITO', 'REYES, ROMMEL', 'SANTIAGO, EDGARDO', 'MENDOZA, JOVITO', 'AQUINO, RENATO', 'DIZON, FERNANDO', 'CRUZ, ALFREDO', 'BAUTISTA, LEONARDO'];
foreach ($drivers as $i => $n) $people['D' . ($i + 1)] = insert($p, 'personnel', ['employee_no' => sprintf('MLW-D-%03d', 11 + $i), 'full_name' => $n, 'person_type' => 'Driver',
    'contact_number' => sprintf('0917 %03d %04d', 210 + $i * 7, 3150 + $i * 113), 'address' => ['Valenzuela City', 'Meycauayan, Bulacan', 'Caloocan City', 'Malabon City', 'Marilao, Bulacan', 'Quezon City', 'Navotas City', 'San Jose del Monte, Bulacan'][$i],
    'license_number' => sprintf('N%02d-%02d-%06d', 1 + $i, 14 + $i, 204317 + $i * 9137), 'license_expiry' => $day(240 + $i * 75), 'date_hired' => $day(-900 - $i * 120)]);
$helpers = ['GARCIA, ARNEL', 'TORRES, JERICHO', 'VILLANUEVA, MARK ANTHONY', 'RAMOS, CHRISTIAN', 'PASCUAL, JOMARI'];
foreach ($helpers as $i => $n) $people['H' . ($i + 1)] = insert($p, 'personnel', ['employee_no' => sprintf('MLW-H-%03d', 21 + $i), 'full_name' => $n, 'person_type' => 'Helper',
    'contact_number' => sprintf('0918 %03d %04d', 330 + $i * 9, 4410 + $i * 127), 'address' => ['Valenzuela City', 'Obando, Bulacan', 'Malabon City', 'Caloocan City', 'Bocaue, Bulacan'][$i], 'date_hired' => $day(-600 - $i * 90)]);
$trucks = [   // plate, unit no., type, make, model, regular driver, regular helper
    ['NBC 4821', 'MLW-021', 'Wing Van', 'Hino', '500 Series FG8J', 'D1', 'H1'], ['ABJ 7305', 'MLW-034', 'Wing Van', 'Isuzu', 'Forward FVR 34', 'D2', 'H2'],
    ['CAH 2196', 'MLW-047', 'Wing Van', 'Fuso', 'Fighter FK61', 'D3', 'H3'], ['NFG 3318', 'MLW-052', 'Wing Van', 'Isuzu', 'Forward FVR 34', null, null],
    ['DAK 6604', 'MLW-058', 'Wing Van', 'Hino', '500 Series FG8J', null, null], ['NCU 9247', 'MLW-063', 'Wing Van', 'Isuzu', 'Giga FVM', null, null],
    ['ABK 1150', 'MLW-071', 'Container', 'Hino', '700 Series SH', 'D4', 'H4'], ['NDT 5532', 'MLW-076', 'Container', 'Isuzu', 'Giga EXZ', null, null],
    ['CBL 8840', 'MLW-082', 'Container', 'Shacman', 'X3000 Tractor', null, null], ['NGH 2077', 'MLW-088', 'Container', 'Hino', '700 Series SH', null, null],
    ['AAQ 4413', 'MLW-093', 'Car Carrier', 'Isuzu', 'Giga CYZ', 'D5', null], ['NEB 7781', 'MLW-097', 'Car Carrier', 'Hino', '700 Series SS', null, null],
    ['DBC 3059', 'MLW-104', 'Car Carrier', 'Fuso', 'Super Great', null, null], ['CAJ 6612', 'MLW-109', 'Car Carrier', 'Isuzu', 'Giga CYZ', null, null],
];
$V = [];
foreach ($trucks as $i => [$plate, $no, $type, $make, $model, $drv, $hlp]) {
    $V[$plate] = insert($p, 'vehicles', ['plate_number' => $plate, 'truck_number' => $no, 'truck_type' => $type, 'make_brand' => $make, 'model' => $model,
        'chassis_number' => sprintf('JHHFG8JR%07d', 4021 + $i * 389), 'engine_number' => sprintf('J08E-%06d', 117283 + $i * 4471),
        'registration_expiry' => $day(120 + $i * 17), 'insurance_provider' => 'Pioneer Insurance & Surety Corp.', 'insurance_policy_number' => sprintf('CV-%06d-26', 30418 + $i * 77),
        'insurance_expiry' => $day(150 + $i * 13), 'assigned_driver_id' => $drv ? $people[$drv] : null, 'assigned_helper_id' => $hlp ? $people[$hlp] : null]);
}
$clients = [
    ['KLY-001', 'KALAYAAN CONSUMER GOODS DISTRIBUTION INC.', 'Kalayaan Distribution', 'Consumer goods distribution', '214-387-905-000', 'Lot 4 Mercedes Ave., Brgy. San Miguel, Pasig City', 'Ma. Cristina Lopez', '0917 642 1180'],
    ['STR-002', 'SAN TELMO AUTO LOGISTICS CORP.', 'San Telmo Auto', 'Vehicle logistics', '318-552-047-000', 'Km. 22 Aguinaldo Highway, Imus, Cavite', 'Paolo Hernandez', '0918 233 5091'],
];
$C = [];
foreach ($clients as [$code, $name, $trade, $nature, $tin, $addr, $contact, $phone]) {
    $cid = insert($p, 'clients', ['client_code' => $code, 'company_name' => $name, 'trade_name' => $trade, 'nature_of_business' => $nature, 'tin' => $tin,
        'business_address' => $addr, 'contact_person' => $contact, 'contact_number' => $phone, 'payment_terms_days' => 30, 'status' => 'Active',
        'created_by' => $ops['id'], 'rate_set_by' => $U['billing']['id'], 'rate_set_at' => date('Y-m-d H:i:s')]);
    foreach (['Wing Van' => '62.00', 'Container' => '78.00', 'Car Carrier' => '85.00'] as $type => $rate)
        insert($p, 'client_rates', ['client_id' => $cid, 'truck_type' => $type, 'rate_per_km' => $rate, 'minimum_charge' => '3500.00', 'updated_by' => $U['billing']['id']]);
    $C[] = $cid;
}
echo "  ok       8 drivers, 5 helpers, 14 trucks, 2 clients with billing rates\n";

// =====================================================================
$section('3. Mechanics (Mechanics.save, Time In / Time Out, deactivate)');
$M = [];
foreach ([['PASCUAL, RODEL', 'Diesel engine overhaul'], ['DIZON, ARNOLD', 'Brakes and suspension'], ['TORRES, VIRGILIO', 'Auto electrical and lighting'],
          ['LIM, NESTOR', 'Air brake and pneumatic systems'], ['SALAZAR, BENJIE', 'Cooling system and radiator'], ['MAGBANUA, ROLANDO', 'Engine tune-up and fuel system'],
          ['ESPIRITU, DANILO', 'Welding and body repair'], ['OCAMPO, JERRY', 'Tires and wheel alignment'], ['FLORES, MARCELINO', 'Transmission and clutch']] as $i => [$name, $spec]) {
    $r = $step("add mechanic $name", fn() => Mechanics::action($mp, 'save', ['full_name' => $name, 'specialization' => $spec, 'contact_number' => sprintf('0919 %03d %04d', 120 + $i * 11, 2040 + $i * 151)]));
    $M[$i + 1] = $r['id'] ?? null;
}
foreach ([1, 2, 3, 4, 5, 6, 7] as $k) if ($M[$k]) $step("Time In {$k}", fn() => Mechanics::action($mp, 'time_in', ['id' => $M[$k]]));
if ($M[9]) $step('deactivate mechanic 9 (resigned)', fn() => Mechanics::action($mp, 'set_active', ['id' => $M[9], 'active' => 0]));

// =====================================================================
$section('4. Trip for the incident path (Operations): create, fund, pre-dispatch checklist opened');
$schedAt = time() + 20 * 60;
$trip = $step('Operations Head creates a Wing Van trip (auto-assigned)', fn() => Trips::action($ops, 'create', ['client_id' => $C[0], 'truck_type' => 'Wing Van',
    'consignee' => 'Kalayaan Distribution - Batangas DC', 'pickup_point' => 'Pier 4, North Harbor, Tondo, Manila', 'delivery_point' => 'Lima Technology Center, Malvar, Batangas',
    'scheduled_at' => date('Y-m-d\TH:i', $schedAt), 'distance_km' => '98', 'delivery_details' => '18 pallets of canned goods and bottled water', 'shipment_number' => 'KCG-DR-260471']));
$tripId = $trip ? (int)$trip['trip_id'] : 0;
$tripRow = $tripId ? one($p, 'SELECT * FROM trips WHERE id=?', [$tripId]) : null;
if ($tripRow && $tripRow['status'] === 'Pre-Assigned') {
    // Opened early so the existing 15-minute minimum runs while the rest of the dataset is created.
    $step('dispatcher opens the pre-dispatch checklist (inspection timer starts)', fn() => Trips::action($wvd, 'checklist_start', ['trip_id' => $tripId, 'kind' => 'Pre-Dispatch']));
    $fund = $step('dispatcher submits the trip fund request', fn() => Funds::action($wvd, 'submit', ['trip_id' => $tripId,
        'categories' => ['Driver Meal Allowance', 'Helper Meal Allowance', 'Passway'], 'notes' => 'NLEX-SLEX Skyway toll and meals']));
    if ($fund) {
        $step('Operations Head approves the fund request', fn() => Funds::action($ops, 'decide', ['fund_id' => $fund['fund_id'], 'decision' => 'approve']));
        $step('Finance releases the trip fund (cash)', fn() => Funds::action($fin, 'release', ['fund_id' => $fund['fund_id']]));
    }
} elseif ($tripRow) {
    $BLOCKED[] = 'trip ' . $tripRow['trip_ref'] . ' is ' . $tripRow['status'] . ' (auto-assignment found no free truck or crew); incident path skipped';
    echo "  BLOCKED  trip is {$tripRow['status']}; incident path skipped\n";
}
$tripTruck = $tripRow['vehicle_id'] ?? null;

// =====================================================================
$section('5. Parts inventory and purchasing budget');
$parts = [   // part no., description, category, unit, unit cost, reorder level, opening qty
    ['RAD-HOSE-U-H500', 'Upper radiator hose, Hino 500', 'Cooling system', 'pc', '1450', 2, 6], ['HOSE-CLMP-50', 'Hose clamp, stainless 50 mm', 'Cooling system', 'pc', '85', 10, 2],
    ['COOL-LL-4L', 'Long-life coolant, 4 L', 'Fluids', 'gal', '780', 4, 9], ['BRK-PAD-F-FVR', 'Front brake pad set, Isuzu FVR', 'Brakes', 'set', '3250', 2, 4],
    ['BRK-SHOE-R-H700', 'Rear brake shoe set, Hino 700', 'Brakes', 'set', '2150', 2, 1], ['AIR-DRY-CART', 'Air dryer cartridge, Wabco type', 'Air system', 'pc', '4850', 1, 0],
    ['BRK-CHMB-30', 'Brake chamber, type 30/30', 'Air system', 'pc', '3680', 1, 2], ['FUEL-FLT-J08E', 'Fuel filter element, Hino J08E', 'Filters', 'pc', '640', 6, 14],
    ['OIL-FLT-6HK1', 'Oil filter, Isuzu 6HK1', 'Filters', 'pc', '520', 6, 11], ['ENG-OIL-1540', 'Engine oil 15W-40, 18 L pail', 'Fluids', 'pail', '1950', 3, 2],
    ['ALT-24V-BRUSH', 'Alternator brush set, 24 V', 'Electrical', 'set', '850', 2, 3], ['ALT-24V-ASSY', 'Alternator assembly, 24 V 60 A', 'Electrical', 'pc', '16800', 0, 0],
    ['HEADLAMP-H500', 'Headlamp assembly LH, Hino 500', 'Electrical', 'pc', '4200', 1, 1], ['UBOLT-LS-22', 'Leaf spring U-bolt with nuts, 22 mm', 'Suspension', 'pc', '390', 8, 20],
    ['LEAF-SPR-ASSY', 'Rear leaf spring assembly, Isuzu Giga', 'Suspension', 'pc', '9800', 0, 0], ['HUB-SEAL-R', 'Rear wheel hub oil seal', 'Wheels and hubs', 'pc', '385', 6, 3],
    ['WIPER-24IN', 'Wiper blade 24 in', 'Body and cab', 'pc', '320', 4, 10], ['TIRE-1100R20', 'Tire 11.00R20 16PR, all-position', 'Tires', 'pc', '21500', 2, 4],
];
$P = [];
foreach ($parts as [$no, $desc, $cat, $unit, $cost, $reorder, $qty]) {
    $step("add part $no", fn() => Purchasing::action($pur, 'part_save', ['part_number' => $no, 'description' => $desc, 'category' => $cat, 'unit' => $unit,
        'unit_cost' => $cost, 'reorder_level' => (string)$reorder, 'warranty_info' => $cost >= 3000 ? '6 months supplier warranty' : '']));
    $P[$no] = (int)(one($p, 'SELECT id FROM parts WHERE part_number=?', [$no])['id'] ?? 0);
    // STOCK_COUNT_RECONCILIATION_V1: part_save starts every part at 0; the demo opening stock is set here, labelled as demo setup.
    if ($qty > 0 && !empty($P[$no])) $step("opening stock $no ($qty, demo setup)", function () use ($p, $pur, $P, $no, $qty) {
        $p->prepare('UPDATE parts SET quantity_on_hand=quantity_on_hand+? WHERE id=?')->execute([$qty, $P[$no]]);
        $p->prepare("INSERT INTO stock_movements (part_id, movement_type, quantity_delta, unit_cost, reason, reference_type, actor_id) SELECT id, 'Adjustment', ?, unit_cost, 'Opening balance (demo setup)', 'adjustment', ? FROM parts WHERE id=?")->execute([$qty, $pur['id'], $P[$no]]);
    });
}
$step('Finance sets the purchasing budget for this month (PHP 150,000)', fn() => Finance::action($fin, 'budget_set', ['amount' => '150000',
    'period_start' => date('Y-m-01'), 'period_end' => date('Y-m-t')]));
// STOCK_COUNT_RECONCILIATION_V1: the direct adjustment is disabled. When migration 13 exists, the damaged unit is recorded as a
// stock count waiting for Admin review (stock unchanged until posted); otherwise the step is skipped.
if (Purchasing::countsReady($p)) $step('stock count: one damaged unit of BRK-CHMB-30 (waits for Admin review)', fn() => Purchasing::action($pur, 'count_submit', ['part_id' => $P['BRK-CHMB-30'],
    'counted_qty' => (string)max(0, (int)one($p, 'SELECT quantity_on_hand FROM parts WHERE id=?', [$P['BRK-CHMB-30']])['quantity_on_hand'] - 1), 'reason' => 'Damaged in storage (cracked housing)']));

// =====================================================================
$section('6. Repairs (Motorpool) in every supported state');
$free = array_values(array_filter(array_keys($V), fn($pl) => $V[$pl] !== (int)$tripTruck));
$truck = fn(int $i) => $V[$free[$i]];
$report = function (int $vid, string $problem, int $daysAgo, ?int $mech = null) use ($step, $withFiles, $png, $mp, $day, $p) {
    $r = $step('report: ' . one($p, 'SELECT plate_number FROM vehicles WHERE id=?', [$vid])['plate_number'] . ' - ' . substr($problem, 0, 50),
        fn() => $withFiles(['photos' => $png('truck_condition.png', [96, 104, 112])], fn() => Maintenance::action($mp, 'report',
            ['vehicle_id' => $vid, 'problem' => $problem, 'reported_on' => $day(-$daysAgo)] + ($mech ? ['mechanic_id' => $mech] : []))));
    return $r ? (int)$r['job_id'] : 0;
};
$inspect = fn(int $job, string $findings, string $diagnosis) => $job ? $step("inspection findings for job $job", fn() => Maintenance::action($mp, 'inspect',
    ['job_id' => $job, 'findings' => $findings, 'diagnosis' => $diagnosis])) : null;
$cost = fn(int $job, string $type, string $desc, string $amount, int $daysAgo, ?string $provider = null) => $job ? $step("repair cost for job $job: $desc", fn() => Maintenance::action($mp, 'add_cost',
    ['job_id' => $job, 'cost_type' => $type, 'description' => $desc, 'amount' => $amount, 'incurred_on' => $day(-$daysAgo)] + ($provider ? ['provider' => $provider] : []))) : null;
$complete = fn(int $job, string $done, int $daysAgo) => $job ? $step("repair completed for job $job", fn() => $withFiles(['evidence' => $png('repair_done.png', [74, 120, 92])],
    fn() => Maintenance::action($mp, 'complete', ['job_id' => $job, 'repair_performed' => $done, 'completed_on' => $day(-$daysAgo)]))) : null;

$J = [];
$J['reported1'] = $report($truck(0), 'Air leak at the rear brake chamber; air pressure drops to zero within 10 minutes of parking', 1);
$J['reported2'] = $report($truck(1), 'Left headlamp and left signal light not working; driver suspects a wiring fault behind the bumper', 0);
$J['inspection'] = $report($truck(2), 'Hard starting every morning with white smoke for the first five minutes', 2, $M[1]);
$J['repair1'] = $report($truck(3), 'Engine overheats on uphill runs at STAR Tollway; coolant leaking at the upper radiator hose', 3, $M[2]);
$inspect($J['repair1'], 'Upper radiator hose cracked near the thermostat housing; two hose clamps corroded; coolant level low.', 'Replace the upper radiator hose and both clamps, flush and refill the coolant.');
$cost($J['repair1'], 'Labor', 'Radiator hose replacement and cooling system flush', '1800', 1);
$J['repair2'] = $report($truck(4), 'Air dryer purges continuously; water drained from the air tanks every morning', 3, $M[3]);
$inspect($J['repair2'], 'Air dryer cartridge saturated; purge valve sticking; moisture in all air tanks.', 'Replace the air dryer cartridge and clean the purge valve.');
$J['verify'] = $report($truck(5), 'Clutch slipping under load; burning smell after the Batangas run', 5, $M[4]);
$inspect($J['verify'], 'Clutch disc worn to the rivets; pressure plate glazed.', 'Machine shop to resurface the flywheel; replace the clutch disc and pressure plate.');
$cost($J['verify'], 'Outside Service', 'Flywheel resurfacing', '3500', 2, 'JMR Machine Shop, Valenzuela');
$complete($J['verify'], 'Clutch disc and pressure plate replaced; flywheel resurfaced; road test 15 km passed.', 1);
$J['returned'] = $report($truck(6), 'Rear wheel hub leaking oil; brake lining contaminated', 6, $M[5]);
$inspect($J['returned'], 'Rear hub oil seal worn; brake shoes soaked with gear oil.', 'Replace the rear hub oil seal and the rear brake shoes.');
$cost($J['returned'], 'Labor', 'Hub seal and brake shoe replacement', '2200', 3);
$complete($J['returned'], 'Rear hub seal and brake shoes replaced; brakes adjusted and tested.', 2);
if ($J['returned']) $step('verification passed: truck returned to service', fn() => Maintenance::action($mp, 'verify', ['job_id' => $J['returned'], 'result' => 'Passed',
    'verification_notes' => 'Brake test on the yard ramp passed; no oil at the hub after a 20 km road test.']));
$J['failed'] = $report($truck(7), 'Brakes pulling to the right at speed; grinding noise from the front axle', 4, $M[4]);
$inspect($J['failed'], 'Front right brake pads worn to the backing plate; caliper slide pin seized.', 'Replace the front brake pads and service the caliper slide pins.');
$complete($J['failed'], 'Front brake pads replaced; slide pins cleaned and greased.', 1);
if ($J['failed']) $step('verification failed: back to repair', fn() => Maintenance::action($mp, 'verify', ['job_id' => $J['failed'], 'result' => 'Failed',
    'verification_notes' => 'Still pulling to the right on the road test; check the brake chamber and the push rod adjustment.', 'mechanic_id' => $M[4]]));
$J['cancelled'] = $report($truck(8), 'Wiper motor not working', 0);
if ($J['cancelled']) $step('cancel a duplicate garage report', fn() => Maintenance::action($mp, 'cancel', ['job_id' => $J['cancelled'], 'reason' => 'Duplicate report; the wiper motor was already replaced last week']));

// =====================================================================
$section('7. Parts requests (Motorpool) and processing (Purchasing)');
$req = function (int $vid, ?int $job, string $purpose, array $lines) use ($step, $mp, $p) {
    $r = $step("parts request: $purpose", fn() => Purchasing::action($mp, 'request_create', ['vehicle_id' => $vid, 'purpose' => $purpose, 'lines' => $lines] + ($job ? ['maintenance_job_id' => $job] : [])));
    return $r ? (int)one($p, 'SELECT id FROM part_requests WHERE purpose=? ORDER BY id DESC LIMIT 1', [$purpose])['id'] : 0;
};
$lineIds = fn(int $rid) => array_column(many($p, 'SELECT id, part_id FROM part_request_lines WHERE part_request_id=? ORDER BY id', [$rid]), 'id', 'part_id');
$R = [];
$R['partial'] = $req($truck(3), $J['repair1'], 'Replace leaking upper radiator hose and clamps', [['part_id' => $P['RAD-HOSE-U-H500'], 'quantity' => 1], ['part_id' => $P['HOSE-CLMP-50'], 'quantity' => 4], ['part_id' => $P['COOL-LL-4L'], 'quantity' => 2]]);
if ($R['partial']) { $l = $lineIds($R['partial']);
    $step('Purchasing releases what is in stock (clamps short)', fn() => Purchasing::action($pur, 'request_process', ['request_id' => $R['partial'], 'notes' => 'Only 2 clamps on hand; the rest are on order.',
        'release' => [['line_id' => $l[$P['RAD-HOSE-U-H500']], 'qty' => 1], ['line_id' => $l[$P['HOSE-CLMP-50']], 'qty' => 2], ['line_id' => $l[$P['COOL-LL-4L']], 'qty' => 2]]])); }
$R['notavail'] = $req($truck(4), $J['repair2'], 'Air dryer cartridge for the continuous purging', [['part_id' => $P['AIR-DRY-CART'], 'quantity' => 1]]);
if ($R['notavail']) $step('Purchasing marks it Not Available (no stock)', fn() => Purchasing::action($pur, 'request_process', ['request_id' => $R['notavail'], 'not_available' => 1, 'notes' => 'Out of stock; PO to be raised.']));
$R['pending1'] = $req($truck(2), $J['inspection'], 'Fuel filters for the hard-starting diagnosis', [['part_id' => $P['FUEL-FLT-J08E'], 'quantity' => 2]]);
$R['released'] = $req($truck(7), $J['failed'], 'Front brake pads for the brake rework', [['part_id' => $P['BRK-PAD-F-FVR'], 'quantity' => 1]]);
if ($R['released']) { $l = $lineIds($R['released']);
    $step('Purchasing releases the full request', fn() => Purchasing::action($pur, 'request_process', ['request_id' => $R['released'], 'release' => [['line_id' => $l[$P['BRK-PAD-F-FVR']], 'qty' => 1]]])); }
$R['cancelled'] = $req($truck(9), null, 'Spare wiper blades for the road kit', [['part_id' => $P['WIPER-24IN'], 'quantity' => 2]]);
if ($R['cancelled']) $step('Motorpool cancels its own request', fn() => Purchasing::action($mp, 'request_cancel', ['request_id' => $R['cancelled']]));
// Not linked to a repair: that record is still Reported, and request_create links only repairs Under Inspection / Under Repair.
$R['pending2'] = $req($truck(0), null, 'Brake chamber for the air leak at the rear axle', [['part_id' => $P['BRK-CHMB-30'], 'quantity' => 1]]);

// =====================================================================
$section('8. Purchase orders (Purchasing), approvals (Finance / Admin), deliveries and payments');
$supA = ['supplier_name' => 'Bagong Pag-asa Truck Parts Trading', 'supplier_address' => 'Km. 17 MacArthur Highway, Malinta, Valenzuela City', 'supplier_contact' => 'Rogelio Tan', 'supplier_phone' => '0917 815 2234'];
$supB = ['supplier_name' => 'Silangan Diesel & Brake Supply', 'supplier_address' => 'National Highway, Brgy. Parian, Calamba, Laguna', 'supplier_contact' => 'Liza Mercado', 'supplier_phone' => '0918 402 7716'];
$supC = ['supplier_name' => 'Tri-Star Auto Electrical Center', 'supplier_address' => '1187 Rizal Avenue Extension, Caloocan City', 'supplier_contact' => 'Benjamin Uy', 'supplier_phone' => '0916 550 3381'];
$po = function (array $sup, string $purpose, array $lines, ?int $reqId = null) use ($step, $pur, $p) {
    $r = $step("PO request: $purpose", fn() => Purchasing::action($pur, 'po_create', $sup + ['purpose' => $purpose, 'lines' => $lines] + ($reqId ? ['part_request_id' => $reqId] : [])));
    return $r ? one($p, 'SELECT id, po_ref, approver_role, total_amount FROM purchase_orders WHERE purpose=? ORDER BY id DESC LIMIT 1', [$purpose]) : null;
};
$decide = fn(?array $o, string $decision, string $notes = '') => $o ? $step(($decision === 'approve' ? 'approve ' : 'reject ') . $o['po_ref'] . ' (' . $o['approver_role'] . ')',
    fn() => Purchasing::action($U[$o['approver_role']], 'po_decide', ['po_id' => $o['id'], 'decision' => $decision] + ($notes !== '' ? ['notes' => $notes] : []))) : null;
$receive = function (?array $o, array $qtyByPart, string $invoice) use ($step, $pur, $p, $withFiles, $png) {
    if (!$o) return null;
    $lines = many($p, 'SELECT id, part_id FROM purchase_order_lines WHERE purchase_order_id=?', [$o['id']]);
    $rcv = array_values(array_filter(array_map(fn($l) => isset($qtyByPart[(int)$l['part_id']]) ? ['line_id' => $l['id'], 'qty' => $qtyByPart[(int)$l['part_id']]] : null, $lines)));
    return $step('receive ' . $o['po_ref'] . " (DR $invoice)", fn() => $withFiles(['receipt' => $png('delivery_receipt.png', [228, 226, 220])],
        fn() => Purchasing::action($pur, 'po_receive', ['po_id' => $o['id'], 'receive' => $rcv, 'supplier_invoice_no' => $invoice])));
};
$pay = fn(?array $o, string $amount, string $invoice, string $ref) => $o ? $step('Finance pays ' . $o['po_ref'] . " PHP $amount", fn() => $withFiles(['or_doc' => $png('official_receipt.png', [236, 234, 228])],
    fn() => Finance::action($fin, 'po_pay', ['po_id' => $o['id'], 'amount' => $amount, 'supplier_invoice_no' => $invoice, 'payment_method' => 'Bank Transfer',
        'reference_number' => $ref, 'released_on' => date('Y-m-d')]))) : null;

$O = [];
$O['partial'] = $po($supB, 'Air dryer cartridges for the purging air dryer', [['part_id' => $P['AIR-DRY-CART'], 'quantity' => 2, 'unit_price' => '4850']], $R['notavail'] ?: null);
$decide($O['partial'], 'approve');
$receive($O['partial'], [$P['AIR-DRY-CART'] => 1], 'SDB-DR-08812');
$pay($O['partial'], '4850.00', 'SDB-SI-08812', 'BDO-TRF-55120418');
$O['paid'] = $po($supC, 'Alternator assembly and brush sets for the charging fault', [['part_id' => $P['ALT-24V-ASSY'], 'quantity' => 1, 'unit_price' => '16800'], ['part_id' => $P['ALT-24V-BRUSH'], 'quantity' => 2, 'unit_price' => '850']]);
$decide($O['paid'], 'approve');
$receive($O['paid'], [$P['ALT-24V-ASSY'] => 1, $P['ALT-24V-BRUSH'] => 2], 'TSE-DR-30471');
$pay($O['paid'], '18500.00', 'TSE-SI-30471', 'BPI-TRF-77310925');
$O['closed'] = $po($supA, 'Engine oil 15W-40 pails for the monthly PMS', [['part_id' => $P['ENG-OIL-1540'], 'quantity' => 6, 'unit_price' => '1950']]);
$decide($O['closed'], 'approve');
$receive($O['closed'], [$P['ENG-OIL-1540'] => 4], 'BPT-DR-14522');
if ($O['closed']) $step('close ' . $O['closed']['po_ref'] . ' balance (supplier out of stock)', fn() => Purchasing::action($pur, 'po_close', ['po_id' => $O['closed']['id'],
    'reason' => 'Supplier cannot deliver the remaining 2 pails this month']));
$pay($O['closed'], '7800.00', 'BPT-SI-14522', 'BDO-TRF-55120577');
$O['approved'] = $po($supB, 'Rear brake shoe sets for the Hino 700 units', [['part_id' => $P['BRK-SHOE-R-H700'], 'quantity' => 4, 'unit_price' => '2150']]);
$decide($O['approved'], 'approve');
$O['pendingFin'] = $po($supA, 'Rear wheel hub oil seals (low stock)', [['part_id' => $P['HUB-SEAL-R'], 'quantity' => 10, 'unit_price' => '385']]);
$O['pendingAdm'] = $po($supA, 'Rear leaf spring assemblies for the Isuzu Giga units', [['part_id' => $P['LEAF-SPR-ASSY'], 'quantity' => 2, 'unit_price' => '9800']]);
$O['rejected'] = $po($supC, 'Headlamp assemblies, left and right', [['part_id' => $P['HEADLAMP-H500'], 'quantity' => 2, 'unit_price' => '4600']]);
$decide($O['rejected'], 'reject', 'Price is higher than the last purchase (PHP 4,200). Canvass two more suppliers.');
$O['nobudget'] = $po($supB, 'Replacement tires 11.00R20 for the container fleet', [['part_id' => $P['TIRE-1100R20'], 'quantity' => 6, 'unit_price' => '21500']]);
$decide($O['nobudget'], 'approve');   // the existing budget check rejects it automatically when the budget is short

// =====================================================================
$section('9. Mechanic attendance: Time Out');
if ($M[7]) $step('Time Out mechanic 7 (end of shift)', fn() => Mechanics::action($mp, 'time_out', ['id' => $M[7]]));

// =====================================================================
$section('10. Trip incident path (waits for the existing minimum checklist duration)');
// Same routine as --finish-trip: if the run is interrupted during the wait, --finish-trip continues from here.
if ($tripRow && $tripRow['status'] === 'Pre-Assigned') {
    echo "  note     if this run is stopped during the wait, finish it later with: php scripts\\visual_test_seed.php --finish-trip\n";
    $finishTrip($tripId);
} else {
    echo "  skipped  (the trip could not be pre-assigned; see BLOCKED)\n";
}

// =====================================================================
$section('Summary (read from the database)');
$count = fn(string $sql) => array_map(fn($r) => $r['k'] . ': ' . $r['n'], many($p, $sql));
$show = fn(string $label, array $rows) => print('  ' . str_pad($label, 26) . ($rows ? implode(' | ', $rows) : '-') . "\n");
$show('Repairs', $count("SELECT CASE WHEN verified_at IS NOT NULL THEN 'Returned to service' WHEN status='Completed' THEN 'Awaiting verification' ELSE status END AS k, COUNT(*) n FROM maintenance_jobs GROUP BY k ORDER BY k"));
$show('Mechanics', $count("SELECT IF(active=1, availability, 'Inactive') AS k, COUNT(*) n FROM mechanics GROUP BY k ORDER BY k"));
$show('Mechanics timed in now', $count("SELECT 'timed in' AS k, COUNT(*) n FROM mechanic_attendance WHERE time_out IS NULL"));
$show('Parts requests', $count('SELECT status AS k, COUNT(*) n FROM part_requests GROUP BY status ORDER BY status'));
$show('Low-stock parts', $count("SELECT 'at or below reorder level' AS k, COUNT(*) n FROM parts WHERE active=1 AND quantity_on_hand<=reorder_level"));
$show('Stock movements', $count('SELECT movement_type AS k, COUNT(*) n FROM stock_movements GROUP BY movement_type ORDER BY movement_type'));
$show('Purchase orders', $count('SELECT status AS k, COUNT(*) n FROM purchase_orders GROUP BY status ORDER BY status'));
$show('PO payment status', $count("SELECT COALESCE(payment_status, '(none)') AS k, COUNT(*) n FROM purchase_orders GROUP BY k ORDER BY k"));
$show('Budget (this month)', $count("SELECT CONCAT('amount ', amount, ', reserved ', reserved_amount, ', spent') AS k, spent_amount AS n FROM budgets WHERE status='Active'"));
$show('Expenses', $count('SELECT category AS k, COUNT(*) n FROM expenses GROUP BY category ORDER BY category'));
$show('Trucks', $count('SELECT status AS k, COUNT(*) n FROM vehicles GROUP BY status ORDER BY status'));
$show('Trips', $count('SELECT status AS k, COUNT(*) n FROM trips GROUP BY status ORDER BY status'));
$show('Incidents', $count('SELECT status AS k, COUNT(*) n FROM incidents GROUP BY status ORDER BY status'));

@array_map('unlink', glob($TMP . DIRECTORY_SEPARATOR . '*') ?: []); @rmdir($TMP);
echo "\nBLOCKED steps (existing rules refused them; nothing was worked around): " . count($BLOCKED) . "\n";
foreach ($BLOCKED as $b) echo "  - $b\n";
echo "\nLogins: $loginFile\nOpen http://127.0.0.1:8081/ after starting the visual-testing server (docs/VISUAL_TEST_ENVIRONMENT.md).\n";
exit($BLOCKED ? 3 : 0);
