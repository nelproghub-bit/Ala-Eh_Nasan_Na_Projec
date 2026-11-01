<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$admin = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = require_admin_role(['owner', 'admin']);
    verify_csrf_token();
    $action = $_POST['action'] ?? '';
    $driverId = validated_positive_id($_POST['driver_id'] ?? null);
    if ($driverId === null) {
        http_response_code(400);
        exit('Invalid driver record.');
    }

    if ($action === 'verification') {
        $status = $_POST['status'] ?? '';
        if (!in_array($status, ['verified', 'rejected'], true)) {
            http_response_code(400);
            exit('Invalid verification status.');
        }
        $update = $pdo->prepare(
            "UPDATE drivers SET verification_status = :status
             WHERE id = :id AND verification_status = 'pending_verification'"
        );
        $update->execute(['status' => $status, 'id' => $driverId]);
        if ($update->rowCount() === 1) {
            record_admin_activity($pdo, $admin, 'driver_' . $status, 'driver', $driverId);
        }
        $_SESSION['admin_flash'] = $update->rowCount() === 1
            ? 'Driver application ' . $status . '.'
            : 'No pending driver application was found.';
        redirect('admin-drivers.php');
    }

    if ($action === 'account_status') {
        $status = $_POST['status'] ?? '';
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (
            !in_array($status, ['active', 'suspended', 'deactivated'], true) ||
            mb_strlen($reason) > 500
        ) {
            http_response_code(400);
            exit('Invalid driver account status or reason.');
        }
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare(
                'INSERT INTO driver_admin_status (driver_id, account_status, reason, updated_by)
                 VALUES (:driver_id, :status, :reason, :admin_id)
                 ON DUPLICATE KEY UPDATE account_status = VALUES(account_status),
                     reason = VALUES(reason), updated_by = VALUES(updated_by)'
            );
            $update->execute([
                'driver_id' => $driverId,
                'status' => $status,
                'reason' => $reason === '' ? null : $reason,
                'admin_id' => $admin['id'],
            ]);
            if ($status !== 'active') {
                $stopTrips = $pdo->prepare(
                    "UPDATE trips SET trip_status = 'cancelled', ended_at = CURRENT_TIMESTAMP
                     WHERE driver_id = :driver_id AND trip_status = 'active'"
                );
                $stopTrips->execute(['driver_id' => $driverId]);
                $deactivateVehicle = $pdo->prepare(
                    "UPDATE jeepney_admin_status s
                     INNER JOIN jeepney_profiles j ON j.id = s.jeepney_profile_id
                     SET s.operational_status = 'inactive', s.updated_by = :admin_id
                     WHERE j.driver_id = :driver_id AND s.operational_status = 'active'"
                );
                $deactivateVehicle->execute(['admin_id' => $admin['id'], 'driver_id' => $driverId]);
            }
            record_admin_activity(
                $pdo,
                $admin,
                'driver_account_' . $status,
                'driver',
                $driverId,
                $reason === '' ? null : $reason
            );
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        $_SESSION['admin_flash'] = 'Driver account status updated.';
        redirect('admin-drivers.php');
    }

    if ($action === 'update_driver') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $license = strtoupper(trim((string) ($_POST['license_number'] ?? '')));
        $phoneDigits = preg_replace('/\D/', '', $phone);
        if (
            $fullName === '' || mb_strlen($fullName) > 120 ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 ||
            strlen($phone) > 32 || preg_match('/^\+?[0-9][0-9 ().-]*$/', $phone) !== 1 ||
            !is_string($phoneDigits) || strlen($phoneDigits) < 7 || strlen($phoneDigits) > 15 ||
            $license === '' || strlen($license) > 64
        ) {
            $_SESSION['admin_flash'] = 'Check the driver name, email, phone number, and license details.';
            redirect('admin-drivers.php');
        }
        try {
            $update = $pdo->prepare(
                'UPDATE drivers SET full_name = :name, email = :email, phone = :phone,
                    license_number = :license WHERE id = :id'
            );
            $update->execute([
                'name' => $fullName, 'email' => $email, 'phone' => $phone,
                'license' => $license, 'id' => $driverId,
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }
            $_SESSION['admin_flash'] = 'The email or license number is already assigned to another driver.';
            redirect('admin-drivers.php');
        }
        record_admin_activity($pdo, $admin, 'driver_details_updated', 'driver', $driverId);
        $_SESSION['admin_flash'] = 'Driver details updated.';
        redirect('admin-drivers.php');
    }

    if ($action === 'document_review') {
        $documentId = validated_positive_id($_POST['document_id'] ?? null);
        $status = $_POST['status'] ?? '';
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($documentId === null || !in_array($status, ['approved', 'rejected'], true) || mb_strlen($note) > 500) {
            http_response_code(400);
            exit('Invalid document review.');
        }
        $review = $pdo->prepare(
            "UPDATE driver_documents SET review_status = :status, review_note = :note,
                 reviewed_by = :admin_id, reviewed_at = CURRENT_TIMESTAMP
             WHERE id = :document_id AND driver_id = :driver_id"
        );
        $review->execute([
            'status' => $status,
            'note' => $note === '' ? null : $note,
            'admin_id' => $admin['id'],
            'document_id' => $documentId,
            'driver_id' => $driverId,
        ]);
        if ($review->rowCount() > 0) {
            record_admin_activity($pdo, $admin, 'document_' . $status, 'driver_document', $documentId);
        }
        $_SESSION['admin_flash'] = 'Document review saved.';
        redirect('admin-drivers.php#documents');
    }

    http_response_code(400);
    exit('Invalid driver action.');
}

