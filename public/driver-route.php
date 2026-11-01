<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$driver = require_driver();

$route = null;
$stops = [];
$routeAssignment = null;
$driverStatusQuery = db()->prepare(
    'SELECT verification_status FROM drivers WHERE id = :driver_id LIMIT 1'
);
$driverStatusQuery->execute(['driver_id' => (int) $driver['id']]);
$driverStatus = $driverStatusQuery->fetchColumn();
if ($driverStatus === false) {
    http_response_code(404);
    exit('Driver account not found.');
}

if ($driverStatus === 'verified') {
    $query = db()->prepare(
        "SELECT r.id AS route_id, r.route_name, s.stop_sequence, s.location_name,
                s.area_label, s.landmark, s.stop_type, s.implementation_note
         FROM jeepney_profiles j
         INNER JOIN routes r
            ON BINARY r.route_name = BINARY j.route_assignment AND r.is_active = 1
         LEFT JOIN RouteStops s ON s.route_id = r.id
         WHERE j.driver_id = :driver_id
           AND j.verification_status = 'verified'
         ORDER BY s.stop_sequence ASC"
    );
    $query->execute(['driver_id' => (int) $driver['id']]);
    $rows = $query->fetchAll();

    foreach ($rows as $row) {
        if ($route === null) {
            $route = [
                'id' => (int) $row['route_id'],
                'name' => (string) $row['route_name'],
            ];
        }
        if ($row['stop_sequence'] !== null) {
            $stops[] = $row;
        }
    }

    if ($route !== null && $stops === []) {
        $terminalQuery = db()->prepare(
            'SELECT t.terminal_name AS location_name, t.municipality AS area_label,
                    COALESCE(td.landmark, t.terminal_name) AS landmark, rt.stop_order
             FROM route_terminals rt
             INNER JOIN terminals t ON t.id = rt.terminal_id
             LEFT JOIN terminal_details td ON td.terminal_id = t.id
             WHERE rt.route_id = :route_id
             ORDER BY rt.stop_order'
        );
        $terminalQuery->execute(['route_id' => $route['id']]);
        $routeTerminals = $terminalQuery->fetchAll();
        foreach ($routeTerminals as $index => $terminal) {
            $stops[] = [
                'stop_sequence' => $terminal['stop_order'],
                'location_name' => $terminal['location_name'],
                'area_label' => $terminal['area_label'],
                'landmark' => $terminal['landmark'],
                'stop_type' => $index === 0 ? 'terminal' : ($index === count($routeTerminals) - 1 ? 'destination' : 'terminal'),
                'implementation_note' => null,
            ];
        }
    }

    if ($route === null) {
        $assignment = db()->prepare(
            "SELECT route_assignment
             FROM jeepney_profiles
             WHERE driver_id = :driver_id AND verification_status = 'verified'
             LIMIT 1"
        );
        $assignment->execute(['driver_id' => (int) $driver['id']]);
        $routeAssignment = $assignment->fetchColumn();
    }
}

$terminals = [];
$destination = null;
foreach ($stops as $stop) {
    if ($stop['stop_type'] === 'terminal') {
        $terminals[] = $stop;
    }
    if ($stop['stop_type'] === 'destination') {
        $destination = $stop;
    }
}

