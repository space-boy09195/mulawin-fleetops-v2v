<?php
/*
 * =====================================================================
 *  scripts/showcase/run_showcase.php  —  SAFE launcher for the development showcase dataset
 * =====================================================================
 *  Usage (from the project root, XAMPP PHP):
 *    php scripts/showcase/run_showcase.php --db=mulawin_v2_showcase --dry-run   (everything in one transaction, rolled back)
 *    php scripts/showcase/run_showcase.php --db=mulawin_v2_showcase --apply     (one transaction, committed; writes the manifest)
 *    php scripts/showcase/run_showcase.php --db=mulawin_v2_showcase --verify    (read only: counts and reconciliation)
 *    php scripts/showcase/run_showcase.php --db=mulawin_v2_showcase --cleanup --confirm   (removes ONLY manifest records)
 *
 *  Safety:
 *   - The project .env is never read by the population code: the backend is copied to a temporary folder with its
 *     own .env whose DB_NAME is the showcase database (same pattern as tests/_launcher.php).
 *   - The target must end with "_showcase", must exist, and must not be DB_NAME of the project .env.
 *   - Mail and map-routing settings are blank in the copy: no e-mail and no map-service call can be made.
 *   - Fixture attachments are written to the copy, then ONLY the files listed in the manifest are copied into
 *     storage/private (cleanup deletes exactly those files).
 *   - The manifest is written OUTSIDE the repository (SHOWCASE_MANIFEST_DIR, default C:/xampp/mulawin_backups/showcase).
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__, 2);
$opts = getopt('', ['db:', 'dry-run', 'apply', 'verify', 'cleanup', 'confirm']);
$db = (string)($opts['db'] ?? '');
$modes = array_values(array_intersect(['dry-run', 'apply', 'verify', 'cleanup'], array_keys($opts)));
if (count($modes) !== 1) { fwrite(STDERR, "Choose exactly one of --dry-run, --apply, --verify, --cleanup.\n"); exit(2); }
$mode = $modes[0];
if (!preg_match('/^[a-z0-9_]+_showcase$/', $db)) { fwrite(STDERR, "Refused: --db must be a database name ending with _showcase.\n"); exit(2); }
if ($mode === 'cleanup' && !isset($opts['confirm'])) { fwrite(STDERR, "Cleanup removes the generated records listed in the manifest. Add --confirm to proceed.\n"); exit(2); }

// Project .env: DB server credentials and the ORIGINAL database name (never the target).
$env = [];
foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $env[trim($k)] = trim($v, " \t\"'");
}
$projectDb = $env['DB_NAME'] ?? '';
if (strcasecmp($db, $projectDb) === 0) { fwrite(STDERR, "Refused: $db is the database the application currently uses (.env). Switch the application back first.\n"); exit(2); }
if (strcasecmp($db, 'mulawin_v2') === 0) { fwrite(STDERR, "Refused: never the original database.\n"); exit(2); }
$server = new PDO('mysql:host=' . ($env['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['DB_PORT'] ?? '3306') . ';charset=utf8mb4', $env['DB_USER'] ?? 'root', $env['DB_PASS'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$exists = $server->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?'); $exists->execute([$db]);
if (!$exists->fetchColumn()) { fwrite(STDERR, "Refused: database $db does not exist. Restore the backup into it first.\n"); exit(2); }

$manifestDir = getenv('SHOWCASE_MANIFEST_DIR') ?: 'C:/xampp/mulawin_backups/showcase';
if (str_starts_with(str_replace('\\', '/', strtolower(realpath(dirname($manifestDir)) ?: $manifestDir)), str_replace('\\', '/', strtolower($root)))) {
    fwrite(STDERR, "Refused: the manifest directory must be outside the repository.\n"); exit(2);
}
@mkdir($manifestDir, 0700, true);

// Temporary copy with its own .env.
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mulawin_showcase_' . bin2hex(random_bytes(4));
$copy = function (string $from, string $to) use (&$copy): void {
    if (is_dir($from)) { @mkdir($to, 0700, true); foreach (scandir($from) ?: [] as $f) if ($f !== '.' && $f !== '..' && !str_ends_with($f, '.bak')) $copy("$from/$f", "$to/$f"); }
    else copy($from, $to);
};
foreach (['backend', 'config', 'database'] as $dir) if (is_dir("$root/$dir")) $copy("$root/$dir", "$tmp/$dir");
$copy("$root/scripts/showcase", "$tmp/scripts/showcase");
@mkdir("$tmp/storage/private", 0700, true);
file_put_contents("$tmp/.env", implode("\n", [
    'APP_ENV=showcase', 'APP_TIMEZONE=' . ($env['APP_TIMEZONE'] ?? 'Asia/Manila'),
    'DB_HOST=' . ($env['DB_HOST'] ?? '127.0.0.1'), 'DB_PORT=' . ($env['DB_PORT'] ?? '3306'), 'DB_NAME=' . $db,
    'DB_USER=' . ($env['DB_USER'] ?? 'root'), 'DB_PASS=' . ($env['DB_PASS'] ?? ''),
    'MAIL_HOST=', 'MAIL_USERNAME=', 'MAIL_PASSWORD=', 'MAIL_FROM_ADDRESS=', 'ORS_API_KEY=',
    'SHOWCASE_MANIFEST_DIR=' . $manifestDir,
]) . "\n");

passthru(escapeshellarg(PHP_BINARY) . ' -d memory_limit=1024M ' . escapeshellarg("$tmp/scripts/showcase/populate.php") . ' ' . escapeshellarg($mode), $code);

// Attachments: copy (apply) or delete (cleanup) exactly the files in the manifest.
$manifestFile = $manifestDir . '/manifest_' . $db . '.json';
$real = $root . '/storage/private';
if ($code === 0 && $mode === 'apply' && is_file($manifestFile)) {
    $m = json_decode((string)file_get_contents($manifestFile), true);
    $n = 0;
    foreach ($m['files'] ?? [] as $name) {
        if (!preg_match('/^[0-9a-f]{40}\.[a-z0-9]{2,5}$/', $name)) continue;
        if (is_file("$tmp/storage/private/$name") && !is_file("$real/$name")) { copy("$tmp/storage/private/$name", "$real/$name"); $n++; }
    }
    echo "Attachment files copied to storage/private: $n\n";
}
if ($code === 0 && $mode === 'cleanup' && is_file($manifestFile . '.cleaned')) {
    $m = json_decode((string)file_get_contents($manifestFile . '.cleaned'), true);
    $n = 0;
    foreach ($m['files'] ?? [] as $name) if (preg_match('/^[0-9a-f]{40}\.[a-z0-9]{2,5}$/', $name) && is_file("$real/$name")) { unlink("$real/$name"); $n++; }
    echo "Attachment files removed from storage/private: $n\n";
}

$rm = function (string $p) use (&$rm): void {
    if (is_dir($p) && !is_link($p)) { foreach (scandir($p) ?: [] as $f) if ($f !== '.' && $f !== '..') $rm("$p/$f"); @rmdir($p); }
    else @unlink($p);
};
$rm($tmp);
exit((int)$code);
