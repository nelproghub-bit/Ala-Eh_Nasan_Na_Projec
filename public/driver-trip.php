<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$driver = require_driver();
$driverId = (int) $driver['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $action = $_POST['action'] ?? '';
    $pdo = db();

    if ($action === 'start') {
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id FROM drivers WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $driverId]);
            if ($lock->fetchColumn() === false) {
                throw new RuntimeException('Driver account was not found.');
            }

            $activeTrip = $pdo->prepare(
                "SELECT id FROM trips WHERE driver_id = :driver_id AND trip_status = 'active' LIMIT 1"
            );
            $activeTrip->execute(['driver_id' => $driverId]);
            if ($activeTrip->fetchColumn() !== false) {
                $pdo->rollBack();
                $_SESSION['driver_trip_flash'] = 'You already have an active trip.';
                redirect('driver-trip.php');
            }

            $vehicle = $pdo->prepare(
                "SELECT j.id AS jeepney_profile_id, r.id AS route_id
                 FROM jeepney_profiles j
                 INNER JOIN jeepney_admin_status js ON js.jeepney_profile_id = j.id
                 INNER JOIN routes r ON BINARY r.route_name = BINARY j.route_assignment AND r.is_active = 1
                 INNER JOIN drivers d ON d.id = j.driver_id AND d.verification_status = 'verified'
                 LEFT JOIN driver_admin_status ds ON ds.driver_id = d.id
                 WHERE j.driver_id = :driver_id
                   AND j.verification_status = 'verified'
                   AND js.operational_status = 'active'
                   AND COALESCE(ds.account_status, 'active') = 'active'
                 LIMIT 1"
            );
            $vehicle->execute(['driver_id' => $driverId]);
            $assignment = $vehicle->fetch();
            if ($assignment === false) {
                $pdo->rollBack();
                $_SESSION['driver_trip_flash'] = 'An approved, active jeepney and route assignment are required before you can start a trip.';
                redirect('driver-trip.php');
            }

            $create = $pdo->prepare(
                "INSERT INTO trips (driver_id, jeepney_profile_id, route_id, trip_status)
                 VALUES (:driver_id, :jeepney_profile_id, :route_id, 'active')"
            );
            $create->execute([
                'driver_id' => $driverId,
                'jeepney_profile_id' => $assignment['jeepney_profile_id'],
                'route_id' => $assignment['route_id'],
            ]);
            $pdo->commit();
            $_SESSION['driver_trip_flash'] = 'Trip started. Your browser will request location permission to share your position with administrators while this trip is active.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        redirect('driver-trip.php');
    }

    if ($action === 'finish' || $action === 'cancel') {
        $tripId = validated_positive_id($_POST['trip_id'] ?? null);
        if ($tripId === null) {
            http_response_code(400);
            exit('Invalid trip.');
        }
        $finish = $pdo->prepare(
            "UPDATE trips SET trip_status = :status, ended_at = CURRENT_TIMESTAMP
             WHERE id = :trip_id AND driver_id = :driver_id AND trip_status = 'active'"
        );
        $finish->execute([
            'status' => $action === 'finish' ? 'completed' : 'cancelled',
            'trip_id' => $tripId,
            'driver_id' => $driverId,
        ]);
        $_SESSION['driver_trip_flash'] = $finish->rowCount() === 1
            ? ($action === 'finish' ? 'Trip completed.' : 'Trip cancelled.')
            : 'No active trip was found.';
        redirect('driver-trip.php');
    }

    if ($action === 'location') {
        header('Content-Type: application/json; charset=UTF-8');
        $tripId = validated_positive_id($_POST['trip_id'] ?? null);
        $latitude = validated_coordinate($_POST['latitude'] ?? null, -90, 90);
        $longitude = validated_coordinate($_POST['longitude'] ?? null, -180, 180);
        $accuracy = validated_coordinate($_POST['accuracy'] ?? null, 0, 100000);
        if ($tripId === null || $latitude === null || $longitude === null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Invalid location update.']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $active = $pdo->prepare(
                "SELECT id FROM trips
                 WHERE id = :trip_id AND driver_id = :driver_id AND trip_status = 'active'
                 FOR UPDATE"
            );
            $active->execute(['trip_id' => $tripId, 'driver_id' => $driverId]);
            if ($active->fetchColumn() === false) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['ok' => false, 'message' => 'This trip is no longer active.']);
                exit;
            }
            $recentCount = $pdo->prepare(
                'SELECT COUNT(*) FROM trip_locations
                 WHERE trip_id = :trip_id
                   AND recorded_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 10 SECOND)'
            );
            $recentCount->execute(['trip_id' => $tripId]);
            if ((int) $recentCount->fetchColumn() > 0) {
                $pdo->rollBack();
                http_response_code(429);
                echo json_encode(['ok' => false, 'message' => 'A location update was received too recently.']);
                exit;
            }
            $insert = $pdo->prepare(
                'INSERT INTO trip_locations (trip_id, latitude, longitude, accuracy_m)
                 VALUES (:trip_id, :latitude, :longitude, :accuracy)'
            );
            $insert->execute([
                'trip_id' => $tripId,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'accuracy' => $accuracy,
            ]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    exit('Invalid trip action.');
}

