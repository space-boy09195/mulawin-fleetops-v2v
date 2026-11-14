<?php
/*
 * =====================================================================
 *  tests/phase2_migration_safety_tests.php  —  Safety of migrations 2026-11-10_10_mechanic_attendance and 2026-11-10_11_daily_attendance
 * =====================================================================
 *  Do NOT run this file directly: run tests/run_phase2_migration_safety_tests.php (temporary copy, own .env, disposable *_test databases only).
 *    A. File structure: the schema_migrations record is the LAST statement; nothing records earlier; fresh-install order
 *    B. Migration 10: existing unavailable_reason never overwritten; stopped after each step -> PARTIAL -> recovery; rollback restores the originals;
 *       foreign-key column types / signedness checked against the real schema
 *    C. Migration 11: clean run, history untouched, stopped after the copy failed -> PARTIAL -> recovery, no duplicate / resurrection
 *    D. Read-only check files run and give the expected state
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/backend/Actions.php';
require_once __DIR__ . '/_launcher.php';

$FRESH = envValue('DB_NAME');
$UPGRADE = envValue('TEST_DB_UPGRADE');
test_guard([$FRESH, $UPGRADE]);
$PASS = 0; $FAIL = 0; $FAILED = [];
function ok(bool $c, string $name, string $detail = ''): void {
    global $PASS, $FAIL, $FAILED;
    if ($c) { $PASS++; echo "  PASS  $name\n"; } else { $FAIL++; $FAILED[] = $name; echo "  FAIL  $name" . ($detail !== '' ? "  -> $detail" : '') . "\n"; }
}
function section(string $t): void { echo "\n== $t ==\n"; }
/** Statements of a SQL file (comment lines removed), like test_import splits them. */
function stmts(string $rel): array { return array_values(array_filter(array_map('trim', preg_split('/;\s*\n/', (string)preg_replace('/^\s*--.*$/m', '', test_sql($rel)))))); }
/** Run statements one by one; returns [executed count, error message|null]. */
function run_stmts(PDO $c, array $list): array {
    $n = 0;
    foreach ($list as $s) { try { $c->exec($s); $n++; } catch (PDOException $e) { return [$n, $e->getMessage()]; } }
    return [$n, null];
}
/** The query of a check file that produces the "state" column. */
function state_of(PDO $c, string $id): string {
    foreach (stmts("database/migrations/checks/{$id}_check.sql") as $q) if (str_contains($q, 'AS state')) return (string)$c->query($q)->fetch()['state'];
    return 'NO STATE QUERY';
}
$M10 = '2026-11-10_10_mechanic_attendance'; $M11 = '2026-11-10_11_daily_attendance';

section('Setup: disposable databases');
test_recreate($FRESH);
$p = db();
test_import($p, test_sql('database/schema.sql'));
test_import($p, test_sql('database/extension.sql'));
$baseSchema = __DIR__ . '/_base_schema.sql'; $baseExt = __DIR__ . '/_base_extension.sql';