$filter = $_GET['status'] ?? 'all';
$allowedStatuses = ['all', 'pending_verification', 'verified', 'rejected'];
if (!is_string($filter) || !in_array($filter, $allowedStatuses, true)) {
    $filter = 'all';
}
$query = 'SELECT d.id, d.full_name, d.email, d.phone, d.license_number,
                 d.verification_status, d.created_at,
                 COALESCE(s.account_status, \'active\') AS account_status,
                 s.reason,
                 (SELECT COUNT(*) FROM driver_documents docs WHERE docs.driver_id = d.id) AS document_count
          FROM drivers d
          LEFT JOIN driver_admin_status s ON s.driver_id = d.id';
if ($filter !== 'all') {
    $query .= ' WHERE d.verification_status = :status';
}
$query .= ' ORDER BY d.created_at DESC LIMIT 200';
$driversQuery = $pdo->prepare($query);
if ($filter !== 'all') {
    $driversQuery->execute(['status' => $filter]);
} else {
    $driversQuery->execute();
}
$drivers = $driversQuery->fetchAll();
$documents = $pdo->query(
    'SELECT docs.id, docs.driver_id, docs.document_type, docs.original_name,
            docs.review_status, docs.review_note, docs.uploaded_at, d.full_name
     FROM driver_documents docs
     INNER JOIN drivers d ON d.id = docs.driver_id
     ORDER BY CASE WHEN docs.review_status = \'pending\' THEN 0 ELSE 1 END,
              docs.uploaded_at DESC LIMIT 100'
)->fetchAll();
$flash = (string) ($_SESSION['admin_flash'] ?? '');
unset($_SESSION['admin_flash']);

page_start('Driver management', 'admin-drivers');
?>
<section class="admin-dashboard">
  <div class="admin-page-heading">
    <div><span class="eyebrow"><span class="eyebrow-dot"></span> DRIVER MANAGEMENT</span><h1>Drivers</h1><p>Review applications, inspect private documents, and manage account access.</p></div>
    <span class="admin-access-badge"><span aria-hidden="true">✓</span> Verified admin actions</span>
  </div>
  <?php if ($flash !== ''): ?><p class="message message-success admin-flash" role="status"><?= h($flash) ?></p><?php endif; ?>
  <section class="admin-queue">
    <div class="admin-queue-heading">
      <div><h2>Driver accounts</h2><p>Up to 200 most recent accounts are shown.</p></div>
      <nav class="admin-filters" aria-label="Filter drivers">
        <?php foreach (['all' => 'All', 'pending_verification' => 'Pending', 'verified' => 'Approved', 'rejected' => 'Rejected'] as $value => $label): ?>
          <a class="<?= $filter === $value ? 'is-active' : '' ?>" href="admin-drivers.php?status=<?= h($value) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
      </nav>
    </div>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead><tr><th>Driver</th><th>License</th><th>Application</th><th>Account access</th><th>Documents</th><th>Manage</th></tr></thead>
        <tbody>
        <?php foreach ($drivers as $driver): ?>
          <tr>
            <td data-label="Driver"><strong><?= h((string) $driver['full_name']) ?></strong><a href="mailto:<?= h((string) $driver['email']) ?>"><?= h((string) $driver['email']) ?></a><small><?= h((string) $driver['phone']) ?></small></td>
            <td data-label="License"><?= h((string) $driver['license_number']) ?></td>
            <td data-label="Application"><span class="admin-status status-<?= h((string) $driver['verification_status']) ?>"><?= h(str_replace('_', ' ', (string) $driver['verification_status'])) ?></span></td>
            <td data-label="Account access"><span class="admin-status"><?= h((string) $driver['account_status']) ?></span><?php if ($driver['reason'] !== null): ?><small><?= h((string) $driver['reason']) ?></small><?php endif; ?></td>
            <td data-label="Documents"><a href="#documents"><?= h((string) $driver['document_count']) ?> files</a></td>
            <td data-label="Manage">
              <?php if (($admin['role'] ?? '') !== 'viewer'): ?>
                <button class="button button-outline button-small admin-manage-button" type="button"
                        data-open-driver-dialog="manage-driver-<?= h((string) $driver['id']) ?>"
                        aria-haspopup="dialog">
                  Manage
                </button>
                <dialog class="admin-manage-dialog" id="manage-driver-<?= h((string) $driver['id']) ?>"
                        aria-labelledby="manage-driver-title-<?= h((string) $driver['id']) ?>">
                  <div class="admin-manage-dialog-header">
                    <div>
                      <span class="eyebrow"><span class="eyebrow-dot"></span> DRIVER ACCOUNT</span>
                      <h2 id="manage-driver-title-<?= h((string) $driver['id']) ?>">Manage <?= h((string) $driver['full_name']) ?></h2>
                      <p><?= h((string) $driver['email']) ?> · <?= h((string) $driver['phone']) ?></p>
                    </div>
                    <button class="admin-manage-dialog-close" type="button"
                            data-close-driver-dialog aria-label="Close driver management dialog">×</button>
                  </div>
                  <div class="admin-manage-dialog-body">
                    <section class="admin-manage-section">
                      <div class="admin-manage-section-heading">
                        <div><h3>Driver details</h3><p>Update the driver's contact and license information.</p></div>
                        <span class="admin-status status-<?= h((string) $driver['verification_status']) ?>"><?= h(str_replace('_', ' ', (string) $driver['verification_status'])) ?></span>
                      </div>
                      <form method="post" class="admin-form-grid admin-manage-form" action="admin-drivers.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="driver_id" value="<?= h((string) $driver['id']) ?>">
                        <input type="hidden" name="action" value="update_driver">
                        <label>Full name<input name="full_name" required maxlength="120" value="<?= h((string) $driver['full_name']) ?>"></label>
                        <label>Email<input type="email" name="email" required maxlength="254" value="<?= h((string) $driver['email']) ?>"></label>
                        <label>Phone<input name="phone" required maxlength="32" value="<?= h((string) $driver['phone']) ?>"></label>
                        <label>License number<input name="license_number" required maxlength="64" value="<?= h((string) $driver['license_number']) ?>"></label>
                        <button class="button button-outline" type="submit">Save details</button>
                      </form>
                    </section>
                    <?php if ($driver['verification_status'] === 'pending_verification'): ?>
                      <section class="admin-manage-section">
                        <div class="admin-manage-section-heading">
                          <div><h3>Application review</h3><p>Approve or reject this driver's registration.</p></div>
                        </div>
                        <div class="admin-actions">
                          <?php foreach (['verified' => 'Approve application', 'rejected' => 'Reject application'] as $status => $label): ?>
                            <form method="post" action="admin-drivers.php">
                              <?= csrf_field() ?>
                              <input type="hidden" name="driver_id" value="<?= h((string) $driver['id']) ?>">
                              <input type="hidden" name="action" value="verification">
                              <button class="button <?= $status === 'verified' ? 'button-approve' : 'button-reject' ?>"
                                      name="status" value="<?= h($status) ?>"><?= h($label) ?></button>
                            </form>
                          <?php endforeach; ?>
                        </div>
                      </section>
                    <?php endif; ?>
                    <section class="admin-manage-section">
                      <div class="admin-manage-section-heading">
                        <div><h3>Account access</h3><p>Set the driver's account status and optionally record a reason.</p></div>
                      </div>
                      <form method="post" class="admin-form-grid admin-manage-form admin-access-form" action="admin-drivers.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="driver_id" value="<?= h((string) $driver['id']) ?>">
                        <input type="hidden" name="action" value="account_status">
                        <label>Access status
                          <select name="status">
                            <?php foreach (['active', 'suspended', 'deactivated'] as $status): ?>
                              <option value="<?= h($status) ?>" <?= $driver['account_status'] === $status ? 'selected' : '' ?>><?= h(ucfirst($status)) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </label>
                        <label>Reason <span class="admin-field-hint">(optional)</span>
                          <input name="reason" maxlength="500" value="<?= h((string) ($driver['reason'] ?? '')) ?>" placeholder="Add a note about this access change">
                        </label>
                        <button class="button button-primary" type="submit">Save access</button>
                      </form>
                    </section>
                  </div>
                </dialog>
              <?php else: ?><span class="admin-no-action">View only</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($drivers === []): ?><tr><td colspan="6">No driver accounts match this filter.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="admin-queue" id="documents">
    <div class="admin-queue-heading"><div><h2>Uploaded driver documents</h2><p>Document files are private and accessible only to signed-in administrators.</p></div></div>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead><tr><th>Driver</th><th>Document</th><th>Uploaded</th><th>Review status</th><th>Review</th></tr></thead>
        <tbody>
        <?php foreach ($documents as $document): ?>
          <tr>
            <td data-label="Driver"><?= h((string) $document['full_name']) ?></td>
            <td data-label="Document"><a href="admin-document.php?id=<?= h((string) $document['id']) ?>"><?= h((string) $document['original_name']) ?></a><small><?= h(str_replace('_', ' ', (string) $document['document_type'])) ?></small></td>
            <td data-label="Uploaded"><?= h(date('M j, Y', strtotime((string) $document['uploaded_at']))) ?></td>
            <td data-label="Review status"><?= h((string) $document['review_status']) ?><?php if ($document['review_note'] !== null): ?><small><?= h((string) $document['review_note']) ?></small><?php endif; ?></td>
            <td data-label="Review">
              <?php if (($admin['role'] ?? '') !== 'viewer'): ?>
              <form method="post" class="admin-inline-form" action="admin-drivers.php#documents">
                <?= csrf_field() ?>
                <input type="hidden" name="driver_id" value="<?= h((string) $document['driver_id']) ?>">
                <input type="hidden" name="document_id" value="<?= h((string) $document['id']) ?>">
                <input name="note" maxlength="500" placeholder="Optional note">
                <button class="button button-approve" name="status" value="approved">Approve</button>
                <button class="button button-reject" name="status" value="rejected">Reject</button>
                <input type="hidden" name="action" value="document_review">
              </form>
              <?php else: ?><span class="admin-no-action">View only</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($documents === []): ?><tr><td colspan="5">No documents have been uploaded.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</section>
<script>
document.querySelectorAll('[data-open-driver-dialog]').forEach((button) => {
  button.addEventListener('click', () => {
    const dialog = document.getElementById(button.dataset.openDriverDialog);
    if (dialog instanceof HTMLDialogElement) {
      dialog.showModal();
    }
  });
});

document.querySelectorAll('[data-close-driver-dialog]').forEach((button) => {
  button.addEventListener('click', () => {
    button.closest('dialog')?.close();
  });
});

document.querySelectorAll('.admin-manage-dialog').forEach((dialog) => {
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) {
      dialog.close();
    }
  });
});
</script>
<?php page_end(); ?>
