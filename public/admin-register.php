<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

if (current_admin() !== null) {
    redirect('admin-dashboard.php');
}
if (!first_admin_registration_available()) {
    redirect('admin-login.php?registration=closed');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if (
        !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 ||
        !is_string($password) || strlen($password) < 12 ||
        !is_string($passwordConfirm) || !hash_equals($password, $passwordConfirm)
    ) {
        $error = 'Enter a valid email address and matching passwords of at least 12 characters.';
    } elseif (!create_first_admin($email, $password)) {
        redirect('admin-login.php?registration=closed');
    } else {
        session_regenerate_id(true);
        unset($_SESSION['driver'], $_SESSION['csrf_token']);
        $_SESSION['admin'] = [
            'id' => (int) db()->lastInsertId(),
            'email' => $email,
        ];
        redirect('admin-dashboard.php');
    }
}

page_start('Create first admin account', 'admin-register');
?>
<section class="auth-layout">
  <div class="auth-aside admin-auth-aside">
    <span class="eyebrow eyebrow-light"><span class="eyebrow-dot"></span> ONE-TIME SETUP</span>
    <h1>Set up your<br><span>owner account.</span></h1>
    <p>Create the first owner account to manage transport operations and administrator access.</p>
    <div class="aside-note"><span class="aside-note-icon" aria-hidden="true">✓</span><span><strong>One-time owner registration</strong><small>The owner can add admins and read-only viewers.</small></span></div>
    <div class="aside-decoration" aria-hidden="true">A</div>
  </div>
  <div class="auth-panel">
    <div class="form-heading">
      <span class="form-kicker">FIRST-TIME SETUP</span>
      <h2>Create the owner account</h2>
      <p>This initial setup is available only once.</p>
    </div>
    <?php if ($error !== ''): ?><p class="message message-error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <form class="form-stack" method="post" action="admin-register.php" autocomplete="on">
      <?= csrf_field() ?>
      <label>Email address <input type="email" name="email" required maxlength="254" autocomplete="username" placeholder="admin@example.com" value="<?= h((string) ($_POST['email'] ?? '')) ?>"></label>
      <label>Password <input type="password" name="password" required minlength="12" autocomplete="new-password" placeholder="At least 12 characters"><span class="field-hint">Use at least 12 characters.</span></label>
      <label>Confirm password <input type="password" name="password_confirm" required minlength="12" autocomplete="new-password" placeholder="Re-enter your password"></label>
      <button class="button button-primary button-submit" type="submit">Create admin account <span aria-hidden="true">→</span></button>
    </form>
    <p class="form-switch">Already set up? <a href="admin-login.php">Admin sign in</a></p>
    <div class="form-security"><span aria-hidden="true">◈</span> The owner can create additional admins with restricted roles.</div>
  </div>
</section>
<?php page_end(); ?>
