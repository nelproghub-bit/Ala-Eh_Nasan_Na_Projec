<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

if (current_admin() !== null) {
    redirect('admin-dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = $_POST['password'] ?? '';
    if (!is_string($password) || $password === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email or password is incorrect.';
    } else {
        $pdo = db();
        $keys = login_bucket_keys($email, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        if (!begin_login_attempt($pdo, $keys)) {
            http_response_code(429);
            $error = 'Too many sign-in attempts. Please wait 15 minutes before trying again.';
        } else {
            $query = $pdo->prepare(
                "SELECT a.id, a.email, a.password_hash, r.role
                 FROM admins a
                 INNER JOIN admin_roles r ON r.admin_id = a.id
                 WHERE a.email = :email AND r.account_status = 'active'
                 LIMIT 1"
            );
            $query->execute(['email' => $email]);
            $admin = $query->fetch();

            if ($admin !== false && password_verify($password, $admin['password_hash'])) {
                if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
                    $rehash = $pdo->prepare('UPDATE admins SET password_hash = :hash WHERE id = :id');
                    $rehash->execute([
                        'hash' => password_hash($password, PASSWORD_DEFAULT),
                        'id' => $admin['id'],
                    ]);
                }
                clear_login_attempts($pdo, $keys);
                session_regenerate_id(true);
                unset($_SESSION['driver'], $_SESSION['csrf_token']);
                $_SESSION['admin'] = [
                    'id' => (int) $admin['id'],
                    'email' => $admin['email'],
                    'role' => $admin['role'],
                ];
                redirect('admin-dashboard.php');
            }

            $error = 'Email or password is incorrect.';
        }
    }
}

page_start('Admin sign in', 'admin-login');
?>
<section class="auth-layout">
  <div class="auth-aside admin-auth-aside">
    <span class="eyebrow eyebrow-light"><span class="eyebrow-dot"></span> ADMINISTRATION</span>
    <h1>Review the<br><span>next journey.</span></h1>
    <p>Sign in to review driver applications and manage verification decisions.</p>
    <div class="aside-note"><span class="aside-note-icon" aria-hidden="true">✓</span><span><strong>Authorized access only</strong><small>Application decisions are for administrators.</small></span></div>
    <div class="aside-decoration" aria-hidden="true">A</div>
  </div>
  <div class="auth-panel">
    <div class="form-heading">
      <span class="form-kicker">ADMIN PORTAL</span>
      <h2>Admin sign in</h2>
      <p>Use your administrator account to continue.</p>
    </div>
    <?php if ($error !== ''): ?><p class="message message-error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <form class="form-stack" method="post" action="admin-login.php" autocomplete="on">
      <?= csrf_field() ?>
      <label>Email address <input type="email" name="email" required autocomplete="username" placeholder="admin@example.com" value="<?= h((string) ($_POST['email'] ?? '')) ?>"></label>
      <label>Password <input type="password" name="password" required autocomplete="current-password" placeholder="Enter your password"></label>
      <button class="button button-primary button-submit" type="submit">Sign in to admin <span aria-hidden="true">→</span></button>
    </form>
    <div class="form-security"><span aria-hidden="true">◈</span> Admin accounts are separate from driver accounts and use role-based access.</div>
    <?php if (first_admin_registration_available()): ?>
    <p class="form-switch">Setting up the portal? <a href="admin-register.php">Create the first admin account</a></p>
    <?php endif; ?>
  </div>
</section>
<?php page_end(); ?>
