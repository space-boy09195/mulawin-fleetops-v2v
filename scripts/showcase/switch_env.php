<?php
/*
 * scripts/showcase/switch_env.php — reversible switch of the application's database (DB_NAME in .env ONLY).
 *   php scripts/showcase/switch_env.php --status
 *   php scripts/showcase/switch_env.php --to=showcase     (DB_NAME=mulawin_v2_showcase)
 *   php scripts/showcase/switch_env.php --to=original     (DB_NAME=mulawin_v2)
 * Only the DB_NAME line is rewritten; every other line (credentials, mail, maps) is left byte-for-byte as it was, and
 * no copy of .env is written anywhere. The target database must exist. Log out and in again if the browser session
 * shows stale data.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
const TARGETS = ['original' => 'mulawin_v2', 'showcase' => 'mulawin_v2_showcase'];
$file = dirname(__DIR__, 2) . '/.env';
$text = (string)file_get_contents($file);
if (!preg_match('/^DB_NAME=(.*)$/m', $text, $m)) { fwrite(STDERR, "DB_NAME line not found in .env.\n"); exit(2); }
$current = trim($m[1], " \t\r\"'");
$opts = getopt('', ['to:', 'status']);
if (isset($opts['status']) || !isset($opts['to'])) { echo "The application uses: $current\n"; exit(0); }
$to = TARGETS[$opts['to']] ?? null;
if ($to === null) { fwrite(STDERR, "--to must be 'showcase' or 'original'.\n"); exit(2); }
if ($to === $current) { echo "Already using $to.\n"; exit(0); }
$env = [];
foreach (preg_split('/\r?\n/', $text) as $line) if (preg_match('/^(DB_HOST|DB_PORT|DB_USER|DB_PASS)=(.*)$/', trim($line), $x)) $env[$x[1]] = trim($x[2], " \t\"'");
$pdo = new PDO('mysql:host=' . ($env['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['DB_PORT'] ?? '3306'), $env['DB_USER'] ?? 'root', $env['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$q = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?'); $q->execute([$to]);
if (!$q->fetchColumn()) { fwrite(STDERR, "Database $to does not exist.\n"); exit(2); }
$new = preg_replace('/^DB_NAME=[^\r\n]*/m', 'DB_NAME=' . $to, $text, 1);
file_put_contents($file, $new);
echo "DB_NAME: $current -> $to\n";
