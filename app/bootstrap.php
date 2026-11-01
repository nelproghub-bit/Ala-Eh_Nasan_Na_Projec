<?php
declare(strict_types=1);

ini_set('display_errors', '0');

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
        if (in_array($name, $allowedNames, true) && is_string($value) && getenv($name) === false) {
            putenv($name . '=' . $value);
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

$secureCookieSetting = getenv('SESSION_COOKIE_SECURE');
$secureCookieSetting = strtolower($secureCookieSetting === false ? 'true' : $secureCookieSetting);
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

function page_start(string $title, string $page = ''): void
{
    $safeTitle = h($title);
    $safePage = h($page);
    $isAuthenticated = current_driver() !== null || current_admin() !== null;
    $layoutClass = $isAuthenticated ? 'has-sidebar' : '';
    
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
            $adminRole = h(ucfirst((string) ($driver['role'] ?? 'admin')));
            $ownerLinks = ($driver['role'] ?? '') === 'owner'
                ? '<a href="admin-users.php" class="sidebar-link"><span class="sidebar-link-icon">🔐</span><span class="sidebar-link-text">Users & admins</span></a><a href="admin-settings.php" class="sidebar-link"><span class="sidebar-link-icon">⚙️</span><span class="sidebar-link-text">Settings & activity</span></a>'
                : '';
            echo <<<HTML
      <div class="sidebar-user">
        <div class="sidebar-user-avatar">A</div>
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
        <div class="sidebar-user-avatar">{$initials}</div>
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
}

function page_end(): void
{
    $isAuthenticated = current_driver() !== null || current_admin() !== null;
    
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
