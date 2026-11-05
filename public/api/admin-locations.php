<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');

$locations = db()->query(
    "SELECT t.id AS trip_id, d.full_name, j.plate_number, r.route_name,
            t.started_at, l.latitude, l.longitude, l.accuracy_m, l.recorded_at
     FROM trips t
     INNER JOIN drivers d ON d.id = t.driver_id
     INNER JOIN jeepney_profiles j ON j.id = t.jeepney_profile_id
     INNER JOIN routes r ON r.id = t.route_id
     LEFT JOIN trip_locations l ON l.id = (
         SELECT MAX(latest.id) FROM trip_locations latest WHERE latest.trip_id = t.id
     )
     WHERE t.trip_status = 'active'
     ORDER BY t.started_at DESC"
)->fetchAll();

echo json_encode(['locations' => $locations], JSON_THROW_ON_ERROR);
