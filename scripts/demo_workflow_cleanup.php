<?php
/*
 * =====================================================================
 *  scripts/demo_workflow_cleanup.php  —  removes ONLY the rows listed in a demo manifest (mulawin_v2)
 * =====================================================================
 *  DRY RUN (default; reads only):
 *    C:\xampp\php\php.exe scripts\demo_workflow_cleanup.php --manifest=C:\xampp\fleetops_demo\demo_manifest_<stamp>.json
 *  APPLY (only after a separate approval):
 *    ... --manifest=<file> --apply --confirm=mulawin_v2 [--include-audit] [--include-notifications]
 *
 *  Rules:
 *    - Deletes nothing unless --apply AND --confirm=mulawin_v2 are given; the project .env and the live connection must be mulawin_v2.
 *    - Deletes ONLY the ids listed under new_ids in the manifest, never other rows. No DROP / TRUNCATE / reset.
 *    - Audit trail (audit_events, audit_event_changes) and notifications are KEPT unless --include-audit / --include-notifications.
 *      While they are kept, the demo Finance account is kept too (they reference it).
 *    - Refuses when any row OUTSIDE the manifest references a demo row (for example a real record created later that uses a
 *      demo part or truck): nothing is deleted then.
 *    - Children are deleted before parents (foreign-key order), all in ONE transaction: all or nothing.
 *    - Uploaded files of deleted documents rows are removed from storage/private only after the transaction commits.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
const CLEAN_DB = 'mulawin_v2';
function clean_refuse(string $why): never { fwrite(STDERR, "REFUSED: $why\nNothing was deleted.\n"); exit(2); }

$opt = ['apply' => false, 'include-audit' => false, 'include-notifications' => false];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(manifest|confirm)=(.+)$/', $a, $m)) $opt[$m[1]] = $m[2];
    elseif (in_array($a, ['--apply', '--include-audit', '--include-notifications'], true)) $opt[substr($a, 2)] = true;
    else clean_refuse("unknown argument $a.");
}
$file = (string)realpath($opt['manifest'] ?? '');
if ($file === '' || !is_file($file)) clean_refuse('--manifest=<demo_manifest_*.json> is required.');
$mf = json_decode((string)file_get_contents($file), true);
if (!is_array($mf) || ($mf['database'] ?? '') !== CLEAN_DB || !is_array($mf['new_ids'] ?? null)) clean_refuse('not a demo manifest of ' . CLEAN_DB . '.');
if ($opt['apply'] && ($opt['confirm'] ?? '') !== CLEAN_DB) clean_refuse('--apply needs --confirm=' . CLEAN_DB . '.');

$ROOT = dirname(__DIR__);
require $ROOT . '/backend/bootstrap.php';
if (envValue('DB_NAME') !== CLEAN_DB) clean_refuse('the project .env is not ' . CLEAN_DB . '.');
$p = db();
if ((string)$p->query('SELECT DATABASE()')->fetchColumn() !== CLEAN_DB) clean_refuse('the live connection is not ' . CLEAN_DB . '.');

// ---------------------------------------------------------------- what to delete
$keep = [];
if (!$opt['include-audit']) { $keep[] = 'audit_events'; $keep[] = 'audit_event_changes'; }
if (!$opt['include-notifications']) $keep[] = 'notifications';
if ($keep) $keep[] = 'users';   // kept audit / notification rows reference the demo Finance account
$targets = array_diff_key($mf['new_ids'], array_flip($keep));
$pk = $p->query("SELECT table_name, column_name FROM information_schema.key_column_usage WHERE table_schema='" . CLEAN_DB . "' AND constraint_name='PRIMARY'")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach (array_keys($targets) as $t) {
    if (!preg_match('/^[a-z_]+$/', $t) || !isset($pk[$t])) clean_refuse("unknown table in the manifest: $t.");
    $targets[$t] = array_values(array_map('intval', $targets[$t]));
}
$idList = fn(string $t) => implode(',', $targets[$t]);

// ---------------------------------------------------------------- foreign keys between tables; outside references refuse
$fks = $p->query("SELECT table_name child, column_name col, referenced_table_name parent, referenced_column_name pcol
    FROM information_schema.key_column_usage WHERE table_schema='" . CLEAN_DB . "' AND referenced_table_name IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
$outside = [];
foreach ($fks as $f) {
    if (!isset($targets[$f['parent']])) continue;
    $sql = "SELECT COUNT(*) FROM `{$f['child']}` WHERE `{$f['col']}` IN (" . $idList($f['parent']) . ')';
    if (isset($targets[$f['child']])) $sql .= " AND `{$pk[$f['child']]}` NOT IN (" . $idList($f['child']) . ')';
    $n = (int)$p->query($sql)->fetchColumn();
    if ($n > 0) $outside[] = "$n row(s) of {$f['child']}.{$f['col']} reference demo {$f['parent']} rows";
}
// Order: a table is deleted only after every table that references it.
$order = []; $left = array_keys($targets);
while ($left) {
    $progress = false;
    foreach ($left as $i => $t) {
        $blocked = false;
        foreach ($fks as $f) if ($f['parent'] === $t && $f['child'] !== $t && in_array($f['child'], $left, true)) { $blocked = true; break; }
        if (!$blocked) { $order[] = $t; unset($left[$i]); $progress = true; }
    }
    if (!$progress) clean_refuse('circular foreign keys between: ' . implode(', ', $left) . '.');
}

echo ($opt['apply'] ? 'APPLY' : 'DRY RUN (nothing is deleted)') . " — manifest " . basename($file) . "\n";
foreach ($order as $t) printf("  delete %4d row(s) from %-22s (%s %d-%d)\n", count($targets[$t]), $t, $pk[$t], min($targets[$t]), max($targets[$t]));
foreach ($keep as $t) if (isset($mf['new_ids'][$t])) printf("  KEEP   %4d row(s) in   %-22s (cleanup policy)\n", count($mf['new_ids'][$t]), $t);
$files = isset($targets['documents']) ? $p->query('SELECT storage_name FROM documents WHERE id IN (' . $idList('documents') . ')')->fetchAll(PDO::FETCH_COLUMN) : [];
echo '  uploaded files to remove from storage/private: ' . count($files) . "\n";
if ($outside) { echo "\nOther records now reference demo rows:\n  - " . implode("\n  - ", $outside) . "\n"; clean_refuse('demo rows are in use by other records.'); }
if (!$opt['apply']) { echo "\nDry run only. Re-run with --apply --confirm=" . CLEAN_DB . " after approval.\n"; exit(0); }

// ---------------------------------------------------------------- delete, all or nothing
$p->beginTransaction();
try {
    foreach ($order as $t) {
        $n = $p->exec("DELETE FROM `$t` WHERE `{$pk[$t]}` IN (" . $idList($t) . ')');
        if ($n !== count($targets[$t])) throw new RuntimeException("$t: expected " . count($targets[$t]) . " row(s), deleted $n (rows changed since the manifest).");
    }
    $p->commit();
} catch (Throwable $e) {
    $p->rollBack();
    clean_refuse('rolled back: ' . $e->getMessage());
}
$dir = $ROOT . '/storage/private';
$removed = 0;
foreach ($files as $f) if (preg_match('/^[a-f0-9]{40}\.(pdf|jpg|png|webp)$/', (string)$f) && is_file("$dir/$f") && @unlink("$dir/$f")) $removed++;
echo "\nDeleted the manifest rows (one transaction). Files removed: $removed of " . count($files) . ".\n";
exit(0);
