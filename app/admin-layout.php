<?php
declare(strict_types=1);
function remember_auth_return_path(string $sessionKey): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }

    $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (str_contains($scriptPath, '/api/')) {
        return;
    }

    $page = basename($scriptPath);
    if (
        !preg_match('/\A[a-zA-Z0-9_-]+\.php\z/', $page)
        || in_array($page, ['index.php', 'login.php', 'admin-login.php', 'logout.php', 'admin-logout.php'], true)
    ) {
        return;
    }

    $query = $_GET;
    unset($query['return_to']);
    $target = $page;
    if ($query !== []) {
        $target .= '?' . http_build_query($query);
    }

    $_SESSION[$sessionKey] = $target;
}

function consume_auth_return_path(string $sessionKey, string $fallback): string
{
    $target = $_SESSION[$sessionKey] ?? null;
    unset($_SESSION[$sessionKey]);
    if (!is_string($target)) {
        return $fallback;
    }

    $parts = parse_url($target);
    $path = is_array($parts) ? ($parts['path'] ?? '') : '';
    if (
        isset($parts['scheme'])
        || isset($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['fragment'])
        || !is_string($path)
        || basename($path) !== $path
        || !preg_match('/\A[a-zA-Z0-9_-]+\.php\z/', $path)
        || in_array($path, ['index.php', 'login.php', 'admin-login.php', 'logout.php', 'admin-logout.php'], true)
    ) {
        return $fallback;
    }

    return $target;
}

function profile_photo_endpoint_path(): string
{
    $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/public/index.php'));
    $publicPath = rtrim(dirname($scriptPath), '/');

    return ($publicPath === '' ? '' : $publicPath) . '/admin-profile-photo.php';
}

function render_toast_system(): void
{
    echo <<<'HTML'
<div class="toast-region" id="toast-region" aria-live="polite" aria-relevant="additions text" aria-atomic="false"></div>
<script>
(() => {
  const region = document.getElementById('toast-region');
  if (!region) return;

  const activeToasts = [];
  const toastSymbols = { success: '✓', error: '!', warning: '!', info: 'i' };

  window.showToast = (message, type = 'info', duration = 4500, pauseOnInteraction = true) => {
    const text = typeof message === 'string' ? message.trim() : '';
    if (!text) return;

    const safeType = ['success', 'error', 'warning', 'info'].includes(type) ? type : 'info';
    const timeout = Number.isFinite(duration) ? Math.min(Math.max(duration, 1500), 10000) : 4500;
    const toast = document.createElement('div');
    toast.className = 'app-toast toast-' + safeType;
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-label', text);
    toast.style.setProperty('--toast-duration', timeout + 'ms');

    const icon = document.createElement('span');
    icon.className = 'app-toast-icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = toastSymbols[safeType];

    const copy = document.createElement('span');
    copy.className = 'app-toast-copy';
    copy.textContent = text;

    const close = document.createElement('button');
    close.className = 'app-toast-close';
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss notification');
    close.textContent = '×';

    const progress = document.createElement('span');
    progress.className = 'app-toast-progress';
    progress.setAttribute('aria-hidden', 'true');

    toast.append(icon, copy, close, progress);
    region.append(toast);
    activeToasts.push(toast);
    while (activeToasts.length > 3) activeToasts.shift().remove();

    let remaining = timeout;
    let timer = 0;
    let startedAt = 0;
    const dismiss = () => {
      window.clearTimeout(timer);
      toast.classList.remove('is-visible');
      window.setTimeout(() => {
        toast.remove();
        const index = activeToasts.indexOf(toast);
        if (index !== -1) activeToasts.splice(index, 1);
      }, 220);
    };
    const resumeTimer = () => {
      if (remaining <= 0 || !toast.isConnected) return;
      toast.classList.remove('is-paused');
      startedAt = Date.now();
      timer = window.setTimeout(dismiss, remaining);
    };
    const pauseTimer = () => {
      if (!timer) return;
      window.clearTimeout(timer);
      timer = 0;
      remaining = Math.max(0, remaining - (Date.now() - startedAt));
      toast.classList.add('is-paused');
    };

    close.addEventListener('click', dismiss);
    if (pauseOnInteraction) {
      toast.addEventListener('mouseenter', pauseTimer);
      toast.addEventListener('mouseleave', resumeTimer);
      toast.addEventListener('focusin', pauseTimer);
      toast.addEventListener('focusout', (event) => {
        if (!toast.contains(event.relatedTarget)) resumeTimer();
      });
    }
    requestAnimationFrame(() => toast.classList.add('is-visible'));
    resumeTimer();
  };

  document.addEventListener('DOMContentLoaded', () => {
    if (window.location.hash === '#applications') {
      const applications = document.getElementById('applications');
      if (applications) {
        const headerOffset = window.innerWidth <= 768 ? 76 : 24;
        const top = applications.getBoundingClientRect().top + window.scrollY - headerOffset;
        window.scrollTo({ top: Math.max(0, top), behavior: 'instant' });
      }
    }

    document.querySelectorAll('.admin-flash, .profile-message.message-success, .message.message-success[role="status"]').forEach((message) => {
      const text = message.textContent.replace(/\s+/g, ' ').trim();
      const type = /\b(could not|already|invalid|must|choose|check|enter|required|unable|failed|not found)\b/i.test(text)
        ? 'warning'
        : 'success';
      window.showToast(text, type);
      message.remove();
    });

  });
})();
</script>
HTML;
}

