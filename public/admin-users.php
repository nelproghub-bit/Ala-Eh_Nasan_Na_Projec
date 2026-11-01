<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$admin = require_admin_role(['owner']);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $action = $_POST['action'] ?? '';
    if ($action === 'create_admin') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = $_POST['password'] ?? '';
        $confirmation = $_POST['password_confirm'] ?? '';
        $role = $_POST['role'] ?? '';
        if (
            !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 ||
            !is_string($password) || strlen($password) < 12 ||
            !is_string($confirmation) || !hash_equals($password, $confirmation) ||
            !in_array($role, ['admin', 'viewer'], true)
        ) {
            $_SESSION['admin_flash'] = 'Use a valid email, matching passwords of at least 12 characters, and a supported role.';
            redirect('admin-users.php');
        }
        try {
            $pdo->beginTransaction();
            $insert = $pdo->prepare(
                'INSERT INTO admins (email, password_hash) VALUES (:email, :password_hash)'
            );
            $insert->execute(['email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
            $newAdminId = (int) $pdo->lastInsertId();
            $roleInsert = $pdo->prepare(
                'INSERT INTO admin_roles (admin_id, role, account_status, created_by)
                 VALUES (:id, :role, \'active\', :created_by)'
            );
            $roleInsert->execute(['id' => $newAdminId, 'role' => $role, 'created_by' => $admin['id']]);
            record_admin_activity($pdo, $admin, 'admin_created', 'admin', $newAdminId, $role);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                $_SESSION['admin_flash'] = 'An administrator already uses that email address.';
                redirect('admin-users.php');
            }
            throw $exception;
        }
        $_SESSION['admin_flash'] = 'Administrator account created.';
        redirect('admin-users.php');
    }

    if ($action === 'update_admin') {
        $adminId = validated_positive_id($_POST['admin_id'] ?? null);
        $role = $_POST['role'] ?? '';
        $status = $_POST['account_status'] ?? '';
        if (
            $adminId === null || $adminId === (int) $admin['id'] ||
            !in_array($role, ['admin', 'viewer'], true) ||
            !in_array($status, ['active', 'deactivated'], true)
        ) {
            http_response_code(400);
            exit('Invalid administrator account update.');
        }
        $target = $pdo->prepare(
            "SELECT role FROM admin_roles WHERE admin_id = :id AND role <> 'owner' FOR UPDATE"
        );
        $pdo->beginTransaction();
        try {
            $target->execute(['id' => $adminId]);
            if ($target->fetchColumn() === false) {
                $pdo->rollBack();
                http_response_code(404);
                exit('Administrator record not found.');
            }
            $update = $pdo->prepare(
                'UPDATE admin_roles SET role = :role, account_status = :status
                 WHERE admin_id = :id'
            );
            $update->execute(['role' => $role, 'status' => $status, 'id' => $adminId]);
            record_admin_activity($pdo, $admin, 'admin_access_updated', 'admin', $adminId, $role . '/' . $status);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        $_SESSION['admin_flash'] = 'Administrator access updated.';
        redirect('admin-users.php');
    }

    if ($action === 'create_commuter') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        if (
            $fullName === '' || mb_strlen($fullName) > 120 ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 ||
            ($phone !== '' && strlen($phone) > 32)
        ) {
            $_SESSION['admin_flash'] = 'Enter a valid commuter name, email, and optional phone number.';
            redirect('admin-users.php#commuters');
        }
        try {
            $insert = $pdo->prepare(
                "INSERT INTO commuters (full_name, email, phone, account_status)
                 VALUES (:name, :email, :phone, 'active')"
            );
            $insert->execute(['name' => $fullName, 'email' => $email, 'phone' => $phone === '' ? null : $phone]);
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }
            $_SESSION['admin_flash'] = 'A commuter account already uses that email address.';
            redirect('admin-users.php#commuters');
        }
        $commuterId = (int) $pdo->lastInsertId();
        record_admin_activity($pdo, $admin, 'commuter_created', 'commuter', $commuterId);
        $_SESSION['admin_flash'] = 'Commuter record created. Commuter sign-in is not enabled in the current website.';
        redirect('admin-users.php#commuters');
    }

    if ($action === 'update_commuter') {
        $commuterId = validated_positive_id($_POST['commuter_id'] ?? null);
        $status = $_POST['account_status'] ?? '';
        if ($commuterId === null || !in_array($status, ['active', 'suspended', 'deactivated'], true)) {
            http_response_code(400);
            exit('Invalid commuter account update.');
        }
        $update = $pdo->prepare(
            'UPDATE commuters SET account_status = :status WHERE id = :id'
        );
        $update->execute(['status' => $status, 'id' => $commuterId]);
        if ($update->rowCount() > 0) {
            record_admin_activity($pdo, $admin, 'commuter_' . $status, 'commuter', $commuterId);
        }
        $_SESSION['admin_flash'] = 'Commuter record status updated.';
        redirect('admin-users.php#commuters');
    }

    http_response_code(400);
    exit('Invalid user management action.');
}