// ---------------------------------------------------------------------
section('A. Record is the LAST step');
foreach ([$M10 => 'mechanic_attendance', $M11 => 'attendance_daily'] as $id => $table) {
    foreach (["database/migrations/$id.sql" => 'migration', "database/migrations/recovery/{$id}_finish.sql" => 'recovery file'] as $rel => $what) {
        $s = stmts($rel);
        ok(str_starts_with(end($s), "INSERT INTO schema_migrations (id) VALUES ('$id')"), "$id $what: the schema_migrations record is the last statement");
        ok(count(array_filter(array_slice($s, 0, -1), fn($x) => str_contains($x, 'schema_migrations') && !str_contains($x, 'SELECT 1 FROM schema_migrations r'))) === 0, "$id $what: nothing records the migration earlier");
    }
    $m = stmts("database/migrations/$id.sql");
    ok(str_starts_with($m[1], 'CREATE TABLE ' . $table), "$id: the new table is created by ONE statement (all-or-nothing) right after the session setting");
    $all = implode("\n", $m);
    ok(!preg_match('/\b(DROP|RENAME|TRUNCATE|DELETE)\b/i', $all), "$id: no DROP / RENAME / TRUNCATE / DELETE anywhere in the migration");
}
$ext = test_sql('database/extension.sql');
foreach ([$M10 => 'CREATE TABLE mechanic_attendance', $M11 => 'CREATE TABLE attendance_daily'] as $id => $create) {
    $posCreate = strpos($ext, $create); $posId = strpos($ext, "'$id'");
    ok($posCreate !== false && $posId !== false && $posId > $posCreate, "extension.sql (fresh install): $id is recorded AFTER its table is created");
}
ok((int)one($p, 'SELECT COUNT(*) AS n FROM schema_migrations WHERE id IN (?,?)', [$M10, $M11])['n'] === 2 && (int)$p->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('mechanic_attendance','attendance_daily')")->fetchColumn() === 2, 'fresh install: both tables exist and both ids are recorded');
ok(state_of($p, $M10) === 'COMPLETE' && state_of($p, $M11) === 'COMPLETE', 'check files say COMPLETE on a fresh install');

if (!is_file($baseSchema) || !is_file($baseExt)) {
    echo "  NOTE  git history not available: upgrade scenarios skipped (run from a git clone)\n";
} else {
    /** Old database just before $before (all earlier migrations applied). */
    $oldDb = function (string $before) use ($UPGRADE, $baseSchema, $baseExt): PDO {
        test_recreate($UPGRADE);
        $c = test_conn($UPGRADE);
        test_import($c, (string)file_get_contents($baseSchema)); test_import($c, (string)file_get_contents($baseExt));
        foreach (test_migrations_from('2026-11-08_08_attendance') as $m) if (strcmp($m, $before) < 0) test_import($c, test_sql("database/migrations/$m.sql"));
        return $c;
    };
    $tables = fn(PDO $c) => $c->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
    $mechSnap = fn(PDO $c) => $c->query('SELECT id, mechanic_code, full_name, duty_shift, active FROM mechanics ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

    // -----------------------------------------------------------------
    section('B. Migration 10: data preservation, partial failure, recovery, rollback');
    $stepsB = stmts("database/migrations/$M10.sql");
    ok(count($stepsB) === 7, 'migration 10 = session setting, CREATE attendance, CREATE backup, backup INSERT, availability UPDATE, default, record LAST');
    $mig10Files = ["database/migrations/$M10.sql", "database/migrations/recovery/{$M10}_finish.sql", "database/migrations/rollback/{$M10}_rollback.sql"];
    foreach ($mig10Files as $rel) {
        ok(!str_contains(test_sql($rel), 'Not timed in (migration)'), "$rel: no marker text (an existing field is never used as a marker)");
    }
    foreach (["database/migrations/$M10.sql", "database/migrations/recovery/{$M10}_finish.sql"] as $rel) {
        $writes = array_filter(stmts($rel), fn($x) => preg_match('/^\s*UPDATE\s+mechanics\b[^;]*\bSET\b[^;]*unavailable_reason/is', $x));
        ok(count($writes) === 0, "$rel: no statement writes mechanics.unavailable_reason");
    }
    /** Old DB before migration 10 with mechanics that have EXISTING unavailable reasons. */
    $seed10 = function () use ($oldDb, $M10): PDO {
        $c = $oldDb($M10);
        $c->exec("INSERT INTO mechanics (mechanic_code, full_name, duty_shift, availability, unavailable_reason) VALUES
          ('M-1','AVAILABLE WITH REASON','Day','Available','Waiting for spare parts'),
          ('M-2','AVAILABLE NO REASON','Night','Available',NULL),
          ('M-3','ASSIGNED','Day','Assigned','kept as is'),
          ('M-4','OFF','Night','Unavailable','On sick leave'),
          ('M-5','AVAILABLE TIMED IN','Day','Available','old note')");
        return $c;
    };
    $fullSnap = fn(PDO $c) => $c->query('SELECT * FROM mechanics ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $reasons = fn(PDO $c) => $c->query('SELECT mechanic_code, unavailable_reason FROM mechanics ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $avail = fn(PDO $c) => $c->query('SELECT mechanic_code, availability FROM mechanics ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $withAdmin = function (PDO $c): int { return insert($c, 'users', ['username' => 'a', 'email' => 'a@example.test', 'password_hash' => 'x', 'full_name' => 'A', 'role' => 'admin']); };
    $openIn = function (PDO $c, int $adm, string $code): void { $c->exec("INSERT INTO mechanic_attendance (mechanic_id, time_in, time_in_by) SELECT id, NOW(), $adm FROM mechanics WHERE mechanic_code='$code'"); };
    $backupRows = fn(PDO $c) => $c->query('SELECT m.mechanic_code, b.original_availability, b.original_unavailable_reason FROM mechanic_availability_backup b JOIN mechanics m ON m.id=b.mechanic_id ORDER BY b.mechanic_id')->fetchAll(PDO::FETCH_NUM);
    $expectedBackup = [['M-1', 'Available', 'Waiting for spare parts'], ['M-2', 'Available', null]];
    $expectedAvail = ['M-1' => 'Unavailable', 'M-2' => 'Unavailable', 'M-3' => 'Assigned', 'M-4' => 'Unavailable', 'M-5' => 'Available'];

    // B1. Stopped after each step: PARTIAL, then the recovery file; originals are never lost.
    // $stepsB: [0] session, [1] CREATE attendance, [2] CREATE backup, [3] backup INSERT, [4] UPDATE availability, [5] default, [6] record
    foreach ([2 => 'after step 1', 3 => 'after step 2', 4 => 'after step 3', 5 => 'after step 4', 6 => 'after step 5'] as $n => $label) {
        $up = $seed10(); $adm = $withAdmin($up); $orig = $fullSnap($up); $origReasons = $reasons($up);
        run_stmts($up, array_slice($stepsB, 0, 2));
        $openIn($up, $adm, 'M-5');                                     // M-5 has an open Time In
        [$k, $err] = run_stmts($up, array_slice($stepsB, 2, $n - 2));
        ok($err === null && state_of($up, $M10) === 'PARTIAL' && !one($up, 'SELECT id FROM schema_migrations WHERE id=?', [$M10]), "stopped $label: state PARTIAL, not recorded", (string)$err);
        $beforeRe = [$fullSnap($up), $tables($up)];
        [$k, $err] = run_stmts($up, $stepsB);
        ok($err !== null && str_contains($err, 'already exists') && [$fullSnap($up), $tables($up)] === $beforeRe, "stopped $label: importing the migration again stops at step 1 and changes nothing");
        [$k, $err] = run_stmts($up, stmts("database/migrations/recovery/{$M10}_finish.sql"));
        ok($err === null && state_of($up, $M10) === 'COMPLETE' && (bool)one($up, 'SELECT id FROM schema_migrations WHERE id=?', [$M10]), "stopped $label: recovery file -> COMPLETE and recorded", (string)$err);
        ok($reasons($up) === $origReasons, "stopped $label: every existing unavailable_reason is exactly as before (none overwritten)", json_encode($reasons($up)));
        ok($avail($up) === $expectedAvail, "stopped $label: Available without Time In -> Unavailable; Assigned, Unavailable and timed-in mechanics untouched", json_encode($avail($up)));
        ok($backupRows($up) === $expectedBackup, "stopped $label: backup holds the ORIGINAL availability and reason of exactly the changed mechanics", json_encode($backupRows($up)));
        $now = $fullSnap($up); foreach ($now as $i => $row) { unset($now[$i]['availability']); unset($orig[$i]['availability']); }
        ok($now === $orig, "stopped $label: all other mechanic columns (id, code, name, legacy duty_shift, reason, active, notes) identical to before");
    }
    // B2. Recovery refused when already COMPLETE; refused (nothing created) when step 1 never ran.
    [$k, $err] = run_stmts($up, stmts("database/migrations/recovery/{$M10}_finish.sql"));
    ok($err !== null && str_contains($err, 'Duplicate') && state_of($up, $M10) === 'COMPLETE' && $backupRows($up) === $expectedBackup, 'recovery file on a COMPLETE database: refused ("Duplicate entry"), backup untouched');
    $fresh10 = $seed10(); $beforeFresh = [$fullSnap($fresh10), $tables($fresh10)];
    [$k, $err] = run_stmts($fresh10, stmts("database/migrations/recovery/{$M10}_finish.sql"));
    ok($err !== null && [$fullSnap($fresh10), $tables($fresh10)] === $beforeFresh && state_of($fresh10, $M10) === 'NOT APPLIED', 'recovery file when step 1 never ran: refused at the guard, nothing created or changed');
    // B3. Clean run, verification queries, and repeating the backup / update statements.
    $up = $seed10(); $adm = $withAdmin($up); $orig = $fullSnap($up); $origReasons = $reasons($up);
    run_stmts($up, array_slice($stepsB, 0, 2)); $openIn($up, $adm, 'M-5'); run_stmts($up, array_slice($stepsB, 2));
    ok(state_of($up, $M10) === 'COMPLETE' && $reasons($up) === $origReasons && $avail($up) === $expectedAvail && $backupRows($up) === $expectedBackup, 'clean import: COMPLETE, reasons untouched, originals saved');
    $up->exec("DELETE FROM schema_migrations WHERE id='$M10'");          // simulate "not yet recorded", then repeat the data steps
    $up->exec($stepsB[3]); $up->exec($stepsB[4]); $up->exec($stepsB[3]);
    ok($backupRows($up) === $expectedBackup && $avail($up) === $expectedAvail && $reasons($up) === $origReasons, 'backup INSERT and availability UPDATE repeated: no duplicate, no overwritten original, no change');
    $up->exec("INSERT INTO schema_migrations (id) VALUES ('$M10')");
    foreach (stmts("database/migrations/checks/{$M10}_check.sql") as $q) {
        if (!str_starts_with($q, 'SELECT') || str_contains($q, 'AS state')) continue;
        if (str_contains($q, 'WHERE m.availability = \'Available\' AND NOT EXISTS') || str_contains($q, 'original_availability <>') || str_contains($q, 'NOT (m.unavailable_reason <=>')) ok($up->query($q)->fetchAll() === [], 'verification query returns 0 rows: ' . substr(preg_replace('/\s+/', ' ', $q), 0, 70));
        if (str_contains($q, 'earlier_draft_marker_rows')) ok((int)$up->query($q)->fetchColumn() === 0, 'verification: no earlier-draft marker rows');
        if (str_contains($q, 'backed_up_mechanics')) { $r = $up->query($q)->fetch(); ok((int)$r['backed_up_mechanics'] === 2 && (int)$r['backed_up_with_reason'] === 1, 'verification: 2 backed-up mechanics, 1 with an original reason'); }
    }
    // B4. Rollback: restores the originals, then the migration can be applied again.
    $rb = stmts("database/migrations/rollback/{$M10}_rollback.sql");
    $up->exec("INSERT INTO mechanics (mechanic_code, full_name, availability) VALUES ('M-6','NEW AFTER MIGRATION','Unavailable')");   // created after the migration: not in the backup
    $up->exec("UPDATE mechanics SET availability='Assigned' WHERE mechanic_code='M-5'");                                                     // timed-in mechanic got a repair: must stay
    $preRb = $fullSnap($up);
    [$k, $err] = run_stmts($up, $rb);
    ok($err === null && count($rb) === 5, 'rollback file runs without error', (string)$err);
    $back = $fullSnap($up);
    $byCode = fn(array $rows) => array_column($rows, null, 'mechanic_code');
    $o = $byCode($orig); $bk = $byCode($back);
    $same = true; foreach (['M-1', 'M-2', 'M-3', 'M-4'] as $c) $same = $same && $bk[$c] == $o[$c];
    ok($same, 'rollback restored the ORIGINAL availability and unavailable_reason of every migrated mechanic (rows identical to before the migration)');
    ok($bk['M-5']['availability'] === 'Assigned' && $bk['M-5']['unavailable_reason'] === 'old note', 'rollback leaves a mechanic that was timed in / assigned after the migration alone');
    ok($bk['M-6']['availability'] === 'Unavailable', 'rollback does not touch a mechanic created after the migration (no backup row)');
    ok(!one($up, 'SELECT id FROM schema_migrations WHERE id=?', [$M10]) && state_of($up, $M10) === 'NOT APPLIED' && !array_intersect(['mechanic_attendance', 'mechanic_availability_backup'], $tables($up)), 'rollback: tables dropped, record removed LAST, state NOT APPLIED');
    ok((string)$up->query("SELECT column_default FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='mechanics' AND column_name='availability'")->fetchColumn() === 'Available' || str_contains((string)$up->query("SELECT column_default FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='mechanics' AND column_name='availability'")->fetchColumn(), 'Available'), 'rollback: column default is Available again');
    [$k, $err] = run_stmts($up, $steps = $stepsB);
    ok($err === null && state_of($up, $M10) === 'COMPLETE', 'the migration can be applied again after a rollback (repeatable)', (string)$err);
    // B5. Rollback that stopped half-way, then run again.
    foreach ([1, 2, 3] as $cut) {
        $up = $seed10(); $adm = $withAdmin($up); $orig = $fullSnap($up);
        run_stmts($up, $stepsB);
        run_stmts($up, array_slice($rb, 0, $cut));
        [$k, $err] = run_stmts($up, $rb);
        $bk = $byCode($fullSnap($up)); $same = true; foreach (['M-1', 'M-2', 'M-3', 'M-4', 'M-5'] as $c) $same = $same && $bk[$c] == $byCode($orig)[$c];
        ok($err === null && $same && state_of($up, $M10) === 'NOT APPLIED', "rollback stopped after statement $cut and run again: originals restored, state NOT APPLIED", (string)$err);
    }
    // B6. Recorded but the backup table is missing (an earlier draft was run): UNEXPECTED, nothing may run blindly.
    $up = $seed10(); run_stmts($up, $stepsB); $up->exec('DROP TABLE mechanic_availability_backup');
    ok(state_of($up, $M10) === 'UNEXPECTED', 'recorded but backup table missing -> state UNEXPECTED');
    // B7. Foreign-key column types and signedness are identical to the referenced columns (real schema, fresh and upgraded).
    $fkQ = "SELECT k.table_name AS t, k.column_name AS col, k.referenced_table_name AS rt, k.referenced_column_name AS rcol, c1.column_type AS ct, c2.column_type AS rct
            FROM information_schema.key_column_usage k
            JOIN information_schema.columns c1 ON c1.table_schema=k.table_schema AND c1.table_name=k.table_name AND c1.column_name=k.column_name
            JOIN information_schema.columns c2 ON c2.table_schema=k.referenced_table_schema AND c2.table_name=k.referenced_table_name AND c2.column_name=k.referenced_column_name
            WHERE k.table_schema=DATABASE() AND k.table_name IN ('mechanic_attendance','mechanic_availability_backup','attendance_daily')";
    $up2 = $oldDb($M10); test_import($up2, test_sql("database/migrations/$M10.sql")); test_import($up2, test_sql("database/migrations/$M11.sql"));
    foreach (['fresh install' => $p, 'upgraded database' => $up2] as $lbl => $c) {
        $fks = $c->query($fkQ)->fetchAll(PDO::FETCH_ASSOC);
        $bad = array_filter($fks, fn($r) => $r['ct'] !== $r['rct'] || str_contains($r['ct'], 'unsigned'));
        ok(count($fks) === 7 && !$bad, "$lbl: all 7 foreign keys of the Phase 2 tables have exactly the referenced column type, signed", json_encode(array_values($bad)));
    }
    $ids = $p->query("SELECT table_name, column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND column_name='id' AND table_name IN ('users','personnel','mechanics')")->fetchAll(PDO::FETCH_KEY_PAIR);
    ok(count($ids) === 3 && !array_filter($ids, fn($t) => str_contains($t, 'unsigned')) && count(array_unique(array_map(fn($t) => preg_replace('/\(\d+\)/', '', $t), $ids))) === 1, 'users.id, personnel.id and mechanics.id are the same signed INT type', json_encode($ids));

    // -----------------------------------------------------------------
    section('C. Migration 11: partial failure and recovery');
    $up = $oldDb($M11);
    $uA = insert($up, 'users', ['username' => 'a1', 'email' => 'a1@example.test', 'password_hash' => 'x', 'full_name' => 'ADMIN ONE', 'role' => 'admin']);
    $uB = insert($up, 'users', ['username' => 'a2', 'email' => 'a2@example.test', 'password_hash' => 'x', 'full_name' => 'ADMIN TWO', 'role' => 'admin']);
    $pe = fn(string $n, ?string $sh) => insert($up, 'personnel', ['full_name' => $n, 'person_type' => 'Driver', 'duty_shift' => $sh]);
    $p1 = $pe('ONE', 'Day'); $p2 = $pe('TWO', 'Night'); $p3 = $pe('THREE', 'Day');
    $mc = insert($up, 'mechanics', ['mechanic_code' => 'M-L', 'full_name' => 'MECH', 'duty_shift' => 'Day']);
    $mk = fn($d, $sh, $col, $id, $st, $by, $at) => insert($up, 'attendance_marks', ['duty_date' => $d, 'duty_shift' => $sh, $col => $id, 'status' => $st, 'marked_by' => $by, 'marked_at' => $at]);
    $mk('2026-11-01', 'Day', 'personnel_id', $p1, 'Present', $uA, '2026-11-01 07:00:00');
    $mk('2026-11-01', 'Night', 'personnel_id', $p2, 'Absent', $uA, '2026-11-01 19:00:00');
    $mk('2026-11-02', 'Day', 'personnel_id', $p3, 'Absent', $uA, '2026-11-02 07:00:00');
    $mk('2026-11-02', 'Night', 'personnel_id', $p3, 'Present', $uB, '2026-11-02 19:00:00');
    $mk('2026-11-03', 'Day', 'mechanic_id', $mc, 'Present', $uA, '2026-11-03 07:00:00');
    $legacy = fn() => $up->query('SELECT id, duty_date, duty_shift, personnel_id, mechanic_id, status, marked_by, marked_at FROM attendance_marks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $shifts = fn() => [$up->query('SELECT id, duty_shift FROM personnel ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR), $up->query('SELECT id, duty_shift FROM mechanics ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR)];
    $L = $legacy(); $S = $shifts();
    ok(state_of($up, $M11) === 'NOT APPLIED', 'before: state NOT APPLIED');
    $steps = stmts("database/migrations/$M11.sql");
    ok(count($steps) === 4, 'migration = session setting, CREATE TABLE, one copy statement, record LAST');
    // Simulated failure of the copy: attendance_marks is not reachable (renamed by hand for the test only).
    [$n, $err] = run_stmts($up, array_slice($steps, 0, 2));
    $up->exec('RENAME TABLE attendance_marks TO attendance_marks_hidden');
    [$n2, $err2] = run_stmts($up, array_slice($steps, 2));
    $up->exec('RENAME TABLE attendance_marks_hidden TO attendance_marks');
    ok($err2 !== null && $n2 === 0 && (int)$up->query('SELECT COUNT(*) FROM attendance_daily')->fetchColumn() === 0 && !one($up, 'SELECT id FROM schema_migrations WHERE id=?', [$M11]), 'copy failed: the new table is EMPTY (never half-filled) and the migration is NOT recorded');
    ok(state_of($up, $M11) === 'PARTIAL', 'after the failed copy the check file says PARTIAL');
    [$n, $err] = run_stmts($up, $steps);
    ok($err !== null && str_contains($err, 'already exists') && (int)$up->query('SELECT COUNT(*) FROM attendance_daily')->fetchColumn() === 0, 'importing the migration again stops at step 1 and changes nothing');
    [$n, $err] = run_stmts($up, stmts("database/migrations/recovery/{$M11}_finish.sql"));
    ok($err === null && state_of($up, $M11) === 'COMPLETE', 'recovery file: copy done, recorded, state COMPLETE', (string)$err);
    ok($legacy() === $L && $shifts() === $S, 'legacy attendance_marks rows and every personnel / mechanic duty_shift are identical to before');
    $d = fn($date, $col, $id) => one($up, "SELECT status, marked_by, legacy_shifts FROM attendance_daily WHERE duty_date=? AND $col=?", [$date, $id]);
    ok((int)$up->query('SELECT COUNT(*) FROM attendance_daily')->fetchColumn() === 4
        && $d('2026-11-02', 'personnel_id', $p3) == ['status' => 'Present', 'marked_by' => $uB, 'legacy_shifts' => 'Day+Night']
        && $d('2026-11-01', 'personnel_id', $p2) == ['status' => 'Absent', 'marked_by' => $uA, 'legacy_shifts' => 'Night'], '5 legacy marks -> 4 daily rows with the documented interpretation');
    foreach (stmts("database/migrations/checks/{$M11}_check.sql") as $q) {
        if (str_contains($q, 'AS state') || !str_starts_with($q, 'SELECT')) continue;
        if (str_contains($q, 'WHERE NOT EXISTS (SELECT 1 FROM attendance_daily') || str_contains($q, 'AND d.status <>')) ok($up->query($q)->fetchAll() === [], 'verification query returns 0 rows: ' . substr(preg_replace('/\s+/', ' ', $q), 0, 60));
    }
    // Recovery again / after the Admin cleared a mark: nothing is duplicated or resurrected.
    $up->exec("DELETE FROM attendance_daily WHERE personnel_id=$p1");
    [$n, $err] = run_stmts($up, stmts("database/migrations/recovery/{$M11}_finish.sql"));
    ok($err !== null && str_contains($err, 'Duplicate') && (int)$up->query('SELECT COUNT(*) FROM attendance_daily')->fetchColumn() === 3 && !$d('2026-11-01', 'personnel_id', $p1), 'recovery on a COMPLETE database: a deliberately cleared mark is NOT copied back; refused at the record');
    // Clean run.
    $up->exec('DROP TABLE attendance_daily'); $up->exec("DELETE FROM schema_migrations WHERE id='$M11'");
    [$n, $err] = run_stmts($up, $steps);
    ok($err === null && $n === 4 && state_of($up, $M11) === 'COMPLETE' && (int)$up->query('SELECT COUNT(*) FROM attendance_daily')->fetchColumn() === 4 && $legacy() === $L, 'clean import of the migration: 4 daily rows, record last, legacy rows unchanged');
    // Copy is idempotent: running the copy statement again adds nothing (migration not recorded -> simulate).
    $up->exec("DELETE FROM schema_migrations WHERE id='$M11'");
    $up->exec($steps[2]);
    ok((int)$up->query('SELECT COUNT(*) FROM attendance_daily')->fetchColumn() === 4, 'copy statement repeated before the record exists: no duplicates (anti-join)');
}

echo "\n----------------------------------------\n";
echo "RESULT: $PASS passed, $FAIL failed\n";
if ($FAILED) { echo "Failed:\n"; foreach ($FAILED as $f) echo "  - $f\n"; }
test_server()->exec("DROP DATABASE IF EXISTS `$FRESH`"); test_server()->exec("DROP DATABASE IF EXISTS `$UPGRADE`");
exit($FAIL === 0 ? 0 : 1);