$tripQuery = db()->prepare(
    "SELECT t.id, t.started_at, r.route_name, j.plate_number
     FROM trips t
     INNER JOIN routes r ON r.id = t.route_id
     INNER JOIN jeepney_profiles j ON j.id = t.jeepney_profile_id
     WHERE t.driver_id = :driver_id AND t.trip_status = 'active'
     ORDER BY t.started_at DESC LIMIT 1"
);
$tripQuery->execute(['driver_id' => $driverId]);
$activeTrip = $tripQuery->fetch();
$driverStatusQuery = db()->prepare(
    'SELECT verification_status FROM drivers WHERE id = :driver_id LIMIT 1'
);
$driverStatusQuery->execute(['driver_id' => $driverId]);
$driverVerified = $driverStatusQuery->fetchColumn() === 'verified';
$flash = (string) ($_SESSION['driver_trip_flash'] ?? '');
unset($_SESSION['driver_trip_flash']);

page_start('Trip tracking', 'driver-trip');
?>
<section class="admin-dashboard">
  <div class="admin-page-heading">
    <div>
      <span class="eyebrow"><span class="eyebrow-dot"></span> DRIVER TRIP</span>
      <h1>Trip tracking</h1>
      <p>Start location sharing only when you are ready to begin an authorized route trip.</p>
    </div>
    <span class="admin-access-badge"><span aria-hidden="true">◈</span> Location sharing is trip-based</span>
  </div>
  <?php if ($flash !== ''): ?><p class="message message-success admin-flash" role="status"><?= h($flash) ?></p><?php endif; ?>
  <section class="admin-queue">
    <div class="admin-queue-heading">
      <div>
        <h2><?= $activeTrip === false ? 'Ready to start?' : 'Trip in progress' ?></h2>
        <p>Your precise location is visible to authorized administrators while this trip remains active.</p>
      </div>
    </div>
    <div class="admin-queue-content">
      <?php if (!$driverVerified): ?>
        <p>Driver verification is required before starting a trip.</p>
      <?php elseif ($activeTrip !== false): ?>
        <p><strong><?= h((string) $activeTrip['route_name']) ?></strong> · Jeepney <?= h((string) ($activeTrip['plate_number'] ?? 'plate pending')) ?> · Started <?= h(date('M j, Y g:i A', strtotime((string) $activeTrip['started_at']))) ?></p>
        <p id="gps-status" role="status" aria-live="polite">Requesting browser location permission…</p>
        <form method="post" action="driver-trip.php">
          <?= csrf_field() ?>
          <input type="hidden" name="trip_id" value="<?= h((string) $activeTrip['id']) ?>">
          <button class="button button-outline" name="action" value="finish">Complete trip</button>
          <button class="button button-reject" name="action" value="cancel">Cancel trip</button>
        </form>
        <script>
        (() => {
          const status = document.getElementById('gps-status');
          const tripId = <?= json_encode((int) $activeTrip['id']) ?>;
          const token = <?= json_encode(csrf_token()) ?>;
          let lastSent = 0;
          if (!navigator.geolocation) {
            status.textContent = 'This browser does not support location sharing.';
            return;
          }
          let watchId = null;
          watchId = navigator.geolocation.watchPosition(async (position) => {
            if (Date.now() - lastSent < 15000) return;
            lastSent = Date.now();
            const body = new URLSearchParams({
              csrf_token: token,
              action: 'location',
              trip_id: String(tripId),
              latitude: String(position.coords.latitude),
              longitude: String(position.coords.longitude),
              accuracy: String(position.coords.accuracy)
            });
            try {
              const response = await fetch('driver-trip.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body
              });
              const result = await response.json();
              if (!result.ok) {
                if (response.status === 409 && watchId !== null) {
                  navigator.geolocation.clearWatch(watchId);
                }
                status.textContent = result.message;
                return;
              }
              status.textContent = `Location shared at ${new Date().toLocaleTimeString()}.`;
            } catch {
              status.textContent = 'Location could not be sent. Check your connection.';
            }
          }, () => {
            status.textContent = 'Location permission was not granted; the trip is active but no position is being shared.';
          }, {enableHighAccuracy: true, maximumAge: 10000, timeout: 20000});
        })();
        </script>
      <?php else: ?>
        <p>Starting a trip activates location sharing in this browser. You can stop sharing by completing or cancelling the trip. A secure HTTPS connection is required on most mobile browsers.</p>
        <form method="post" action="driver-trip.php">
          <?= csrf_field() ?>
          <button class="button button-primary" name="action" value="start" <?= $driverVerified ? '' : 'disabled' ?>>Start trip & share location</button>
        </form>
      <?php endif; ?>
    </div>
  </section>
</section>
<?php page_end(); ?>
