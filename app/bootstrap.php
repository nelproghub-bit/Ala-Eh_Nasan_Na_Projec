<?php
declare(strict_types=1);

ini_set('display_errors', '0');

function app_environment_setting(string $name): ?string
{
    $value = getenv($name);
    if (is_string($value) && trim($value) !== '') {
        return $value;
    }

    foreach ([$_ENV, $_SERVER] as $source) {
        $value = $source[$name] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }
    }

    return null;
}

function load_local_environment(): void
{
    $environmentFile = dirname(__DIR__) . '/.env';
    if (!is_file($environmentFile)) {
        return;
    }

    $values = parse_ini_file($environmentFile, false, INI_SCANNER_RAW);
    if ($values === false) {
        throw new RuntimeException('Unable to read the local environment configuration.');
    }

    $allowedNames = [
        'DB_HOST',
        'DB_PORT',
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
        'DB_SSL_CA',
        'MIGRATION_DB_USER',
        'MIGRATION_DB_PASSWORD',
        'SESSION_COOKIE_SECURE',
    ];
    foreach ($values as $name => $value) {
        if (
            in_array($name, $allowedNames, true)
            && is_string($value)
            && app_environment_setting($name) === null
        ) {
            $_ENV[$name] = $value;
        }
    }
}

load_local_environment();

set_exception_handler(static function (Throwable $exception): void {
    error_log((string) $exception);
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'An unexpected server error occurred.';
});

$secureCookieSetting = strtolower(app_environment_setting('SESSION_COOKIE_SECURE') ?? 'true');
$secureCookie = !in_array($secureCookieSetting, ['false', '0', 'off'], true);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
session_name('driver_portal_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/vehicles.php';
require_once __DIR__ . '/admin.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function current_driver(): ?array
{
    return isset($_SESSION['driver']) && is_array($_SESSION['driver'])
        ? $_SESSION['driver']
        : null;
}

function current_admin(): ?array
{
    return isset($_SESSION['admin']) && is_array($_SESSION['admin'])
        ? $_SESSION['admin']
        : null;
}

function require_driver(): array
{
    $driver = current_driver();
    if ($driver === null) {
        redirect('login.php');
    }

    $status = db()->prepare(
        "SELECT COALESCE(
            (SELECT account_status FROM driver_admin_status WHERE driver_id = :driver_id),
            'active'
         )"
    );
    $status->execute(['driver_id' => $driver['id']]);
    if ($status->fetchColumn() !== 'active') {
        unset($_SESSION['driver']);
        redirect('login.php?account=disabled');
    }

    return $driver;
}

function require_admin(): array
{
    $admin = current_admin();
    if ($admin === null) {
        redirect('admin-login.php');
    }

    $access = db()->prepare(
        'SELECT role, account_status FROM admin_roles WHERE admin_id = :admin_id LIMIT 1'
    );
    $access->execute(['admin_id' => $admin['id']]);
    $role = $access->fetch();
    if ($role === false || $role['account_status'] !== 'active') {
        unset($_SESSION['admin']);
        redirect('admin-login.php?account=disabled');
    }
    $_SESSION['admin']['role'] = $role['role'];

    return $_SESSION['admin'];
}

function profile_photo_endpoint_path(): string
{
    $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/public/index.php'));
    $publicPath = rtrim(dirname($scriptPath), '/');

    return ($publicPath === '' ? '' : $publicPath) . '/profile-photo.php';
}

function page_start(string $title, string $page = ''): void
{
    $safeTitle = h($title);
    $safePage = h($page);
    $isAuthenticated = current_driver() !== null || current_admin() !== null;
    $layoutClass = $isAuthenticated ? 'has-sidebar' : '';
    $photoCacheKey = bin2hex(random_bytes(8));
    $profilePhotoPath = profile_photo_endpoint_path();
    $profilePhotoEndpoint = h($profilePhotoPath);
    $profilePhotoEndpointJson = json_encode(
        $profilePhotoPath,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
    );
    
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$safeTitle} | Ala-Eh Nasaan Na? Jeepney Locator</title>
  <meta name="theme-color" content="#f5f8ff">
  <link rel="icon" type="image/png" href="assets/ala-eh-logo.png">
  <link rel="apple-touch-icon" href="assets/ala-eh-logo.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/styles.css">
  <script src="assets/password-toggle.js" defer></script>
  <script>
    try {
      if (localStorage.getItem('alaEhTheme') === 'dark') {
        document.documentElement.classList.add('dark-mode');
      }
    } catch (error) {
      console.warn('Theme preference could not be restored.', error);
    }
  </script>
