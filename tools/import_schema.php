<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';

$pdo = null;
$lockAcquired = false;
$exitCode = 0;

try {
    $pdo = database_connect_with_prefix('MIGRATION_DB_', 'DB_');
    $lockAcquired = (int) $pdo->query("SELECT GET_LOCK('ala_eh_schema_migrations', 10)")->fetchColumn() === 1;
    if (!$lockAcquired) {
        throw new RuntimeException('Could not acquire the database migration lock.');
    }

    $tables = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if ($tables !== []) {
        throw new RuntimeException(
            'Schema import requires an empty database. Existing tables were left unchanged.'
        );
    }

    $schemaPath = __DIR__ . '/../database/schema.sql';
    $schema = file_get_contents($schemaPath);
    if ($schema === false || trim($schema) === '') {
        throw new RuntimeException('The initial database schema is empty or unreadable.');
    }

    $statements = array_filter(
        array_map('trim', explode(';', $schema)),
        static fn (string $statement): bool => $statement !== ''
    );
    if ($statements === []) {
        throw new RuntimeException('The initial database schema contains no SQL statements.');
    }

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    $pdo->prepare('INSERT IGNORE INTO schema_migrations (version) VALUES (?)')
        ->execute(['0000_initial_schema']);

    fwrite(STDOUT, "Initial database schema imported successfully.\n");
} catch (Throwable $exception) {
    error_log('Initial database schema import failed: ' . (string) $exception);
    fwrite(
        STDERR,
        "Initial schema import failed. Review the server log; no existing tables or data were deleted.\n"
    );
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
