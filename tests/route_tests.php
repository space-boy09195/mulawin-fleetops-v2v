<?php
/*
 * =====================================================================
 *  tests/route_tests.php  —  Client Routes tests (Checklist #20)
 * =====================================================================
 *  Do NOT run this file directly: run tests/run_route_tests.php, which copies the code
 *  to a temporary folder with a separate test .env. This file refuses to run when the
 *  database name does not end with "_test".
 *
 *  Groups:
 *    A. Migration: fresh install vs upgrade (identical schema), repeat-import guard,
 *       partially failed migration detection and recovery
 *    B. Role permissions (actions and page access)
 *    C. Route request validation and normalization
 *    D. Rate entry validation
 *    E. Approval / rejection (new route vs revision), Admin-only rate decision
 *    F. Rate revision safety: atomic supersede, rollback on failure, concurrent approval
 *    G. Duplicate prevention (application and database keys), truck types, directional routes
 *    H. Selectable routes, withdraw, deactivate, history
 *    J. Client eligibility re-checked at approval (new route and revision), lock order
 *    K. approvedFor (record list) vs selectableFor (planning eligibility)
 *    L. Screen normalization (routes.js) = server normalization (Routes.php), when Node.js exists
 *    I. Historical data preserved + regression of the trip / fund / dispatch / billing flow
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/backend/Actions.php';
require_once __DIR__ . '/_launcher.php';
core('Access');

const NAME_RULE = '/^[a-z0-9_]+_test$/';
$FRESH = envValue('DB_NAME'); $UPGRADE = envValue('TEST_DB_UPGRADE'); $PARTIAL = envValue('TEST_DB_PARTIAL');
test_guard([$FRESH, $UPGRADE, $PARTIAL]);
foreach ([$FRESH, $UPGRADE, $PARTIAL] as $n) {
    if (!preg_match(NAME_RULE, $n)) { fwrite(STDERR, "Refused: database name '$n' does not end with _test. Use tests/run_route_tests.php.\n"); exit(2); }
}
if (!is_file(dirname(__DIR__) . '/.env') || !str_contains((string)file_get_contents(dirname(__DIR__) . '/.env'), 'APP_ENV=test')) {
    fwrite(STDERR, "Refused: this is not the temporary test copy. Use tests/run_route_tests.php.\n"); exit(2);
}

// ---------------------------------------------------------------------
// Sub-process mode for the concurrency test: php route_tests.php --decide <rate_id> <user_id>
// ---------------------------------------------------------------------
if (($argv[1] ?? '') === '--decide') {
    must(one(db(), 'SELECT DATABASE() AS d')['d'] === $FRESH, 'wrong database');
    $u = one(db(), 'SELECT id, username, email, full_name, role FROM users WHERE id=?', [(int)$argv[3]]); $u['id'] = (int)$u['id'];
    Actions::load('Routes');
    usleep(200000);
    try { $r = Routes::action($u, 'decide', ['rate_id' => (int)$argv[2], 'decision' => 'approve']); echo 'OK ' . $r['message']; }
    catch (Throwable $e) { echo 'FAIL ' . $e->getMessage(); }
    exit(0);
}

// ---------------------------------------------------------------------
// tiny test framework
// ---------------------------------------------------------------------
$PASS = 0; $FAIL = 0; $FAILED = [];
function ok(bool $cond, string $name, string $detail = ''): void {
    global $PASS, $FAIL, $FAILED;
    if ($cond) { $PASS++; echo "  PASS  $name\n"; } else { $FAIL++; $FAILED[] = $name; echo "  FAIL  $name" . ($detail !== '' ? "  -> $detail" : '') . "\n"; }
}
/** Expects a BusinessRuleException (optionally with HTTP code and message part). */
function fails(callable $fn, string $name, ?int $code = null, string $contains = ''): void {
    try { $fn(); ok(false, $name, 'no error was raised'); }
    catch (BusinessRuleException $e) {
        $good = ($code === null || $e->httpStatus === $code) && ($contains === '' || stripos($e->getMessage(), $contains) !== false);
        ok($good, $name, "got {$e->httpStatus}: {$e->getMessage()}");
    }
    catch (Throwable $e) { ok(false, $name, get_class($e) . ': ' . $e->getMessage()); }
}
function section(string $t): void { echo "\n== $t ==\n"; }

/** Raw connection without a selected database (only used for the _test databases). */
function server(): PDO { return test_server(); }
function conn(string $db): PDO { return test_conn($db); }
function recreate(string $db): void { test_recreate($db); }
/** Imports SQL text (same splitting rule as scripts/seed_test_data.php). */
function importSql(PDO $p, string $sql): void {
    $sql = (string)preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (preg_split('/;\s*\n/', $sql) as $stmt) { $stmt = trim($stmt); if ($stmt !== '') $p->exec($stmt); }
}
function file_sql(string $rel): string { return (string)file_get_contents(dirname(__DIR__) . '/' . $rel); }