function ui_icons(): array
{
    $shapes = [
        'overview' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'drivers' => '<circle cx="9" cy="7" r="3"/><path d="M3 21v-2a6 6 0 0 1 9-5.2"/><circle cx="17" cy="17" r="4"/><path d="M13 17h8m-4 0v4"/>',
        'jeepney' => '<path d="M5 10V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v5M5 6h14m-7 0v4M4 18v3m16-3v3M2 8h3m14 0h3"/><rect x="3" y="10" width="18" height="8" rx="2"/><path d="M10 13v2m4-2v2m-4 0h4m-8-1h1m10 0h1"/>',
        'routes' => '<circle cx="5" cy="5" r="2"/><circle cx="19" cy="19" r="2"/><path d="M7 5h9a4 4 0 0 1 0 8H8a3 3 0 0 0 0 6h9"/>',
        'map' => '<path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3V6Zm6-3v15m6-12v15"/>',
        'reports' => '<path d="M4 3v17h17M8 16v-4m5 4V8m5 8V5"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-2a6 6 0 0 1 12 0v2m1-15a3 3 0 0 1 0 6m3 9v-2a6 6 0 0 0-2-4.5"/>',
        'settings' => '<path d="m9 3-.5 2.3-2 .9-2.1-.7-2 3.5L4 10.5v3l-1.6 1.5 2 3.5 2.1-.7 2 .9L9 21h6l.5-2.3 2-.9 2.1.7 2-3.5-1.6-1.5v-3l1.6-1.5-2-3.5-2.1.7-2-.9L15 3H9Z"/><circle cx="12" cy="12" r="3"/>',
        'home' => '<path d="m3 10 9-7 9 7v9a2 2 0 0 1-2 2h-4v-8H9v8H5a2 2 0 0 1-2-2v-9Z"/>',
        'application' => '<rect x="8" y="3" width="8" height="4" rx="1"/><path d="M8 5H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M8 12h8m-8 4h5"/>',
        'terminal' => '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><rect x="8" y="6" width="8" height="8" rx="2"/><path d="M8 10h8m-6 4v2m4-2v2"/>',
        'pending' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'vehicle-review' => '<path d="M4 11V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v6M4 6h14m-7 0v5H4a2 2 0 0 0-2 2v4a2 2 0 0 0 2 2h5m-5 0v2m1-7h1"/><circle cx="17" cy="17" r="5"/><path d="m14.5 17 1.5 1.5 3.5-3.5"/>',
        'tracking' => '<circle cx="12" cy="12" r="7"/><circle cx="12" cy="12" r="2"/><path d="M12 2v3m0 14v3M2 12h3m14 0h3"/>',
        'documents' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Zm0 0v6h6M8 13h8m-8 4h6"/>',
        'moon' => '<path d="M20.9 13A9 9 0 0 1 11 3.1 9 9 0 1 0 20.9 13Z"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4m7 14 5-5-5-5m5 5H9"/>',
    ];

    $icons = [];
    foreach ($shapes as $name => $shape) {
        $icons[$name] = '<svg class="ui-icon ui-icon-' . $name . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $shape . '</svg>';
    }

    return $icons;
}

