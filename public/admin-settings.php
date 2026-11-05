<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$admin = require_admin_role(['owner']);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $action = $_POST['action'] ?? '';
    if ($action !== 'save_settings') {
        http_response_code(400);
        exit('Invalid settings action.');
    }
    $refreshSeconds = filter_var($_POST['map_refresh_seconds'] ?? null, FILTER_VALIDATE_INT);
    if ($refreshSeconds === false || $refreshSeconds < 10 || $refreshSeconds > 120) {
        $_SESSION['admin_flash'] = 'Map refresh must be between 10 and 120 seconds.';
        redirect('admin-settings.php');
    }
    $save = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value, updated_by)
         VALUES (\'map_refresh_seconds\', :value, :admin_id)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
             updated_by = VALUES(updated_by)'
    );
    $save->execute(['value' => (string) $refreshSeconds, 'admin_id' => $admin['id']]);
    record_admin_activity($pdo, $admin, 'system_setting_updated', 'app_setting', null, 'Map refresh interval: ' . $refreshSeconds . ' seconds');
    $_SESSION['admin_flash'] = 'System setting saved.';
    redirect('admin-settings.php');
}

$setting = $pdo->query(
    "SELECT setting_value FROM app_settings WHERE setting_key = 'map_refresh_seconds'"
)->fetchColumn();
$activity = $pdo->query(
    'SELECT l.action, l.entity_type, l.entity_id, l.details, l.created_at, a.email
     FROM activity_logs l
     LEFT JOIN admins a ON a.id = l.admin_id
     ORDER BY l.created_at DESC LIMIT 100'
)->fetchAll();
$flash = (string) ($_SESSION['admin_flash'] ?? '');
unset($_SESSION['admin_flash']);
$secureCookie = strtolower(app_environment_setting('SESSION_COOKIE_SECURE') ?? 'true');
$cookieSecureEnabled = !in_array($secureCookie, ['false', '0', 'off'], true);
$tls = db()->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_NUM);
$tlsVerified = is_array($tls) && isset($tls[1]) && $tls[1] !== '';

page_start('System settings and security', 'admin-settings');
?>
<section class="admin-dashboard">
  <div class="admin-page-heading"><div><span class="eyebrow"><span class="eyebrow-dot"></span> SYSTEM SECURITY</span><h1>Settings & activity</h1><p>Manage supported application settings and review the audit trail.</p></div><span class="admin-access-badge"><span aria-hidden="true">🔐</span> Owner-only controls</span></div>
  <?php if ($flash !== ''): ?><p class="message message-success admin-flash" role="status"><?= h($flash) ?></p><?php endif; ?>
  <div class="admin-settings-grid">
    <section class="admin-queue">
      <div class="admin-queue-heading"><div><h2>Application configuration</h2><p>Settings available for runtime changes.</p></div></div>
      <form method="post" class="admin-form-grid" action="admin-settings.php">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_settings">
        <label>Live map refresh (seconds)<input type="number" name="map_refresh_seconds" min="10" max="120" step="1" required value="<?= h((string) $setting) ?>"></label>
        <p>Server credentials, TLS certificates, and session cookie settings remain in protected environment configuration, not editable from this page.</p>
        <button class="button button-primary" type="submit">Save settings</button>
      </form>
    </section>
    <section class="admin-queue">
      <div class="admin-queue-heading"><div><h2>Security status</h2><p>Read-only checks for this application process.</p></div></div>
      <dl class="admin-security-list">
        <div><dt>Database transport</dt><dd><?= $tlsVerified ? 'Verified TLS connection active' : 'TLS verification failed' ?></dd></div>
        <div><dt>Session cookie Secure flag</dt><dd><?= $cookieSecureEnabled ? 'Enabled' : 'Disabled for local HTTP development' ?></dd></div>
        <div><dt>Administrator permissions</dt><dd>Owner, admin, and viewer roles; permissions are rechecked on each request.</dd></div>
        <div><dt>Document storage</dt><dd>Private shared database blobs; download requires an authenticated admin session.</dd></div>
      </dl>
    </section>
  </div>
  <section class="admin-queue" id="activity">
    <div class="admin-queue-heading"><div><h2>Activity log</h2><p>Latest 100 recorded administrator actions. Logs are retained; configure a retention policy before production use.</p></div></div>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Action</th><th>Record</th><th>Details</th><th>Administrator</th><th>Time</th></tr></thead><tbody>
      <?php foreach ($activity as $item): ?>
        <tr><td data-label="Action"><?= h(str_replace('_', ' ', (string) $item['action'])) ?></td><td data-label="Record"><?= h(ucfirst(str_replace('_', ' ', (string) $item['entity_type']))) ?><?= $item['entity_id'] === null ? '' : ' #' . h((string) $item['entity_id']) ?></td><td data-label="Details"><?= h((string) ($item['details'] ?? '')) ?></td><td data-label="Administrator"><?= h((string) ($item['email'] ?? 'Deleted account')) ?></td><td data-label="Time"><?= h(date('M j, Y g:i A', strtotime((string) $item['created_at']))) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($activity === []): ?><tr><td colspan="5">No recorded admin activity yet.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
</section>
<?php page_end(); ?>
