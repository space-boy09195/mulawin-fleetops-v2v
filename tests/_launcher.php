<?php
/*
 * =====================================================================
 *  tests/_launcher.php  —  shared SAFE launcher for the integration tests
 * =====================================================================
 *  - The project .env is NOT read by the tests: the code is copied to a temporary
 *    folder with its own test .env, so db() can only reach the test databases.
 *  - Every database name must end with "_test" and must not be DB_NAME of the project .env.
 *  - MIGRATION CHAIN: when git is available, the schema as it was BEFORE $baseMigration was
 *    added is written to the copy (tests/_base_schema.sql, tests/_base_extension.sql), so the
 *    test can apply every migration from $baseMigration on, in order, and compare the result
 *    with a fresh install.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

function run_isolated_tests(string $testFile, string $defaultPrefix, array $dbSuffixes, string $baseMigration, array $extraEnv = [], bool $createOnly = false): int {
    $root = dirname(__DIR__);
    $cfg = [
        'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('TEST_DB_PORT') ?: '3306',
        'user' => getenv('TEST_DB_USER') ?: '',
        'pass' => getenv('TEST_DB_PASS') !== false ? (string)getenv('TEST_DB_PASS') : '',
        'prefix' => getenv('TEST_DB_PREFIX') ?: $defaultPrefix,
    ];
    if ($cfg['user'] === '') { fwrite(STDERR, "Set TEST_DB_USER (and TEST_DB_PASS) first. See tests/README.md.\n"); return 2; }
    if (!preg_match('/^[a-z0-9_]{1,30}$/', $cfg['prefix'])) { fwrite(STDERR, "TEST_DB_PREFIX may only contain a-z, 0-9 and _ (max 30).\n"); return 2; }
    // Unique names isolate concurrent runs and prevent reuse of old test databases.
    $cfg['prefix'] .= '_' . bin2hex(random_bytes(6));
    if (!in_array($cfg['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
        fwrite(STDERR, "Refused: integration databases must be on the local server.\n"); return 2;
    }
    foreach (array_merge(array_values($cfg), array_values($extraEnv)) as $value) {
        if (strpbrk((string)$value, "\r\n") !== false) { fwrite(STDERR, "Refused: multiline configuration.\n"); return 2; }
    }
    if (!ctype_digit($cfg['port']) || (int)$cfg['port'] < 1 || (int)$cfg['port'] > 65535) {
        fwrite(STDERR, "Refused: invalid test database port.\n"); return 2;
    }
    $names = [];
    foreach ($dbSuffixes as $s) $names[$s] = $cfg['prefix'] . '_' . $s . '_test';

    $projectDb = '';
    if (is_file($root . '/.env')) {
        foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*DB_NAME\s*=\s*["\']?([^"\'\s]*)/', $line, $m)) $projectDb = $m[1];
        }
    }
    foreach ($names as $n) {
        if (!preg_match('/^[a-z0-9_]+_test$/', $n)) { fwrite(STDERR, "Refused: $n does not end with _test.\n"); return 2; }
        if ($projectDb !== '' && strcasecmp($n, $projectDb) === 0) { fwrite(STDERR, "Refused: $n is the project database in .env.\n"); return 2; }
    }

    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mulawin_tests_' . bin2hex(random_bytes(4));
    $copy = function (string $from, string $to) use (&$copy): void {
        if (is_dir($from)) { @mkdir($to, 0700, true); foreach (scandir($from) ?: [] as $f) if ($f !== '.' && $f !== '..' && $f !== 'reports' && !is_link("$from/$f")) $copy("$from/$f", "$to/$f"); }
        else copy($from, $to);
    };
    foreach (['backend', 'config', 'database', 'public', 'tests'] as $dir) $copy("$root/$dir", "$tmp/$dir");
    @mkdir("$tmp/storage/private", 0700, true);
    @mkdir("$tmp/storage/backups", 0700, true);
    $env = ['APP_ENV=test', 'APP_TIMEZONE=Asia/Manila',
        'DB_HOST=' . $cfg['host'], 'DB_PORT=' . $cfg['port'], 'DB_NAME=' . reset($names),
        'DB_USER=' . $cfg['user'], 'DB_PASS=' . $cfg['pass'],
        'MAIL_HOST=', 'MAIL_USERNAME=', 'MAIL_PASSWORD=', 'MAIL_FROM_ADDRESS=', 'ORS_API_KEY='];
    foreach ($names as $s => $n) $env[] = 'TEST_DB_' . strtoupper($s) . '=' . $n;
    foreach ($extraEnv as $k => $v) {
        if (preg_match('/^(DB_|TEST_DB_|APP_ENV)/', $k)) throw new RuntimeException('Refused: isolation configuration override.');
        $env[] = "$k=$v";
    }
    file_put_contents("$tmp/tests/_isolation.json", json_encode([
        'root' => realpath($tmp), 'names' => array_values($names), 'host' => $cfg['host'],
        'port' => $cfg['port'], 'user' => $cfg['user'], 'token' => bin2hex(random_bytes(24)), 'create_only' => $createOnly,
    ], JSON_THROW_ON_ERROR));
    file_put_contents("$tmp/.env", implode("\n", $env) . "\n");

    // Schema BEFORE $baseMigration was added (git), for the migration chain test.
    $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $git = 'git -C ' . escapeshellarg($root);
    $commits = array_values(array_filter(explode("\n", trim((string)@shell_exec("$git log --diff-filter=A --format=%H -- " . escapeshellarg("database/migrations/$baseMigration.sql") . " 2>$null")))));
    $added = $commits ? end($commits) : '';   // oldest commit that added the migration file
    if (preg_match('/^[0-9a-f]{40}$/', $added)) {
        foreach (['schema', 'extension'] as $f) {
            $sql = (string)@shell_exec("$git show " . escapeshellarg("{$added}~1:database/$f.sql") . " 2>$null");
            if ($sql !== '') file_put_contents("$tmp/tests/_base_$f.sql", $sql);
        }
    }

    // Cleanup is registered before running children, including failed runs.
    register_shutdown_function(static function () use ($tmp, $cfg, $names, $createOnly): void {
        $manifest = json_decode((string)@file_get_contents($tmp . '/tests/_isolation.json'), true);
        try {
            $server = new PDO('mysql:host=' . $cfg['host'] . ';port=' . $cfg['port'] . ';charset=utf8mb4', $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            foreach ($names as $name) {
                if ($createOnly) continue; // No SQL DROP: the caller owns and removes its new disposable server.
                try {
                    $token = $server->query("SELECT token FROM `$name`._fleetops_test_owner")->fetchColumn();
                    if (is_array($manifest) && hash_equals($manifest['token'], (string)$token)) $server->exec("DROP DATABASE `$name`");
                } catch (PDOException $e) { /* Missing/unowned databases are never dropped. */ }
            }
        } catch (PDOException $e) { fwrite(STDERR, "Test database cleanup unavailable; no unverified database was dropped.\n"); }

        $remove = static function (string $path) use (&$remove): void {
            if (is_dir($path) && !is_link($path)) {
                foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
                @rmdir($path);
            } else @unlink($path);
        };
        $remove($tmp);
    });
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$tmp/tests/$testFile"), $code);

    return (int)$code;
}