</head>
<body class="page-{$safePage} {$layoutClass}">
HTML;
    
    if ($isAuthenticated) {
        // Sidebar layout for authenticated users
        echo <<<HTML
<div class="app-layout">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <a class="sidebar-brand" href="index.php" aria-label="Ala-Eh Nasaan Na? Jeepney Locator home">
        <img class="sidebar-brand-mark" src="assets/ala-eh-logo.png" alt="" aria-hidden="true">
        <span class="sidebar-brand-name">Ala-Eh Nasaan Na?<span>Jeepney Locator</span></span>
      </a>
      <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle sidebar">
        <span class="toggle-icon"></span>
      </button>
    </div>
    <nav class="sidebar-nav" aria-label="Main navigation">
HTML;
        
        if (current_admin() !== null) {
            $driver = current_admin();
            $adminEmail = h((string) ($driver['email'] ?? 'Admin'));
            $initials = h(strtoupper(substr((string) ($driver['email'] ?? 'A'), 0, 1)));
            $adminRole = h(ucfirst((string) ($driver['role'] ?? 'admin')));
            $ownerLinks = ($driver['role'] ?? '') === 'owner'
                ? '<a href="admin-users.php" class="sidebar-link"><span class="sidebar-link-icon">🔐</span><span class="sidebar-link-text">Users & admins</span></a><a href="admin-settings.php" class="sidebar-link"><span class="sidebar-link-icon">⚙️</span><span class="sidebar-link-text">Settings & activity</span></a>'
                : '';
            echo <<<HTML
      <div class="sidebar-user">
        <button class="sidebar-user-avatar" type="button" data-profile-photo-open aria-label="Manage profile picture" title="Manage profile picture">
          <img src="{$profilePhotoEndpoint}?v={$photoCacheKey}" alt="" data-profile-photo-avatar hidden>
          <span data-profile-photo-initial>{$initials}</span>
          <span class="profile-photo-camera" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M8.2 5.5 9.4 4h5.2l1.2 1.5H19a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-10a2 2 0 0 1 2-2h3.2ZM12 9a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm0 1.7a2.3 2.3 0 1 1 0 4.6 2.3 2.3 0 0 1 0-4.6Z"/></svg></span>
        </button>
        <div class="sidebar-user-info">
          <span class="sidebar-user-name">{$adminEmail}</span>
          <span class="sidebar-user-role">{$adminRole}</span>
        </div>
      </div>
      <div class="sidebar-menu">
        <a href="admin-dashboard.php" class="sidebar-link">
          <span class="sidebar-link-icon">📊</span>
          <span class="sidebar-link-text">Overview</span>
        </a>
        <a href="admin-drivers.php" class="sidebar-link">
          <span class="sidebar-link-icon">👤</span>
          <span class="sidebar-link-text">Drivers</span>
        </a>
        <a href="admin-jeepneys.php" class="sidebar-link">
          <span class="sidebar-link-icon">🚌</span>
          <span class="sidebar-link-text">Jeepneys</span>
        </a>
        <a href="admin-routes.php" class="sidebar-link">
          <span class="sidebar-link-icon">📍</span>
          <span class="sidebar-link-text">Terminals & routes</span>
        </a>
        <a href="admin-trips.php" class="sidebar-link">
          <span class="sidebar-link-icon">🗺️</span>
          <span class="sidebar-link-text">Trips & live map</span>
        </a>
        <a href="admin-reports.php" class="sidebar-link">
          <span class="sidebar-link-icon">📈</span>
          <span class="sidebar-link-text">Reports</span>
        </a>
        {$ownerLinks}
        <a href="index.php" class="sidebar-link">
          <span class="sidebar-link-icon">🏠</span>
          <span class="sidebar-link-text">Public site</span>
        </a>
      </div>
      <div class="sidebar-footer">
        <button class="sidebar-theme-toggle" type="button" data-theme-toggle aria-pressed="false">
          <span class="sidebar-link-icon" data-theme-icon aria-hidden="true">🌙</span>
          <span class="sidebar-link-text" data-theme-label>Dark mode</span>
        </button>
        <form method="post" action="admin-logout.php">
HTML;
        echo csrf_field();
        echo <<<HTML
          <button class="sidebar-logout" type="submit">
            <span class="sidebar-link-icon">🚪</span>
            <span class="sidebar-link-text">Log out</span>
          </button>
        </form>
      </div>
HTML;
        } elseif (current_driver() !== null) {
            $driver = current_driver();
            $initials = h(strtoupper(substr((string) $driver['full_name'], 0, 1)));
            $driverName = h((string) $driver['full_name']);
            echo <<<HTML
      <div class="sidebar-user">
        <button class="sidebar-user-avatar" type="button" data-profile-photo-open aria-label="Manage profile picture" title="Manage profile picture">
          <img src="{$profilePhotoEndpoint}?v={$photoCacheKey}" alt="" data-profile-photo-avatar hidden>
          <span data-profile-photo-initial>{$initials}</span>
          <span class="profile-photo-camera" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M8.2 5.5 9.4 4h5.2l1.2 1.5H19a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-10a2 2 0 0 1 2-2h3.2ZM12 9a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm0 1.7a2.3 2.3 0 1 1 0 4.6 2.3 2.3 0 0 1 0-4.6Z"/></svg></span>
        </button>
        <div class="sidebar-user-info">
          <span class="sidebar-user-name">{$driverName}</span>
          <span class="sidebar-user-role">Driver</span>
        </div>
      </div>
      <div class="sidebar-menu">
        <a href="index.php" class="sidebar-link">
          <span class="sidebar-link-icon">🏠</span>
          <span class="sidebar-link-text">Home</span>
        </a>
        <a href="dashboard.php" class="sidebar-link">
          <span class="sidebar-link-icon">📋</span>
          <span class="sidebar-link-text">My application</span>
        </a>
        <a href="driver-route.php" class="sidebar-link">
          <span class="sidebar-link-icon">🗺️</span>
          <span class="sidebar-link-text">My route</span>
        </a>
        <a href="driver-trip.php" class="sidebar-link">
          <span class="sidebar-link-icon">📡</span>
          <span class="sidebar-link-text">Trip tracking</span>
        </a>
        <a href="jeepney-profile.php" class="sidebar-link">
          <span class="sidebar-link-icon">🚌</span>
          <span class="sidebar-link-text">My jeepney</span>
        </a>
        <a href="driver-profile.php" class="sidebar-link">
          <span class="sidebar-link-icon">👤</span>
          <span class="sidebar-link-text">My profile</span>
        </a>
        <a href="driver-documents.php" class="sidebar-link">
          <span class="sidebar-link-icon">📄</span>
          <span class="sidebar-link-text">My documents</span>
        </a>
      </div>
      <div class="sidebar-footer">
        <button class="sidebar-theme-toggle" type="button" data-theme-toggle aria-pressed="false">
          <span class="sidebar-link-icon" data-theme-icon aria-hidden="true">🌙</span>
          <span class="sidebar-link-text" data-theme-label>Dark mode</span>
        </button>
        <form method="post" action="logout.php">
HTML;
        echo csrf_field();
        echo <<<HTML
          <button class="sidebar-logout" type="submit">
            <span class="sidebar-link-icon">🚪</span>
            <span class="sidebar-link-text">Log out</span>
          </button>
        </form>
      </div>
HTML;
        }
        
        echo <<<HTML
    </nav>
  </aside>
  <div class="app-content">
    <header class="top-header">
      <button class="mobile-menu-toggle" id="mobile-toggle" aria-label="Open menu">
        <span></span>
        <span></span>
        <span></span>
      </button>
      <h1 class="page-title">{$safeTitle}</h1>
    </header>
    <main class="site-main" id="main-content">
HTML;
    } else {
        // Regular header for non-authenticated pages
        echo <<<HTML
<header class="site-header">
  <div class="header-inner">
    <a class="brand" href="index.php" aria-label="Ala-Eh Nasaan Na? Jeepney Locator home">
      <img class="brand-mark" src="assets/ala-eh-logo.png" alt="" aria-hidden="true">
      <span class="brand-name">Ala-Eh Nasaan Na?<span>Jeepney Locator</span></span>
    </a>
    <nav class="site-nav" aria-label="Main navigation">
      <a href="index.php">Home</a>
      <a href="login.php">Log in</a>
      <a class="button button-primary button-small" href="register.php">Get started <span aria-hidden="true">→</span></a>
    </nav>
  </div>
</header>
<main class="site-main" id="main-content">
HTML;
    }

    if ($isAuthenticated) {
        $profileUser = current_admin() ?? current_driver();
        $profileName = current_admin() !== null
            ? (string) ($profileUser['email'] ?? 'Admin')
            : (string) ($profileUser['full_name'] ?? 'Driver');
        $profileInitial = h(strtoupper(substr($profileName, 0, 1)));
        $csrfField = csrf_field();
        echo <<<HTML
<dialog class="profile-photo-dialog" id="profile-photo-dialog" aria-labelledby="profile-photo-title">
  <div class="profile-photo-dialog-header">
    <div><span class="eyebrow"><span class="eyebrow-dot"></span> ACCOUNT SETTINGS</span><h2 id="profile-photo-title">Your profile picture</h2><p>Personalize your account with a photo that appears in the sidebar.</p></div>
    <button class="admin-manage-dialog-close" type="button" data-profile-photo-close aria-label="Close dialog">×</button>
  </div>
  <form class="profile-photo-form" id="profile-photo-form" action="{$profilePhotoEndpoint}" method="post" enctype="multipart/form-data">
    {$csrfField}
HTML;
        echo <<<HTML
    <div class="profile-photo-preview" aria-live="polite">
      <img src="{$profilePhotoEndpoint}?v={$photoCacheKey}" alt="Your current profile picture" data-profile-photo-preview hidden>
      <span data-profile-photo-preview-initial>{$profileInitial}</span>
      <span class="profile-photo-preview-ring" aria-hidden="true"></span>
    </div>
    <div class="profile-photo-upload-copy">
      <strong>Choose a clear, recognizable photo</strong>
      <span>JPEG, PNG, or WebP · up to 5 MB · maximum 16 megapixels</span>
    </div>
    <input class="profile-photo-file-input" id="profile-photo-file" name="photo" type="file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp">
    <label class="profile-photo-file-label" for="profile-photo-file">Select a photo</label>
    <span class="profile-photo-file-name" data-profile-photo-file-name>No photo selected</span>
    <p class="profile-photo-feedback" id="profile-photo-feedback" role="status" aria-live="polite"></p>
    <div class="profile-photo-actions">
      <button class="button button-outline profile-photo-remove" type="submit" name="action" value="remove" data-profile-photo-remove hidden>Remove photo</button>
      <button class="button button-primary" type="submit" name="action" value="save" data-profile-photo-save disabled>Save photo</button>
    </div>
  </form>
</dialog>
HTML;
    }
}

