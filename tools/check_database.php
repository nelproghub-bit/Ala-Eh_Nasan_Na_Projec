<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';

try {
    db();
    fwrite(STDOUT, "Database connection succeeded (verified TLS).\n");
    exit(0);
} catch (Throwable $exception) {
    error_log('Local database connectivity check failed: ' . (string) $exception);
    fwrite(
        STDERR,
        "Database connection failed. Check the local environment, CA certificate, network allowlist, and account permissions.\n"
    );
    exit(1);
}