page_start('My route', 'driver-route');
?>
<section class="route-page">
  <div class="route-page-heading">
    <span class="eyebrow"><span class="eyebrow-dot"></span> ROUTE MANAGEMENT</span>
    <h1>My assigned route</h1>
    <p>View your approved route, terminals, destination, and all boarding points in travel order.</p>
  </div>

  <?php if ($driverStatus !== 'verified'): ?>
    <section class="route-empty-state">
      <div class="empty-state-icon">
        <span aria-hidden="true">◷</span>
      </div>
      <h2>Route details unavailable</h2>
      <p>Your driver account must be verified before an assigned route can be displayed. Once verified, your route information will appear here.</p>
      <a class="button button-outline" href="dashboard.php">
        <span>View application status</span>
        <span aria-hidden="true">→</span>
      </a>
    </section>
  <?php elseif ($route === null): ?>
    <section class="route-empty-state">
      <div class="empty-state-icon warning">
        <span aria-hidden="true">↗</span>
      </div>
      <h2>No configured route found</h2>
      <?php if (is_string($routeAssignment) && $routeAssignment !== ''): ?>
        <p>Your approved vehicle route is <strong><?= h($routeAssignment) ?></strong>, but it doesn't match an active route record yet.</p>
        <div class="empty-state-note">
          <span aria-hidden="true">ℹ</span>
          <span>Please contact the service administrator to configure this route in the system.</span>
        </div>
      <?php else: ?>
        <p>An approved vehicle route assignment is needed before your route details can appear here.</p>
      <?php endif; ?>
      <a class="button button-primary" href="jeepney-profile.php">
        <span>Manage vehicle profile</span>
        <span aria-hidden="true">→</span>
      </a>
    </section>
  <?php elseif ($stops === []): ?>
    <section class="route-empty-state">
      <div class="empty-state-icon info">
        <span aria-hidden="true">◎</span>
      </div>
      <h2><?= h($route['name']) ?></h2>
      <p>This route is active in the system, but its ordered stops haven't been configured yet. Check back later for updates.</p>
    </section>
  <?php else: ?>
    <!-- Route Header Card -->
    <section class="route-header-card">
      <div class="route-header-icon">
        <span aria-hidden="true">🗺️</span>
      </div>
      <div class="route-header-content">
        <div class="route-header-top">
          <div>
            <span class="route-badge">APPROVED ROUTE</span>
            <h2><?= h($route['name']) ?></h2>
          </div>
          <span class="route-status-badge">
            <span class="status-dot"></span>
            Active
          </span>
        </div>
        <p><?= count($stops) ?> <?= count($stops) === 1 ? 'location' : 'locations' ?> configured in travel sequence</p>
      </div>
    </section>

    <!-- Route Summary Cards -->
    <div class="route-summary-section">
      <h3 class="section-title">Route Overview</h3>
      <div class="route-summary-grid">
        <!-- Terminal Card -->
        <article class="route-info-card">
          <div class="info-card-header">
            <div class="info-card-icon terminal">
              <span aria-hidden="true">🏁</span>
            </div>
            <span class="info-card-label">Terminal Location<?= count($terminals) > 1 ? 's' : '' ?></span>
          </div>
          <div class="info-card-content">
            <?php if ($terminals === []): ?>
              <span class="info-card-empty">Not specified</span>
            <?php else: foreach ($terminals as $index => $terminal): ?>
              <div class="info-card-item <?= $index > 0 ? 'has-divider' : '' ?>">
                <strong><?= h((string) $terminal['location_name']) ?></strong>
                <span class="info-card-meta">
                  <span class="meta-icon" aria-hidden="true">📍</span>
                  <?= h((string) $terminal['area_label']) ?>
                </span>
                <span class="info-card-detail"><?= h((string) $terminal['landmark']) ?></span>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </article>

        <!-- Destination Card -->
        <article class="route-info-card">
          <div class="info-card-header">
            <div class="info-card-icon destination">
              <span aria-hidden="true">🎯</span>
            </div>
            <span class="info-card-label">Final Destination</span>
          </div>
          <div class="info-card-content">
            <?php if ($destination === null): ?>
              <span class="info-card-empty">Not specified</span>
            <?php else: ?>
              <div class="info-card-item">
                <strong><?= h((string) $destination['location_name']) ?></strong>
                <span class="info-card-meta">
                  <span class="meta-icon" aria-hidden="true">📍</span>
                  <?= h((string) $destination['area_label']) ?>
                </span>
                <span class="info-card-detail"><?= h((string) $destination['landmark']) ?></span>
              </div>
            <?php endif; ?>
          </div>
        </article>

        <!-- Stats Card -->
        <article class="route-info-card stats">
          <div class="info-card-header">
            <div class="info-card-icon stats">
              <span aria-hidden="true">📊</span>
            </div>
            <span class="info-card-label">Route Statistics</span>
          </div>
          <div class="info-card-content">
            <div class="stats-grid">
              <div class="stat-item">
                <span class="stat-value"><?= count($stops) ?></span>
                <span class="stat-label">Total Stops</span>
              </div>
              <div class="stat-item">
                <span class="stat-value"><?= count($terminals) ?></span>
                <span class="stat-label">Terminals</span>
              </div>
              <div class="stat-item">
                <span class="stat-value"><?= count(array_filter($stops, fn($s) => $s['stop_type'] === 'boarding_point' || $s['stop_type'] === 'stop')) ?></span>
                <span class="stat-label">Stops</span>
              </div>
            </div>
          </div>
        </article>
      </div>
    </div>

    <!-- Detailed Route Stops -->
    <section class="route-stops-section">
      <div class="section-header">
        <div>
          <h3 class="section-title">Travel Sequence</h3>
          <p class="section-description">All stops listed in order from origin to destination</p>
        </div>
        <span class="section-badge"><?= count($stops) ?> locations</span>
      </div>

      <div class="route-timeline">
        <?php foreach ($stops as $index => $stop): ?>
          <div class="timeline-item <?= $stop['stop_type'] ?>">
            <div class="timeline-marker">
              <span class="timeline-number"><?= h((string) $stop['stop_sequence']) ?></span>
              <?php if ($index < count($stops) - 1): ?>
                <div class="timeline-connector"></div>
              <?php endif; ?>
            </div>
            <div class="timeline-content">
              <div class="timeline-header">
                <h4><?= h((string) $stop['location_name']) ?></h4>
                <span class="timeline-type"><?= h(str_replace('_', ' ', (string) $stop['stop_type'])) ?></span>
              </div>
              <div class="timeline-location">
                <span class="location-icon" aria-hidden="true">📍</span>
                <span><?= h((string) $stop['area_label']) ?></span>
              </div>
              <div class="timeline-landmark">
                <span class="landmark-icon" aria-hidden="true">🏢</span>
                <span><?= h((string) $stop['landmark']) ?></span>
              </div>
              <?php if (is_string($stop['implementation_note']) && $stop['implementation_note'] !== ''): ?>
                <div class="timeline-note">
                  <span class="note-icon" aria-hidden="true">ℹ</span>
                  <span><?= h((string) $stop['implementation_note']) ?></span>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <div class="route-footer-note">
      <span class="note-icon" aria-hidden="true">ℹ</span>
      <span>Route information is based on your approved vehicle assignment and configured route stops in the system.</span>
    </div>
  <?php endif; ?>
</section>
<?php page_end(); ?>
