<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/admin-bootstrap.php';
$admin = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = require_admin_role(['owner', 'admin']);
    verify_csrf_token();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_vehicle') {
        $vehicleIdInput = $_POST['vehicle_id'] ?? '';
        $vehicleId = $vehicleIdInput === '' ? null : validated_positive_id($vehicleIdInput);
        if ($vehicleIdInput !== '' && $vehicleId === null) {
            http_response_code(400);
            exit('Invalid jeepney record.');
        }
        $driverId = validated_positive_id($_POST['driver_id'] ?? null);
        $routeName = trim((string) ($_POST['route_assignment'] ?? ''));
        $status = $_POST['operational_status'] ?? 'active';
        $normalized = normalize_vehicle_input([
            'plate_number' => $_POST['plate_number'] ?? '',
            'capacity' => $_POST['capacity'] ?? '',
            'route_assignment' => $routeName,
        ]);
        if ($driverId === null || $normalized['errors'] !== [] || !in_array($status, ['active', 'inactive', 'maintenance'], true)) {
            $_SESSION['admin_flash'] = 'Check the plate, capacity, route, driver, and operational status.';
            redirect('admin-jeepneys.php');
        }
        $driverQuery = $pdo->prepare(
            "SELECT d.id FROM drivers d
             LEFT JOIN driver_admin_status s ON s.driver_id = d.id
             WHERE d.id = :id AND d.verification_status = 'verified'
               AND COALESCE(s.account_status, 'active') = 'active'"
        );
        $driverQuery->execute(['id' => $driverId]);
        if ($driverQuery->fetchColumn() === false) {
            $_SESSION['admin_flash'] = 'Assign a verified, active driver.';
            redirect('admin-jeepneys.php');
        }
        $otherVehicle = $pdo->prepare(
            'SELECT id FROM jeepney_profiles WHERE driver_id = :driver_id' .
            ($vehicleId === null ? '' : ' AND id <> :vehicle_id') .
            ' LIMIT 1'
        );
        $otherVehicle->execute($vehicleId === null
            ? ['driver_id' => $driverId]
            : ['driver_id' => $driverId, 'vehicle_id' => $vehicleId]);
        if ($otherVehicle->fetchColumn() !== false) {
            $_SESSION['admin_flash'] = 'That driver already has a registered jeepney.';
            redirect('admin-jeepneys.php');
        }
        $routeQuery = $pdo->prepare(
            'SELECT route_name FROM routes WHERE BINARY route_name = BINARY :name AND is_active = 1 LIMIT 1'
        );
        $routeQuery->execute(['name' => $routeName]);
        $routeName = $routeQuery->fetchColumn();
        if ($routeName === false) {
            $_SESSION['admin_flash'] = 'Choose an active authorized route.';
            redirect('admin-jeepneys.php');
        }
        $isNewVehicle = $vehicleId === null;
        $pdo->beginTransaction();
        try {
            if ($vehicleId !== null) {
                $save = $pdo->prepare(
                    "UPDATE jeepney_profiles
                     SET driver_id = :driver_id, plate_number = :plate_number,
                         capacity = :capacity, route_assignment = :route_assignment,
                         pending_plate_number = NULL, pending_capacity = NULL,
                         pending_route_assignment = NULL, verification_status = 'verified'
                     WHERE id = :id"
                );
                $save->execute([
                    'driver_id' => $driverId,
                    'plate_number' => $normalized['vehicle']['plate_number'],
                    'capacity' => $normalized['vehicle']['capacity'],
                    'route_assignment' => $routeName,
                    'id' => $vehicleId,
                ]);
            } else {
                $save = $pdo->prepare(
                    "INSERT INTO jeepney_profiles
                        (driver_id, plate_number, capacity, route_assignment, verification_status)
                     VALUES (:driver_id, :plate_number, :capacity, :route_assignment, 'verified')"
                );
                $save->execute([
                    'driver_id' => $driverId,
                    'plate_number' => $normalized['vehicle']['plate_number'],
                    'capacity' => $normalized['vehicle']['capacity'],
                    'route_assignment' => $routeName,
                ]);
                $vehicleId = (int) $pdo->lastInsertId();
            }
            $saveStatus = $pdo->prepare(
                'INSERT INTO jeepney_admin_status (jeepney_profile_id, operational_status, updated_by)
                 VALUES (:id, :status, :admin_id)
                 ON DUPLICATE KEY UPDATE operational_status = VALUES(operational_status),
                     updated_by = VALUES(updated_by)'
            );
            $saveStatus->execute(['id' => $vehicleId, 'status' => $status, 'admin_id' => $admin['id']]);
            if ($status !== 'active') {
                $stopTrips = $pdo->prepare(
                    "UPDATE trips SET trip_status = 'cancelled', ended_at = CURRENT_TIMESTAMP
                     WHERE jeepney_profile_id = :id AND trip_status = 'active'"
                );
                $stopTrips->execute(['id' => $vehicleId]);
            }
            record_admin_activity(
                $pdo,
                $admin,
                $isNewVehicle ? 'jeepney_created' : 'jeepney_updated',
                'jeepney_profile',
                $vehicleId,
                $normalized['vehicle']['plate_number']
            );
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                $_SESSION['admin_flash'] = 'That plate number or driver is already assigned to another jeepney.';
                redirect('admin-jeepneys.php');
            }
            throw $exception;
        }
        $_SESSION['admin_flash'] = 'Jeepney record saved. Operationally inactive vehicles are retained for trip history.';
        redirect('admin-jeepneys.php');
    }

    if ($action === 'decommission') {
        $vehicleId = validated_positive_id($_POST['vehicle_id'] ?? null);
        if ($vehicleId === null) {
            http_response_code(400);
            exit('Invalid jeepney record.');
        }
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare(
                "INSERT INTO jeepney_admin_status (jeepney_profile_id, operational_status, updated_by)
                 VALUES (:id, 'inactive', :admin_id)
                 ON DUPLICATE KEY UPDATE operational_status = 'inactive', updated_by = VALUES(updated_by)"
            );
            $update->execute(['id' => $vehicleId, 'admin_id' => $admin['id']]);
            $stopTrips = $pdo->prepare(
                "UPDATE trips SET trip_status = 'cancelled', ended_at = CURRENT_TIMESTAMP
                 WHERE jeepney_profile_id = :id AND trip_status = 'active'"
            );
            $stopTrips->execute(['id' => $vehicleId]);
            record_admin_activity($pdo, $admin, 'jeepney_decommissioned', 'jeepney_profile', $vehicleId);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        $_SESSION['admin_flash'] = 'Jeepney decommissioned and retained for historical records.';
        redirect('admin-jeepneys.php');
    }

    http_response_code(400);
    exit('Invalid jeepney action.');
}