function page_end(): void
{
    $isAuthenticated = current_driver() !== null || current_admin() !== null;
    $profilePhotoEndpointJson = json_encode(
        profile_photo_endpoint_path(),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
    );
    
    if ($isAuthenticated) {
        echo <<<HTML
    </main>
  </div>
</div>
<script>
// Sidebar toggle functionality
const sidebar = document.getElementById('sidebar');
const sidebarToggle = document.getElementById('sidebar-toggle');
const mobileToggle = document.getElementById('mobile-toggle');
const themeToggles = document.querySelectorAll('[data-theme-toggle]');
const themeIsDark = document.documentElement.classList.contains('dark-mode');
const profilePhotoDialog = document.getElementById('profile-photo-dialog');
const profilePhotoForm = document.getElementById('profile-photo-form');
const profilePhotoEndpoint = {$profilePhotoEndpointJson};
const profilePhotoInput = document.getElementById('profile-photo-file');
const profilePhotoFeedback = document.getElementById('profile-photo-feedback');
const profilePhotoFileName = document.querySelector('[data-profile-photo-file-name]');
const profilePhotoRemove = document.querySelector('[data-profile-photo-remove]');
const profilePhotoSave = document.querySelector('[data-profile-photo-save]');
let profilePhotoPreviewUrl = null;

const setProfilePhoto = (source, updateAvatar = true) => {
  const selector = updateAvatar
    ? '[data-profile-photo-avatar], [data-profile-photo-preview]'
    : '[data-profile-photo-preview]';
  document.querySelectorAll(selector).forEach((image) => {
    image.hidden = true;
    image.src = source;
  });
};

document.querySelectorAll('[data-profile-photo-avatar], [data-profile-photo-preview]').forEach((image) => {
  image.addEventListener('load', () => {
    image.hidden = false;
    const fallback = image.parentElement.querySelector('[data-profile-photo-initial], [data-profile-photo-preview-initial]');
    if (fallback) fallback.hidden = true;
    if (image.matches('[data-profile-photo-avatar]') && profilePhotoRemove) profilePhotoRemove.hidden = false;
  });
  image.addEventListener('error', () => {
    image.hidden = true;
    const fallback = image.parentElement.querySelector('[data-profile-photo-initial], [data-profile-photo-preview-initial]');
    if (fallback) fallback.hidden = false;
    if (image.matches('[data-profile-photo-avatar]') && profilePhotoRemove) profilePhotoRemove.hidden = true;
  });
  if (image.complete && image.naturalWidth > 0) image.dispatchEvent(new Event('load'));
});

document.querySelectorAll('[data-profile-photo-open]').forEach((button) => {
  button.addEventListener('click', () => {
    if (profilePhotoInput) profilePhotoInput.value = '';
    if (profilePhotoSave) profilePhotoSave.disabled = true;
    if (profilePhotoFileName) profilePhotoFileName.textContent = 'No photo selected';
    if (profilePhotoFeedback) profilePhotoFeedback.textContent = '';
    if (profilePhotoPreviewUrl) {
      URL.revokeObjectURL(profilePhotoPreviewUrl);
      profilePhotoPreviewUrl = null;
    }
    setProfilePhoto(profilePhotoEndpoint + '?v=' + Date.now());
    if (profilePhotoDialog && !profilePhotoDialog.open) profilePhotoDialog.showModal();
  });
});

document.querySelectorAll('[data-profile-photo-close]').forEach((button) => {
  button.addEventListener('click', () => profilePhotoDialog?.close());
});

if (profilePhotoDialog) {
  profilePhotoDialog.addEventListener('click', (event) => {
    if (event.target === profilePhotoDialog) profilePhotoDialog.close();
  });
  profilePhotoDialog.addEventListener('close', () => {
    if (profilePhotoPreviewUrl) {
      URL.revokeObjectURL(profilePhotoPreviewUrl);
      profilePhotoPreviewUrl = null;
    }
    if (profilePhotoInput) profilePhotoInput.value = '';
    if (profilePhotoSave) profilePhotoSave.disabled = true;
    if (profilePhotoFileName) profilePhotoFileName.textContent = 'No photo selected';
    setProfilePhoto(profilePhotoEndpoint + '?v=' + Date.now());
  });
}

if (profilePhotoInput) {
  profilePhotoInput.addEventListener('change', () => {
    const file = profilePhotoInput.files?.[0];
    if (!file) return;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) {
      profilePhotoFeedback.textContent = 'Choose a JPEG, PNG, or WebP image smaller than 5 MB.';
      profilePhotoInput.value = '';
      profilePhotoSave.disabled = true;
      profilePhotoFileName.textContent = 'No photo selected';
      if (profilePhotoPreviewUrl) {
        URL.revokeObjectURL(profilePhotoPreviewUrl);
        profilePhotoPreviewUrl = null;
      }
      setProfilePhoto(profilePhotoEndpoint + '?v=' + Date.now(), false);
      return;
    }
    if (profilePhotoPreviewUrl) URL.revokeObjectURL(profilePhotoPreviewUrl);
    profilePhotoPreviewUrl = URL.createObjectURL(file);
    setProfilePhoto(profilePhotoPreviewUrl, false);
    profilePhotoFileName.textContent = file.name;
    profilePhotoFeedback.textContent = '';
    profilePhotoSave.disabled = false;
  });
}

if (profilePhotoForm) {
  profilePhotoForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const action = event.submitter?.value === 'remove' ? 'remove' : 'save';
    if (action === 'save' && !profilePhotoInput?.files?.length) {
      profilePhotoFeedback.textContent = 'Select a photo before saving.';
      return;
    }
    const formData = new FormData(profilePhotoForm);
    formData.set('action', action);
    profilePhotoFeedback.textContent = action === 'remove' ? 'Removing your photo…' : 'Saving your photo…';
    profilePhotoSave.disabled = true;
    if (profilePhotoRemove) profilePhotoRemove.disabled = true;
    try {
      const response = await fetch(profilePhotoEndpoint, {
        method: 'POST',
        body: formData,
        headers: { Accept: 'application/json' },
        credentials: 'same-origin'
      });
      const contentType = response.headers.get('content-type') || '';
      if (response.redirected || !contentType.toLowerCase().includes('application/json')) {
        if (response.redirected) {
          throw new Error('Your sign-in session may have expired. Reload the page and sign in again.');
        }
        if (response.status === 413) {
          throw new Error('The image exceeds the server upload limit. Choose a smaller photo.');
        }
        if (response.status >= 500) {
          throw new Error('The server could not save your photo (HTTP ' + response.status + '). It was not changed; please retry.');
        }
        throw new Error('The photo request was rejected (HTTP ' + response.status + '). Reload the page and try again.');
      }
      const result = await response.json();
      if (!response.ok) {
        const requestReference = result.request_id ? ' Reference: ' + result.request_id + '.' : '';
        throw new Error((result.message || 'The profile photo could not be updated.') + requestReference);
      }
      if (profilePhotoPreviewUrl) {
        URL.revokeObjectURL(profilePhotoPreviewUrl);
        profilePhotoPreviewUrl = null;
      }
      if (action === 'remove') {
        document.querySelectorAll('[data-profile-photo-avatar], [data-profile-photo-preview]').forEach((image) => {
          image.removeAttribute('src');
          image.hidden = true;
          const fallback = image.parentElement.querySelector('[data-profile-photo-initial], [data-profile-photo-preview-initial]');
          if (fallback) fallback.hidden = false;
        });
        if (profilePhotoRemove) profilePhotoRemove.hidden = true;
      } else {
        const updatedSource = profilePhotoEndpoint + '?v=' + Date.now();
        setProfilePhoto(updatedSource);
      }
      profilePhotoInput.value = '';
      profilePhotoFileName.textContent = 'No photo selected';
      profilePhotoSave.disabled = true;
      profilePhotoFeedback.textContent = result.message;
    } catch (error) {
      profilePhotoFeedback.textContent = error instanceof Error ? error.message : 'The profile photo could not be updated.';
    } finally {
      profilePhotoSave.disabled = false;
      if (profilePhotoRemove) profilePhotoRemove.disabled = false;
    }
  });
}