/* ---------------------------------------------------------------------
 * Helpers used INSIDE the temporary copy by the test files.
 * ------------------------------------------------------------------- */
const TEST_NAME_RULE = '/^[a-z0-9_]+_test$/';

/** Refuses to run outside the temporary copy or on a database that does not end with _test. */
function test_guard(array $names): void {
    test_isolation($names);
    foreach ($names as $n) if (!preg_match(TEST_NAME_RULE, (string)$n)) { fwrite(STDERR, "Refused: database name '$n' does not end with _test. Use the tests/run_*.php launcher.\n"); exit(2); }
    $env = dirname(__DIR__) . '/.env';
    if (!is_file($env) || !str_contains((string)file_get_contents($env), 'APP_ENV=test')) { fwrite(STDERR, "Refused: this is not the temporary test copy. Use the tests/run_*.php launcher.\n"); exit(2); }
}
function test_server(): PDO {
    test_isolation([]);
    static $s = null;
    return $s ??= new PDO('mysql:host=' . envValue('DB_HOST') . ';port=' . envValue('DB_PORT') . ';charset=utf8mb4', envValue('DB_USER'), envValue('DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
function test_conn(string $db): PDO {
    test_isolation([$db]);
    if (!preg_match(TEST_NAME_RULE, $db)) { fwrite(STDERR, "Refused $db\n"); exit(2); }
    $p = new PDO('mysql:host=' . envValue('DB_HOST') . ';port=' . envValue('DB_PORT') . ';dbname=' . $db . ';charset=utf8mb4', envValue('DB_USER'), envValue('DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    if ($p->query('SELECT DATABASE()')->fetchColumn() !== $db) { fwrite(STDERR, "Connected to the wrong database\n"); exit(2); }
    return $p;
}
/** Drops and re-creates ONE disposable test database (guarded by the _test name rule). */
function test_recreate(string $db): void {
    $manifest = test_isolation([$db]);
    $server = test_server();
    $q = $server->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
    $q->execute([$db]);
    $exists = (bool)$q->fetchColumn();
    if ($exists) {
        if (!empty($manifest['create_only'])) throw new RuntimeException('Refused: create-only tests never replace an existing database.');
        // A name alone never proves ownership: verify the marker in the active database.
        $p = test_conn($db);
        try { $owner = $p->query('SELECT token FROM _fleetops_test_owner')->fetchColumn(); }
        catch (PDOException $e) { throw new RuntimeException('Refused: database has no ownership marker.'); }
        if (!hash_equals($manifest['token'], (string)$owner)) throw new RuntimeException('Refused: database belongs to another run.');
        $server->exec("DROP DATABASE `$db`");
    }
    // CREATE without IF NOT EXISTS prevents adopting a database created concurrently.
    $server->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $p = test_conn($db);
    $p->exec('CREATE TABLE _fleetops_test_owner (token VARCHAR(48) NOT NULL)');
    $p->prepare('INSERT INTO _fleetops_test_owner VALUES (?)')->execute([$manifest['token']]);
}

/** Drop only a database bearing this run's ownership token. */
function test_drop(string $db): void {
    $m = test_isolation([$db]);
    if (!empty($m['create_only'])) throw new RuntimeException('Refused: database drops are disabled for this test run.');
    $q = test_server()->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
    $q->execute([$db]);
    if (!$q->fetchColumn()) return;
    try { $owner = test_conn($db)->query('SELECT token FROM _fleetops_test_owner')->fetchColumn(); }
    catch (PDOException $e) { throw new RuntimeException('Refused: missing database ownership marker.'); }
    if (!hash_equals($m['token'], (string)$owner)) throw new RuntimeException('Refused: database ownership mismatch.');
    test_server()->exec("DROP DATABASE `$db`");
}

/** Validate the temporary copy, exact database allowlist and connection configuration. */
function test_isolation(array $names): array {
    $root = realpath(dirname(__DIR__));
    $file = __DIR__ . '/_isolation.json';
    $m = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    if (!is_array($m) || ($m['root'] ?? '') !== $root
        || !str_starts_with(basename($root), 'mulawin_tests_')
        || realpath(dirname($root)) !== realpath(sys_get_temp_dir())
        || !preg_match('/^[a-f0-9]{48}$/', $m['token'] ?? '')
        || !is_array($m['names'] ?? null)) throw new RuntimeException('Refused: no valid temporary isolation manifest.');
    if (!function_exists('envValue') || envValue('APP_ENV') !== 'test'
        || envValue('DB_HOST') !== $m['host'] || envValue('DB_PORT') !== $m['port']
        || envValue('DB_USER') !== $m['user']
        || !in_array(envValue('DB_NAME'), $m['names'], true)) throw new RuntimeException('Refused: test connection configuration mismatch.');
    foreach (array_merge($m['names'], $names) as $name) {
        if (!preg_match(TEST_NAME_RULE, (string)$name) || strlen($name) > 64
            || !in_array($name, $m['names'], true)) throw new RuntimeException('Refused: database is outside the run allowlist.');
    }
    return $m;
}
/** Imports SQL text (same splitting rule as scripts/seed_test_data.php). */
function test_import(PDO $p, string $sql): void {
    $sql = (string)preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (preg_split('/;\s*\n/', $sql) as $stmt) { $stmt = trim($stmt); if ($stmt !== '') $p->exec($stmt); }
}
function test_sql(string $rel): string { return (string)file_get_contents(dirname(__DIR__) . '/' . $rel); }
/** Migration files from $first on, in name order (= the order they must be imported). */
function test_migrations_from(string $first): array {
    $out = [];
    foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [] as $f) { $id = basename($f, '.sql'); if (strcmp($id, $first) >= 0) $out[] = $id; }
    sort($out, SORT_STRING);
    return $out;
}
/** Schema fingerprint of a database (tables, columns, indexes, checks, foreign keys). */
function test_fingerprint(PDO $p, string $db): array {
    $q = fn(string $sql) => array_map('json_encode', many($p, $sql, [$db]));
    return [
        'tables' => $q('SELECT table_name, engine, table_collation FROM information_schema.tables WHERE table_schema=? ORDER BY table_name'),
        'columns' => $q('SELECT table_name, column_name, ordinal_position, column_type, is_nullable, column_default, extra, generation_expression, column_comment
                         FROM information_schema.columns WHERE table_schema=? ORDER BY table_name, ordinal_position'),
        'indexes' => $q('SELECT table_name, index_name, seq_in_index, column_name, non_unique FROM information_schema.statistics WHERE table_schema=? ORDER BY table_name, index_name, seq_in_index'),
        'checks' => $q('SELECT tc.table_name, cc.constraint_name, cc.check_clause FROM information_schema.check_constraints cc JOIN information_schema.table_constraints tc ON tc.constraint_schema=cc.constraint_schema AND tc.constraint_name=cc.constraint_name AND tc.constraint_type=\'CHECK\' WHERE cc.constraint_schema=? ORDER BY tc.table_name, cc.constraint_name'),
        'fks' => $q('SELECT k.table_name, k.constraint_name, k.column_name, k.referenced_table_name, k.referenced_column_name FROM information_schema.key_column_usage k
                     WHERE k.table_schema=? AND k.referenced_table_name IS NOT NULL ORDER BY k.table_name, k.constraint_name, k.ordinal_position'),
    ];
}

/**
 * INTEGRATION_PLANNING_FIXTURE (test databases only). Creates a trip through the real kenneth workflow that replaced
 * Trips 'create' + automatic assignment:
 *   1. Operations Head 'create' with an approved client route (km = the payload km), client rate, dispatcher group:Day
 *      and the responsible Dispatcher (the suite's existing Dispatcher account when there is one).
 *   2. When a truck of the type is Available: its fixed Driver (and regular Helper) are linked if the master data has
 *      none, timed in by that Dispatcher (Attendance 'time_in') and assigned with Trips 'assign_resources'.
 *      With no free truck the trip stays Awaiting Resources, as the retired automatic assignment did.
 * Returns the 'create' result (trip_id, ...).
 */
function test_create_planned_trip(array $ops, array $payload, bool $assign = true): array {
    $p = db();
    $type = (string)$payload['truck_type']; $cid = (int)$payload['client_id']; $km = number_format((float)($payload['distance_km'] ?? 20), 2, '.', '');
    $role = dispatcherRoleFor($type);
    $disp = one($p, 'SELECT id, role, shift, full_name FROM users WHERE role=? AND active=1 ORDER BY id LIMIT 1', [$role]);
    if (!$disp) { insert($p, 'users', ['username' => 'fx_' . $role, 'email' => 'fx_' . $role . '@example.test', 'password_hash' => 'fixture',
        'full_name' => 'Fixture ' . $role, 'role' => $role, 'shift' => 'Day']); $disp = one($p, 'SELECT id, role, shift, full_name FROM users WHERE role=? ORDER BY id DESC LIMIT 1', [$role]); }
    if ($disp['shift'] !== 'Day') { run($p, "UPDATE users SET shift='Day' WHERE id=?", [$disp['id']]); $disp['shift'] = 'Day'; }
    $disp['id'] = (int)$disp['id'];
    if (empty($payload['client_route_id']) && !one($p, 'SELECT 1 FROM client_rates WHERE client_id=? AND truck_type=?', [$cid, $type]))
        insert($p, 'client_rates', ['client_id' => $cid, 'truck_type' => $type, 'rate_per_km' => '55', 'minimum_charge' => '0', 'updated_by' => $ops['id']]);
    $route = !empty($payload['client_route_id']) ? ['id' => (int)$payload['client_route_id']]   // the caller chose the route: create nothing
        : one($p, "SELECT id FROM client_routes WHERE client_id=? AND truck_type=? AND status='Approved' AND distance_km=? ORDER BY id LIMIT 1", [$cid, $type, $km]);
    if ($route) $routeId = (int)$route['id'];
    else {
        $tag = $cid . '-' . str_replace(' ', '', $type) . '-' . str_replace('.', '_', $km);
        $routeId = insert($p, 'client_routes', ['route_ref' => 'FX-' . $tag, 'client_id' => $cid, 'truck_type' => $type, 'origin_label' => 'Fixture origin ' . $tag,
            'destination_label' => 'Fixture destination ' . $tag, 'origin_key' => 'fixture origin ' . $tag, 'destination_key' => 'fixture destination ' . $tag,
            'distance_km' => $km, 'status' => 'Approved', 'requested_by' => $ops['id']]);
        insert($p, 'client_route_rates', ['route_id' => $routeId, 'version_no' => 1, 'rate_type' => 'Flat', 'rate_amount' => '1100', 'status' => 'Approved',
            'entered_by' => $ops['id'], 'effective_from' => date('Y-m-d H:i:s', time() - 60)]);
    }
    $res = Trips::action($ops, 'create', $payload + ['client_route_id' => $routeId, 'dispatcher_assignment' => $role . ':Day', 'responsible_dispatcher_id' => $disp['id']]); // explicit fixture values win
    if (!$assign) return $res;
    $tid = (int)$res['trip_id'];
    $v = one($p, "SELECT * FROM vehicles WHERE active=1 AND truck_type=? AND status='Available'
                  AND NOT EXISTS (SELECT 1 FROM trip_assignments a WHERE a.resource_type='vehicle' AND a.resource_id=vehicles.id) ORDER BY id LIMIT 1", [$type]);
    if (!$v) return $res;
    $free = fn(string $kind, array $not) => one($p, "SELECT id FROM personnel WHERE active=1 AND person_type=? AND availability='Available'
        AND NOT EXISTS (SELECT 1 FROM trip_assignments a WHERE a.resource_type='person' AND a.resource_id=personnel.id)
        AND NOT EXISTS (SELECT 1 FROM vehicles x WHERE x.active=1 AND (x.assigned_driver_id=personnel.id OR x.assigned_helper_id=personnel.id))"
        . ($not ? ' AND id NOT IN (' . implode(',', array_map('intval', $not)) . ')' : '') . ' ORDER BY id LIMIT 1', [$kind]);
    $link = static fn(string $col, ?array $pe) => $pe ? run($p, "UPDATE vehicles SET $col=? WHERE id=?", [(int)$pe['id'], (int)$v['id']]) : null;
    if (!$v['assigned_driver_id']) { $link('assigned_driver_id', $free('Driver', [])); }
    $needHelper = $type !== 'Car Carrier';
    if ($needHelper && !$v['assigned_helper_id']) { $link('assigned_helper_id', $free('Helper', [])); }
    $v = one($p, 'SELECT * FROM vehicles WHERE id=?', [(int)$v['id']]);
    if (!$v['assigned_driver_id'] || ($needHelper && !$v['assigned_helper_id'])) return $res;
    $crew = array_filter([(int)$v['assigned_driver_id'], $needHelper ? (int)$v['assigned_helper_id'] : 0]);
    $driver2 = null;
    if (!$needHelper) { $d2 = $free('Driver', [(int)$v['assigned_driver_id']]); if (!$d2) return $res; $driver2 = (int)$d2['id']; $crew[] = $driver2; }
    // kenneth: a Driver needs a license number and expiry before assignment; complete the fixture master data only where missing.
    foreach ($crew as $pid) run($p, "UPDATE personnel SET license_number=COALESCE(license_number, CONCAT('FX-LIC-', id)), license_expiry=COALESCE(license_expiry, '2099-12-31') WHERE id=? AND person_type='Driver'", [$pid]);
    Actions::load('Attendance');
    foreach ($crew as $pid) if (!one($p, 'SELECT 1 FROM dispatcher_attendance_sessions WHERE personnel_id=? AND time_out IS NULL', [$pid])) {
        $h = bin2hex(random_bytes(16));
        Attendance::action($disp, 'time_in', ['personnel_id' => $pid, 'request_key' => substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12)]);
    }
    Trips::action($disp, 'assign_resources', ['trip_id' => $tid, 'vehicle_id' => (int)$v['id']] + ($needHelper ? ['helper_id' => (int)$v['assigned_helper_id']] : ['driver2_id' => $driver2]));
    return $res;
}
