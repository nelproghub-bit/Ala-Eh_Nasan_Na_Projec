<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/commuter-auth.php';
require_once dirname(__DIR__, 3) . '/app/live-update.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

if (commuter_account() === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Not signed in.'], JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode(['token' => live_update_token()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