$drivers = $pdo->query(
    "SELECT d.id, d.full_name FROM drivers d
     LEFT JOIN driver_admin_status s ON s.driver_id = d.id
     WHERE d.verification_status = 'verified' AND COALESCE(s.account_status, 'active') = 'active'
     ORDER BY d.full_name"
)->fetchAll();
$routes = $pdo->query('SELECT id, route_name FROM routes WHERE is_active = 1 ORDER BY route_name')->fetchAll();
$vehicles = $pdo->query(
    "SELECT j.id, j.driver_id, j.plate_number, j.capacity, j.route_assignment,
            j.verification_status, d.full_name, COALESCE(s.operational_status, 'inactive') AS operational_status
     FROM jeepney_profiles j
     INNER JOIN drivers d ON d.id = j.driver_id
     LEFT JOIN jeepney_admin_status s ON s.jeepney_profile_id = j.id
     ORDER BY j.updated_at DESC LIMIT 200"
)->fetchAll();
$flash = (string) ($_SESSION['admin_flash'] ?? '');
unset($_SESSION['admin_flash']);

page_start('Jeepney management', 'admin-jeepneys');
?>
<section class="admin-dashboard">
  <div class="admin-page-heading"><div><span class="eyebrow"><span class="eyebrow-dot"></span> VEHICLE MANAGEMENT</span><h1>Jeepneys</h1><p>Manage registered vehicles, assigned drivers and routes, and operating status.</p></div><span class="admin-access-badge"><span aria-hidden="true">✓</span> Trip history is preserved</span></div>
  <?php if ($flash !== ''): ?><p class="message message-success admin-flash" role="status"><?= h($flash) ?></p><?php endif; ?>
  <section class="admin-queue">
    <div class="admin-queue-heading"><div><h2>Registered vehicles</h2><p>Changes to vehicle data and status are recorded in the activity log.</p></div></div>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead><tr><th>Vehicle</th><th>Driver</th><th>Route</th><th>Operational state</th><th>Edit / decommission</th></tr></thead>
        <tbody>
        <?php foreach ($vehicles as $vehicle): ?>
          <tr>
            <td data-label="Vehicle"><strong><?= h((string) ($vehicle['plate_number'] ?? 'Pending plate')) ?></strong><small><?= h((string) $vehicle['capacity']) ?> passengers  -  <?= h((string) $vehicle['verification_status']) ?></small></td>
            <td data-label="Driver"><?= h((string) $vehicle['full_name']) ?></td>
            <td data-label="Route"><?= h((string) ($vehicle['route_assignment'] ?? 'Unassigned')) ?></td>
            <td data-label="Operational state"><?= h((string) $vehicle['operational_status']) ?></td>
            <td data-label="Edit / decommission">
              <div class="vehicle-row-actions">
                <button class="button button-outline admin-manage-button" type="button"
                  data-vehicle-edit
                  data-vehicle-id="<?= h((string) $vehicle['id']) ?>"
                  data-plate="<?= h((string) $vehicle['plate_number']) ?>"
                  data-capacity="<?= h((string) $vehicle['capacity']) ?>"
                  data-driver-id="<?= h((string) $vehicle['driver_id']) ?>"
                  data-route="<?= h((string) ($vehicle['route_assignment'] ?? '')) ?>"
                  data-status="<?= h((string) $vehicle['operational_status']) ?>"
                  data-description="<?= h((string) ($vehicle['plate_number'] ?? 'Pending plate') . '  -  ' . $vehicle['full_name']) ?>">Edit</button>
                <button class="button button-reject" type="button"
                  data-vehicle-decommission
                  data-vehicle-id="<?= h((string) $vehicle['id']) ?>"
                  data-description="<?= h((string) ($vehicle['plate_number'] ?? 'Pending plate') . '  -  ' . $vehicle['full_name']) ?>">Decommission</button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($vehicles === []): ?><tr><td colspan="5">No jeepneys are registered yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</section>
