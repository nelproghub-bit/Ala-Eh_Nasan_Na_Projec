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

$rows = db()->query(
    "SELECT r.id AS route_id, r.route_name, s.stop_sequence, s.location_name,
            s.area_label, s.landmark, c.latitude, c.longitude
     FROM routes r
     INNER JOIN RouteStops s ON s.route_id = r.id
     LEFT JOIN route_stop_coordinates c ON c.route_stop_id = s.id
     WHERE r.is_active = 1
     ORDER BY r.route_name, s.stop_sequence"
)->fetchAll();

$routes = [];
foreach ($rows as $row) {
    $routeId = (int) $row['route_id'];
    if (!isset($routes[$routeId])) {
        $routes[$routeId] = [
            'id' => $routeId,
            'name' => (string) $row['route_name'],
            'stops' => [],
        ];
    }

    $routes[$routeId]['stops'][] = [
        'sequence' => (int) $row['stop_sequence'],
        'name' => (string) $row['location_name'],
        'area' => (string) $row['area_label'],
        'landmark' => (string) $row['landmark'],
        'latitude' => $row['latitude'] === null ? null : (float) $row['latitude'],
        'longitude' => $row['longitude'] === null ? null : (float) $row['longitude'],
    ];
}

echo json_encode([
    'accuracy' => 'approximate',
    'routes' => array_values($routes),
], JSON_THROW_ON_ERROR);
