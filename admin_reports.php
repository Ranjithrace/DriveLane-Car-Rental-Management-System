<?php
session_start();
require_once __DIR__ . '/database.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$roleStmt = mysqli_prepare($dbc, 'SELECT username, role FROM users WHERE id = ?');
mysqli_stmt_bind_param($roleStmt, 'i', $userId);
mysqli_stmt_execute($roleStmt);
$admin = mysqli_fetch_assoc(mysqli_stmt_get_result($roleStmt));
mysqli_stmt_close($roleStmt);

if (!$admin || $admin['role'] !== 'admin') {
    http_response_code(403);
    exit('Access denied. Administrators only.');
}

function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function validDate(string $value): bool {
    if ($value === '') {
        return true;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

/** Execute a prepared aggregate query without changing the database. */
function reportRows(mysqli $db, string $query, array $parameters): array {
    $stmt = mysqli_prepare($db, $query);
    if (!$stmt) {
        throw new RuntimeException('Could not prepare a reporting query.');
    }
    try {
        // PHP 8.2 supports the parameter array on mysqli_stmt_execute().
        if (!mysqli_stmt_execute($stmt, $parameters)) {
            throw new RuntimeException('Could not run a reporting query.');
        }
        $result = mysqli_stmt_get_result($stmt);
        if (!$result) {
            throw new RuntimeException('Could not read reporting results.');
        }
        return mysqli_fetch_all($result, MYSQLI_ASSOC);
    } finally {
        mysqli_stmt_close($stmt);
    }
}

$from = isset($_GET['from']) && is_string($_GET['from']) ? trim($_GET['from']) : '';
$to = isset($_GET['to']) && is_string($_GET['to']) ? trim($_GET['to']) : '';
$filterError = '';
if (!validDate($from) || !validDate($to)) {
    $filterError = 'Enter valid dates in YYYY-MM-DD format.';
} elseif ($from !== '' && $to !== '' && $from > $to) {
    $filterError = 'The From date cannot be after the To date.';
}

$conditions = [];
$params = [];
$joinConditions = [];
if ($filterError === '') {
    if ($from !== '') {
        $conditions[] = 'b.start_date >= ?';
        $joinConditions[] = 'b.start_date >= ?';
        $params[] = $from;
    }
    if ($to !== '') {
        $conditions[] = 'b.start_date <= ?';
        $joinConditions[] = 'b.start_date <= ?';
        $params[] = $to;
    }
}
$whereSql = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$joinSql = $joinConditions ? ' AND ' . implode(' AND ', $joinConditions) : '';

try {
    $summary = reportRows($dbc,
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(b.status = 'pending'), 0) AS pending,
                COALESCE(SUM(b.status = 'approved'), 0) AS approved,
                COALESCE(SUM(b.status = 'rejected'), 0) AS rejected,
                COALESCE(SUM(b.status = 'cancelled'), 0) AS cancelled,
                COALESCE(SUM(b.status = 'collected'), 0) AS collected,
                COALESCE(SUM(b.status = 'returned'), 0) AS returned,
                COALESCE(SUM(CASE WHEN b.status = 'returned' THEN b.total_price ELSE 0 END), 0) AS completed_value
         FROM bookings b" . $whereSql, $params)[0];

    $months = reportRows($dbc,
        "SELECT DATE_FORMAT(b.start_date, '%Y-%m') AS rental_month,
                COUNT(*) AS booking_count,
                COALESCE(SUM(b.status = 'returned'), 0) AS returned_count,
                COALESCE(SUM(CASE WHEN b.status = 'returned' THEN b.total_price ELSE 0 END), 0) AS completed_value
         FROM bookings b" . $whereSql . "
         GROUP BY YEAR(b.start_date), MONTH(b.start_date)
         ORDER BY YEAR(b.start_date) DESC, MONTH(b.start_date) DESC
         LIMIT 12", $params);
    $months = array_reverse($months);

    $popular = reportRows($dbc,
        "SELECT c.id, c.brand, c.model, c.registration_number,
                COUNT(b.id) AS booking_count,
                COALESCE(SUM(b.status = 'returned'), 0) AS returned_count,
                COALESCE(SUM(CASE WHEN b.status = 'returned' THEN b.total_price ELSE 0 END), 0) AS completed_value
         FROM cars c
         LEFT JOIN bookings b ON b.car_id = c.id" . $joinSql . "
         GROUP BY c.id, c.brand, c.model, c.registration_number
         HAVING COUNT(b.id) > 0
         ORDER BY booking_count DESC, returned_count DESC, c.id ASC
         LIMIT 8", $params);
} catch (Throwable $exception) {
    error_log('DriveLane admin report: ' . $exception->getMessage());
    http_response_code(500);
    exit('Unable to generate reports. Please try again.');
}

$statuses = [
    'pending' => 'Pending', 'approved' => 'Approved', 'collected' => 'Collected',
    'returned' => 'Returned', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'
];
$total = (int) $summary['total'];
$completedValue = (float) $summary['completed_value'];
$maxMonthCount = max(1, ...array_map(static fn($m) => (int) $m['booking_count'], $months));
$maxCarCount = max(1, ...array_map(static fn($c) => (int) $c['booking_count'], $popular));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reports &amp; Statistics | DriveLane Administrator</title>
<style>
* {box-sizing:border-box}
body {margin:0;background:#f4f7fb;color:#112846;font-family:Inter,'Segoe UI',Arial,sans-serif}
.rep-wrap {width:min(1230px,calc(100% - 36px));margin:0 auto;padding:37px 0 75px}
.rep-back {font-weight:700;color:#1769e0;text-decoration:none;font-size:13px}
.rep-title {font-size:clamp(29px,4vw,42px);letter-spacing:-1.2px;margin:16px 0 8px}
.rep-muted {color:#62748b;line-height:1.6;font-size:14px}
.rep-panel {background:#fff;border:1px solid #dfe7f1;border-radius:17px;padding:24px;margin-top:22px}
.rep-panel h2 {font-size:21px;margin:0 0 18px}
.rep-filter {display:flex;align-items:end;gap:14px;flex-wrap:wrap}
.rep-field {flex:1;min-width:190px;max-width:280px}
.rep-field label {display:block;font-size:13px;font-weight:700;margin-bottom:8px}
.rep-field input {width:100%;min-height:44px;border:1px solid #cbd8e8;border-radius:9px;padding:10px 12px;font-size:14px}
.rep-btn,.rep-reset {display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:10px 17px;border-radius:9px;text-decoration:none;font-size:14px;font-weight:700}
.rep-btn {background:#1769e0;color:#fff;border:0;cursor:pointer}
.rep-reset {background:#eaf1fb;color:#1454a6}
.rep-export-actions {display:flex;flex-wrap:wrap;gap:10px;margin:16px 0}
.rep-error {margin:12px 0;padding:13px 16px;border-radius:10px;background:#fff0ef;color:#a3212c;font-weight:700}
.rep-cards {display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px;margin-top:22px}
.rep-card {background:white;border:1px solid #dfe7f1;border-radius:16px;padding:23px}
.rep-card small {display:block;color:#61728a;font-size:13px}
.rep-card strong {display:block;font-size:30px;margin-top:10px;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}
.rep-grid {display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start}
.rep-bar-row {margin:0 0 15px}
.rep-bar-top {display:flex;justify-content:space-between;gap:12px;font-size:14px;margin-bottom:8px}
.rep-bar-track {background:#e9eff7;border-radius:99px;height:12px;overflow:hidden}
.rep-bar-fill {background:#1769e0;border-radius:99px;height:100%;min-width:0}
.rep-table-wrap {overflow-x:auto}
.rep-table {border-collapse:collapse;width:100%;min-width:560px;font-size:14px}
.rep-table th {background:#eef3f9;text-align:left;font-size:12px;color:#45617f}
.rep-table td,.rep-table th {padding:14px 13px;border-top:1px solid #e6edf5}
.rep-table td:nth-child(n+2) {font-variant-numeric:tabular-nums}
.rep-empty {padding:18px 0;color:#61728a}
.rep-note {background:#eaf1fb;border-radius:13px;padding:17px 19px;color:#3a5575;line-height:1.65;font-size:13px;margin-top:20px}
@media(max-width:930px){.rep-cards {grid-template-columns:repeat(2,minmax(0,1fr))}.rep-grid{grid-template-columns:1fr}}
@media(max-width:520px){.rep-cards{gap:10px}.rep-card{padding:17px}.rep-card strong{font-size:25px}.rep-panel{padding:18px}}
</style>
</head>
<body>
<?php require __DIR__ . '/header.php'; ?>
<main class="rep-wrap">
  <a href="admin_dashboard.php" class="rep-back">&larr; Back to Administrator Dashboard</a>
  <h1 class="rep-title">Reports &amp; Statistics</h1>
  <p class="rep-muted">Review rental requests, completion values and the most requested vehicles. All figures come from the current DriveLane database.</p>

  <section class="rep-panel" aria-label="Reporting filters">
    <h2>Report period</h2>
    <form class="rep-filter" method="get" action="admin_reports.php">
      <div class="rep-field"><label for="from">Rental starts from</label><input id="from" type="date" name="from" value="<?= e($from) ?>"></div>
      <div class="rep-field"><label for="to">Rental starts to</label><input id="to" type="date" name="to" value="<?= e($to) ?>"></div>
      <button class="rep-btn" type="submit">Apply period</button>
      <a class="rep-reset" href="admin_reports.php">All dates</a>
    </form>
    <?php if ($filterError !== ''): ?><p class="rep-error" role="alert"><?= e($filterError) ?> Filters were not applied.</p><?php endif; ?>
    <p class="rep-muted">Date filters use each booking's <strong>rental start date</strong>, including pending and rejected requests.</p>
  </section>

  <section class="rep-panel" aria-label="Download CSV reports">
    <h2>Export data to CSV</h2>
    <p class="rep-muted">Download current records for analysis in Excel. Bookings and activity exports follow the rental-start filters above; Users and Cars include all records.</p>
    <?php $exportDates = http_build_query(['from' => $filterError === '' ? $from : '', 'to' => $filterError === '' ? $to : '']); ?>
    <div class="rep-export-actions">
      <a class="rep-btn" href="admin_export.php?type=bookings&amp;<?= e($exportDates) ?>">Export Bookings</a>
      <a class="rep-btn" href="admin_export.php?type=activity&amp;<?= e($exportDates) ?>">Export Activity</a>
      <a class="rep-reset" href="admin_export.php?type=users">Export Users</a>
      <a class="rep-reset" href="admin_export.php?type=cars">Export Cars</a>
    </div>
    <p class="rep-muted"><strong>Privacy:</strong> User exports contain email addresses, so keep downloaded CSV files private. Passwords and password hashes are never exported.</p>
  </section>

  <div class="rep-cards">
    <div class="rep-card"><small>Total booking requests</small><strong><?= number_format($total) ?></strong></div>
    <div class="rep-card"><small>Pending requests</small><strong><?= number_format((int)$summary['pending']) ?></strong></div>
    <div class="rep-card"><small>Returned rentals</small><strong><?= number_format((int)$summary['returned']) ?></strong></div>
    <div class="rep-card"><small>Value of returned rentals</small><strong>€<?= number_format($completedValue,2) ?></strong></div>
  </div>

  <div class="rep-grid">
    <section class="rep-panel">
      <h2>Booking status breakdown</h2>
      <?php foreach ($statuses as $key => $label): ?>
        <?php $count = (int)$summary[$key]; $percent = $total ? ($count / $total) * 100 : 0; ?>
        <div class="rep-bar-row"><div class="rep-bar-top"><span><?= e($label) ?></span><strong><?= number_format($count) ?> (<?= number_format($percent,1) ?>%)</strong></div>
          <div class="rep-bar-track" role="img" aria-label="<?= e($label . ': ' . number_format($count) . ' bookings') ?>"><div class="rep-bar-fill" style="width:<?= number_format($percent,2,'.','') ?>%"></div></div>
        </div>
      <?php endforeach; ?>
    </section>
    <section class="rep-panel">
      <h2>Rental start trends</h2>
      <?php if (!$months): ?><p class="rep-empty">No bookings in this period.</p><?php endif; ?>
      <?php foreach ($months as $month): ?>
        <?php $barPercent = ((int)$month['booking_count'] / $maxMonthCount)*100; ?>
        <div class="rep-bar-row"><div class="rep-bar-top"><span><?= e($month['rental_month']) ?></span><strong><?= (int)$month['booking_count'] ?> requests</strong></div>
          <div class="rep-bar-track"><div class="rep-bar-fill" style="width:<?= number_format($barPercent,2,'.','') ?>%"></div></div>
        </div>
      <?php endforeach; ?>
      <p class="rep-muted">Shows up to the latest 12 months represented in the selected data.</p>
    </section>
  </div>

  <section class="rep-panel">
    <h2>Most requested cars</h2>
    <?php if (!$popular): ?><p class="rep-empty">No vehicle requests for the selected period.</p><?php else: ?>
      <div class="rep-table-wrap"><table class="rep-table">
        <thead><tr><th>Vehicle</th><th>Registration</th><th>Requests</th><th>Returned</th><th>Returned rental value</th></tr></thead>
        <tbody><?php foreach ($popular as $car): ?>
          <tr><td><strong><?= e($car['brand'] . ' ' . $car['model']) ?></strong></td><td><?= e($car['registration_number']) ?></td><td><?= number_format((int)$car['booking_count']) ?></td><td><?= number_format((int)$car['returned_count']) ?></td><td>€<?= number_format((float)$car['completed_value'],2) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
  </section>
  <p class="rep-note"><strong>Reporting definitions:</strong> “Value of returned rentals” is the sum of booked prices for rentals with status Returned. DriveLane does not currently record payments, so this is <strong>not confirmed revenue or money received</strong>. Requests include all statuses (including rejected and cancelled). Date filters use the scheduled rental start date.</p>
</main>
</body>
</html>
<?php mysqli_close($dbc); ?>