function admin_page_start(string $title, string $page = ''): void
{
    $safeTitle = h($title);
    $safePage = h($page);
    $isAuthenticated = current_admin() !== null;
    $layoutClass = $isAuthenticated ? 'has-sidebar' : '';
    $sidebarIcons = $isAuthenticated ? ui_icons() : [];
    $homePath = current_admin() !== null ? 'admin-dashboard.php' : 'dashboard.php';
    $homeLabel = current_admin() !== null
        ? 'Ala-Eh Nasaan Na? Admin dashboard'
        : 'Ala-Eh Nasaan Na? Driver dashboard';
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
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>{$safeTitle} | Ala-Eh Nasaan Na? Jeepney Locator</title>
  <meta name="theme-color" content="#f5f8ff">
  <link rel="icon" type="image/png" href="assets/admin/ala-eh-logo.png">
  <link rel="apple-touch-icon" href="assets/admin/ala-eh-logo.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/admin/styles.css?v=20261109-responsive">
  <script src="assets/admin/password-toggle.js" defer></script>
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
    render_toast_system();
    
    if ($isAuthenticated) {
        // Sidebar layout for authenticated users
        echo <<<HTML
<div class="app-layout">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <a class="sidebar-brand" href="{$homePath}" aria-label="{$homeLabel}">
        <img class="sidebar-brand-mark" src="assets/admin/ala-eh-logo.png" alt="" aria-hidden="true">
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
                ? '<a href="admin-users.php" class="sidebar-link"><span class="sidebar-link-icon">' . $sidebarIcons['users'] . '</span><span class="sidebar-link-text">Users & admins</span></a><a href="admin-settings.php" class="sidebar-link"><span class="sidebar-link-icon">' . $sidebarIcons['settings'] . '</span><span class="sidebar-link-text">Settings & activity</span></a>'
                : '';
            $mapPinsLink = ($driver['role'] ?? '') !== 'viewer'
                ? '<a href="admin-locations.php" class="sidebar-link"><span class="sidebar-link-icon">' . $sidebarIcons['map'] . '</span><span class="sidebar-link-text">Commuter map pins</span></a>'
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
          <span class="sidebar-link-icon">{$sidebarIcons['overview']}</span>
          <span class="sidebar-link-text">Overview</span>
        </a>
        <a href="admin-drivers.php" class="sidebar-link">
          <span class="sidebar-link-icon">{$sidebarIcons['drivers']}</span>
          <span class="sidebar-link-text">Drivers</span>
        </a>
        <a href="admin-jeepneys.php" class="sidebar-link">
          <span class="sidebar-link-icon">{$sidebarIcons['jeepney']}</span>
          <span class="sidebar-link-text">Jeepneys</span>
        </a>
        <a href="admin-routes.php" class="sidebar-link">
          <span class="sidebar-link-icon">{$sidebarIcons['routes']}</span>
          <span class="sidebar-link-text">Terminals & routes</span>
        </a>
        <a href="admin-trips.php" class="sidebar-link">
          <span class="sidebar-link-icon">{$sidebarIcons['map']}</span>
          <span class="sidebar-link-text">Trips & live map</span>
        </a>
        <a href="admin-reports.php" class="sidebar-link">
          <span class="sidebar-link-icon">{$sidebarIcons['reports']}</span>
          <span class="sidebar-link-text">Reports</span>
        </a>
        {$mapPinsLink}
        {$ownerLinks}
      </div>
      <div class="sidebar-footer">
        <button class="sidebar-theme-toggle" type="button" data-theme-toggle aria-pressed="false">
          <span class="sidebar-link-icon" data-theme-icon aria-hidden="true">{$sidebarIcons['moon']}{$sidebarIcons['sun']}</span>
          <span class="sidebar-link-text" data-theme-label>Dark mode</span>
        </button>
        <form method="post" action="admin-logout.php">
HTML;
        echo csrf_field();
        echo <<<HTML
          <button class="sidebar-logout" type="submit">
            <span class="sidebar-link-icon">{$sidebarIcons['logout']}</span>
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
        <a href="dashboard.php" class="sidebar-link">
          <span class="sidebar-link-icon">{$sidebarIcons['home']}</span>
          <span class="sidebar-link-text">Home</span>
        </a>
        <a href="dashboard.php" class="sidebar-link">
          <span class="sidebar-link-icon">{$sidebarIcons['application']}</span>
          <span class="sidebar-link-text">My application</span>
        </a>
        <a href="driver-trip.php" class="sidebar-link">
          <span class="sidebar-link-icon">{$sidebarIcons['tracking']}</span>
          <span class="sidebar-link-text">Trip tracking</span>
        </a>
        <a href="jeepney-profile.php" class="sidebar-link">
          <span class="sidebar-link-icon">{$sidebarIcons['jeepney']}</span>
          <span class="sidebar-link-text">My jeepney</span>
        </a>
        <a href="driver-documents.php" class="sidebar-link">
          <span class="sidebar-link-icon">{$sidebarIcons['documents']}</span>
          <span class="sidebar-link-text">My documents</span>
        </a>
      </div>
      <div class="sidebar-footer">
        <button class="sidebar-theme-toggle" type="button" data-theme-toggle aria-pressed="false">
          <span class="sidebar-link-icon" data-theme-icon aria-hidden="true">{$sidebarIcons['moon']}{$sidebarIcons['sun']}</span>
          <span class="sidebar-link-text" data-theme-label>Dark mode</span>
        </button>
        <form method="post" action="logout.php">
HTML;
        echo csrf_field();
        echo <<<HTML
          <button class="sidebar-logout" type="submit">
            <span class="sidebar-link-icon">{$sidebarIcons['logout']}</span>
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
      <button class="mobile-menu-toggle" id="mobile-toggle" aria-label="Open menu" aria-controls="sidebar" aria-expanded="false">
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
    <a class="brand" href="admin-login.php" aria-label="Ala-Eh Nasaan Na? Admin sign in">
      <img class="brand-mark" src="assets/admin/ala-eh-logo.png" alt="" aria-hidden="true">
      <span class="brand-name">Ala-Eh Nasaan Na?<span>Jeepney Locator</span></span>
    </a>
    <nav class="site-nav" aria-label="Main navigation">
      <a href="admin-login.php">Admin sign in</a>
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
      <span>JPEG, PNG, or WebP  -  up to 5 MB  -  maximum 16 megapixels</span>
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

function admin_page_end(): void
{
    $isAuthenticated = current_admin() !== null;
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
      window.showToast('Choose a JPEG, PNG, or WebP image smaller than 5 MB.', 'error');
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
      window.showToast(result.message, 'success');
    } catch (error) {
      const message = error instanceof Error ? error.message : 'The profile photo could not be updated.';
      profilePhotoFeedback.textContent = message;
      window.showToast(message, 'error');
    } finally {
      profilePhotoSave.disabled = false;
      if (profilePhotoRemove) profilePhotoRemove.disabled = false;
    }
  });
}

