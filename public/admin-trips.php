<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$admin = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = require_admin_role(['owner', 'admin']);
    verify_csrf_token();
    $tripId = validated_positive_id($_POST['trip_id'] ?? null);
    if ($tripId === null || ($_POST['action'] ?? '') !== 'cancel_trip') {
        http_response_code(400);
        exit('Invalid trip action.');
    }
    $cancel = $pdo->prepare(
        "UPDATE trips SET trip_status = 'cancelled', ended_at = CURRENT_TIMESTAMP
         WHERE id = :id AND trip_status = 'active'"
    );
    $cancel->execute(['id' => $tripId]);
    if ($cancel->rowCount() === 1) {
        record_admin_activity($pdo, $admin, 'trip_cancelled_by_admin', 'trip', $tripId);
    }
    $_SESSION['admin_flash'] = $cancel->rowCount() === 1 ? 'Active trip cancelled.' : 'No active trip was found.';
    redirect('admin-trips.php');
}

$activeTrips = $pdo->query(
    "SELECT t.id, t.started_at, d.full_name, j.plate_number, r.route_name,
            l.latitude, l.longitude, l.recorded_at
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
$history = $pdo->query(
    "SELECT t.id, t.trip_status, t.started_at, t.ended_at, d.full_name,
            j.plate_number, r.route_name,
            (SELECT COUNT(*) FROM trip_locations l WHERE l.trip_id = t.id) AS location_updates
     FROM trips t
     INNER JOIN drivers d ON d.id = t.driver_id
     INNER JOIN jeepney_profiles j ON j.id = t.jeepney_profile_id
     INNER JOIN routes r ON r.id = t.route_id
     ORDER BY t.started_at DESC LIMIT 100"
)->fetchAll();
$refreshQuery = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'map_refresh_seconds'");
$refreshQuery->execute();
$refreshSeconds = max(10, min(120, (int) ($refreshQuery->fetchColumn() ?: 20)));
$flash = (string) ($_SESSION['admin_flash'] ?? '');
unset($_SESSION['admin_flash']);

page_start('Trip monitoring', 'admin-trips');
?>
<section class="admin-dashboard">
  <div class="admin-page-heading"><div><span class="eyebrow"><span class="eyebrow-dot"></span> TRIP OPERATIONS</span><h1>Trips & live map</h1><p>Monitor opt-in driver locations while active trips are running, and review trip history.</p></div><span class="admin-access-badge"><span aria-hidden="true">📡</span> Refreshes every <?= h((string) $refreshSeconds) ?> seconds</span></div>
  <?php if ($flash !== ''): ?><p class="message message-success admin-flash" role="status"><?= h($flash) ?></p><?php endif; ?>
  <section class="admin-queue">
    <div class="admin-queue-heading"><div><h2>Live jeepney locations</h2><p>Only drivers who start a trip and grant browser location permission appear. Positions may be stale or unavailable.</p></div><span id="map-update-status" role="status" aria-live="polite">Loading location updates…</span></div>
    <div id="trip-map" class="admin-map" aria-label="Map showing active jeepney trip locations"></div>
    <p class="map-attribution">Map data © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>. Precise locations are visible only to authorized admins.</p>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Trip</th><th>Driver / vehicle</th><th>Route</th><th>Last location</th><th>Action</th></tr></thead><tbody id="active-trip-rows">
      <?php foreach ($activeTrips as $trip): ?>
        <tr><td data-label="Trip">#<?= h((string) $trip['id']) ?><small>Started <?= h(date('M j, g:i A', strtotime((string) $trip['started_at']))) ?></small></td>
          <td data-label="Driver / vehicle"><strong><?= h((string) $trip['full_name']) ?></strong><small><?= h((string) $trip['plate_number']) ?></small></td>
          <td data-label="Route"><?= h((string) $trip['route_name']) ?></td>
          <td data-label="Last location"><?= $trip['latitude'] === null ? 'Awaiting GPS' : h((string) $trip['latitude'] . ', ' . (string) $trip['longitude'] . ' · ' . $trip['recorded_at']) ?></td>
          <td data-label="Action"><?php if (($admin['role'] ?? '') !== 'viewer'): ?><form method="post" action="admin-trips.php" onsubmit="return confirm('Cancel this active trip?');"><?= csrf_field() ?><input type="hidden" name="trip_id" value="<?= h((string) $trip['id']) ?>"><button class="button button-reject" name="action" value="cancel_trip">Cancel trip</button></form><?php else: ?>View only<?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if ($activeTrips === []): ?><tr><td colspan="5">No active trips.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
  <section class="admin-queue">
    <div class="admin-queue-heading"><div><h2>Trip history</h2><p>Most recent 100 active, completed, and cancelled trips.</p></div></div>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Trip</th><th>Driver / jeepney</th><th>Route</th><th>Status</th><th>Duration / location points</th></tr></thead><tbody>
      <?php foreach ($history as $trip): ?>
        <tr><td data-label="Trip">#<?= h((string) $trip['id']) ?><small><?= h(date('M j, Y g:i A', strtotime((string) $trip['started_at']))) ?></small></td>
          <td data-label="Driver / jeepney"><?= h((string) $trip['full_name']) ?><small><?= h((string) $trip['plate_number']) ?></small></td>
          <td data-label="Route"><?= h((string) $trip['route_name']) ?></td>
          <td data-label="Status"><?= h(ucfirst((string) $trip['trip_status'])) ?></td>
          <td data-label="Duration / location points"><?= $trip['ended_at'] === null ? 'Ongoing' : h((string) $trip['ended_at']) ?> · <?= h((string) $trip['location_updates']) ?> points<?php if ((int) $trip['location_updates'] > 0): ?><small><a href="admin-trip-history.php?id=<?= h((string) $trip['id']) ?>">View GPS trail</a></small><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if ($history === []): ?><tr><td colspan="5">No trip history yet.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
</section>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
(() => {
  const status = document.getElementById('map-update-status');
  const mapElement = document.getElementById('trip-map');
  const activeRows = document.getElementById('active-trip-rows');
  const refreshSeconds = <?= json_encode($refreshSeconds) ?>;
  const csrfToken = <?= json_encode(csrf_token()) ?>;
  const canCancelTrips = <?= json_encode(in_array($admin['role'] ?? '', ['owner', 'admin'], true)) ?>;
  const defaultMapCenter = [13.7565, 121.0583];
  let map = null;
  let hasCenteredOnLocations = false;
  const markers = new Map();
  const jeepneyIcon = window.L ? L.divIcon({
    className: 'jeepney-map-icon',
    html: '<span class="jeepney-map-marker" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M5 3.75h14A2.25 2.25 0 0 1 21.25 6v11A2.25 2.25 0 0 1 19 19.25h-.5v1a1 1 0 0 1-2 0v-1h-9v1a1 1 0 0 1-2 0v-1H5A2.25 2.25 0 0 1 2.75 17V6A2.25 2.25 0 0 1 5 3.75Zm-.25 3v4h14.5v-4a.25.25 0 0 0-.25-.25H5a.25.25 0 0 0-.25.25Zm0 6v4.25c0 .138.112.25.25.25h14a.25.25 0 0 0 .25-.25v-4.25H4.75ZM7 14a1.25 1.25 0 1 1 0 2.5A1.25 1.25 0 0 1 7 14Zm10 0a1.25 1.25 0 1 1 0 2.5 1.25 1.25 0 0 1 0-2.5Z"/></svg></span>',
    iconSize: [44, 44],
    iconAnchor: [22, 22],
    popupAnchor: [0, -20]
  }) : null;
  const initializeMap = () => {
    if (map || !window.L) return;
    map = L.map(mapElement).setView(defaultMapCenter, 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
  };
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[char]));
  const updateMap = async () => {
    try {
      const response = await fetch('api/admin-locations.php', {headers: {'Accept': 'application/json'}, cache: 'no-store'});
      if (!response.ok) throw new Error('Location service unavailable.');
      const data = await response.json();
      const locations = Array.isArray(data.locations) ? data.locations : [];
      const valid = locations.filter(point => point.latitude !== null && point.longitude !== null);
      activeRows.replaceChildren();
      for (const point of locations) {
        const row = document.createElement('tr');
        const addCell = (label, text) => {
          const cell = document.createElement('td');
          cell.dataset.label = label;
          cell.textContent = text;
          row.appendChild(cell);
          return cell;
        };
        addCell('Trip', `#${point.trip_id} · Started ${point.started_at}`);
        addCell('Driver / vehicle', `${point.full_name} · ${point.plate_number ?? 'No plate'}`);
        addCell('Route', point.route_name);
        addCell('Last location', point.latitude === null
          ? 'Awaiting GPS'
          : `${point.latitude}, ${point.longitude} · ${point.recorded_at}`);
        const actionCell = addCell('Action', '');
        if (canCancelTrips) {
          const form = document.createElement('form');
          form.method = 'post';
          form.action = 'admin-trips.php';
          const hidden = (name, value) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            return input;
          };
          form.append(hidden('csrf_token', csrfToken), hidden('trip_id', String(point.trip_id)));
          const button = document.createElement('button');
          button.className = 'button button-reject';
          button.type = 'submit';
          button.name = 'action';
          button.value = 'cancel_trip';
          button.textContent = 'Cancel trip';
          form.appendChild(button);
          actionCell.appendChild(form);
        } else {
          actionCell.textContent = 'View only';
        }
        activeRows.appendChild(row);
      }
      if (locations.length === 0) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 5;
        cell.textContent = 'No active trips.';
        row.appendChild(cell);
        activeRows.appendChild(row);
      }
      if (!window.L || !jeepneyIcon) {
        status.textContent = 'The map could not load. Active trip details are still available below.';
        return;
      }
      initializeMap();
      const activeIds = new Set();
      for (const point of valid) {
        const key = String(point.trip_id);
        activeIds.add(key);
        const markerContent = `<strong>${escapeHtml(point.plate_number || 'Jeepney')}</strong><br>${escapeHtml(point.full_name)}<br>${escapeHtml(point.route_name)}<br>Updated ${escapeHtml(point.recorded_at)}`;
        const position = [Number(point.latitude), Number(point.longitude)];
        if (markers.has(key)) {
          markers.get(key).setLatLng(position).setPopupContent(markerContent);
        } else {
          markers.set(key, L.marker(position, {icon: jeepneyIcon}).addTo(map).bindPopup(markerContent));
        }
      }
      for (const [key, marker] of markers) {
        if (!activeIds.has(key)) {
          map.removeLayer(marker);
          markers.delete(key);
        }
      }
      if (valid.length && !hasCenteredOnLocations) {
        const bounds = L.latLngBounds(valid.map(point => [Number(point.latitude), Number(point.longitude)]));
        map.fitBounds(bounds, {padding: [36, 36], maxZoom: 15});
        hasCenteredOnLocations = true;
      }
      status.textContent = valid.length
        ? `${valid.length} jeepney location${valid.length === 1 ? '' : 's'} · Refreshes every ${refreshSeconds}s · Updated ${new Date().toLocaleTimeString()}`
        : locations.length
          ? `${locations.length} active trip${locations.length === 1 ? '' : 's'} · Waiting for driver location permission or GPS fix.`
          : `No active trip is sharing a GPS position. Map refreshes every ${refreshSeconds} seconds.`;
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : 'Location updates are unavailable.';
    }
  };
  initializeMap();
  updateMap();
  window.setInterval(updateMap, refreshSeconds * 1000);
})();
</script>
<?php page_end(); ?>
