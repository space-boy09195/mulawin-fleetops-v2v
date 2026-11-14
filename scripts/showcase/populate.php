<?php
/*
 * =====================================================================
 *  scripts/showcase/populate.php  —  DEVELOPMENT SHOWCASE DATASET (runs ONLY inside the launcher's temporary copy)
 * =====================================================================
 *  Do not run directly: use scripts/showcase/run_showcase.php (own .env, *_showcase database only).
 *
 *  HOW THE DATA IS MADE
 *   - Every record is created through the application's OWN actions (Trips, Funds, Billing, Clients, Maintenance,
 *     Mechanics, Purchasing, Finance) with their role checks, status rules, reference-number generators and audit.
 *     Nothing is inserted directly except the controlled system-loader accounts (inactive, unusable password) that
 *     are recorded as the actors, so no action is attributed to a real employee.
 *   - Discrete-event simulation: every step of every scenario has a target time; steps run in time order. Before a
 *     step the database session clock is set to the target (SET TIMESTAMP), so NOW()/CURRENT_TIMESTAMP, the
 *     30-day rotation of trucks and crew, and the database timers follow the scenario timeline. Values the PHP code
 *     stamps with the real clock on rows created in that step are moved to the target (Sim::fix).
 *   - Real-time gates that cannot follow a session clock (trip schedule must be in the future; checklist / fund
 *     release / dispatch windows) are satisfied by keeping the trip's schedule a minute ahead of the real clock until
 *     dispatch; the final schedule is then set relative to the dispatch time (fixture correction, logged).
 *   - Attachments required by the workflows are labelled non-official placeholders (approved by the project owner).
 *   - Everything runs in ONE transaction: --dry-run rolls it back; --apply commits and writes the manifest.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$MODE = $argv[1] ?? '';
require dirname(__DIR__, 2) . '/backend/Actions.php';
core('Access'); core('Settings'); core('FleetState'); core('Files'); core('Money'); core('Notify');
foreach (['Clients', 'Trips', 'Funds', 'Billing', 'Finance', 'Maintenance', 'Mechanics', 'Purchasing'] as $m) Actions::load($m);
require_once dirname(__DIR__, 2) . '/backend/Modules/Operations/Inspections/Checklist.php';

// ------------------------------------------------------------------ guards
$DB = envValue('DB_NAME');
if (envValue('APP_ENV') !== 'showcase' || !preg_match('/^[a-z0-9_]+_showcase$/', $DB) || $DB === 'mulawin_v2') { fwrite(STDERR, "Refused: run through scripts/showcase/run_showcase.php.\n"); exit(2); }
$p = db();
if ($p->query('SELECT DATABASE()')->fetchColumn() !== $DB) { fwrite(STDERR, "Refused: connected to the wrong database.\n"); exit(2); }
$MANIFEST = rtrim(envValue('SHOWCASE_MANIFEST_DIR'), '/\\') . '/manifest_' . $DB . '.json';
const LOADER_PREFIX = 'sysloader_';

require __DIR__ . '/lib_sim.php';
require __DIR__ . '/lib_verify.php';

if ($MODE === 'verify') { exit(ShowcaseVerify::run($p, is_file($MANIFEST) ? json_decode((string)file_get_contents($MANIFEST), true) : null)); }
if ($MODE === 'cleanup') { exit(ShowcaseVerify::cleanup($p, $MANIFEST)); }
if (!in_array($MODE, ['dry-run', 'apply'], true)) { fwrite(STDERR, "Unknown mode.\n"); exit(2); }

// ------------------------------------------------------------------ duplicate prevention
if (one($p, "SELECT id FROM users WHERE username LIKE 'sysloader\\_%' LIMIT 1")) { fwrite(STDERR, "Refused: this database already contains the showcase dataset (system-loader accounts exist). Run --cleanup --confirm first.\n"); exit(3); }
if (is_file($MANIFEST)) { fwrite(STDERR, "Refused: a manifest already exists ($MANIFEST). Run --cleanup --confirm first.\n"); exit(3); }

mt_srand(20261113);
$NOW = time();
$t0 = microtime(true);
Sim::init($p);
$baseline = Sim::maxIds();
$snapshot = ShowcaseVerify::snapshot($p);

$p->beginTransaction();
try {
    require __DIR__ . '/scenarios.php';
    Sim::run();
    $summary = ShowcaseVerify::counts($p, $baseline);
    $created = Sim::createdIds($baseline);
    $files = array_column(many($p, 'SELECT storage_name FROM documents WHERE id > ?', [$baseline['documents'] ?? 0]), 'storage_name');
    if ($MODE === 'dry-run') {
        $p->rollBack();
        foreach (storedFiles(null, true) as $f) @unlink($f);
        echo "\nDRY RUN — everything rolled back. Steps executed: " . Sim::$steps . ' in ' . round(microtime(true) - $t0, 1) . " s\n";
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        foreach (Sim::$notes as $n) echo "  note: $n\n";
        exit(0);
    }
    $p->commit();
} catch (Throwable $e) {
    if ($p->inTransaction()) $p->rollBack();
    foreach (storedFiles(null, true) as $f) @unlink($f);
    fwrite(STDERR, "FAILED (everything rolled back): " . $e->getMessage() . "\n" . ($e->getPrevious() ? '  cause: ' . $e->getPrevious()->getMessage() . ' @ ' . basename($e->getPrevious()->getFile()) . ':' . $e->getPrevious()->getLine() . "\n" : '') . '  at ' . basename($e->getFile()) . ':' . $e->getLine() . "\n");
    exit(1);
}
$manifest = [
    'purpose' => 'Development showcase fixtures for Mulawin FleetOps (NOT company history). Generated by scripts/showcase/populate.php.',
    'database' => $DB, 'generated_at' => date('c'), 'seed' => 20261113, 'steps' => Sim::$steps,
    'baseline_max_ids' => $baseline, 'created_ids' => $created,
    'trip_ids' => $created['trips'] ?? [], 'files' => $files,
    'preexisting_snapshot' => $snapshot, 'fixture_corrections' => Sim::$corrections, 'notes' => Sim::$notes, 'counts' => $summary,
];
file_put_contents($MANIFEST, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nAPPLIED. Steps: " . Sim::$steps . ' in ' . round(microtime(true) - $t0, 1) . " s. Manifest: $MANIFEST\n";
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
foreach (Sim::$notes as $n) echo "  note: $n\n";
exit(0);
