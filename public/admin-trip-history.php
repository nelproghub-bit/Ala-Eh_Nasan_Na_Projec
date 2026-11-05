<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$tripId = validated_positive_id($_GET['id'] ?? null);
if ($tripId === null) {
    http_response_code(404);
    exit('Trip not found.');
}
$tripQuery = db()->prepare(
    'SELECT t.id, t.trip_status, t.started_at, t.ended_at, d.full_name,
            j.plate_number, r.route_name
     FROM trips t
     INNER JOIN drivers d ON d.id = t.driver_id
     INNER JOIN jeepney_profiles j ON j.id = t.jeepney_profile_id
     INNER JOIN routes r ON r.id = t.route_id
     WHERE t.id = :id LIMIT 1'
);
$tripQuery->execute(['id' => $tripId]);
$trip = $tripQuery->fetch();
if ($trip === false) {
    http_response_code(404);
    exit('Trip not found.');
}
$locationsQuery = db()->prepare(
    'SELECT latitude, longitude, accuracy_m, recorded_at
     FROM trip_locations WHERE trip_id = :trip_id
     ORDER BY recorded_at ASC, id ASC LIMIT 5000'
);
$locationsQuery->execute(['trip_id' => $tripId]);
$locations = $locationsQuery->fetchAll();

page_start('Trip GPS history', 'admin-trip-history');
?>
<section class="admin-dashboard">
  <div class="admin-page-heading"><div><span class="eyebrow"><span class="eyebrow-dot"></span> TRIP HISTORY</span><h1>Trip #<?= h((string) $trip['id']) ?></h1><p><?= h((string) $trip['full_name']) ?> · <?= h((string) ($trip['plate_number'] ?? 'No plate')) ?> · <?= h((string) $trip['route_name']) ?> · <?= h(ucfirst((string) $trip['trip_status'])) ?></p><p><?= h((string) $trip['started_at']) ?><?= $trip['ended_at'] === null ? '' : ' — ' . h((string) $trip['ended_at']) ?></p></div><a class="admin-access-badge" href="admin-trips.php">← Back to trips</a></div>
  <section class="admin-queue">
    <div class="admin-queue-heading"><div><h2>Recorded GPS trail</h2><p><?= h((string) count($locations)) ?> of up to 5,000 chronological location points. GPS accuracy varies by device.</p></div></div>
    <?php if ($locations === []): ?><div class="admin-empty"><p>No location samples were recorded for this trip.</p></div><?php else: ?>
      <div id="trip-history-map" class="admin-map" aria-label="Map of this trip's recorded GPS trail"></div>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Recorded at</th><th>Latitude</th><th>Longitude</th><th>Accuracy</th></tr></thead><tbody>
        <?php foreach ($locations as $location): ?><tr><td data-label="Recorded at"><?= h((string) $location['recorded_at']) ?></td><td data-label="Latitude"><?= h((string) $location['latitude']) ?></td><td data-label="Longitude"><?= h((string) $location['longitude']) ?></td><td data-label="Accuracy"><?= $location['accuracy_m'] === null ? 'Unknown' : h((string) $location['accuracy_m'] . ' m') ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>
</section>
<?php if ($locations !== []): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
(() => {
  const points = <?= json_encode(array_map(
      static fn (array $location): array => [
          'latitude' => (float) $location['latitude'],
          'longitude' => (float) $location['longitude'],
          'recorded_at' => $location['recorded_at'],
      ],
      $locations
  ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
  const coordinates = points.map(point => [point.latitude, point.longitude]);
  const map = L.map('trip-history-map');
  if (coordinates.length === 1) {
    map.setView(coordinates[0], 14);
  } else {
    map.fitBounds(coordinates, {padding: [24, 24]});
  }
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map);
  L.polyline(coordinates, {color: '#2563eb', weight: 5}).addTo(map);
  L.marker(coordinates[0]).addTo(map).bindPopup('Trip started · ' + points[0].recorded_at);
  if (coordinates.length > 1) {
    L.marker(coordinates[coordinates.length - 1]).addTo(map).bindPopup('Latest recorded position · ' + points[points.length - 1].recorded_at);
  }
})();
</script>
<?php endif; ?>
<?php page_end(); ?>
