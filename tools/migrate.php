<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';

const REQUIRED_SCHEMA_TABLES = [
    'drivers',
    'jeepney_profiles',
    'routes',
    'RouteStops',
    'admins',
    'admin_registration_lock',
    'login_rate_limits',
];

$pdo = null;
$lockAcquired = false;
$exitCode = 0;

try {
    $pdo = database_connect_with_prefix('MIGRATION_DB_', 'DB_');
    $lockAcquired = (int) $pdo->query("SELECT GET_LOCK('ala_eh_schema_migrations', 10)")->fetchColumn() === 1;
    if (!$lockAcquired) {
        throw new RuntimeException('Could not acquire the database migration lock.');
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(191) NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (version)
        ) ENGINE=InnoDB'
    );

    $placeholders = implode(', ', array_fill(0, count(REQUIRED_SCHEMA_TABLES), '?'));
    $tableQuery = $pdo->prepare(
        'SELECT table_name FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name IN (' . $placeholders . ')'
    );
    $tableQuery->execute(REQUIRED_SCHEMA_TABLES);
    $existingTables = array_map('strtolower', $tableQuery->fetchAll(PDO::FETCH_COLUMN));
    $missingTables = array_values(array_filter(
        REQUIRED_SCHEMA_TABLES,
        static fn (string $table): bool => !in_array(strtolower($table), $existingTables, true)
    ));
    if ($missingTables !== []) {
        throw new RuntimeException(
            'The existing application schema is incomplete. Import database/schema.sql before running migrations.'
        );
    }

    $baseline = $pdo->prepare('INSERT IGNORE INTO schema_migrations (version) VALUES (?)');
    $baseline->execute(['0000_initial_schema']);

    $migrationFiles = glob(__DIR__ . '/../database/migrations/[0-9][0-9][0-9][0-9]_*.sql');
    if ($migrationFiles === false) {
        throw new RuntimeException('Could not read the database migration directory.');
    }
    sort($migrationFiles, SORT_STRING);

    $appliedQuery = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = ?');
    foreach ($migrationFiles as $migrationFile) {
        $version = basename($migrationFile, '.sql');
        $appliedQuery->execute([$version]);
        if ($appliedQuery->fetchColumn() !== false) {
            continue;
        }

        $sql = file_get_contents($migrationFile);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('Migration file is empty or unreadable: ' . $version);
        }

        $pdo->exec($sql);
        $record = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
        $record->execute([$version]);
        fwrite(STDOUT, 'Applied migration: ' . $version . PHP_EOL);
    }

    fwrite(STDOUT, "Database migrations are up to date.\n");
} catch (Throwable $exception) {
    error_log('Database migration failed: ' . (string) $exception);
    fwrite(STDERR, "Database migration failed. Review the server log and migration setup guide.\n");
    $exitCode = 1;
} finally {
    if ($lockAcquired && $pdo instanceof PDO) {
        try {
            $pdo->query("SELECT RELEASE_LOCK('ala_eh_schema_migrations')");
        } catch (Throwable $exception) {
            error_log('Could not release the database migration lock: ' . (string) $exception);
        }
    }
}

exit($exitCode);