themeToggles.forEach((button) => {
  const icon = button.querySelector('[data-theme-icon]');
  const label = button.querySelector('[data-theme-label]');
  button.setAttribute('aria-pressed', String(themeIsDark));
  button.setAttribute('aria-label', themeIsDark ? 'Switch to light mode' : 'Switch to dark mode');
  if (icon) icon.textContent = themeIsDark ? '☀️' : '🌙';
  if (label) label.textContent = themeIsDark ? 'Light mode' : 'Dark mode';

  button.addEventListener('click', () => {
    const enableDarkMode = !document.documentElement.classList.contains('dark-mode');
    document.documentElement.classList.toggle('dark-mode', enableDarkMode);
    button.setAttribute('aria-pressed', String(enableDarkMode));
    button.setAttribute('aria-label', enableDarkMode ? 'Switch to light mode' : 'Switch to dark mode');
    if (icon) icon.textContent = enableDarkMode ? '☀️' : '🌙';
    if (label) label.textContent = enableDarkMode ? 'Light mode' : 'Dark mode';
    try {
      localStorage.setItem('alaEhTheme', enableDarkMode ? 'dark' : 'light');
    } catch (error) {
      console.warn('Theme preference could not be saved.', error);
    }
  });
});

if (sidebarToggle) {
  sidebarToggle.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
  });
}

