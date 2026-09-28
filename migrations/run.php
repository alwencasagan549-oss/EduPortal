<?php
/**
 * Migration runner.
 *
 * Schema changes are a deploy-time concern; controllers only report readiness.
 * Run this as a release step, before the new code starts serving traffic:
 *
 *   php migrations/run.php
 *   php migrations/run.php --status
 *
 * Each migration is idempotent and exits non-zero on failure so a release can
 * abort rather than serving code against a half-migrated schema.
 */

require_once __DIR__ . '/../config/database.php';

function migration_list(): array
{
    $files = glob(__DIR__ . '/*.php') ?: [];
    sort($files);

    $migrations = [];
    foreach ($files as $file) {
        if (basename($file) === 'run.php') {
            continue;
        }
        $migrations[basename($file, '.php')] = $file;
    }

    return $migrations;
}

$migrations = migration_list();
$statusOnly = in_array('--status', $argv ?? [], true);

if ($statusOnly) {
    echo "Migrations in " . __DIR__ . ":\n";
    foreach ($migrations as $name => $file) {
        echo "  - {$name}\n";
    }
    echo "\nRun them in order, or use: php migrations/run.php\n";
    exit(0);
}

echo "Running " . count($migrations) . " migration(s) against " . DB_NAME . "@" . DB_HOST . "\n\n";

$failed = [];

foreach ($migrations as $name => $file) {
    echo "== {$name}\n";
    $started = microtime(true);

    // Migrations echo their own progress; run.php.php is a no-op.
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file), $exitCode);

    $elapsed = round((microtime(true) - $started) * 1000);
    if ($exitCode !== 0) {
        $failed[] = $name;
        echo "   FAILED after {$elapsed}ms (exit {$exitCode})\n\n";
        // Stop at the first failure: later migrations may depend on this one.
        break;
    }
    echo "   ok ({$elapsed}ms)\n\n";
}

if ($failed !== []) {
    fwrite(STDERR, 'Migration failed: ' . implode(', ', $failed) . "\n");
    fwrite(STDERR, "Resolve the error above and re-run. The schema is now inconsistent.\n");
    exit(1);
}

echo "All migrations applied.\n";
exit(0);