$admins = $pdo->query(
    'SELECT a.id, a.email, r.role, r.account_status, r.created_at
     FROM admins a INNER JOIN admin_roles r ON r.admin_id = a.id
     ORDER BY r.role, a.email'
)->fetchAll();
$commuters = $pdo->query(
    'SELECT id, full_name, email, phone, account_status, created_at
     FROM commuters ORDER BY created_at DESC LIMIT 200'
)->fetchAll();
$flash = (string) ($_SESSION['admin_flash'] ?? '');
unset($_SESSION['admin_flash']);

page_start('Users and permissions', 'admin-users');
?>
<section class="admin-dashboard">
  <div class="admin-page-heading"><div><span class="eyebrow"><span class="eyebrow-dot"></span> ACCESS CONTROL</span><h1>Users & permissions</h1><p>Manage commuter records and issue least-privilege administrator access.</p></div><span class="admin-access-badge"><span aria-hidden="true">🔐</span> Owner-only controls</span></div>
  <?php if ($flash !== ''): ?><p class="message message-success admin-flash" role="status"><?= h($flash) ?></p><?php endif; ?>
  <section class="admin-queue">
    <div class="admin-queue-heading"><div><h2>Create administrator</h2><p>New administrators receive their password directly from the owner; never send it through GitHub.</p></div></div>
    <form method="post" class="admin-form-grid" action="admin-users.php">
      <?= csrf_field() ?><input type="hidden" name="action" value="create_admin">
      <label>Email<input type="email" name="email" maxlength="254" required autocomplete="off"></label>
      <label>Role<select name="role"><option value="admin">Admin — manage operations</option><option value="viewer">Viewer — read only</option></select></label>
      <label>Initial password<input type="password" name="password" minlength="12" required autocomplete="new-password"></label>
      <label>Confirm password<input type="password" name="password_confirm" minlength="12" required autocomplete="new-password"></label>
      <button class="button button-primary" type="submit">Create admin</button>
    </form>
  </section>
  <section class="admin-queue">
    <div class="admin-queue-heading"><div><h2>Administrator access</h2><p>The owner account cannot be changed here. Deactivated admins are denied on their next request.</p></div></div>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Admin</th><th>Role</th><th>Status</th><th>Access</th></tr></thead><tbody>
      <?php foreach ($admins as $account): ?>
        <tr><td data-label="Admin"><?= h((string) $account['email']) ?><?= (int) $account['id'] === (int) $admin['id'] ? ' (you)' : '' ?></td>
          <td data-label="Role"><?= h(ucfirst((string) $account['role'])) ?></td><td data-label="Status"><?= h((string) $account['account_status']) ?></td>
          <td data-label="Access"><?php if ($account['role'] === 'owner'): ?>Protected owner<?php else: ?>
            <form method="post" class="admin-inline-form" action="admin-users.php">
              <?= csrf_field() ?><input type="hidden" name="action" value="update_admin"><input type="hidden" name="admin_id" value="<?= h((string) $account['id']) ?>">
              <select name="role"><option value="admin" <?= $account['role'] === 'admin' ? 'selected' : '' ?>>Admin</option><option value="viewer" <?= $account['role'] === 'viewer' ? 'selected' : '' ?>>Viewer</option></select>
              <select name="account_status"><option value="active" <?= $account['account_status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="deactivated" <?= $account['account_status'] === 'deactivated' ? 'selected' : '' ?>>Deactivated</option></select>
              <button class="button button-outline button-small" type="submit">Save access</button>
            </form><?php endif; ?>
          </td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </section>
  <section class="admin-queue" id="commuters">
    <div class="admin-queue-heading"><div><h2>Commuter records</h2><p>These are administrative contact records only; commuter login and registration are not yet part of the site.</p></div></div>
    <form method="post" class="admin-form-grid" action="admin-users.php#commuters">
      <?= csrf_field() ?><input type="hidden" name="action" value="create_commuter">
      <label>Full name<input name="full_name" maxlength="120" required></label><label>Email<input type="email" name="email" maxlength="254" required></label><label>Phone<input name="phone" maxlength="32"></label><button class="button button-primary" type="submit">Add commuter record</button>
    </form>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Commuter</th><th>Contact</th><th>Status</th><th>Manage</th></tr></thead><tbody>
      <?php foreach ($commuters as $commuter): ?>
        <tr><td data-label="Commuter"><?= h((string) $commuter['full_name']) ?></td><td data-label="Contact"><?= h((string) $commuter['email']) ?><small><?= h((string) ($commuter['phone'] ?? '')) ?></small></td><td data-label="Status"><?= h((string) $commuter['account_status']) ?></td><td data-label="Manage">
          <form method="post" class="admin-inline-form" action="admin-users.php#commuters"><?= csrf_field() ?><input type="hidden" name="action" value="update_commuter"><input type="hidden" name="commuter_id" value="<?= h((string) $commuter['id']) ?>"><select name="account_status"><?php foreach (['active', 'suspended', 'deactivated'] as $status): ?><option value="<?= h($status) ?>" <?= $commuter['account_status'] === $status ? 'selected' : '' ?>><?= h(ucfirst($status)) ?></option><?php endforeach; ?></select><button class="button button-outline button-small" type="submit">Save</button></form>
        </td></tr>
      <?php endforeach; ?>
      <?php if ($commuters === []): ?><tr><td colspan="4">No commuter records.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
</section>
<?php page_end(); ?>