if (mobileToggle) {
  mobileToggle.addEventListener('click', () => {
    sidebar.classList.toggle('mobile-open');
  });
}

// Restore sidebar state
if (localStorage.getItem('sidebarCollapsed') === 'true') {
  sidebar.classList.add('collapsed');
}

// Close sidebar when clicking outside on mobile
document.addEventListener('click', (e) => {
  if (window.innerWidth <= 768 && sidebar.classList.contains('mobile-open')) {
    if (!sidebar.contains(e.target) && !mobileToggle.contains(e.target)) {
      sidebar.classList.remove('mobile-open');
    }
  }
});

// Active link highlighting
const currentPath = window.location.pathname.split('/').pop();
document.querySelectorAll('.sidebar-link').forEach(link => {
  if (link.getAttribute('href') === currentPath) {
    link.classList.add('active');
  }
});
</script>
</body>
</html>
HTML;
    } else {
        echo <<<HTML
</main>
<footer class="site-footer">
  <div class="footer-inner">
    <a class="footer-brand" href="index.php" aria-label="Ala-Eh Nasaan Na? Jeepney Locator home">
      <img class="footer-brand-mark" src="assets/ala-eh-logo.png" alt="" aria-hidden="true">
      <span>Ala-Eh Nasaan Na?<small>Jeepney Locator</small></span>
    </a>
    <span>Built for the road ahead. <a class="admin-footer-link" href="admin-login.php">Admin sign in</a></span>
  </div>
</footer>
</body>
</html>
HTML;
    }
}
