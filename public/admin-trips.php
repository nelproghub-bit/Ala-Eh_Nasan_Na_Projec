<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/admin-bootstrap.php';
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
        $driverIdQuery = $pdo->prepare('SELECT driver_id FROM trips WHERE id = ?');
        $driverIdQuery->execute([$tripId]);
        $cancelledDriverId = (int) $driverIdQuery->fetchColumn();
        $pdo->prepare("UPDATE driver_trip_status SET status = 'out', revision = revision + 1 WHERE driver_id = ?")->execute([$cancelledDriverId]);
        $pdo->prepare("UPDATE driver_pickup_assignments SET availability = 'off_duty', revision = revision + 1 WHERE driver_id = ?")->execute([$cancelledDriverId]);
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
$driverAvailability = $pdo->query("SELECT d.full_name, j.plate_number,
    CASE WHEN s.status = 'out' THEN 'out'
         WHEN seats.capacity = j.capacity AND seats.occupied >= j.capacity THEN 'full'
         ELSE COALESCE(s.status, 'available') END AS status,
    GREATEST(COALESCE(s.updated_at, '1970-01-01'), COALESCE(seats.updated_at, '1970-01-01')) AS updated_at,
    a.route_name, l.location_name
    FROM drivers d LEFT JOIN driver_trip_status s ON s.driver_id = d.id
    LEFT JOIN driver_seat_availability seats ON seats.driver_id = d.id
    LEFT JOIN jeepney_profiles j ON j.driver_id = d.id
    LEFT JOIN driver_pickup_assignments a ON a.driver_id = d.id
    LEFT JOIN map_locations l ON l.location_key = a.location_key
    LEFT JOIN driver_admin_status ds ON ds.driver_id = d.id
    LEFT JOIN jeepney_admin_status js ON js.jeepney_profile_id = j.id
    WHERE COALESCE(ds.account_status, 'active') = 'active'
      AND COALESCE(js.operational_status, 'active') = 'active'
      AND (s.driver_id IS NOT NULL OR seats.driver_id IS NOT NULL)
    ORDER BY updated_at DESC LIMIT 100")->fetchAll();

page_start('Trip monitoring', 'admin-trips');
?>
<section class="admin-dashboard">
  <div class="admin-page-heading"><div><span class="eyebrow"><span class="eyebrow-dot"></span> TRIP OPERATIONS</span><h1>Trips & live map</h1><p>Monitor opt-in driver locations while active trips are running, and review trip history.</p></div><span class="admin-access-badge"><span aria-hidden="true">📡</span> Refreshes every <?= h((string) $refreshSeconds) ?> seconds</span></div>
  <?php if ($flash !== ''): ?><p class="message message-success admin-flash" role="status"><?= h($flash) ?></p><?php endif; ?>
  <section class="admin-queue">
    <div class="admin-queue-heading"><div><h2>Driver availability</h2><p>Current status and assigned starting area from the driver dashboard. These are status reports, not GPS positions.</p></div></div>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Driver / jeepney</th><th>Assigned route / area</th><th>Status</th><th>Updated</th></tr></thead><tbody>
      <?php foreach ($driverAvailability as $availability): ?>
      <tr><td><?= h((string) $availability['full_name']) ?><small><?= h((string) ($availability['plate_number'] ?? '')) ?></small></td><td><?= h((string) ($availability['route_name'] ?? 'Not assigned')) ?><small><?= h((string) ($availability['location_name'] ?? '')) ?></small></td><td><?= h(ucfirst(str_replace('_', ' ', (string) $availability['status']))) ?></td><td><?= h((string) $availability['updated_at']) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($driverAvailability === []): ?><tr><td colspan="4">No driver status reports yet.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
  <section class="admin-queue">
    <div class="admin-queue-heading"><div><h2>Live jeepney locations</h2><p>Only drivers who start a trip and grant browser location permission appear. Positions may be stale or unavailable.</p></div><span id="map-update-status" role="status" aria-live="polite">Loading location updates…</span></div>
    <p id="route-map-status" class="route-map-status" role="status" aria-live="polite">Loading planned route lines…</p>
    <div id="trip-map" class="admin-map" aria-label="Map showing approximate Batangas–Mabini road route and active jeepney locations"></div>
    <div class="route-map-legend"><span><i class="route-map-line" aria-hidden="true"></i> Approximate road route</span><span><i class="route-map-stop" aria-hidden="true"></i> Planned route stop</span><span>Map navigation is limited to the Batangas area.</span><span>Landmark pins are approximate; confirm exact locations locally.</span></div>
    <p class="map-attribution">Map data © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>. Road routing by <a href="https://project-osrm.org/" target="_blank" rel="noopener noreferrer">OSRM</a>. Precise jeepney locations are visible only to authorized admins.</p>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Trip</th><th>Driver / vehicle</th><th>Route</th><th>Last location</th><th>Action</th></tr></thead><tbody id="active-trip-rows">
      <?php foreach ($activeTrips as $trip): ?>
        <tr><td data-label="Trip">#<?= h((string) $trip['id']) ?><small>Started <?= h(date('M j, g:i A', strtotime((string) $trip['started_at']))) ?></small></td>
          <td data-label="Driver / vehicle"><strong><?= h((string) $trip['full_name']) ?></strong><small><?= h((string) $trip['plate_number']) ?></small></td>
          <td data-label="Route"><?= h((string) $trip['route_name']) ?></td>
          <td data-label="Last location"><?= $trip['latitude'] === null ? 'Awaiting GPS' : h((string) $trip['latitude'] . ', ' . (string) $trip['longitude'] . '  -  ' . $trip['recorded_at']) ?></td>
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
          <td data-label="Duration / location points"><?= $trip['ended_at'] === null ? 'Ongoing' : h((string) $trip['ended_at']) ?>  -  <?= h((string) $trip['location_updates']) ?> points<?php if ((int) $trip['location_updates'] > 0): ?><small><a href="admin-trip-history.php?id=<?= h((string) $trip['id']) ?>">View GPS trail</a></small><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if ($history === []): ?><tr><td colspan="5">No trip history yet.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
</section>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="assets/admin/batangas-map.js"></script>
<script>
(() => {
  const status = document.getElementById('map-update-status');
  const routeStatus = document.getElementById('route-map-status');
  const mapElement = document.getElementById('trip-map');
  const activeRows = document.getElementById('active-trip-rows');
  const refreshSeconds = <?= json_encode($refreshSeconds) ?>;
  const csrfToken = <?= json_encode(csrf_token()) ?>;
  const canCancelTrips = <?= json_encode(in_array($admin['role'] ?? '', ['owner', 'admin'], true)) ?>;
  let map = null;
  let routeLinesLoaded = false;
  let hasSetInitialBounds = false;
  let latestValidLocations = [];
  const routeBoundsPoints = [];
  const markers = new Map();
  let routeLayer = null;
  const jeepneyIcon = window.L ? L.divIcon({
    className: 'jeepney-map-icon',
    html: '<span class="jeepney-map-marker" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M5 3.75h14A2.25 2.25 0 0 1 21.25 6v11A2.25 2.25 0 0 1 19 19.25h-.5v1a1 1 0 0 1-2 0v-1h-9v1a1 1 0 0 1-2 0v-1H5A2.25 2.25 0 0 1 2.75 17V6A2.25 2.25 0 0 1 5 3.75Zm-.25 3v4h14.5v-4a.25.25 0 0 0-.25-.25H5a.25.25 0 0 0-.25.25Zm0 6v4.25c0 .138.112.25.25.25h14a.25.25 0 0 0 .25-.25v-4.25H4.75ZM7 14a1.25 1.25 0 1 1 0 2.5A1.25 1.25 0 0 1 7 14Zm10 0a1.25 1.25 0 1 1 0 2.5 1.25 1.25 0 0 1 0-2.5Z"/></svg></span>',
    iconSize: [44, 44],
    iconAnchor: [22, 22],
    popupAnchor: [0, -20]
  }) : null;
  const initializeMap = () => {
    if (map || !window.L) return;
    map = BatangasMap.create(mapElement);
    routeLayer = L.featureGroup().addTo(map);
  };
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[char]));
  const fitInitialBounds = () => {
    if (!routeLinesLoaded || hasSetInitialBounds || !map) return;
    const points = [...routeBoundsPoints, ...latestValidLocations.map(point => [
      Number(point.latitude), Number(point.longitude)
    ])];
    if (points.length) {
      BatangasMap.fitPoints(map, points, {padding: [36, 36], maxZoom: 12});
    }
    hasSetInitialBounds = true;
  };
  const loadRouteLines = async () => {
    try {
      const response = await fetch('api/admin-route-lines.php', {
        headers: {'Accept': 'application/json'},
        cache: 'no-store'
      });
      if (!response.ok) throw new Error('Planned route data is unavailable.');
      const data = await response.json();
      const routes = Array.isArray(data.routes) ? data.routes : [];
      if (!routes.length) {
        routeStatus.textContent = 'No active routes have planned stops configured.';
        return;
      }

      const colors = ['#e11d48', '#0f766e', '#7c3aed', '#b45309'];
      let drawnRoutes = 0;
      let routesWithoutRoadLines = 0;
      let missingCoordinates = 0;
      const routeStops = [];
      for (const [index, route] of routes.entries()) {
        const color = colors[index % colors.length];
        const stops = Array.isArray(route.stops) ? route.stops : [];
        const locatedStops = stops.filter(stop =>
          stop.latitude !== null && stop.longitude !== null &&
          Number.isFinite(Number(stop.latitude)) &&
          Number.isFinite(Number(stop.longitude)) &&
          Number(stop.latitude) >= -90 && Number(stop.latitude) <= 90 &&
          Number(stop.longitude) >= -180 && Number(stop.longitude) <= 180
        );
        missingCoordinates += stops.length - locatedStops.length;
        for (const stop of locatedStops) {
          const position = [Number(stop.latitude), Number(stop.longitude)];
          routeBoundsPoints.push(position);
          const title = escapeHtml(stop.name);
          const popup = `<strong>${title}</strong><br>${escapeHtml(stop.area)}<br>${escapeHtml(stop.landmark)}`;
          routeStops.push({position, color, popup});
        }
        if (locatedStops.length < 2) {
          routesWithoutRoadLines++;
          continue;
        }

        const coordinates = locatedStops.map(stop => `${stop.longitude},${stop.latitude}`).join(';');
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 12000);
        let roadData;
        try {
          const roadResponse = await fetch(
            `https://router.project-osrm.org/route/v1/driving/${coordinates}?overview=full&geometries=geojson&steps=false`,
            {headers: {'Accept': 'application/json'}, cache: 'no-store', signal: controller.signal}
          );
          if (!roadResponse.ok) {
            routesWithoutRoadLines++;
            continue;
          }
          roadData = await roadResponse.json();
        } catch {
          routesWithoutRoadLines++;
          continue;
        } finally {
          window.clearTimeout(timeout);
        }
        const roadCoordinates = roadData?.routes?.[0]?.geometry?.coordinates;
        if (roadData.code !== 'Ok' || !Array.isArray(roadCoordinates) || roadCoordinates.length < 2) {
          routesWithoutRoadLines++;
          continue;
        }

        const roadPoints = roadCoordinates.map(([longitude, latitude]) => [latitude, longitude]);
        routeBoundsPoints.push(...roadPoints);
        L.polyline(roadPoints, {color: '#fff', opacity: 0.9, weight: 10, lineCap: 'round', lineJoin: 'round'}).addTo(routeLayer);
        L.polyline(roadPoints, {color, opacity: 0.92, weight: 5, lineCap: 'round', lineJoin: 'round'}).addTo(routeLayer);
        drawnRoutes++;
      }
      for (const stop of routeStops) {
        L.circleMarker(stop.position, {
          radius: 7,
          color: '#fff',
          weight: 3,
          fillColor: stop.color,
          fillOpacity: 1
        }).bindPopup(stop.popup).addTo(routeLayer);
      }
      if (drawnRoutes) {
        routeStatus.textContent = `Showing ${drawnRoutes} approximate road route${drawnRoutes === 1 ? '' : 's'} with planned stops. Map routing follows available roads, not verified transit paths.`
          + (missingCoordinates ? ` ${missingCoordinates} stop${missingCoordinates === 1 ? '' : 's'} have no coordinates.` : '')
          + (routesWithoutRoadLines ? ` ${routesWithoutRoadLines} route${routesWithoutRoadLines === 1 ? '' : 's'} could not be drawn.` : '');
      } else {
        routeStatus.textContent = (routeStops.length
          ? 'Planned stop pins are shown, but road-routing is unavailable; no route line could be drawn.'
          : 'Planned stops are missing usable coordinates or road-routing is unavailable; no route line could be drawn.')
          + (missingCoordinates ? ` ${missingCoordinates} stop${missingCoordinates === 1 ? '' : 's'} have no coordinates.` : '');
      }
    } catch (error) {
      routeStatus.textContent = error instanceof Error && error.name === 'AbortError'
        ? 'Road routing took too long. Planned stops may still be available after refreshing.'
        : 'Approximate planned route lines are unavailable. Live jeepney locations can still load.';
    } finally {
      routeLinesLoaded = true;
      fitInitialBounds();
    }
  };
  const updateMap = async () => {
    try {
      const response = await fetch('api/admin-locations.php', {headers: {'Accept': 'application/json'}, cache: 'no-store'});
      if (!response.ok) throw new Error('Location service unavailable.');
      const data = await response.json();
      const locations = Array.isArray(data.locations) ? data.locations : [];
      const valid = locations.filter(point => BatangasMap.contains([point.latitude, point.longitude]));
      latestValidLocations = valid;
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
        addCell('Trip', `#${point.trip_id}  -  Started ${point.started_at}`);
        addCell('Driver / vehicle', `${point.full_name}  -  ${point.plate_number ?? 'No plate'}`);
        addCell('Route', point.route_name);
        addCell('Last location', point.latitude === null
          ? 'Awaiting GPS'
          : `${point.latitude}, ${point.longitude}  -  ${point.recorded_at}`);
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
      fitInitialBounds();
      status.textContent = valid.length
        ? `${valid.length} jeepney location${valid.length === 1 ? '' : 's'}  -  Refreshes every ${refreshSeconds}s  -  Updated ${new Date().toLocaleTimeString()}`
        : locations.length
          ? `${locations.length} active trip${locations.length === 1 ? '' : 's'}  -  No GPS positions within the Batangas map area.`
          : `No active trip is sharing a GPS position. Map refreshes every ${refreshSeconds} seconds.`;
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : 'Location updates are unavailable.';
    }
  };
  initializeMap();
  loadRouteLines();
  updateMap();
  window.setInterval(updateMap, refreshSeconds * 1000);
})();
</script>
<?php page_end(); ?>
