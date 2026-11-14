<?php
/*
 * scripts/migrate.php — automatically runs pending migrations after git pull.
 * Usage:
 *   php scripts/migrate.php
 *
 *
 *   this is baste, i made this shit because i dont know fcuking sql queries to migrate...
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit('CLI only');
require dirname(__DIR__) . '/backend/bootstrap.php';

try {
    $p = db();
    $p->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
    $p->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        id VARCHAR(80) PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $applied = $p->query("SELECT id FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
    $appliedSet = array_flip($applied);

    $migrationDir = dirname(__DIR__) . '/database/migrations';
    $files = glob($migrationDir . '/*.sql');
    sort($files);

    $ran = 0;
    foreach ($files as $file) {
        $name = basename($file, '.sql');
        if (isset($appliedSet[$name])) {
            continue;
        }

        echo "Applying: $name.sql ... ";
        $sql = file_get_contents($file);
        if ($sql === false || trim($sql) === '') {
            echo "SKIPPED (empty)\n";
            continue;
        }

        try {
            $p->exec($sql);
            // Ensure recorded in schema_migrations if script didn't insert it
            $stmt = $p->prepare("INSERT IGNORE INTO schema_migrations (id) VALUES (?)");
            $stmt->execute([$name]);
            echo "DONE\n";
            $ran++;
        } catch (Throwable $e) {
            echo "FAILED\n";
            fwrite(STDERR, "Error in $name.sql: " . $e->getMessage() . "\n");
            exit(1);
        }
    }

    if ($ran === 0) {
        echo "Database is already up to date. No pending migrations.\n";
    } else {
        echo "\nSuccessfully applied $ran migration(s).\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Database connection error: " . $e->getMessage() . "\n");
    exit(1);
}
