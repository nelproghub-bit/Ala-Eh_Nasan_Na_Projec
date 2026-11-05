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
    $rows = $exportQuery->fetchAll(PDO::FETCH_NUM);
    $format = $_GET['format'] ?? 'csv';
    if ($format === 'pdf') {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('PDF reports require installed Composer dependencies. Run composer install.');
        }
        require_once $autoload;

        $reportTitles = [
            'trips' => 'Trip Report',
            'vehicles' => 'Jeepney Report',
            'drivers' => 'Driver Report',
        ];
        $title = $reportTitles[$export];
        $tableHeaders = implode('', array_map(
            static fn (string $header): string => '<th>' . h($header) . '</th>',
            $headers
        ));
        $tableRows = '';
        foreach ($rows as $row) {
            $tableRows .= '<tr>' . implode('', array_map(
                static fn (mixed $value): string => '<td>' . h((string) ($value ?? '—')) . '</td>',
                $row
            )) . '</tr>';
        }
        if ($tableRows === '') {
            $tableRows = '<tr><td class="empty" colspan="' . count($headers) . '">No records found for this report.</td></tr>';
        }
        $period = $export === 'trips'
            ? '<p class="period">Trip start date: ' . h($from) . ' through ' . h($to) . ' (inclusive)</p>'
            : '';
        $html = '<!doctype html><html lang="en"><head><meta charset="UTF-8"><style>
            @page { margin: 34px 30px; }
            body { color: #172033; font-family: "DejaVu Sans", sans-serif; font-size: 9px; }
            .brand { color: #2563eb; font-size: 9px; font-weight: bold; letter-spacing: 1.4px; text-transform: uppercase; }
            h1 { margin: 8px 0 4px; color: #102a56; font-size: 22px; }
            .meta, .period { margin: 0 0 5px; color: #64748b; font-size: 9px; }
            .period { margin-bottom: 15px; }
            table { width: 100%; border-collapse: collapse; }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; }
            th { padding: 8px 6px; border: 1px solid #dbe3ee; color: #fff; background: #173b70; text-align: left; font-size: 8px; }
            td { padding: 7px 6px; border: 1px solid #dbe3ee; vertical-align: top; word-wrap: break-word; }
            tr:nth-child(even) td { background: #f3f6fa; }
            .empty { padding: 18px; color: #64748b; text-align: center; }
            .footer { margin-top: 12px; color: #64748b; font-size: 8px; }
        </style></head><body>
            <div class="brand">Ala-Eh Nasaan Na? · Administration</div>
            <h1>' . h($title) . '</h1>
            <p class="meta">Generated ' . h(date('F j, Y g:i A')) . ' · ' . count($rows) . ' record' . (count($rows) === 1 ? '' : 's') . '</p>
            ' . $period . '
            <table><thead><tr>' . $tableHeaders . '</tr></thead><tbody>' . $tableRows . '</tbody></table>
            <p class="footer">Confidential administrative report. Handle and share responsibly.</p>
        </body></html>';

        $pdf = new \Dompdf\Dompdf([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
        ]);
        $pdf->setPaper('A4', 'landscape');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        $filename = 'ala-eh-' . $export . '-report.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo $pdf->output();
        exit;
    }

    if ($format !== 'csv') {
        http_response_code(400);
        exit('Unsupported report format.');
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
    foreach ($rows as $row) {
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
        COALESCE(SUM(j.verification_status = 'verified'), 0) AS verified,
        COALESCE(SUM(s.operational_status = 'active'
            AND j.verification_status = 'verified'
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
    <div class="admin-queue-heading"><div><h2>Export reports</h2><p>Preview a professionally formatted PDF, then download it or export the same records as CSV.</p></div></div>
    <div class="report-export-links">
      <button class="button button-outline" type="button" data-report-export="trips" data-report-title="Trip report" data-report-query="&amp;from=<?= h($from) ?>&amp;to=<?= h($to) ?>">Preview trip report</button>
      <button class="button button-outline" type="button" data-report-export="vehicles" data-report-title="Jeepney report">Preview jeepney report</button>
      <button class="button button-outline" type="button" data-report-export="drivers" data-report-title="Driver report">Preview driver report</button>
    </div>
  </section>
  <dialog class="admin-manage-dialog report-preview-dialog" id="report-preview-dialog" aria-labelledby="report-preview-title">
    <div class="admin-manage-dialog-header">
      <div>
        <span class="eyebrow"><span class="eyebrow-dot"></span> REPORT PREVIEW</span>
        <h2 id="report-preview-title">PDF report</h2>
        <p id="report-preview-status" role="status" aria-live="polite">Preparing your report…</p>
      </div>
      <button class="admin-manage-dialog-close" type="button" data-report-close aria-label="Close report preview">×</button>
    </div>
    <div class="report-preview-frame-wrap">
      <iframe id="report-preview-frame" title="PDF report preview" hidden></iframe>
      <div class="report-preview-loading" id="report-preview-loading">Generating a secure PDF preview…</div>
    </div>
    <div class="report-preview-actions">
      <a class="button button-outline" id="report-csv-download" href="#">Download CSV</a>
      <a class="button button-primary" id="report-pdf-download" href="#" download>Download PDF</a>
    </div>
  </dialog>
  <script>
  (() => {
    const dialog = document.getElementById('report-preview-dialog');
    const title = document.getElementById('report-preview-title');
    const status = document.getElementById('report-preview-status');
    const frame = document.getElementById('report-preview-frame');
    const loading = document.getElementById('report-preview-loading');
    const pdfDownload = document.getElementById('report-pdf-download');
    const csvDownload = document.getElementById('report-csv-download');
    let previewUrl = null;
    let request = null;

    const clearPreview = () => {
      request?.abort();
      request = null;
      frame.removeAttribute('src');
      frame.hidden = true;
      loading.hidden = false;
      loading.textContent = 'Generating a secure PDF preview…';
      if (previewUrl) {
        URL.revokeObjectURL(previewUrl);
        previewUrl = null;
      }
      pdfDownload.removeAttribute('href');
      csvDownload.removeAttribute('href');
    };

    document.querySelectorAll('[data-report-export]').forEach((button) => {
      button.addEventListener('click', async () => {
        clearPreview();
        title.textContent = button.dataset.reportTitle || 'PDF report';
        status.textContent = 'Preparing your report…';
        dialog.showModal();
        request = new AbortController();
        const query = button.dataset.reportQuery || '';
        const baseUrl = `admin-reports.php?export=${encodeURIComponent(button.dataset.reportExport)}${query.replaceAll('&amp;', '&')}`;
        csvDownload.href = `${baseUrl}&format=csv`;

        try {
          const response = await fetch(`${baseUrl}&format=pdf`, {
            headers: {'Accept': 'application/pdf'},
            cache: 'no-store',
            signal: request.signal
          });
          if (!response.ok || !response.headers.get('content-type')?.includes('application/pdf')) {
            throw new Error('The PDF could not be generated. Try again or download the CSV report.');
          }
          const pdf = await response.blob();
          previewUrl = URL.createObjectURL(pdf);
          frame.src = previewUrl;
          frame.hidden = false;
          loading.hidden = true;
          pdfDownload.href = previewUrl;
          pdfDownload.download = `ala-eh-${button.dataset.reportExport}-report.pdf`;
          status.textContent = 'Preview ready. Download the PDF or export the data as CSV.';
        } catch (error) {
          if (error.name !== 'AbortError') {
            status.textContent = error instanceof Error ? error.message : 'The report preview is unavailable.';
            loading.textContent = status.textContent;
          }
        }
      });
    });

    document.querySelector('[data-report-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('cancel', () => request?.abort());
    dialog.addEventListener('close', clearPreview);
  })();
  </script>
  <section class="admin-queue">
    <div class="admin-queue-heading"><div><h2>Daily trip activity</h2><p>Counts include only trips started in the selected period.</p></div></div>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Date</th><th>Total</th><th>Completed</th><th>Cancelled</th></tr></thead><tbody>
      <?php foreach ($dailyTrips as $day): ?><tr><td data-label="Date"><?= h((string) $day['trip_day']) ?></td><td data-label="Total"><?= h((string) $day['total']) ?></td><td data-label="Completed"><?= h((string) $day['completed']) ?></td><td data-label="Cancelled"><?= h((string) $day['cancelled']) ?></td></tr><?php endforeach; ?>
      <?php if ($dailyTrips === []): ?><tr><td colspan="4">No trips were recorded in this period.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
</section>
<?php page_end(); ?>