<dialog class="admin-manage-dialog terminal-edit-dialog" id="vehicle-edit-dialog" aria-labelledby="vehicle-edit-title">
  <div class="admin-manage-dialog-header">
    <div><span class="eyebrow"><span class="eyebrow-dot"></span> VEHICLE MANAGEMENT</span><h2 id="vehicle-edit-title">Edit jeepney details</h2><p id="vehicle-edit-description">Update the assigned driver, route, and operating status.</p></div>
    <button class="admin-manage-dialog-close" type="button" data-vehicle-edit-close aria-label="Close edit dialog">×</button>
  </div>
  <div class="terminal-edit-dialog-body">
    <form method="post" class="admin-form-grid terminal-edit-form" action="admin-jeepneys.php">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_vehicle"><input type="hidden" name="vehicle_id" data-vehicle-field="id">
      <label>Plate number<input name="plate_number" data-vehicle-field="plate" maxlength="10" required></label>
      <label>Capacity<input type="number" name="capacity" data-vehicle-field="capacity" min="1" max="4294967295" required></label>
      <label>Driver<select name="driver_id" data-vehicle-field="driver" required><?php foreach ($drivers as $driver): ?><option value="<?= h((string) $driver['id']) ?>"><?= h((string) $driver['full_name']) ?></option><?php endforeach; ?></select></label>
      <label>Route<select name="route_assignment" data-vehicle-field="route" required><?php foreach ($routes as $route): ?><option value="<?= h((string) $route['route_name']) ?>"><?= h((string) $route['route_name']) ?></option><?php endforeach; ?></select></label>
      <label>Operational status<select name="operational_status" data-vehicle-field="status"><?php foreach (['active', 'inactive', 'maintenance'] as $status): ?><option value="<?= h($status) ?>"><?= h(ucfirst($status)) ?></option><?php endforeach; ?></select></label>
      <div class="terminal-edit-actions">
        <button class="button button-outline" type="button" data-vehicle-edit-close>Cancel</button>
        <button class="button button-primary" type="submit">Save changes</button>
      </div>
    </form>
  </div>