/** Schema fingerprint of a database (tables, columns, indexes, checks, foreign keys). */
function fingerprint(PDO $p, string $db): array {
    $q = fn(string $sql) => array_map('json_encode', many($p, $sql, [$db]));
    return [
        'tables' => $q('SELECT table_name, engine, table_collation FROM information_schema.tables WHERE table_schema=? ORDER BY table_name'),
        'columns' => $q('SELECT table_name, column_name, ordinal_position, column_type, is_nullable, column_default, extra, generation_expression, column_comment
                         FROM information_schema.columns WHERE table_schema=? ORDER BY table_name, ordinal_position'),
        'indexes' => $q('SELECT table_name, index_name, seq_in_index, column_name, non_unique FROM information_schema.statistics WHERE table_schema=? ORDER BY table_name, index_name, seq_in_index'),
        'checks' => $q('SELECT table_name, constraint_name, check_clause FROM information_schema.check_constraints WHERE constraint_schema=? ORDER BY table_name, constraint_name'),
        'fks' => $q('SELECT k.table_name, k.constraint_name, k.column_name, k.referenced_table_name, k.referenced_column_name FROM information_schema.key_column_usage k
                     WHERE k.table_schema=? AND k.referenced_table_name IS NOT NULL ORDER BY k.table_name, k.constraint_name, k.ordinal_position'),
    ];
}

$MIG = '2026-11-06_02_client_routes';
$MIGFILE = "database/migrations/$MIG.sql";
$ROUTE_TABLES = ['client_routes', 'client_route_rates', 'client_route_events'];

// ---------------------------------------------------------------------
// A. MIGRATION
// ---------------------------------------------------------------------
section('A. Migration: fresh install vs upgrade');
recreate($FRESH); recreate($UPGRADE); recreate($PARTIAL);
$fresh = conn($FRESH);
importSql($fresh, file_sql('database/schema.sql'));
importSql($fresh, file_sql('database/extension.sql'));
ok(true, 'fresh install imports schema.sql + extension.sql');

// MIGRATION CHAIN: schema as it was BEFORE this feature (from git, written by tests/_launcher.php),
// then EVERY migration from this one on, in order (later migrations of other features included),
// must give exactly the same database as a fresh install.
$baseSchema = __DIR__ . '/_base_schema.sql'; $baseExt = __DIR__ . '/_base_extension.sql';
$CHAIN = test_migrations_from($MIG);
if (!is_file($baseSchema) || !is_file($baseExt)
    || str_contains((string)file_get_contents($baseExt), 'CREATE TABLE IF NOT EXISTS client_routes')
    || str_contains((string)file_get_contents($baseExt), 'CREATE TABLE client_routes')) {
    // A squashed Git history cannot reconstruct the pre-route schema. Do not
    // pretend its modern baseline is a valid upgrade fixture or replay migrations.
    echo "  NOTE  pre-route historical baseline unavailable: upgrade and partial-migration checks skipped; fresh-install and route behavior checks still run.\n";
} else {
    ok(!str_contains((string)file_get_contents($baseExt), 'client_routes'), 'base schema (before this feature) has no route tables');
    ok($CHAIN[0] === $MIG, 'migration chain starts with ' . $MIG . ' (' . count($CHAIN) . ' migration(s): ' . implode(', ', $CHAIN) . ')');
    $up = conn($UPGRADE);
    importSql($up, (string)file_get_contents($baseSchema));
    importSql($up, (string)file_get_contents($baseExt));
    // A little existing data, to prove the migrations do not touch it.
    $up->exec("INSERT INTO users (username, email, password_hash, full_name, role) VALUES ('old_admin','old@x.test','x','Old Admin','admin')");
    $up->exec("INSERT INTO clients (company_name, status) VALUES ('OLD CLIENT INC', 'Active')");
    $up->exec("INSERT INTO client_rates (client_id, truck_type, rate_per_km, minimum_charge, updated_by) VALUES (1,'Wing Van',55.50,1000,1)");
    $beforeRates = $up->query('CHECKSUM TABLE client_rates')->fetch()['Checksum'];
    foreach ($CHAIN as $m) importSql($up, file_sql("database/migrations/$m.sql"));
    ok(true, 'upgrade: base schema + ' . count($CHAIN) . ' migration(s) import in order');
    ok($up->query('CHECKSUM TABLE client_rates')->fetch()['Checksum'] === $beforeRates, 'upgrade: existing client_rates rows unchanged');
    $fa = fingerprint($fresh, $FRESH); $fb = fingerprint($up, $UPGRADE);
    foreach ($fa as $k => $v) ok($v === $fb[$k], "fresh install and upgraded database have identical $k", count(array_diff($v, $fb[$k])) . ' / ' . count(array_diff($fb[$k], $v)) . ' differences');
    $migFresh = array_column(many($fresh, 'SELECT id FROM schema_migrations ORDER BY id'), 'id');
    $migUp = array_column(many($up, 'SELECT id FROM schema_migrations ORDER BY id'), 'id');
    // Migrations whose file does not record itself in schema_migrations cannot be matched by an upgrade:
    // they are listed as a NOTE (open item for their owner), every other entry must match exactly.
    $selfTracked = fn(string $m) => str_contains(file_sql("database/migrations/$m.sql"), "INSERT INTO schema_migrations (id) VALUES ('$m')");
    $untracked = array_values(array_filter($CHAIN, fn($m) => !$selfTracked($m)));
    if ($untracked) echo '  NOTE  migration file(s) without their own schema_migrations row (an upgraded database cannot record them): ' . implode(', ', $untracked) . "\n";
    ok(array_values(array_diff($migFresh, $untracked)) === array_values(array_diff($migUp, $untracked)) && in_array($MIG, $migUp, true),
       'schema_migrations has the same entries (including this migration)' . ($untracked ? ' apart from the NOTE above' : ''));
    try { importSql($up, file_sql($MIGFILE)); ok(false, 'second import is refused'); }
    catch (PDOException $e) { ok(($e->errorInfo[1] ?? 0) === 1062, 'second import is refused by schema_migrations (duplicate key)', $e->getMessage()); }
    ok(fingerprint($up, $UPGRADE) === $fb, 'refused second import changed nothing');

    section('A2. Partially failed migration: detection and recovery (docs procedure)');
    $pa = conn($PARTIAL);
    importSql($pa, (string)file_get_contents($baseSchema));
    importSql($pa, (string)file_get_contents($baseExt));
    // Simulate a stop after the tracker row and the first table only.
    $parts = array_values(array_filter(array_map('trim', preg_split('/;\s*\n/', (string)preg_replace('/^\s*--.*$/m', '', file_sql($MIGFILE)))), fn($s) => $s !== ''));
    $pa->exec($parts[0]); $pa->exec($parts[1]);
    $detect = "SELECT (SELECT COUNT(*) FROM schema_migrations WHERE id='$MIG') AS tracked,
                      (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('client_routes','client_route_rates','client_route_events')) AS tables_found";
    $st = $pa->query($detect)->fetch();
    ok((int)$st['tracked'] === 1 && (int)$st['tables_found'] === 1, 'detection query shows: tracked = 1 but only 1 of 3 tables (partial)', json_encode($st));
    ok((int)$pa->query('SELECT COUNT(*) FROM client_routes')->fetchColumn() === 0, 'partially created table is empty (safe to drop)');
    // Recovery steps from docs/CLAUDE_20_CLIENT_ROUTES.md
    $pa->exec('DROP TABLE IF EXISTS client_route_events');
    $pa->exec('DROP TABLE IF EXISTS client_route_rates');
    $pa->exec('DROP TABLE IF EXISTS client_routes');
    $pa->exec("DELETE FROM schema_migrations WHERE id='$MIG'");
    importSql($pa, file_sql($MIGFILE));
    $st = $pa->query($detect)->fetch();
    ok((int)$st['tracked'] === 1 && (int)$st['tables_found'] === 3, 'after recovery: tracked = 1 and 3 of 3 tables', json_encode($st));
    foreach (array_slice($CHAIN, 1) as $m) importSql($pa, file_sql("database/migrations/$m.sql"));
    ok(fingerprint($pa, $PARTIAL) === fingerprint($fresh, $FRESH), 'recovered database (+ later migrations) is identical to a fresh install');
}

// ---------------------------------------------------------------------
// Test data on the FRESH database (db() points to it through the test .env)
// ---------------------------------------------------------------------
$p = db();
must($p->query('SELECT DATABASE()')->fetchColumn() === $FRESH, 'db() is not the fresh test database');
Actions::load('Routes'); Actions::load('Trips'); Actions::load('Funds');
$users = [];
foreach (array_keys(ROLE_LABELS) as $role) {
    $id = insert($p, 'users', ['username' => $role, 'email' => "$role@example.test", 'password_hash' => password_hash('x' . random_int(1, 9), PASSWORD_DEFAULT), 'full_name' => strtoupper($role) . ' USER', 'role' => $role]);
    $users[$role] = ['id' => $id, 'username' => $role, 'email' => "$role@example.test", 'full_name' => strtoupper($role) . ' USER', 'role' => $role, 'shift' => null];
}
$admin2 = insert($p, 'users', ['username' => 'admin2', 'email' => 'admin2@example.test', 'password_hash' => 'x', 'full_name' => 'SECOND ADMIN', 'role' => 'admin']);
$users['admin2'] = ['id' => $admin2, 'username' => 'admin2', 'email' => 'admin2@example.test', 'full_name' => 'SECOND ADMIN', 'role' => 'admin', 'shift' => null];
$billing2 = insert($p, 'users', ['username' => 'billing2', 'email' => 'billing2@example.test', 'password_hash' => 'x', 'full_name' => 'SECOND BILLING', 'role' => 'billing']);
$users['billing2'] = ['id' => $billing2, 'username' => 'billing2', 'email' => 'billing2@example.test', 'full_name' => 'SECOND BILLING', 'role' => 'billing', 'shift' => null];
$ops2 = insert($p, 'users', ['username' => 'ops2', 'email' => 'ops2@example.test', 'password_hash' => 'x', 'full_name' => 'SECOND OPS', 'role' => 'operations_head']);
$users['ops2'] = ['id' => $ops2, 'username' => 'ops2', 'email' => 'ops2@example.test', 'full_name' => 'SECOND OPS', 'role' => 'operations_head', 'shift' => null];
$U = fn(string $r) => $users[$r];
$act = fn(string $role, string $action, array $d) => Routes::action($users[$role], $action, $d);

// Imported historical rows only: new Head Management entries activate immediately.
// Keep the legacy decision, rollback and concurrency cases without inventing a
// second approval step in today's workflow. test_guard above owns this database.
$legacyPending = function (int $routeId, string $amount, string $role = 'admin', string $notes = '') use ($p, $users): int {
    return transaction(function (PDO $p) use ($routeId, $amount, $role, $notes, $users): int {
        $r = one($p, 'SELECT * FROM client_routes WHERE id=?', [$routeId]);
        $version = 1 + (int)one($p, 'SELECT MAX(version_no) AS n FROM client_route_rates WHERE route_id=?', [$routeId])['n'];
        $id = insert($p, 'client_route_rates', ['route_id'=>$routeId,'version_no'=>$version,'rate_type'=>'Flat',
            'rate_amount'=>$amount,'status'=>'Pending Approval','entered_by'=>$users[$role]['id'],'entry_notes'=>$notes]);
        $revision = $r['status'] === 'Approved';
        if (!$revision) run($p, "UPDATE client_routes SET status='Pending Approval' WHERE id=?", [$routeId]);
        $event = new ReflectionMethod(Routes::class, 'event');
        $event->invoke(null, $p, $users[$role]['id'], $routeId, $id,
            $revision ? 'rate revision entered' : 'rate entered', $r['status'], $revision ? 'Approved' : 'Pending Approval', 'Historical pending fixture');
        Notify::roles($p, ['admin'], 'Route approval', 'Historical pending fixture', 'routes', 'route-approve-'.$id);
        return $id;
    });
};


$client = fn(string $name, string $status) => insert($p, 'clients', ['company_name' => $name, 'status' => $status, 'created_by' => $users['operations_head']['id']]);
$cActive = $client('ABC LOGISTICS CORP', 'Active');
$cPending = $client('PENDING RATE INC', 'Pending Rate');
$cIncomplete = $client('INCOMPLETE CO', 'Incomplete');
$cInactive = $client('INACTIVE CO', 'Inactive');
$req = fn(array $over = []) => $over + ['client_id' => $cActive, 'truck_type' => 'Container', 'origin' => 'Tanauan, Batangas', 'destination' => 'Malvar, Batangas', 'distance_km' => '18.5'];

// Snapshot of existing financial / trip data (must not change through route actions).
$checksums = function () use ($p): array {
    $o = [];
    foreach (['client_rates', 'trips', 'billings', 'fund_requests', 'disbursements', 'expenses'] as $t) $o[$t] = $p->query("CHECKSUM TABLE $t")->fetch()['Checksum'];
    return $o;
};

// ---------------------------------------------------------------------
// B. ROLE PERMISSIONS
// ---------------------------------------------------------------------
section('B. Role permissions');
foreach (array_keys(ROLE_LABELS) as $role) {
    $expect = in_array($role, ['admin', 'operations_head', 'billing', 'finance', 'audit'], true);
    ok(Access::canOpen($U($role), 'routes') === $expect, "page access 'routes' for $role = " . ($expect ? 'yes' : 'no'));
}
foreach (array_keys(ROLE_LABELS) as $role) {
    if ($role === 'operations_head') continue;
    fails(fn() => $act($role, 'request', $req()), "request refused for $role", 403);
}
$r1 = $act('operations_head', 'request', $req());
ok(str_starts_with($r1['route_ref'], 'RTE-'), 'Operations Head can request a route (' . $r1['route_ref'] . ')');
$route1 = (int)$r1['route_id'];
foreach (array_keys(ROLE_LABELS) as $role) {
    if ($role === 'admin') continue;
    fails(fn() => $act($role, 'rate_enter', ['route_id' => $route1, 'rate_type' => 'Flat', 'rate_amount' => '12500']), "rate entry refused for $role", 403);
}
$currentRoute = (int)$act('operations_head', 'request', $req(['origin'=>'Current contract depot','destination'=>'Current contract destination']))['route_id'];
$currentRate = (int)$act('admin', 'rate_enter', ['route_id'=>$currentRoute,'rate_type'=>'Flat','rate_amount'=>'12,500.00'])['rate_id'];
ok(one($p, 'SELECT status FROM client_routes WHERE id=?', [$currentRoute])['status']==='Approved'
    && one($p, 'SELECT status FROM client_route_rates WHERE id=?', [$currentRate])['status']==='Approved', 'Head Management contract entry immediately approves route and rate');
ok(in_array($currentRoute, array_map('intval', array_column(Routes::selectableFor($p, $cActive), 'id')), true), 'Operations Head can select current contract without another approval');
fails(fn()=>$act('admin','decide',['rate_id'=>$currentRate,'decision'=>'approve']), 'current contract does not require a second approval', 409, 'already decided');
$currentRevision = (int)$act('admin','rate_enter',['route_id'=>$currentRoute,'rate_type'=>'Flat','rate_amount'=>'13000'])['rate_id'];
ok(one($p,'SELECT status FROM client_route_rates WHERE id=?',[$currentRate])['status']==='Superseded'
    && one($p,'SELECT status FROM client_route_rates WHERE id=?',[$currentRevision])['status']==='Approved', 'current contract revision atomically supersedes prior rate immediately');
$rate1 = $legacyPending($route1, '12500');
ok($currentRate > 0, 'Admin can enter the rate');
foreach (array_keys(ROLE_LABELS) as $role) {
    if ($role === 'admin') continue;
    fails(fn() => $act($role, 'decide', ['rate_id' => $rate1, 'decision' => 'approve']), "approval refused for $role", 403);
}
foreach (array_keys(ROLE_LABELS) as $role) {
    if ($role === 'admin') continue;
    fails(fn() => $act($role, 'deactivate', ['route_id' => $route1, 'reason' => 'x']), "deactivate refused for $role", 403);
}
foreach (['finance', 'audit'] as $role) ok(isset($act($role, 'details', ['route_id' => $route1])['route']), "$role can view route details (read-only)");
foreach (['car_carrier_dispatcher', 'container_wingvan_dispatcher', 'motorpool', 'purchasing'] as $role) fails(fn() => $act($role, 'details', ['route_id' => $route1]), "$role cannot view route details", 403);
fails(fn() => $act('purchasing', 'for_client', ['client_id' => $cActive]), 'for_client refused for a role without client access', 403);
ok(is_array($act('car_carrier_dispatcher', 'for_client', ['client_id' => $cActive])['routes']), 'for_client allowed for a role that can open the Client Masterlist');
fails(fn() => Routes::action($users['ops2'], 'withdraw', ['route_id' => $route1, 'reason' => 'x']), 'another Operations Head cannot withdraw this request', 403);

// ---------------------------------------------------------------------
// C. REQUEST VALIDATION
// ---------------------------------------------------------------------
section('C. Route request validation and normalization');
$row1 = one($p, 'SELECT * FROM client_routes WHERE id=?', [$route1]);
ok($row1['origin_label'] === 'TANAUAN, BATANGAS' && $row1['origin_key'] === 'TANAUAN BATANGAS' && $row1['destination_key'] === 'MALVAR BATANGAS', 'labels upper-cased; keys normalized', json_encode([$row1['origin_label'], $row1['origin_key']]));
ok($row1['status'] === 'Pending Approval' && $row1['distance_source'] === 'Manual' && $row1['distance_km'] === '18.50', 'route stored with Manual distance');
fails(fn() => $act('operations_head', 'request', $req(['client_id' => $cIncomplete])), 'Incomplete client refused', 409, 'Incomplete');
fails(fn() => $act('operations_head', 'request', $req(['client_id' => $cInactive])), 'Inactive client refused', 409);
fails(fn() => $act('operations_head', 'request', $req(['client_id' => 999999])), 'unknown client refused', 404);
$rp = $act('operations_head', 'request', $req(['client_id' => $cPending, 'truck_type' => 'Wing Van']));
ok($rp['route_id'] > 0, 'Pending Rate client allowed (provisional policy)');
fails(fn() => $act('operations_head', 'request', $req(['truck_type' => 'Tricycle'])), 'invalid truck type refused');
fails(fn() => $act('operations_head', 'request', $req(['distance_km' => '0'])), 'distance 0 refused');
fails(fn() => $act('operations_head', 'request', $req(['distance_km' => '-5'])), 'negative distance refused');
fails(fn() => $act('operations_head', 'request', $req(['distance_km' => '3000.01'])), 'distance above 3000 km refused');
fails(fn() => $act('operations_head', 'request', $req(['distance_km' => '1e3'])), 'scientific notation refused');
fails(fn() => $act('operations_head', 'request', $req(['destination' => ' tanauan  batangas.'])), 'origin = destination after normalization refused', 400, 'different');
fails(fn() => $act('operations_head', 'request', $req(['origin' => '---'])), 'origin without letters or numbers refused');
fails(fn() => $act('operations_head', 'request', $req(['origin' => str_repeat('A', 201)])), 'origin longer than 200 refused');
fails(fn() => $act('operations_head', 'request', $req(['origin' => ''])), 'empty origin refused');
fails(fn() => $act('operations_head', 'request', $req(['origin' => 'X1', 'origin_lat' => '14.1'])), 'incomplete map pin refused', 400, 'incomplete');
fails(fn() => $act('operations_head', 'request', $req(['origin' => 'X2', 'origin_lat' => '35.6', 'origin_lng' => '139.7'])), 'map pin outside the Philippines refused', 400, 'outside');
$rc = $act('operations_head', 'request', $req(['origin' => 'Calamba, Laguna', 'origin_lat' => '14.2117', 'origin_lng' => '121.1653']));
ok((float)one($p, 'SELECT origin_lat FROM client_routes WHERE id=?', [$rc['route_id']])['origin_lat'] === 14.2117, 'valid map pin stored');
ok(one($p, "SELECT COUNT(*) AS n FROM client_route_events WHERE route_id=? AND event_type='route requested'", [$route1])['n'] == 1, 'request event recorded');
ok((bool)one($p, "SELECT id FROM audit_events WHERE area='Routes' AND record_ref=? AND action='route requested'", ['route ' . $route1]), 'request written to the activity log');
ok((bool)one($p, "SELECT id FROM notifications WHERE user_id=? AND dedupe_key=?", [$users['admin']['id'], 'route-rate-' . $route1]), 'Billing was notified');

// ---------------------------------------------------------------------
// D. RATE VALIDATION
// ---------------------------------------------------------------------
section('D. Rate entry validation');
$rv = (int)$act('operations_head', 'request', $req(['origin' => 'Lipa City', 'destination' => 'Batangas Port']))['route_id'];
foreach (['0' => 'zero', '-1' => 'negative', 'abc' => 'text', '10.555' => '3 decimals', '' => 'empty'] as $v => $label)
    fails(fn() => $act('admin', 'rate_enter', ['route_id' => $rv, 'rate_type' => 'Flat', 'rate_amount' => (string)$v]), "rate $label refused");
fails(fn() => $act('admin', 'rate_enter', ['route_id' => $rv, 'rate_type' => 'Per Km', 'rate_amount' => '50']), 'Per Km refused while the policy enables Flat only', 400, 'not enabled');
fails(fn() => $act('admin', 'rate_enter', ['route_id' => $rv, 'rate_type' => 'Hourly', 'rate_amount' => '50']), 'unknown rate type refused');
fails(fn() => $act('admin', 'rate_enter', ['route_id' => $rv, 'rate_type' => 'Flat', 'rate_amount' => '5000', 'minimum_charge' => '100']), 'minimum charge with a Flat rate refused', 400, 'minimum');
fails(fn() => $act('admin', 'rate_enter', ['route_id' => $route1, 'rate_type' => 'Flat', 'rate_amount' => '9000']), 'new rate refused while the first rate waits for approval', 409, 'Pending Approval');
fails(fn() => $act('admin', 'rate_enter', ['route_id' => 999999, 'rate_type' => 'Flat', 'rate_amount' => '9000']), 'rate for unknown route refused', 404);
try { insert($p, 'client_route_rates', ['route_id' => $route1, 'version_no' => 9, 'rate_type' => 'Flat', 'rate_amount' => '1', 'status' => 'Pending Approval', 'entered_by' => $users['admin']['id']]); ok(false, 'database refuses a second pending rate'); }
catch (PDOException $e) { ok(str_contains($e->getMessage(), 'uq_route_rate_pending'), 'database refuses a second pending rate (uq_route_rate_pending)'); }
foreach ([['rate_type' => 'Daily'], ['rate_amount' => '0'], ['rate_type' => 'Flat', 'minimum_charge' => '5']] as $i => $bad) {
    try { insert($p, 'client_route_rates', array_merge(['route_id' => $rv, 'version_no' => 50 + $i, 'rate_type' => 'Flat', 'rate_amount' => '10', 'status' => 'Rejected', 'entered_by' => $users['admin']['id']], $bad)); ok(false, "database CHECK refuses " . json_encode($bad)); }
    catch (PDOException $e) { ok(in_array((int)($e->errorInfo[1] ?? 0), [3819, 4025], true), 'database CHECK refuses ' . json_encode($bad)); }
}
// Per Km works when the policy enables it (schema supports both; done through reflection only in this test).
$policyOk = (function () use ($p, $users, $rv): bool {
    $st = $p->prepare("INSERT INTO client_route_rates (route_id, version_no, rate_type, rate_amount, minimum_charge, status, entered_by) VALUES (?,?,?,?,?,?,?)");
    $st->execute([$rv, 77, 'Per Km', '55.50', '1000', 'Rejected', $users['admin']['id']]);
    $ok = (bool)one($p, "SELECT id FROM client_route_rates WHERE route_id=? AND version_no=77 AND rate_type='Per Km' AND minimum_charge=1000", [$rv]);
    run($p, 'DELETE FROM client_route_rates WHERE route_id=? AND version_no IN (77)', [$rv]);
    return $ok;
})();
ok($policyOk, 'database accepts a Per Km rate with a minimum charge (ready for the company decision)');

// ---------------------------------------------------------------------
// E. APPROVAL / REJECTION
// ---------------------------------------------------------------------
section('E. Approval and rejection');
$before = $checksums();
// Current provisional policy permits an Admin to enter and decide a rate.
// Verify that the decision is recorded, then reject a second decision.
$rs = (int)$act('operations_head', 'request', $req(['origin' => 'Sto Tomas', 'destination' => 'Calamba']))['route_id'];
$selfRate = insert($p, 'client_route_rates', ['route_id' => $rs, 'version_no' => 1, 'rate_type' => 'Flat', 'rate_amount' => '3000', 'status' => 'Pending Approval', 'entered_by' => $users['admin']['id']]);
run($p, "UPDATE client_routes SET status='Pending Approval' WHERE id=?", [$rs]);
ok(isset($act('admin', 'decide', ['rate_id' => $selfRate, 'decision' => 'approve'])['message']),
   'Admin can decide an entered rate under the current provisional policy');
fails(fn() => Routes::action($users['admin2'], 'decide', ['rate_id' => $selfRate, 'decision' => 'approve']),
   'Another Admin cannot approve a rate twice', 409, 'already decided');

fails(fn() => $act('admin', 'decide', ['rate_id' => $rate1, 'decision' => 'reject']), 'reject without a reason refused');
$m = $act('admin', 'decide', ['rate_id' => $rate1, 'decision' => 'approve', 'notes' => 'Per contract 2026']);
$r = one($p, 'SELECT * FROM client_routes WHERE id=?', [$route1]); $rt = one($p, 'SELECT * FROM client_route_rates WHERE id=?', [$rate1]);
ok($r['status'] === 'Approved' && $rt['status'] === 'Approved' && $rt['effective_from'] !== null && (int)$rt['decided_by'] === $users['admin']['id'], 'approve: route and rate Approved, approver and effective date recorded');
fails(fn() => $act('admin', 'decide', ['rate_id' => $rate1, 'decision' => 'approve']), 'approving an already decided rate refused (double click)', 409, 'already decided');
fails(fn() => $act('admin', 'decide', ['rate_id' => 999999, 'decision' => 'approve']), 'unknown rate refused', 404);
ok((bool)one($p, 'SELECT id FROM notifications WHERE user_id=? AND dedupe_key=? AND state=?', [$users['admin']['id'], 'route-approve-' . $rate1, 'Resolved']), 'approval notification resolved after the decision');

// Reject a NEW route request -> terminal
$rn = (int)$act('operations_head', 'request', $req(['origin' => 'Tanauan', 'destination' => 'Lipa']))['route_id'];
$rnr = $legacyPending($rn, '4000');
$act('admin', 'decide', ['rate_id' => $rnr, 'decision' => 'reject', 'reason' => 'Not in the contract']);
$r = one($p, 'SELECT * FROM client_routes WHERE id=?', [$rn]);
ok($r['status'] === 'Rejected' && $r['closed_reason'] === 'Not in the contract' && (int)$r['closed_by'] === $users['admin']['id'], 'reject new request: route Rejected with reason and person');
ok(one($p, 'SELECT status FROM client_route_rates WHERE id=?', [$rnr])['status'] === 'Rejected', 'reject new request: its rate is Rejected');
fails(fn() => $act('admin', 'rate_enter', ['route_id' => $rn, 'rate_type' => 'Flat', 'rate_amount' => '4500']), 'rejected route is terminal (no new rate)', 409);
$again = $act('operations_head', 'request', $req(['origin' => 'Tanauan', 'destination' => 'Lipa']));
ok($again['route_id'] > 0, 'a rejected route does not block a new request for the same route');
ok($checksums() === $before, 'approvals and rejections did not change trips, billings, client_rates, funds, disbursements, expenses');

// ---------------------------------------------------------------------
// F. RATE REVISION SAFETY
// ---------------------------------------------------------------------
section('F. Rate revision: reject, approve, rollback, concurrency');
fails(fn() => $act('admin', 'rate_enter', ['route_id' => $route1, 'rate_type' => 'Flat', 'rate_amount' => '12500']), 'revision equal to the current rate refused', 409, 'same');
$rev1 = $legacyPending($route1, '13000');
ok(one($p, 'SELECT status FROM client_routes WHERE id=?', [$route1])['status'] === 'Approved', 'during a revision the route stays Approved');
fails(fn() => Routes::action($users['admin2'], 'rate_enter', ['route_id' => $route1, 'rate_type' => 'Flat', 'rate_amount' => '13100']), 'second pending revision refused (application rule)', 409, 'legacy rate is still pending');
$sel = Routes::approvedFor($p, $cActive, 'Container');
$x = array_values(array_filter($sel, fn($s) => (int)$s['id'] === $route1))[0] ?? null;
ok($x && $x['rate_amount'] === '12500.00' && (int)$x['rate_id'] === $rate1, 'during a revision the OLD approved rate is the one in use');
$act('admin', 'decide', ['rate_id' => $rev1, 'decision' => 'reject', 'reason' => 'Too high']);
ok(one($p, 'SELECT status FROM client_routes WHERE id=?', [$route1])['status'] === 'Approved', 'rejected revision: route stays Approved');
$o = one($p, 'SELECT * FROM client_route_rates WHERE id=?', [$rate1]);
ok($o['status'] === 'Approved' && $o['rate_amount'] === '12500.00' && $o['effective_to'] === null, 'rejected revision: old approved rate unchanged and still current');
ok(one($p, 'SELECT status FROM client_route_rates WHERE id=?', [$rev1])['status'] === 'Rejected', 'rejected revision: only the revision is Rejected');

// Approve a revision
$rev2 = $legacyPending($route1, '13500', 'admin2', '2027 contract');
$act('admin', 'decide', ['rate_id' => $rev2, 'decision' => 'approve']);
$o = one($p, 'SELECT * FROM client_route_rates WHERE id=?', [$rate1]); $n = one($p, 'SELECT * FROM client_route_rates WHERE id=?', [$rev2]);
ok($o['status'] === 'Superseded' && $o['effective_to'] !== null && $o['rate_amount'] === '12500.00', 'approved revision: old rate Superseded, amount kept, effective_to set');
ok($n['status'] === 'Approved' && (int)$n['version_no'] === 3 && $n['effective_from'] !== null, 'approved revision: new rate Approved as version 3');
ok((int)one($p, "SELECT COUNT(*) AS n FROM client_route_rates WHERE route_id=? AND status='Approved'", [$route1])['n'] === 1, 'exactly one current approved rate');
try { run($p, "UPDATE client_route_rates SET status='Approved' WHERE id=?", [$rate1]); ok(false, 'database refuses two approved rates'); }
catch (PDOException $e) { ok(str_contains($e->getMessage(), 'uq_route_rate_current'), 'database refuses two approved rates for one route (uq_route_rate_current)'); }

// Rollback: make activation of the next revision fail AFTER the old rate was superseded -> everything rolled back.
$rev3 = $legacyPending($route1, '14000');
$routeTriggerReady = true;
try { $p->exec("CREATE TRIGGER test_fail_activation BEFORE UPDATE ON client_route_rates FOR EACH ROW
          BEGIN IF NEW.id = $rev3 AND NEW.status = 'Approved' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated failure'; END IF; END"); }
catch (PDOException $e) {
    if ((int)($e->errorInfo[1] ?? 0) !== 1419) throw $e;
    $routeTriggerReady = false;
    echo "  SKIP  activation rollback trigger test: test MySQL server denies CREATE TRIGGER with binary logging enabled\n";
}
if ($routeTriggerReady) {
try { $act('admin', 'decide', ['rate_id' => $rev3, 'decision' => 'approve']); ok(false, 'simulated failure raised'); }
catch (Throwable $e) { ok(str_contains($e->getMessage(), 'simulated failure'), 'simulated failure during activation raised'); }
$p->exec('DROP TRIGGER test_fail_activation');
$n = one($p, 'SELECT * FROM client_route_rates WHERE id=?', [$rev2]);
ok($n['status'] === 'Approved' && $n['effective_to'] === null, 'after the failure the previous approved rate is still Approved (rolled back)');
ok(one($p, 'SELECT status FROM client_route_rates WHERE id=?', [$rev3])['status'] === 'Pending Approval', 'after the failure the revision is still Pending Approval');
ok(!$p->inTransaction(), 'no transaction left open after the failure');
}

// Concurrency: two processes approve the same revision at the same moment -> exactly one succeeds.
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " --decide $rev3 ";
$procs = [];
foreach ([$users['admin']['id'], $users['admin2']['id']] as $uid) $procs[] = proc_open($cmd . $uid, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$uid]);
$outs = [];
foreach ([$users['admin']['id'], $users['admin2']['id']] as $i => $uid) { $outs[] = stream_get_contents($pipes[$uid][1]) . stream_get_contents($pipes[$uid][2]); proc_close($procs[$i]); }
$okCount = count(array_filter($outs, fn($o) => str_starts_with($o, 'OK')));
$already = count(array_filter($outs, fn($o) => str_contains($o, 'already decided')));
ok($okCount === 1 && $already === 1, 'concurrent approval of the same rate: one succeeds, the other is refused', implode(' | ', $outs));
ok((int)one($p, "SELECT COUNT(*) AS n FROM client_route_rates WHERE route_id=? AND status='Approved'", [$route1])['n'] === 1
   && one($p, 'SELECT status FROM client_route_rates WHERE id=?', [$rev3])['status'] === 'Approved', 'after concurrent approval: one current rate (the revision)');
ok((int)one($p, "SELECT COUNT(*) AS n FROM client_route_events WHERE rate_id=? AND event_type='rate revision approved'", [$rev3])['n'] === 1, 'after concurrent approval: one approval event');

// ---------------------------------------------------------------------
// G. DUPLICATES, TRUCK TYPES, DIRECTION
// ---------------------------------------------------------------------
section('G. Duplicate prevention, truck types, direction');
fails(fn() => $act('operations_head', 'request', $req()), 'same client + truck type + route refused', 409, 'already exists');
fails(fn() => $act('operations_head', 'request', $req(['origin' => '  tanauan;BATANGAS ', 'destination' => 'malvar - batangas'])), 'case / spacing / punctuation variant refused', 409, 'already exists');
$wv = $act('operations_head', 'request', $req(['truck_type' => 'Wing Van']));
ok($wv['route_id'] > 0, 'same route with another truck type (Wing Van) allowed');
$wvr = (int)$act('admin', 'rate_enter', ['route_id' => $wv['route_id'], 'rate_type' => 'Flat', 'rate_amount' => '9800'])['rate_id'];
$both = Routes::approvedFor($p, $cActive);
$tm = array_values(array_filter($both, fn($s) => $s['origin_label'] === 'TANAUAN, BATANGAS' && $s['destination_label'] === 'MALVAR, BATANGAS'));
ok(count($tm) === 2 && count(array_unique(array_column($tm, 'rate_amount'))) === 2, 'Container and Wing Van of the same route have different approved rates');
$back = $act('operations_head', 'request', $req(['origin' => 'Malvar, Batangas', 'destination' => 'Tanauan, Batangas']));
ok($back['route_id'] > 0, 'reverse direction (B -> A) is a separate route');
$other = $act('operations_head', 'request', $req(['client_id' => $cPending]));
ok($other['route_id'] > 0, 'same route for another client allowed');
try { insert($p, 'client_routes', ['route_ref' => 'DUP-1', 'client_id' => $cActive, 'truck_type' => 'Container', 'origin_label' => 'X', 'destination_label' => 'Y',
        'origin_key' => 'TANAUAN BATANGAS', 'destination_key' => 'MALVAR BATANGAS', 'distance_km' => '1', 'status' => 'Pending Rate', 'requested_by' => $users['operations_head']['id']]); ok(false, 'database refuses duplicate active route'); }
catch (PDOException $e) { ok(str_contains($e->getMessage(), 'uq_route_active'), 'database refuses a duplicate active route (uq_route_active)'); }
// race: the application check passes but the database key catches it -> friendly message
$second = conn($FRESH);
$second->exec("INSERT INTO client_routes (route_ref, client_id, truck_type, origin_label, destination_label, origin_key, destination_key, distance_km, status, requested_by)
               VALUES ('RACE-1', $cActive, 'Car Carrier', 'RACE A', 'RACE B', 'RACE A', 'RACE B', 5, 'Pending Rate', {$users['ops2']['id']})");
fails(fn() => $act('operations_head', 'request', $req(['truck_type' => 'Car Carrier', 'origin' => 'race a', 'destination' => 'race b'])), 'route saved by another session is refused with a clear message', 409);

// ---------------------------------------------------------------------
// H. SELECTABLE, WITHDRAW, DEACTIVATE, HISTORY
// ---------------------------------------------------------------------
section('H. Selectable routes, withdraw, deactivate, history');
$selectable = array_column(Routes::approvedFor($p, $cActive), 'id');
$statuses = array_unique(array_map(fn($id) => one($p, 'SELECT status FROM client_routes WHERE id=?', [$id])['status'], $selectable));
ok($statuses === ['Approved'], 'only Approved routes are selectable');
ok(!in_array($rn, $selectable, true) && !in_array((int)$back['route_id'], $selectable, true), 'Rejected and Pending routes are not selectable');
ok(count(Routes::approvedFor($p, $cActive, 'Wing Van')) === 1, 'selectable list filters by truck type');
$fc = $act('operations_head', 'for_client', ['client_id' => $cActive]);
ok(count($fc['routes']) === count($selectable) && $fc['can_open_routes'] === true, 'client details section returns the approved routes');

$wd = (int)$back['route_id'];
fails(fn() => $act('operations_head', 'withdraw', ['route_id' => $wd]), 'withdraw without a reason refused');
$act('operations_head', 'withdraw', ['route_id' => $wd, 'reason' => 'Client cancelled']);
ok(one($p, 'SELECT status FROM client_routes WHERE id=?', [$wd])['status'] === 'Withdrawn', 'own request withdrawn');
fails(fn() => $act('operations_head', 'withdraw', ['route_id' => $route1, 'reason' => 'x']), 'an Approved route cannot be withdrawn', 409);
// withdraw with a pending rate -> the pending rate is closed too
$wp = (int)$act('operations_head', 'request', $req(['origin' => 'Batangas Port', 'destination' => 'Sto Tomas']))['route_id'];
$wpr = $legacyPending($wp, '7000');
$act('operations_head', 'withdraw', ['route_id' => $wp, 'reason' => 'Duplicate of another request']);
ok(one($p, 'SELECT status FROM client_route_rates WHERE id=?', [$wpr])['status'] === 'Rejected', 'withdrawing closes the pending rate');
fails(fn() => $act('admin', 'decide', ['rate_id' => $wpr, 'decision' => 'approve']), 'a rate of a withdrawn route cannot be approved', 409);

$wvId = (int)$wv['route_id'];
$pend = $legacyPending($wvId, '9900');
fails(fn() => $act('admin', 'deactivate', ['route_id' => $wvId]), 'deactivate without a reason refused');
$act('admin', 'deactivate', ['route_id' => $wvId, 'reason' => 'Contract ended']);
ok(one($p, 'SELECT status FROM client_routes WHERE id=?', [$wvId])['status'] === 'Inactive', 'route deactivated');
ok(one($p, 'SELECT status FROM client_route_rates WHERE id=?', [$pend])['status'] === 'Rejected' && one($p, 'SELECT status FROM client_route_rates WHERE id=?', [$wvr])['status'] === 'Approved', 'deactivate: pending revision closed, last approved rate kept as history');
ok(count(Routes::approvedFor($p, $cActive, 'Wing Van')) === 0, 'Inactive route is not selectable');
fails(fn() => $act('admin', 'rate_enter', ['route_id' => $wvId, 'rate_type' => 'Flat', 'rate_amount' => '1']), 'no rate entry on an Inactive route', 409);
$re = $act('operations_head', 'request', $req(['truck_type' => 'Wing Van']));
ok($re['route_id'] > 0, 'an Inactive route does not block a new request');

$det = $act('admin', 'details', ['route_id' => $route1]);
ok(count($det['rates']) === 4 && $det['rates'][0]['version_no'] == 4 && array_column($det['rates'], 'status') === ['Approved', 'Superseded', 'Rejected', 'Superseded'],
   'details: full rate history (4 versions newest first: Approved, Superseded, Rejected, Superseded)', json_encode(array_column($det['rates'], 'status')));
$types = array_column($det['events'], 'event_type');
foreach (['route requested', 'rate entered', 'route approved', 'rate revision entered', 'rate revision rejected', 'rate revision approved'] as $ev) ok(in_array($ev, $types, true), "history has '$ev'");
ok((int)one($p, "SELECT COUNT(*) AS n FROM audit_events WHERE area='Routes'")['n'] >= count($det['events']), 'every route event is also in the activity log');
$page = Routes::read($users['billing'], 'routes', []);
ok(empty($page['_errors']) && count($page['routes']) >= 10 && !$page['can']['rate'] && !$page['can']['approve'], 'Billing can view routes without Admin-only rate or approval actions');
$pf = Routes::read($users['operations_head'], 'routes', ['client_id' => (string)$cPending]);
ok(count(array_unique(array_column($pf['routes'], 'client_id'))) === 1, 'page filter by client works');

// ---------------------------------------------------------------------
// J. CLIENT ELIGIBILITY IS CHECKED AGAIN AT APPROVAL (PR #1 review, item 1)
// ---------------------------------------------------------------------
section('J. Client eligibility re-checked at approval time');
$state = function (int $routeId, int $rateId) use ($p): array {
    return [
        'route' => one($p, 'SELECT status, closed_reason, closed_by, updated_at FROM client_routes WHERE id=?', [$routeId]),
        'rates' => many($p, 'SELECT id, status, decided_by, decided_at, effective_from, effective_to FROM client_route_rates WHERE route_id=? ORDER BY id', [$routeId]),
        'events' => (int)one($p, 'SELECT COUNT(*) AS n FROM client_route_events WHERE route_id=?', [$routeId])['n'],
        'audit' => (int)one($p, "SELECT COUNT(*) AS n FROM audit_events WHERE area='Routes' AND record_ref=?", ['route ' . $routeId])['n'],
        'notif' => many($p, 'SELECT user_id, state FROM notifications WHERE dedupe_key=? ORDER BY user_id', ['route-approve-' . $rateId]),
    ];
};
// 1) New route: request (eligible) -> rate -> client deactivated -> approval blocked
$cJ = $client('LATE DEACTIVATION CORP', 'Active');
$jr = (int)$act('operations_head', 'request', $req(['client_id' => $cJ]))['route_id'];
$jrate = $legacyPending($jr, '8000');
run($p, "UPDATE clients SET status='Inactive' WHERE id=?", [$cJ]);
$snap = $state($jr, $jrate);
fails(fn() => $act('admin', 'decide', ['rate_id' => $jrate, 'decision' => 'approve']), 'approval blocked when the client became Inactive after the request', 409, 'Cannot approve');
ok($state($jr, $jrate) === $snap, 'blocked approval changed nothing (route, rates, events, activity log, notifications)');
ok(one($p, 'SELECT status FROM client_routes WHERE id=?', [$jr])['status'] === 'Pending Approval'
   && one($p, 'SELECT status FROM client_route_rates WHERE id=?', [$jrate])['status'] === 'Pending Approval', 'route and rate stay Pending Approval');
ok(!$p->inTransaction(), 'no transaction left open after the blocked approval');
ok(count(Routes::approvedFor($p, $cJ)) === 0 && count(Routes::selectableFor($p, $cJ)) === 0, 'blocked route is neither approved nor selectable');
$act('admin', 'decide', ['rate_id' => $jrate, 'decision' => 'reject', 'reason' => 'Client deactivated']);
ok(one($p, 'SELECT status FROM client_routes WHERE id=?', [$jr])['status'] === 'Rejected', 'rejection is still allowed for a deactivated client (request can be closed)');
// Incomplete is also not eligible (policy: Active or Pending Rate)
$cJ2 = $client('BECAME INCOMPLETE CORP', 'Pending Rate');
$jr2 = (int)$act('operations_head', 'request', $req(['client_id' => $cJ2]))['route_id'];
$jrate2 = $legacyPending($jr2, '8100');
run($p, "UPDATE clients SET status='Incomplete' WHERE id=?", [$cJ2]);
fails(fn() => $act('admin', 'decide', ['rate_id' => $jrate2, 'decision' => 'approve']), 'approval blocked for a client that is no longer eligible (Incomplete)', 409, 'Cannot approve');
run($p, "UPDATE clients SET status='Pending Rate' WHERE id=?", [$cJ2]);
$act('admin', 'decide', ['rate_id' => $jrate2, 'decision' => 'approve']);
ok(one($p, 'SELECT status FROM client_routes WHERE id=?', [$jr2])['status'] === 'Approved', 'approval works again once the client is eligible');
// 2) Revision of an approved route: client deactivated -> revision approval blocked, old rate kept
$cJ3 = $client('REVISION DEACTIVATION CORP', 'Active');
$jr3 = (int)$act('operations_head', 'request', $req(['client_id' => $cJ3]))['route_id'];
$v1 = (int)$act('admin', 'rate_enter', ['route_id' => $jr3, 'rate_type' => 'Flat', 'rate_amount' => '7000'])['rate_id'];
$v2 = $legacyPending($jr3, '7500');
run($p, "UPDATE clients SET status='Inactive' WHERE id=?", [$cJ3]);
$snap = $state($jr3, $v2);
fails(fn() => $act('admin', 'decide', ['rate_id' => $v2, 'decision' => 'approve']), 'revision approval blocked when the client became Inactive', 409, 'Cannot approve');
ok($state($jr3, $v2) === $snap, 'blocked revision approval changed nothing');
ok(one($p, 'SELECT status FROM client_route_rates WHERE id=?', [$v1])['status'] === 'Approved' && one($p, 'SELECT status FROM client_routes WHERE id=?', [$jr3])['status'] === 'Approved',
   'old approved rate and Approved route are kept');
// 3) Lock order: while another session holds the client row, the decision waits instead of deciding on stale data
$holder = conn($FRESH);
$holder->exec('SET SESSION innodb_lock_wait_timeout = 10');
$holder->beginTransaction();
$holder->query("SELECT id FROM clients WHERE id=$cJ3 FOR UPDATE")->fetchAll();
$p->exec('SET SESSION innodb_lock_wait_timeout = 1');
try { $act('admin', 'decide', ['rate_id' => $v2, 'decision' => 'reject', 'reason' => 'x']); ok(false, 'decision waits for the client lock'); }
catch (PDOException $e) { ok(($e->errorInfo[1] ?? 0) === 1205, 'decision waits for the client row lock (lock wait timeout while another session holds it)', $e->getMessage()); }
$holder->rollBack();
$p->exec('SET SESSION innodb_lock_wait_timeout = 50');
ok(one($p, 'SELECT status FROM client_route_rates WHERE id=?', [$v2])['status'] === 'Pending Approval', 'timed-out decision changed nothing');

// ---------------------------------------------------------------------
// K. RECORD LIST vs PLANNING LIST (PR #1 review, item 2)
// ---------------------------------------------------------------------
section('K. approvedFor (record) vs selectableFor (planning eligibility)');
$cK = $client('PLANNING ELIGIBILITY CORP', 'Active');
$kr = (int)$act('operations_head', 'request', $req(['client_id' => $cK, 'truck_type' => 'Container']))['route_id'];
$act('admin', 'rate_enter', ['route_id' => $kr, 'rate_type' => 'Flat', 'rate_amount' => '6000']);
$kw = (int)$act('operations_head', 'request', $req(['client_id' => $cK, 'truck_type' => 'Wing Van']))['route_id'];
$act('admin', 'rate_enter', ['route_id' => $kw, 'rate_type' => 'Flat', 'rate_amount' => '6500']);
$kp = (int)$act('operations_head', 'request', $req(['client_id' => $cK, 'truck_type' => 'Car Carrier']))['route_id'];   // stays Pending Rate
ok(array_column(Routes::selectableFor($p, $cK), 'id') === array_column(Routes::approvedFor($p, $cK), 'id') && count(Routes::selectableFor($p, $cK)) === 2,
   'Active client: both approved routes are selectable');
ok(!in_array($kp, array_column(Routes::selectableFor($p, $cK), 'id'), true), 'Pending route of an Active client is not selectable');
ok(count(Routes::selectableFor($p, $cK, 'Wing Van')) === 1 && (int)Routes::selectableFor($p, $cK, 'Wing Van')[0]['id'] === $kw, 'selectable list filters by truck type');
run($p, "UPDATE clients SET status='Inactive' WHERE id=?", [$cK]);
ok(count(Routes::selectableFor($p, $cK)) === 0, 'Inactive client: no selectable routes');
ok(count(Routes::approvedFor($p, $cK)) === 2, 'Inactive client: approved route records are still listed (history not hidden)');
ok(count($act('operations_head', 'for_client', ['client_id' => $cK])['routes']) === 2, 'client details still show the approved routes of an Inactive client');
run($p, "UPDATE clients SET status='Pending Rate' WHERE id=?", [$cK]);
ok(count(Routes::selectableFor($p, $cK)) === 0, 'Pending Rate client: not selectable for planning (trips need an Active client)');
run($p, "UPDATE clients SET status='Active' WHERE id=?", [$cK]);
ok(count(Routes::selectableFor($p, $cK)) === 2, 'reactivated client: routes are selectable again');
$all = array_merge(...array_map(fn($c) => Routes::selectableFor($p, (int)$c['id']), many($p, 'SELECT id FROM clients')));
$bad = array_filter($all, fn($r) => one($p, 'SELECT c.status AS cs, r.status AS rs FROM client_routes r JOIN clients c ON c.id=r.client_id WHERE r.id=?', [$r['id']]) != ['cs' => 'Active', 'rs' => 'Approved']);
ok(!$bad && count($all) > 0, 'every selectable route in the database has an Approved route and an Active client', count($bad) . ' bad of ' . count($all));

// ---------------------------------------------------------------------
// L. SCREEN NORMALIZATION = SERVER NORMALIZATION (PR #1 review, item 3)
// ---------------------------------------------------------------------
section('L. RouteUI.norm() (screen) matches Routes::normalize() (server)');
$samples = ["  Tanauan,\t\t  Batangas  ", "Sto.   Tomas", "STO TOMAS", "malvar - batangas", "Lipa\n\nCity", "x--y__z", "Parañaque  City", "Biñan,   Laguna",
            "a\u{00A0}\u{00A0}b", "  ", "123   Road", "San José del Monte", "straße"];
$js = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/modules/operations/routes.js');
$node = trim((string)@shell_exec((DIRECTORY_SEPARATOR === '\\' ? 'where node 2>NUL' : 'command -v node 2>/dev/null')));
if (!preg_match('/^\s*norm: (\(v\) => .*?),\s*$/m', $js, $mm)) {
    ok(false, 'RouteUI.norm found in routes.js');
} elseif ($node === '') {
    echo "  NOTE  Node.js not found: screen/server normalization comparison skipped\n";
} else {
    $tmpJs = tempnam(sys_get_temp_dir(), 'norm') . '.js';
    file_put_contents($tmpJs, 'const norm = ' . $mm[1] . ";\nconst s = " . json_encode($samples, JSON_UNESCAPED_UNICODE) . ";\nprocess.stdout.write(JSON.stringify(s.map(norm)));\n");
    $jsOut = json_decode((string)shell_exec(escapeshellarg(strtok($node, "\r\n")) . ' ' . escapeshellarg($tmpJs)), true);
    @unlink($tmpJs);
    $phpOut = array_map([Routes::class, 'normalize'], $samples);
    ok(is_array($jsOut) && count($jsOut) === count($samples), 'screen normalization ran in Node.js for ' . count($samples) . ' samples');
    foreach ($samples as $i => $s) ok(($jsOut[$i] ?? null) === $phpOut[$i], 'same result for ' . json_encode($s, JSON_UNESCAPED_UNICODE) . ' -> ' . json_encode($phpOut[$i], JSON_UNESCAPED_UNICODE), json_encode($jsOut[$i] ?? null, JSON_UNESCAPED_UNICODE));
}

// ---------------------------------------------------------------------
// I. HISTORICAL DATA + REGRESSION OF THE TRIP / BILLING FLOW
// ---------------------------------------------------------------------
// I. Billing snapshot regression for approved fixed routes. Full manual
// vehicle/crew dispatch is exercised by the isolated Dispatcher Turnover suite.
// ---------------------------------------------------------------------
section('I. Fixed-route billing snapshot survives later contract revisions');
$client2 = $client('REGRESSION CLIENT CORP', 'Active');
insert($p, 'client_rates', ['client_id'=>$client2,'truck_type'=>'Wing Van','rate_per_km'=>'55.50','minimum_charge'=>'1000','updated_by'=>$users['admin']['id']]);
$rr = (int)$act('operations_head','request',['client_id'=>$client2,'truck_type'=>'Wing Van','origin'=>'Tanauan','destination'=>'Malvar','distance_km'=>'20'])['route_id'];
$rateId = (int)$act('admin','rate_enter',['route_id'=>$rr,'rate_type'=>'Flat','rate_amount'=>'99999'])['rate_id'];
$dispatcherId = insert($p,'users',['username'=>'billing_regression_dispatcher','email'=>'billing-regression@example.test',
    'full_name'=>'Billing Regression Dispatcher','password_hash'=>'fixture','role'=>'container_wingvan_dispatcher','shift'=>'Day']);
$created = Trips::action($users['operations_head'],'create',[
    'client_id'=>$client2,'truck_type'=>'Wing Van','client_route_id'=>$rr,
    'dispatcher_assignment'=>'container_wingvan_dispatcher:Day','responsible_dispatcher_id'=>$dispatcherId,
    'pickup_point'=>'Tanauan','delivery_point'=>'Malvar','distance_km'=>'20',
    'scheduled_at'=>date('Y-m-d\TH:i',strtotime('+1 hour'))]);
$trip = one($p,'SELECT * FROM trips WHERE id=?',[$created['trip_id']]);
ok($trip['status']==='Awaiting Resources' && $trip['vehicle_id']===null
    && (int)$trip['client_route_id']===$rr,'Operations Head plans approved fixed route without auto-selecting a Truck');
core('BillingDraft');
$billId = BillingDraft::createFor($p,$trip);
$beforeBill = one($p,'SELECT * FROM billings WHERE id=?',[$billId]);
$beforeSnapshot = one($p,'SELECT snapshot_json FROM billing_pricing WHERE billing_id=?',[$billId])['snapshot_json']??null;
$stored = json_decode((string)$beforeSnapshot,true);
ok($beforeBill && $beforeBill['status']==='Ongoing' && (float)$beforeBill['base_amount']===99999.0
    && (int)($stored['rate_id']??0)===$rateId,'BillingDraft freezes approved fixed route rate rather than legacy per-km price');
Actions::load('Clients');
$cd = Clients::action($users['operations_head'],'details',['id'=>$client2]);
ok(count($cd['rates'])===1 && $cd['rates'][0]['rate_per_km']==='55.50','historical client per-km rates remain visible');
$revId = (int)$act('admin','rate_enter',['route_id'=>$rr,'rate_type'=>'Flat','rate_amount'=>'88888'])['rate_id'];
BillingDraft::createFor($p,$trip);
$afterBill = one($p,'SELECT * FROM billings WHERE id=?',[$billId]);
$afterSnapshot = one($p,'SELECT snapshot_json FROM billing_pricing WHERE billing_id=?',[$billId])['snapshot_json']??null;
ok($beforeBill===$afterBill && $beforeSnapshot===$afterSnapshot &&
    (int)one($p,'SELECT COUNT(*) n FROM billings WHERE trip_id=?',[$trip['id']])['n']===1,
    'later route-rate revision does not mutate or duplicate existing billing snapshot');

// ---------------------------------------------------------------------
echo "\n" . str_repeat('=', 60) . "\nRESULT: $PASS passed, $FAIL failed\n";
if ($FAILED) echo "Failed:\n - " . implode("\n - ", $FAILED) . "\n";
exit($FAIL ? 1 : 0);
