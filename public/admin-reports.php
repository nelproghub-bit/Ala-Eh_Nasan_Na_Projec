<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();
$pdo = db();

$today = new DateTimeImmutable('today');
$from = $_GET['from'] ?? $today->modify('-29 days')->format('Y-m-d');
$to = $_GET['to'] ?? $today->format('Y-m-d');
$validDate = static function (mixed $value): bool {
    if (!is_string($value)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};
if (!$validDate($from) || !$validDate($to) || $from > $to) {
    http_response_code(400);
    exit('Select a valid date range.');
}

$export = $_GET['export'] ?? '';
if (in_array($export, ['trips', 'vehicles', 'drivers'], true)) {
    if ($export === 'trips') {
        $exportQuery = $pdo->prepare(
            'SELECT t.id, t.trip_status, t.started_at, t.ended_at, d.full_name,
                    j.plate_number, r.route_name
             FROM trips t
             INNER JOIN drivers d ON d.id = t.driver_id
             INNER JOIN jeepney_profiles j ON j.id = t.jeepney_profile_id
             INNER JOIN routes r ON r.id = t.route_id
             WHERE t.started_at >= :from_date AND t.started_at < DATE_ADD(:to_date, INTERVAL 1 DAY)
             ORDER BY t.started_at DESC'
        );
        $exportQuery->execute(['from_date' => $from, 'to_date' => $to]);
        $headers = ['Trip ID', 'Status', 'Started', 'Ended', 'Driver', 'Plate', 'Route'];
    } elseif ($export === 'vehicles') {
        $exportQuery = $pdo->query(
            "SELECT j.id, j.plate_number, j.capacity, j.route_assignment,
                    j.verification_status, COALESCE(s.operational_status, 'inactive'),
                    d.full_name
             FROM jeepney_profiles j
             INNER JOIN drivers d ON d.id = j.driver_id
             LEFT JOIN jeepney_admin_status s ON s.jeepney_profile_id = j.id
             ORDER BY j.id"
        );
        $headers = ['Jeepney ID', 'Plate', 'Capacity', 'Route', 'Verification', 'Operational status', 'Driver'];
    } else {
        $exportQuery = $pdo->query(
            "SELECT d.id, d.full_name, d.phone, d.license_number,
                    d.verification_status, COALESCE(s.account_status, 'active'), d.created_at
             FROM drivers d LEFT JOIN driver_admin_status s ON s.driver_id = d.id
             ORDER BY d.created_at DESC"
        );
        $headers = ['Driver ID', 'Full name', 'Phone', 'License number', 'Verification', 'Account status', 'Registered'];
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ala-eh-' . $export . '-report.csv"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Could not generate the requested report.');
    }
    fputcsv($output, $headers);
    foreach ($exportQuery->fetchAll(PDO::FETCH_NUM) as $row) {
        $safeRow = array_map(
            static function (mixed $value): string {
                $cell = (string) ($value ?? '');
                return preg_match('/^[\s]*[=+\-@]/', $cell) === 1 ? "'" . $cell : $cell;
            },
            $row
        );
        fputcsv($output, $safeRow);
    }
    fclose($output);
    exit;
}

$tripQuery = $pdo->prepare(
    "SELECT COUNT(*) AS total,
        COALESCE(SUM(trip_status = 'active'), 0) AS active,
        COALESCE(SUM(trip_status = 'completed'), 0) AS completed,
        COALESCE(SUM(trip_status = 'cancelled'), 0) AS cancelled
     FROM trips WHERE started_at >= :from_date AND started_at < DATE_ADD(:to_date, INTERVAL 1 DAY)"
);
$tripQuery->execute(['from_date' => $from, 'to_date' => $to]);
$tripCounts = $tripQuery->fetch();
$vehicleCounts = $pdo->query(
    "SELECT COUNT(*) AS registered,
        COALESCE(SUM(verification_status = 'verified'), 0) AS verified,
        COALESCE(SUM(s.operational_status = 'active'
            AND d.verification_status = 'verified'
            AND COALESCE(ds.account_status, 'active') = 'active'), 0) AS active
     FROM jeepney_profiles j
    INNER JOIN drivers d ON d.id = j.driver_id
    LEFT JOIN jeepney_admin_status s ON s.jeepney_profile_id = j.id
    LEFT JOIN driver_admin_status ds ON ds.driver_id = d.id"
)->fetch();
$driverCounts = $pdo->query(
    "SELECT COUNT(*) AS registered,
        COALESCE(SUM(d.verification_status = 'verified'), 0) AS verified,
        COALESCE(SUM(d.verification_status = 'verified' AND COALESCE(s.account_status, 'active') = 'active'), 0) AS active
     FROM drivers d LEFT JOIN driver_admin_status s ON s.driver_id = d.id"
)->fetch();
$dailyQuery = $pdo->prepare(
    'SELECT DATE(started_at) AS trip_day, COUNT(*) AS total,
            SUM(trip_status = \'completed\') AS completed,
            SUM(trip_status = \'cancelled\') AS cancelled
     FROM trips
     WHERE started_at >= :from_date AND started_at < DATE_ADD(:to_date, INTERVAL 1 DAY)
     GROUP BY DATE(started_at) ORDER BY trip_day DESC LIMIT 100'
);
$dailyQuery->execute(['from_date' => $from, 'to_date' => $to]);
$dailyTrips = $dailyQuery->fetchAll();
$usageQuery = $pdo->prepare(
    "SELECT
        (SELECT COUNT(*) FROM activity_logs
         WHERE created_at >= :from_activity AND created_at < DATE_ADD(:to_activity, INTERVAL 1 DAY)) AS admin_actions,
        (SELECT COUNT(*) FROM trip_locations
         WHERE recorded_at >= :from_locations AND recorded_at < DATE_ADD(:to_locations, INTERVAL 1 DAY)) AS location_updates,
        (SELECT COUNT(*) FROM commuters) AS commuter_records"
);
$usageQuery->execute([
    'from_activity' => $from, 'to_activity' => $to,
    'from_locations' => $from, 'to_locations' => $to,
]);
$usage = $usageQuery->fetch();

page_start('Reports and analytics', 'admin-reports');
?>
<section class="admin-dashboard">
  <div class="admin-page-heading"><div><span class="eyebrow"><span class="eyebrow-dot"></span> REPORTS & ANALYTICS</span><h1>System reports</h1><p>Summaries use records currently stored in the shared database; GPS totals reflect submitted location points only.</p></div><span class="admin-access-badge"><span aria-hidden="true">📈</span> Reporting period</span></div>
  <section class="admin-queue">
    <div class="admin-queue-heading"><div><h2>Choose date range</h2><p>Trip reports use the trip start date, inclusively.</p></div></div>
    <form method="get" class="admin-form-grid" action="admin-reports.php">
      <label>From<input type="date" name="from" required value="<?= h($from) ?>"></label>
      <label>To<input type="date" name="to" required value="<?= h($to) ?>"></label>
      <button class="button button-primary" type="submit">Apply range</button>
    </form>
  </section>
  <div class="admin-stats">
    <article class="admin-stat"><span class="admin-stat-icon">🚌</span><span class="admin-stat-label">Registered jeepneys</span><strong><?= h((string) $vehicleCounts['registered']) ?></strong><small><?= h((string) $vehicleCounts['active']) ?> operational</small></article>
    <article class="admin-stat"><span class="admin-stat-icon">👤</span><span class="admin-stat-label">Active drivers</span><strong><?= h((string) $driverCounts['active']) ?></strong><small><?= h((string) $driverCounts['verified']) ?> verified</small></article>
    <article class="admin-stat"><span class="admin-stat-icon">🧭</span><span class="admin-stat-label">Trips in range</span><strong><?= h((string) $tripCounts['total']) ?></strong><small><?= h((string) $tripCounts['completed']) ?> completed · <?= h((string) $tripCounts['cancelled']) ?> cancelled</small></article>
    <article class="admin-stat"><span class="admin-stat-icon">📡</span><span class="admin-stat-label">Ongoing trips</span><strong><?= h((string) $tripCounts['active']) ?></strong><small>Within selected range</small></article>
  </div>
  <section class="admin-queue">
    <div class="admin-queue-heading"><div><h2>System usage</h2><p>Counts for the selected period, except total commuter records.</p></div></div>
    <div class="admin-stats">
      <article class="admin-stat"><span class="admin-stat-icon">⚙️</span><span class="admin-stat-label">Admin actions</span><strong><?= h((string) $usage['admin_actions']) ?></strong><small>Logged during range</small></article>
      <article class="admin-stat"><span class="admin-stat-icon">📡</span><span class="admin-stat-label">GPS updates</span><strong><?= h((string) $usage['location_updates']) ?></strong><small>Submitted during range</small></article>
      <article class="admin-stat"><span class="admin-stat-icon">🧑</span><span class="admin-stat-label">Commuter records</span><strong><?= h((string) $usage['commuter_records']) ?></strong><small>No commuter login in current app</small></article>
    </div>
  </section>
  <section class="admin-queue">
    <div class="admin-queue-heading"><div><h2>Export reports</h2><p>CSV exports are generated on demand and protected by administrator authentication.</p></div></div>
    <div class="report-export-links">
      <a class="button button-outline" href="admin-reports.php?from=<?= h($from) ?>&amp;to=<?= h($to) ?>&amp;export=trips">Download trip report</a>
      <a class="button button-outline" href="admin-reports.php?export=vehicles">Download jeepney report</a>
      <a class="button button-outline" href="admin-reports.php?export=drivers">Download driver report</a>
    </div>
  </section>
  <section class="admin-queue">
    <div class="admin-queue-heading"><div><h2>Daily trip activity</h2><p>Counts include only trips started in the selected period.</p></div></div>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Date</th><th>Total</th><th>Completed</th><th>Cancelled</th></tr></thead><tbody>
      <?php foreach ($dailyTrips as $day): ?><tr><td data-label="Date"><?= h((string) $day['trip_day']) ?></td><td data-label="Total"><?= h((string) $day['total']) ?></td><td data-label="Completed"><?= h((string) $day['completed']) ?></td><td data-label="Cancelled"><?= h((string) $day['cancelled']) ?></td></tr><?php endforeach; ?>
      <?php if ($dailyTrips === []): ?><tr><td colspan="4">No trips were recorded in this period.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
</section>
<?php page_end(); ?>