</dialog>
<dialog class="admin-manage-dialog vehicle-decommission-dialog" id="vehicle-decommission-dialog" aria-labelledby="vehicle-decommission-title">
  <div class="admin-manage-dialog-header">
    <div><span class="eyebrow"><span class="eyebrow-dot"></span> CONFIRM VEHICLE CHANGE</span><h2 id="vehicle-decommission-title">Decommission jeepney?</h2><p id="vehicle-decommission-description">This will mark the vehicle inactive and cancel any active trip. Historical records will be retained.</p></div>
    <button class="admin-manage-dialog-close" type="button" data-vehicle-decommission-close aria-label="Close confirmation dialog">×</button>
  </div>
  <div class="terminal-edit-dialog-body vehicle-decommission-body">
    <p id="vehicle-decommission-record" class="vehicle-decommission-record"></p>
    <form method="post" action="admin-jeepneys.php" class="terminal-edit-actions">
      <?= csrf_field() ?><input type="hidden" name="vehicle_id" data-decommission-vehicle-id><input type="hidden" name="action" value="decommission">
      <button class="button button-outline" type="button" data-vehicle-decommission-close>Keep vehicle</button>
      <button class="button button-reject" type="submit">Decommission vehicle</button>
    </form>
  </div>
</dialog>
<script>
(() => {
  const editDialog = document.getElementById('vehicle-edit-dialog');
  const decommissionDialog = document.getElementById('vehicle-decommission-dialog');
  const editFields = {
    id: editDialog?.querySelector('[data-vehicle-field="id"]'),
    plate: editDialog?.querySelector('[data-vehicle-field="plate"]'),
    capacity: editDialog?.querySelector('[data-vehicle-field="capacity"]'),
    driver: editDialog?.querySelector('[data-vehicle-field="driver"]'),
    route: editDialog?.querySelector('[data-vehicle-field="route"]'),
    status: editDialog?.querySelector('[data-vehicle-field="status"]')
  };

  document.querySelectorAll('[data-vehicle-edit]').forEach((button) => {
    button.addEventListener('click', () => {
      Object.entries(editFields).forEach(([field, input]) => {
        const datasetField = {id: 'vehicleId', driver: 'driverId'}[field] || field;
        if (input) input.value = button.dataset[datasetField] || '';
      });
      const description = editDialog?.querySelector('#vehicle-edit-description');
      if (description) description.textContent = button.dataset.description || 'Update the assigned driver, route, and operating status.';
      editDialog?.showModal();
      editFields.plate?.focus();
    });
  });

  document.querySelectorAll('[data-vehicle-edit-close]').forEach((button) => {
    button.addEventListener('click', () => editDialog?.close());
  });

  document.querySelectorAll('[data-vehicle-decommission]').forEach((button) => {
    button.addEventListener('click', () => {
      const idField = decommissionDialog?.querySelector('[data-decommission-vehicle-id]');
      const record = decommissionDialog?.querySelector('#vehicle-decommission-record');
      if (idField) idField.value = button.dataset.vehicleId || '';
      if (record) record.textContent = button.dataset.description || '';
      decommissionDialog?.showModal();
    });
  });

  document.querySelectorAll('[data-vehicle-decommission-close]').forEach((button) => {
    button.addEventListener('click', () => decommissionDialog?.close());
  });

  [editDialog, decommissionDialog].forEach((dialog) => {
    dialog?.addEventListener('click', (event) => {
      if (event.target === dialog) dialog.close();
    });
  });
})();
</script>
<?php page_end(); ?>
