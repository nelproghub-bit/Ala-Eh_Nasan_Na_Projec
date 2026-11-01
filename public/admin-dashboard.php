<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = require_admin_role(['owner', 'admin']);
    verify_csrf_token();

    $action = $_POST['action'] ?? '';
    $newStatus = match ($action) {
        'approve' => 'verified',
        'reject' => 'rejected',
        default => null,
    };
    $recordType = $_POST['record_type'] ?? 'driver';

    $recordId = filter_var($_POST['record_id'] ?? $_POST['driver_id'] ?? null, FILTER_VALIDATE_INT);
    if ($recordId === false || $recordId === null || $recordId < 1 || $newStatus === null) {
        http_response_code(400);
        exit('Invalid application action.');
    }

    if ($recordType === 'vehicle') {
        if ($action === 'approve') {
            $update = db()->prepare(
                "UPDATE jeepney_profiles
                 SET plate_number = pending_plate_number,
                     capacity = pending_capacity,
                     route_assignment = pending_route_assignment,
                     pending_plate_number = NULL,
                     pending_capacity = NULL,
                     pending_route_assignment = NULL,
                     verification_status = 'verified'
                 WHERE id = :id
                   AND verification_status = 'pending_verification'
                   AND pending_plate_number IS NOT NULL
                   AND pending_capacity IS NOT NULL
                   AND pending_route_assignment IS NOT NULL"
            );
        } elseif ($action === 'reject') {
            $update = db()->prepare(
                "UPDATE jeepney_profiles
                 SET verification_status = 'rejected'
                 WHERE id = :id AND verification_status = 'pending_verification'"
            );
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $update->execute(['id' => $recordId]);
            if ($update->rowCount() === 1 && $newStatus === 'verified') {
                $status = $pdo->prepare(
                    "INSERT IGNORE INTO jeepney_admin_status (jeepney_profile_id, operational_status)
                     VALUES (?, 'active')"
                );
                $status->execute([$recordId]);
            }
            $_SESSION['admin_flash'] = $update->rowCount() === 1
                ? 'Vehicle changes ' . ($newStatus === 'verified' ? 'approved' : 'rejected') . ' successfully.'
                : 'These vehicle changes were already reviewed or could not be found.';
            if ($update->rowCount() === 1) {
                record_admin_activity($pdo, $admin, 'vehicle_' . $newStatus, 'jeepney_profile', $recordId);
            }
            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }
            $_SESSION['admin_flash'] = 'These vehicle details could not be approved because the plate number is already assigned.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        redirect('admin-dashboard.php#vehicle-review');
    }

    if ($recordType !== 'driver') {
        http_response_code(400);
        exit('Invalid application type.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare(
            "UPDATE drivers
             SET verification_status = :status
             WHERE id = :id AND verification_status = 'pending_verification'"
        );
        $update->execute(['status' => $newStatus, 'id' => $recordId]);
        $_SESSION['admin_flash'] = $update->rowCount() === 1
            ? 'Application ' . ($newStatus === 'verified' ? 'approved' : 'rejected') . ' successfully.'
            : 'This application was already reviewed or could not be found.';
        if ($update->rowCount() === 1) {
            record_admin_activity($pdo, $admin, 'driver_' . $newStatus, 'driver', $recordId);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    redirect('admin-dashboard.php');
}

$allowedFilters = ['pending_verification', 'verified', 'rejected', 'all'];
$filter = $_GET['status'] ?? 'pending_verification';
if (!is_string($filter) || !in_array($filter, $allowedFilters, true)) {
    $filter = 'pending_verification';
}

$pdo = db();
$counts = $pdo->query(
    "SELECT COUNT(*) AS total,
        COALESCE(SUM(verification_status = 'pending_verification'), 0) AS pending,
        COALESCE(SUM(verification_status = 'verified'), 0) AS verified,
        COALESCE(SUM(verification_status = 'rejected'), 0) AS rejected
     FROM drivers"
)->fetch();
$pendingVehicleCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM jeepney_profiles WHERE verification_status = 'pending_verification'"
)->fetchColumn();
$overview = [
    'vehicles' => (int) $pdo->query(
        "SELECT COUNT(*) FROM jeepney_profiles WHERE verification_status = 'verified'"
    )->fetchColumn(),
    'drivers' => (int) $pdo->query(
        "SELECT COUNT(*) FROM drivers d
         LEFT JOIN driver_admin_status s ON s.driver_id = d.id
         WHERE d.verification_status = 'verified'
           AND COALESCE(s.account_status, 'active') = 'active'"
    )->fetchColumn(),
    'terminals' => (int) $pdo->query(
        'SELECT COUNT(*) FROM terminals WHERE is_active = 1'
    )->fetchColumn(),
    'trips' => (int) $pdo->query(
        "SELECT COUNT(*) FROM trips WHERE trip_status = 'active'"
    )->fetchColumn(),
];
$recentActivity = $pdo->query(
    'SELECT l.action, l.entity_type, l.entity_id, l.details, l.created_at, a.email
     FROM activity_logs l
     LEFT JOIN admins a ON a.id = l.admin_id
     ORDER BY l.created_at DESC LIMIT 8'
)->fetchAll();

