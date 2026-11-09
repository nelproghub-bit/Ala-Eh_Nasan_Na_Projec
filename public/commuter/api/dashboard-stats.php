<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/commuter-integration.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['error' => 'Only GET requests are supported.']);
    exit;
}

try {
    $terminalRecords = commuter_terminal_records();
    $activeDrivers = [];
    foreach ($terminalRecords as $terminal) {
        foreach (($terminal['drivers'] ?? []) as $driver) {
            // A driver can appear at more than one terminal; count each once.
            $key = (string) ($driver['plate_number'] ?? '') . '|' . (string) ($driver['name'] ?? '');
            $activeDrivers[$key] = true;
        }
    }
    $activeDriverCount = count($activeDrivers);

    echo json_encode([
        'available_jeeps' => $activeDriverCount,
        'active_drivers' => $activeDriverCount,
        'terminals' => count($terminalRecords),
        'updated_at' => gmdate(DATE_ATOM),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('Commuter dashboard statistics: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Dashboard statistics are temporarily unavailable.']);
}
