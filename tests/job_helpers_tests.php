<?php
/*
 * =====================================================================
 *  tests/job_helpers_tests.php  —  JOB_HELPER_MECHANICS_V1 (more than one mechanic per repair)
 * =====================================================================
 *  Do NOT run this file directly: run tests/run_job_helpers_tests.php (temporary copy, own .env,
 *  disposable *_test databases only).
 *
 *    A. Before migration 12 (table absent): every existing flow works; helpers_add / helper_remove refuse clearly
 *    B. Migration 12 imported on that database = the fresh-install table (schema fingerprint), no existing row changed
 *    C. Add assisting mechanics: rules (lead first, active, timed in, not busy, no duplicates, all-or-nothing), roles
 *    D. Busy everywhere: lead assignment, Time Out, deactivation and the boards treat an assisting mechanic as busy
 *    E. Remove with reason (history kept); completion and cancellation release every assisting mechanic
 *    F. Single-mechanic consumers unchanged (maintenance_jobs.mechanic_id stays the lead); audit + events recorded
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/backend/Actions.php';
require_once __DIR__ . '/_launcher.php';
core('Access');
core('Settings');

$FRESH = envValue('DB_NAME');
$REF = envValue('TEST_DB_REFERENCE');
test_guard([$FRESH, $REF]);

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
function resetReady(): void { $r = new ReflectionProperty('Maintenance', 'helpersReady'); $r->setAccessible(true); $r->setValue(null, null); }

section('Setup: disposable databases');
test_recreate($FRESH); test_recreate($REF);
$p = db();
ok($p->query('SELECT DATABASE()')->fetchColumn() === $FRESH, 'db() is the disposable test database');
test_import($p, test_sql('database/schema.sql'));
test_import($p, test_sql('database/extension.sql'));
// State of a database installed before migration 12: no helper table, no migration row.
$p->exec('DROP TABLE maintenance_job_helpers');
$p->exec("DELETE FROM schema_migrations WHERE id='2026-11-12_12_job_helper_mechanics'");
$ref = test_conn($REF);
test_import($ref, test_sql('database/schema.sql'));
test_import($ref, test_sql('database/extension.sql'));

$staff = ['admin' => ['tvillanueva', 'Teresita Villanueva'], 'operations_head' => ['rmanalo', 'Ricardo Manalo'], 'motorpool' => ['ebautista', 'Ernesto Bautista'],
    'purchasing' => ['msantos', 'Maricel Santos'], 'finance' => ['laquino', 'Lourdes Aquino'], 'audit' => ['amendoza', 'Arnel Mendoza']];
$users = [];
foreach ($staff as $role => [$uname, $full]) {
    $id = insert($p, 'users', ['username' => $uname, 'email' => "$uname@mulawinfleet.example", 'password_hash' => 'x', 'full_name' => $full, 'role' => $role]);
    $users[$role] = ['id' => $id, 'username' => $uname, 'email' => "$uname@mulawinfleet.example", 'full_name' => $full, 'role' => $role, 'shift' => null];
}
$mp = $users['motorpool'];
foreach (['Maintenance', 'Mechanics', 'Purchasing'] as $m) Actions::load($m);
$V = [];
foreach ([1 => ['NBC 4821', 'MLW-021'], 2 => ['ABJ 7305', 'MLW-034'], 3 => ['CAH 2196', 'MLW-047']] as $i => [$plate, $no])
    $V[$i] = insert($p, 'vehicles', ['plate_number' => $plate, 'truck_number' => $no, 'truck_type' => 'Wing Van']);
$M = [];
foreach ([['RODEL PASCUAL', 'Diesel engine'], ['ARNOLD DIZON', 'Brakes and suspension'], ['VIRGILIO TORRES', 'Auto electrical'], ['NESTOR LIM', 'Hydraulics'], ['JERRY OCAMPO', 'Body and paint']] as [$name, $spec])
    $M[] = (int)Mechanics::action($mp, 'save', ['full_name' => $name, 'specialization' => $spec])['id'];
foreach ([0, 1, 2, 3] as $i) Mechanics::action($mp, 'time_in', ['id' => $M[$i]]);   // M[4] stays timed out (Unavailable)
$tmpDir = sys_get_temp_dir() . '/fleet_jh_' . bin2hex(random_bytes(4)); mkdir($tmpDir);
$png = function (string $name) use ($tmpDir): array {
    $path = $tmpDir . '/' . bin2hex(random_bytes(6));
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    file_put_contents($path, $bytes);
    return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
};
$report = function (int $vid, string $problem, ?int $mech) use ($mp, $png): int {
    $_FILES = ['photos' => $png('defect.png')];
    $r = Maintenance::action($mp, 'report', ['vehicle_id' => $vid, 'problem' => $problem, 'reported_on' => date('Y-m-d'), 'mechanic_id' => $mech]);
    $_FILES = [];
    return (int)$r['job_id'];
};
$avail = fn(int $mid) => one($p, 'SELECT availability FROM mechanics WHERE id=?', [$mid])['availability'];

// ---------------------------------------------------------------------
section('A. Before migration 12: existing flows work, the new actions refuse clearly');
resetReady();
$j1 = $report($V[1], 'Brake air leak at the rear relay valve; pressure drops below 80 psi when parked', $M[0]);
$job = one($p, 'SELECT status, mechanic_id FROM maintenance_jobs WHERE id=?', [$j1]);
ok($job['status'] === 'Under Inspection' && (int)$job['mechanic_id'] === $M[0], 'report with a mechanic works without the table (Under Inspection, lead set)');
ok($avail($M[0]) === 'Assigned', 'lead mechanic is Assigned');
$page = Maintenance::read($mp, 'maintenance', []);
ok($page['helpersReady'] === false && $page['helpers'] === [] && empty($page['_errors']), 'Repair Management read: helpersReady=false, helpers=[], no section error');
$det = Maintenance::action($mp, 'details', ['job_id' => $j1]);
ok($det['helpersReady'] === false && $det['helpers'] === [], 'details: helpersReady=false, helpers=[]');
fails(fn() => Maintenance::action($mp, 'helpers_add', ['job_id' => $j1, 'mechanic_ids' => [$M[1]]]), 'helpers_add refuses before the migration (409, database update)', 409, 'database update');
fails(fn() => Maintenance::action($mp, 'helper_remove', ['job_id' => $j1, 'mechanic_id' => $M[1], 'reason' => 'x']), 'helper_remove refuses before the migration (409)', 409);
$att = Mechanics::read($mp, 'mechanic_attendance', []);
ok(empty($att['_errors']) && count($att['board']) === 5, 'Mechanic Attendance board works without the table');
fails(fn() => Mechanics::action($mp, 'time_out', ['id' => $M[0]]), 'Time Out of the lead is still refused during the repair', 409, 'cannot time out');

// ---------------------------------------------------------------------
section('B. Migration 12 = fresh install; existing rows unchanged');
$before = many($p, 'SELECT id, status, mechanic_id, active_mechanic FROM maintenance_jobs ORDER BY id');
$mechBefore = many($p, 'SELECT id, availability, active FROM mechanics ORDER BY id');
test_import($p, test_sql('database/migrations/2026-11-12_12_job_helper_mechanics.sql'));
$fp = fn(PDO $c, string $db) => array_map('json_encode', many($c, "SELECT column_name, column_type, is_nullable, column_default, extra, generation_expression FROM information_schema.columns WHERE table_schema=? AND table_name='maintenance_job_helpers' ORDER BY ordinal_position", [$db]));
$ix = fn(PDO $c, string $db) => array_map('json_encode', many($c, "SELECT index_name, seq_in_index, column_name, non_unique FROM information_schema.statistics WHERE table_schema=? AND table_name='maintenance_job_helpers' ORDER BY index_name, seq_in_index", [$db]));
ok($fp($p, $FRESH) === $fp($ref, $REF) && count($fp($p, $FRESH)) === 9, 'migrated table columns = fresh install (9 columns)');
ok($ix($p, $FRESH) === $ix($ref, $REF), 'migrated table indexes = fresh install');
ok((bool)one($p, "SELECT id FROM schema_migrations WHERE id='2026-11-12_12_job_helper_mechanics'"), 'migration recorded');
ok($before === many($p, 'SELECT id, status, mechanic_id, active_mechanic FROM maintenance_jobs ORDER BY id') && $mechBefore === many($p, 'SELECT id, availability, active FROM mechanics ORDER BY id'),
    'no maintenance job or mechanic row changed by the migration');
try { test_import($p, test_sql('database/migrations/2026-11-12_12_job_helper_mechanics.sql')); ok(false, 'importing the migration twice stops'); } catch (PDOException $e) { ok(stripos($e->getMessage(), 'already exists') !== false, 'importing the migration twice stops at "table already exists"', $e->getMessage()); }
resetReady();

// ---------------------------------------------------------------------
section('C. Add assisting mechanics');
foreach (['admin', 'operations_head', 'purchasing', 'finance', 'audit'] as $r)
    fails(fn() => Maintenance::action($users[$r], 'helpers_add', ['job_id' => $j1, 'mechanic_ids' => [$M[1]]]), "$r cannot add mechanics (403)", 403);
$jNoLead = $report($V[2], 'Left headlamp not working; wiring harness chafed near the bumper bracket', null);
fails(fn() => Maintenance::action($mp, 'helpers_add', ['job_id' => $jNoLead, 'mechanic_ids' => [$M[1]]]), 'refused while no lead mechanic is assigned', 409, 'lead mechanic first');
fails(fn() => Maintenance::action($mp, 'helpers_add', ['job_id' => $j1, 'mechanic_ids' => []]), 'refused with no mechanic chosen', 400);
fails(fn() => Maintenance::action($mp, 'helpers_add', ['job_id' => $j1, 'mechanic_ids' => [$M[0]]]), 'the lead cannot be added as assisting', 409, 'lead');
fails(fn() => Maintenance::action($mp, 'helpers_add', ['job_id' => $j1, 'mechanic_ids' => [$M[1], $M[4]]]), 'a mechanic not timed in refuses the whole request', 409, 'not timed in');
ok((int)one($p, 'SELECT COUNT(*) AS n FROM maintenance_job_helpers')['n'] === 0 && $avail($M[1]) === 'Available', 'all-or-nothing: nothing was assigned and M1 stays Available');
$r = Maintenance::action($mp, 'helpers_add', ['job_id' => $j1, 'mechanic_ids' => [$M[1], $M[2], $M[1]]]);
ok(str_contains($r['message'], 'ARNOLD DIZON') && str_contains($r['message'], 'VIRGILIO TORRES'), 'two mechanics added in one request (duplicate id ignored)', $r['message']);
ok((int)one($p, 'SELECT COUNT(*) AS n FROM maintenance_job_helpers WHERE maintenance_job_id=? AND released_at IS NULL', [$j1])['n'] === 2, 'two active assisting rows, no duplicate');
ok($avail($M[1]) === 'Assigned' && $avail($M[2]) === 'Assigned', 'assisting mechanics are Assigned');
fails(fn() => Maintenance::action($mp, 'helpers_add', ['job_id' => $j1, 'mechanic_ids' => [$M[1]]]), 'adding the same mechanic again is refused', 409, 'already assisting');
$j3 = $report($V[3], 'Hydraulic tailgate lift drifting down under load; suspected worn cylinder seal', $M[3]);
fails(fn() => Maintenance::action($mp, 'helpers_add', ['job_id' => $j3, 'mechanic_ids' => [$M[2]]]), 'a mechanic assisting on another repair is refused', 409, 'already assisting');
fails(fn() => Maintenance::action($mp, 'helpers_add', ['job_id' => $j1, 'mechanic_ids' => [$M[3]]]), 'a mechanic leading another repair is refused', 409, 'already assigned');

// ---------------------------------------------------------------------
section('D. An assisting mechanic is busy everywhere');
fails(fn() => Maintenance::action($mp, 'assign_mechanic', ['job_id' => $jNoLead, 'mechanic_id' => $M[1]]), 'cannot become lead of another repair while assisting', 409, 'already assisting');
fails(fn() => Mechanics::action($mp, 'time_out', ['id' => $M[1]]), 'Time Out refused while assisting', 409, 'cannot time out');
fails(fn() => Mechanics::action($mp, 'set_active', ['id' => $M[1], 'active' => 0]), 'deactivation refused while assisting', 409);
$board = array_column(Mechanics::read($mp, 'mechanic_attendance', [])['board'], null, 'id');
ok(($board[$M[1]]['job_ref'] ?? null) !== null && $board[$M[1]]['job_id'] == $j1, 'attendance board shows the repair of the assisting mechanic');
$list = array_column(Mechanics::read($mp, 'mechanics', [])['mechanics'], null, 'id');
ok(($list[$M[2]]['job_id'] ?? null) == $j1, 'mechanic masterlist shows the repair of the assisting mechanic');
$page = Maintenance::read($mp, 'maintenance', []);
ok($page['helpersReady'] === true && count(array_filter($page['helpers'], fn($h) => (int)$h['job_id'] === $j1)) === 2, 'Repair Management read lists the 2 assisting mechanics of the job');
$det = Maintenance::action($users['audit'], 'details', ['job_id' => $j1]);
ok(count($det['helpers']) === 2 && $det['helpers'][0]['assigned_by_name'] === 'Ernesto Bautista', 'details (Audit, read-only) show assisting mechanics and who assigned them');

// ---------------------------------------------------------------------
section('E. Remove (history kept); completion releases everyone');
fails(fn() => Maintenance::action($mp, 'helper_remove', ['job_id' => $j1, 'mechanic_id' => $M[2]]), 'remove needs a reason', 400);
fails(fn() => Maintenance::action($mp, 'helper_remove', ['job_id' => $j1, 'mechanic_id' => $M[3], 'reason' => 'Wrong person']), 'removing a mechanic who is not assisting: 404', 404);
Maintenance::action($mp, 'helper_remove', ['job_id' => $j1, 'mechanic_id' => $M[2], 'reason' => 'Needed on the electrical backlog']);
$h = one($p, 'SELECT released_at, released_by, release_reason FROM maintenance_job_helpers WHERE maintenance_job_id=? AND mechanic_id=?', [$j1, $M[2]]);
ok($h && $h['released_at'] !== null && (int)$h['released_by'] === $mp['id'] && $h['release_reason'] === 'Needed on the electrical backlog', 'removed row kept with who/when/why');
ok($avail($M[2]) === 'Available', 'removed mechanic is Available again');
Maintenance::action($mp, 'helpers_add', ['job_id' => $j1, 'mechanic_ids' => [$M[2]]]);
ok((int)one($p, 'SELECT COUNT(*) AS n FROM maintenance_job_helpers WHERE maintenance_job_id=? AND mechanic_id=?', [$j1, $M[2]])['n'] === 2, 're-adding the same mechanic later creates a new history row');
Maintenance::action($mp, 'inspect', ['job_id' => $j1, 'findings' => 'Relay valve diaphragm torn; rear service line chafed', 'diagnosis' => 'Replace the relay valve and the chafed air line section']);
$_FILES = ['evidence' => $png('relay_valve_replaced.png')];
Maintenance::action($mp, 'complete', ['job_id' => $j1, 'repair_performed' => 'Replaced the rear relay valve and 1.2 m of air line; leak test passed', 'completed_on' => date('Y-m-d')]);
$_FILES = [];
ok((int)one($p, 'SELECT COUNT(*) AS n FROM maintenance_job_helpers WHERE maintenance_job_id=? AND released_at IS NULL', [$j1])['n'] === 0, 'completion released every assisting mechanic');
ok($avail($M[0]) === 'Available' && $avail($M[1]) === 'Available' && $avail($M[2]) === 'Available', 'lead and assisting mechanics are Available after completion');
fails(fn() => Maintenance::action($mp, 'helpers_add', ['job_id' => $j1, 'mechanic_ids' => [$M[1]]]), 'no mechanics can be added to a completed repair', 409);
Maintenance::action($mp, 'assign_mechanic', ['job_id' => $jNoLead, 'mechanic_id' => $M[0]]);
Maintenance::action($mp, 'helpers_add', ['job_id' => $jNoLead, 'mechanic_ids' => [$M[1]]]);
Maintenance::action($mp, 'cancel', ['job_id' => $jNoLead, 'reason' => 'Reported on the wrong truck']);
ok((int)one($p, 'SELECT COUNT(*) AS n FROM maintenance_job_helpers WHERE maintenance_job_id=? AND released_at IS NULL', [$jNoLead])['n'] === 0 && $avail($M[1]) === 'Available', 'cancellation released the assisting mechanic');
ok(Mechanics::action($mp, 'time_out', ['id' => $M[1]])['availability'] === 'Unavailable', 'a released mechanic can Time Out');

// ---------------------------------------------------------------------
section('F. Single-mechanic consumers, audit and events');
ok((int)one($p, 'SELECT mechanic_id FROM maintenance_jobs WHERE id=?', [$j1])['mechanic_id'] === $M[0], 'maintenance_jobs.mechanic_id is still the lead');
$hist = Maintenance::read($mp, 'maint_history', []);
ok(($hist['records'][0]['mechanic'] ?? '') !== '' && empty($hist['_errors']), 'Maintenance History still reads the lead mechanic');
$ev = array_column(many($p, 'SELECT details FROM maintenance_events WHERE maintenance_job_id=? ORDER BY id', [$j1]), 'details');
ok(count(array_filter($ev, fn($d) => str_starts_with((string)$d, 'assisting mechanic added'))) === 3 && count(array_filter($ev, fn($d) => str_starts_with((string)$d, 'assisting mechanic removed'))) === 1, 'events: 3 added, 1 removed');
$au = array_column(many($p, "SELECT action FROM audit_events WHERE record_ref=? AND action LIKE 'assisting%'", ['job ' . $j1]), 'action');
ok(in_array('assisting mechanics added', $au, true) && in_array('assisting mechanic removed', $au, true), 'audit records add and remove with actor');
$ov = Maintenance::read($mp, 'maintenance_overview', []);
ok(empty($ov['_errors']) && in_array('Assisting mechanic added', array_column($ov['activity'] ?? [], 'label'), true), 'Overview activity names the new events');

@array_map('unlink', glob($tmpDir . '/*') ?: []); @rmdir($tmpDir);
echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL ? 1 : 0);