if ($filter === 'all') {
    $drivers = $pdo->query(
        'SELECT id, full_name, email, phone, license_number, verification_status, created_at
         FROM drivers ORDER BY created_at DESC LIMIT 100'
    )->fetchAll();
} else {
    $query = $pdo->prepare(
        'SELECT id, full_name, email, phone, license_number, verification_status, created_at
         FROM drivers WHERE verification_status = :status
         ORDER BY created_at DESC LIMIT 100'
    );
    $query->execute(['status' => $filter]);
    $drivers = $query->fetchAll();
}

$flash = $_SESSION['admin_flash'] ?? '';
unset($_SESSION['admin_flash']);
$vehicleReviews = $pdo->query(
    "SELECT j.id,
            COALESCE(j.pending_plate_number, j.plate_number) AS review_plate_number,
            COALESCE(j.pending_capacity, j.capacity) AS review_capacity,
            COALESCE(j.pending_route_assignment, j.route_assignment) AS review_route_assignment,
            j.verification_status, j.updated_at, d.full_name, d.email
     FROM jeepney_profiles j
     INNER JOIN drivers d ON d.id = j.driver_id
     WHERE j.verification_status IN ('pending_verification', 'verified', 'rejected')
     ORDER BY CASE WHEN j.verification_status = 'pending_verification' THEN 0 ELSE 1 END,
              j.updated_at DESC
     LIMIT 100"
)->fetchAll();