themeToggles.forEach((button) => {
  const label = button.querySelector('[data-theme-label]');
  button.setAttribute('aria-pressed', String(themeIsDark));
  button.setAttribute('aria-label', themeIsDark ? 'Switch to light mode' : 'Switch to dark mode');
  if (label) label.textContent = themeIsDark ? 'Light mode' : 'Dark mode';

  button.addEventListener('click', () => {
    const enableDarkMode = !document.documentElement.classList.contains('dark-mode');
    document.documentElement.classList.toggle('dark-mode', enableDarkMode);
    button.setAttribute('aria-pressed', String(enableDarkMode));
    button.setAttribute('aria-label', enableDarkMode ? 'Switch to light mode' : 'Switch to dark mode');
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
    mobileToggle.setAttribute('aria-expanded', String(sidebar.classList.contains('mobile-open')));
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
      mobileToggle.setAttribute('aria-expanded', 'false');
    }
  }
});

// Active link highlighting
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && sidebar.classList.contains('mobile-open')) {
    sidebar.classList.remove('mobile-open');
    mobileToggle.setAttribute('aria-expanded', 'false');
    mobileToggle.focus();
  }
});
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
    <a class="footer-brand" href="admin-login.php" aria-label="Ala-Eh Nasaan Na? Admin sign in">
      <img class="footer-brand-mark" src="assets/admin/ala-eh-logo.png" alt="" aria-hidden="true">
      <span>Ala-Eh Nasaan Na?<small>Jeepney Locator</small></span>
    </a>
    <span>Built for the road ahead.</span>
  </div>
</footer>
</body>
</html>
HTML;
    }
}