page_start('Admin dashboard', 'admin-dashboard');
?>
<section class="admin-dashboard">
  <div class="admin-page-heading">
    <div>
      <span class="eyebrow"><span class="eyebrow-dot"></span> ADMINISTRATION</span>
      <h1>Operations overview</h1>
      <p>Welcome, <?= h((string) $admin['email']) ?>. Review transport activity and manage the service.</p>
    </div>
    <span class="admin-access-badge"><span aria-hidden="true">✓</span> Admin access</span>
  </div>

  <div class="admin-stats" aria-label="Transport operations overview">
    <a class="admin-stat" href="admin-jeepneys.php">
      <span class="admin-stat-icon">🚌</span><span class="admin-stat-label">Registered jeepneys</span>
      <strong><?= h((string) $overview['vehicles']) ?></strong>
    </a>
    <a class="admin-stat admin-stat-verified" href="admin-drivers.php">
      <span class="admin-stat-icon">👤</span><span class="admin-stat-label">Active drivers</span>
      <strong><?= h((string) $overview['drivers']) ?></strong>
    </a>
    <a class="admin-stat" href="admin-routes.php">
      <span class="admin-stat-icon">📍</span><span class="admin-stat-label">Available terminals</span>
      <strong><?= h((string) $overview['terminals']) ?></strong>
    </a>
    <a class="admin-stat admin-stat-pending" href="admin-trips.php">
      <span class="admin-stat-icon">🗺️</span><span class="admin-stat-label">Ongoing trips</span>
      <strong><?= h((string) $overview['trips']) ?></strong>
    </a>
  </div>

  <section class="admin-queue">
    <div class="admin-queue-heading">
      <div><h2>Recent system activity</h2><p>Administrative changes are recorded for accountability.</p></div>
      <?php if (($admin['role'] ?? '') === 'owner'): ?><a class="button button-outline" href="admin-settings.php#activity">Full activity log</a><?php endif; ?>
    </div>
    <?php if ($recentActivity === []): ?>
      <div class="admin-empty"><h3>No activity recorded yet</h3><p>Administrative actions will appear here as the system is used.</p></div>
    <?php else: ?>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead><tr><th>Activity</th><th>Record</th><th>Administrator</th><th>Time</th></tr></thead>
          <tbody>
          <?php foreach ($recentActivity as $activity): ?>
            <tr>
              <td data-label="Activity"><strong><?= h(str_replace('_', ' ', (string) $activity['action'])) ?></strong><?php if ($activity['details'] !== null): ?><small><?= h((string) $activity['details']) ?></small><?php endif; ?></td>
              <td data-label="Record"><?= h(ucfirst(str_replace('_', ' ', (string) $activity['entity_type']))) ?><?= $activity['entity_id'] === null ? '' : ' #' . h((string) $activity['entity_id']) ?></td>
              <td data-label="Administrator"><?= h((string) ($activity['email'] ?? 'Deleted account')) ?></td>
              <td data-label="Time"><?= h(date('M j, Y g:i A', strtotime((string) $activity['created_at']))) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <div class="admin-stats" aria-label="Application statistics">
    <a class="admin-stat" href="admin-dashboard.php?status=all">
      <span class="admin-stat-icon">◎</span><span class="admin-stat-label">Total applications</span>
      <strong><?= h((string) $counts['total']) ?></strong>
    </a>
    <a class="admin-stat admin-stat-pending" href="admin-dashboard.php?status=pending_verification">
      <span class="admin-stat-icon">◷</span><span class="admin-stat-label">Awaiting review</span>
      <strong><?= h((string) $counts['pending']) ?></strong>
    </a>
    <a class="admin-stat admin-stat-verified" href="admin-dashboard.php?status=verified">
      <span class="admin-stat-icon">✓</span><span class="admin-stat-label">Approved</span>
      <strong><?= h((string) $counts['verified']) ?></strong>
    </a>
    <a class="admin-stat admin-stat-rejected" href="admin-dashboard.php?status=rejected">
      <span class="admin-stat-icon">×</span><span class="admin-stat-label">Rejected</span>
      <strong><?= h((string) $counts['rejected']) ?></strong>
    </a>
    <a class="admin-stat admin-stat-pending" href="#vehicle-review">
      <span class="admin-stat-icon">▰</span><span class="admin-stat-label">Vehicle reviews</span>
      <strong><?= h((string) $pendingVehicleCount) ?></strong>
    </a>
  </div>

  <?php if ($flash !== ''): ?><p class="message message-success admin-flash" role="status"><span aria-hidden="true">✓</span> <?= h((string) $flash) ?></p><?php endif; ?>

  <section class="admin-queue">
    <div class="admin-queue-heading">
      <div>
        <h2><?= $filter === 'pending_verification' ? 'Applications awaiting review' : 'Driver applications' ?></h2>
        <p>Review driver details before approving or rejecting an application.</p>
      </div>
      <nav class="admin-filters" aria-label="Filter applications">
        <a class="<?= $filter === 'pending_verification' ? 'is-active' : '' ?>" href="admin-dashboard.php?status=pending_verification">Pending</a>
        <a class="<?= $filter === 'verified' ? 'is-active' : '' ?>" href="admin-dashboard.php?status=verified">Approved</a>
        <a class="<?= $filter === 'rejected' ? 'is-active' : '' ?>" href="admin-dashboard.php?status=rejected">Rejected</a>
        <a class="<?= $filter === 'all' ? 'is-active' : '' ?>" href="admin-dashboard.php?status=all">All</a>
      </nav>
    </div>

    <?php if ($drivers === []): ?>
      <div class="admin-empty">
        <span aria-hidden="true">✓</span>
        <h3><?= $filter === 'pending_verification' ? 'You’re all caught up' : 'No applications here yet' ?></h3>
        <p><?= $filter === 'pending_verification' ? 'New driver applications awaiting review will appear here.' : 'Try another status filter to see more applications.' ?></p>
      </div>
    <?php else: ?>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead><tr><th>Driver</th><th>Contact</th><th>License number</th><th>Submitted</th><th>Status</th><?php if ($filter === 'pending_verification' || $filter === 'all'): ?><th>Action</th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach ($drivers as $driver): ?>
            <tr>
              <td data-label="Driver"><strong><?= h((string) $driver['full_name']) ?></strong></td>
              <td data-label="Contact"><a href="mailto:<?= h((string) $driver['email']) ?>"><?= h((string) $driver['email']) ?></a><small><?= h((string) $driver['phone']) ?></small></td>
              <td data-label="License number"><?= h((string) $driver['license_number']) ?></td>
              <td data-label="Submitted"><?= h(date('M j, Y', strtotime((string) $driver['created_at']))) ?></td>
              <td data-label="Status"><span class="admin-status status-<?= h((string) $driver['verification_status']) ?>"><?= h(str_replace('_', ' ', (string) $driver['verification_status'])) ?></span></td>
              <?php if ($filter === 'pending_verification' || $filter === 'all'): ?>
              <td data-label="Action">
                <?php if ($driver['verification_status'] === 'pending_verification'): ?>
                  <div class="admin-actions">
                    <form method="post" action="admin-dashboard.php">
                      <?= csrf_field() ?>
                      <input type="hidden" name="driver_id" value="<?= h((string) $driver['id']) ?>">
                      <button class="button button-approve" type="submit" name="action" value="approve">Approve</button>
                    </form>
                    <form method="post" action="admin-dashboard.php">
                      <?= csrf_field() ?>
                      <input type="hidden" name="driver_id" value="<?= h((string) $driver['id']) ?>">
                      <button class="button button-reject" type="submit" name="action" value="reject">Reject</button>
                    </form>
                  </div>
                <?php else: ?>
                  <span class="admin-no-action">Reviewed</span>
                <?php endif; ?>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (count($drivers) === 100): ?><p class="admin-limit-note">Showing the 100 most recent applications.</p><?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="admin-queue vehicle-review-queue" id="vehicle-review">
    <div class="admin-queue-heading">
      <div>
        <h2>Jeepney profiles</h2>
        <p><?= h((string) $pendingVehicleCount) ?> vehicle profile<?= $pendingVehicleCount === 1 ? '' : 's' ?> awaiting verification.</p>
      </div>
    </div>
    <?php if ($vehicleReviews === []): ?>
      <div class="admin-empty">
        <span aria-hidden="true">✓</span>
        <h3>No vehicle profiles submitted</h3>
        <p>Driver vehicle details will appear here after they submit a profile.</p>
      </div>
    <?php else: ?>
      <div class="admin-table-wrap">
        <table class="admin-table vehicle-review-table">
          <thead><tr><th>Driver</th><th>Proposed plate</th><th>Capacity</th><th>Route assignment</th><th>Submitted</th><th>Status</th><th>Action</th></tr></thead>
          <tbody>
          <?php foreach ($vehicleReviews as $vehicle): ?>
            <tr>
              <td data-label="Driver"><strong><?= h((string) $vehicle['full_name']) ?></strong><a class="admin-driver-email" href="mailto:<?= h((string) $vehicle['email']) ?>"><?= h((string) $vehicle['email']) ?></a></td>
              <td data-label="Proposed plate"><?= h((string) $vehicle['review_plate_number']) ?></td>
              <td data-label="Capacity"><?= h((string) $vehicle['review_capacity']) ?> passengers</td>
              <td data-label="Route assignment"><?= h((string) $vehicle['review_route_assignment']) ?></td>
              <td data-label="Submitted"><?= h(date('M j, Y', strtotime((string) $vehicle['updated_at']))) ?></td>
              <td data-label="Status"><span class="admin-status status-<?= h((string) $vehicle['verification_status']) ?>"><?= h(str_replace('_', ' ', (string) $vehicle['verification_status'])) ?></span></td>
              <td data-label="Action">
                <?php if ($vehicle['verification_status'] === 'pending_verification'): ?>
                  <div class="admin-actions">
                    <?php foreach (['approve' => 'Approve', 'reject' => 'Reject'] as $action => $label): ?>
                    <form method="post" action="admin-dashboard.php#vehicle-review">
                      <?= csrf_field() ?>
                      <input type="hidden" name="record_type" value="vehicle">
                      <input type="hidden" name="record_id" value="<?= h((string) $vehicle['id']) ?>">
                      <button class="button <?= $action === 'approve' ? 'button-approve' : 'button-reject' ?>" type="submit" name="action" value="<?= h($action) ?>"><?= h($label) ?></button>
                    </form>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <span class="admin-no-action">Reviewed</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (count($vehicleReviews) === 100): ?><p class="admin-limit-note">Showing the 100 most recent vehicle profiles.</p><?php endif; ?>
    <?php endif; ?>
  </section>
</section>
<?php page_end(); ?>
