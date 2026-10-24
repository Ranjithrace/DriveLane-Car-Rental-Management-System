<?php
session_start();
require 'database.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$staffId = (int) $_SESSION['user_id'];
$authStmt = mysqli_prepare($dbc, 'SELECT username, role FROM users WHERE id = ?');
mysqli_stmt_bind_param($authStmt, 'i', $staffId);
mysqli_stmt_execute($authStmt);
$staff = mysqli_fetch_assoc(mysqli_stmt_get_result($authStmt));
mysqli_stmt_close($authStmt);
if (!$staff || $staff['role'] !== 'staff') {
    http_response_code(403);
    exit('Access denied. Rental Staff only.');
}

function rsEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function rsDate($text) {
    if (!is_string($text)) { return null; }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
    return $date && $date->format('Y-m-d') === $text ? $date : null;
}

$today = new DateTimeImmutable('today');
$startRaw = $_GET['from'] ?? $today->format('Y-m-d');
$startDate = rsDate($startRaw);
$notice = '';
if ($startDate === null) {
    $startDate = $today;
    $notice = 'Invalid start date. Showing the schedule from today.';
}
$days = filter_var($_GET['days'] ?? 14, FILTER_VALIDATE_INT);
if (!in_array($days, [7, 14, 30], true)) { $days = 14; }
$lastDate = $startDate->modify('+' . ($days - 1) . ' days');
$startSql = $startDate->format('Y-m-d');
$lastSql = $lastDate->format('Y-m-d');
$selectedCar = filter_var($_GET['car_id'] ?? 0, FILTER_VALIDATE_INT);
if ($selectedCar === false || $selectedCar < 0) { $selectedCar = 0; }

$carsResult = mysqli_query($dbc,
    "SELECT id, brand, model, registration_number, status
     FROM cars ORDER BY brand, model, registration_number, id");
if (!$carsResult) {
    http_response_code(500);
    exit('Unable to load the car fleet.');
}
$allCars = mysqli_fetch_all($carsResult, MYSQLI_ASSOC);
mysqli_free_result($carsResult);
$carIds = array_fill_keys(array_map(static fn($c) => (int)$c['id'], $allCars), true);
if ($selectedCar > 0 && !isset($carIds[$selectedCar])) {
    $selectedCar = 0;
    $notice = 'Selected car was not found. Showing all vehicles.';
}
$visibleCars = array_values(array_filter($allCars,
    static fn($c) => $selectedCar === 0 || (int)$c['id'] === $selectedCar));

// A schedule means future/current confirmed reservations, not pending requests.
// Dates overlap inclusively because DriveLane charges for start and end days.
$sql = "SELECT b.id, b.car_id, b.start_date, b.end_date, b.status,
               b.total_price, u.username AS customer_name,
               c.brand, c.model, c.registration_number
        FROM bookings b
        JOIN users u ON u.id = b.customer_id
        JOIN cars c ON c.id = b.car_id
        WHERE b.status IN ('approved', 'collected')
          AND b.start_date <= ? AND b.end_date >= ?
          AND (? = 0 OR b.car_id = ?)
        ORDER BY b.start_date, b.id";
$stmt = mysqli_prepare($dbc, $sql);
mysqli_stmt_bind_param($stmt, 'ssii', $lastSql, $startSql, $selectedCar, $selectedCar);
mysqli_stmt_execute($stmt);
$reservations = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$calendarDays = [];
for ($i = 0; $i < $days; $i++) {
    $day = $startDate->modify('+' . $i . ' days');
    $calendarDays[] = ['iso' => $day->format('Y-m-d'), 'weekday' => $day->format('D'), 'number' => $day->format('j'), 'today' => $day->format('Y-m-d') === $today->format('Y-m-d')];
}
$grid = [];
foreach ($reservations as $booking) {
    $carId = (int)$booking['car_id'];
    foreach ($calendarDays as $day) {
        $date = $day['iso'];
        if ($booking['start_date'] <= $date && $booking['end_date'] >= $date) {
            $grid[$carId][$date][] = $booking;
        }
    }
}
$approved = count(array_filter($reservations, static fn($b) => $b['status'] === 'approved'));
$collected = count($reservations) - $approved;
$prev = $startDate->modify('-' . $days . ' days')->format('Y-m-d');
$next = $startDate->modify('+' . $days . ' days')->format('Y-m-d');
$urlParams = static function ($date) use ($days, $selectedCar) {
    return 'rental_schedule.php?' . http_build_query(['from' => $date, 'days' => $days, 'car_id' => $selectedCar]);
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rental Schedule | DriveLane</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f5f7fb;color:#102544;font-family:Arial,Helvetica,sans-serif}
.rs-container{width:min(1260px,calc(100% - 36px));margin:0 auto;padding:35px 0 70px}
.rs-kicker{font-size:12px;font-weight:800;letter-spacing:.15em;color:#276bdb}
.rs-top{display:flex;gap:16px;justify-content:space-between;align-items:center;flex-wrap:wrap;margin-bottom:20px}
.rs-top h1{margin:8px 0;font-size:clamp(29px,4vw,40px)}
.rs-muted{color:#64748b;font-size:14px;line-height:1.6}
.rs-btn{display:inline-block;padding:11px 16px;border-radius:10px;background:#1961db;color:#fff;text-decoration:none;font-weight:700;font-size:14px;border:0;cursor:pointer}
.rs-btn-light{background:#e9f0fe;color:#1859ba}
.rs-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:23px 0}
.rs-stat{background:#fff;border:1px solid #dce5f0;padding:19px 22px;border-radius:15px}
.rs-stat span{color:#68788f;font-size:13px;display:block;margin-bottom:10px}
.rs-stat strong{font-size:30px}
.rs-panel{background:#fff;border:1px solid #dce5f0;border-radius:15px;overflow:hidden;margin-top:18px}
.rs-panel-head{padding:20px 22px;display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap}
.rs-panel h2{font-size:21px;margin:0}
.rs-filters{display:flex;align-items:end;flex-wrap:wrap;gap:12px;padding:0 22px 22px}
.rs-field{display:flex;flex-direction:column;gap:8px}
.rs-field label{font-size:12px;font-weight:700;color:#52637d}
.rs-field input,.rs-field select{padding:10px;border:1px solid #cfd9e7;border-radius:9px;color:#102544;font-size:14px;background:#fff;min-height:42px}
.rs-nav{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:14px 22px;background:#f8faff;border-top:1px solid #e7ecf4}
.rs-scroll{width:100%;overflow-x:auto}
.rs-calendar{border-collapse:separate;border-spacing:0;width:100%;min-width:880px}
.rs-calendar th,.rs-calendar td{border-right:1px solid #e4ebf4;border-bottom:1px solid #e4ebf4;padding:9px 4px;text-align:center;vertical-align:middle;min-width:43px}
.rs-calendar thead th{background:#eff4fb;color:#546780;font-size:11px;position:sticky;top:0;z-index:1}
.rs-calendar .rs-car{width:195px;min-width:195px;text-align:left;padding:13px 15px;background:#fff;position:sticky;left:0;z-index:2;box-shadow:1px 0 0 #e4ebf4}
.rs-calendar thead .rs-car{background:#eff4fb;z-index:3}
.rs-calendar .rs-car strong{display:block;font-size:13px}
.rs-calendar .rs-car small{display:block;color:#64748b;margin-top:4px}
.rs-calendar .rs-today{background:#deedff}
.rs-booking{display:inline-block;width:100%;padding:7px 2px;border-radius:7px;font-size:11px;font-weight:800;line-height:1.1;white-space:nowrap;text-decoration:none}
.rs-approved{background:#dff6e9;color:#0e6847}
.rs-collected{background:#dbeaff;color:#134fa1}
.rs-conflict{background:#ffe0df;color:#922124}
.rs-legend{display:flex;gap:15px;flex-wrap:wrap;padding:13px 22px;font-size:12px;color:#50647c}
.rs-dot{display:inline-block;width:12px;height:12px;border-radius:3px;vertical-align:middle;margin-right:5px}
.rs-table{width:100%;border-collapse:collapse;min-width:820px}
.rs-table th{text-align:left;padding:14px 16px;background:#eff4fb;font-size:12px;color:#50647c}
.rs-table td{padding:15px 16px;border-top:1px solid #e4ebf4;font-size:13px}
.rs-table a{color:#175bd0;text-decoration:none;font-weight:700}
.rs-pill{padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700;display:inline-block;text-transform:capitalize}
.rs-note{background:#edf4ff;border:1px solid #d4e5ff;border-radius:10px;padding:12px 15px;font-size:13px;color:#35557d}
.rs-warning{color:#9c3326;background:#fff2ee;padding:13px;border-radius:9px;margin:15px 0}
.rs-empty{padding:22px;color:#6a7a90}
@media(max-width:760px){.rs-stats{grid-template-columns:1fr}.rs-container{width:calc(100% - 24px)}.rs-panel-head,.rs-filters,.rs-nav{padding-left:14px;padding-right:14px}}
</style>
</head>
<body>
<?php require __DIR__ . '/header.php'; ?>
<main class="rs-container">
  <div class="rs-kicker">RENTAL STAFF OPERATIONS</div>
  <div class="rs-top">
    <div><h1>Rental Schedule</h1><div class="rs-muted">See confirmed bookings and plan vehicle pickup and return dates.</div></div>
    <a class="rs-btn rs-btn-light" href="staff_dashboard.php">← Staff Dashboard</a>
  </div>
  <?php if ($notice): ?><p class="rs-warning" role="alert"><?= rsEscape($notice) ?></p><?php endif; ?>
  <section class="rs-stats" aria-label="Reservations in selected period">
    <div class="rs-stat"><span>Confirmed reservations in view</span><strong><?= count($reservations) ?></strong></div>
    <div class="rs-stat"><span>Approved, not collected</span><strong><?= $approved ?></strong></div>
    <div class="rs-stat"><span>Collected vehicles</span><strong><?= $collected ?></strong></div>
  </section>
  <section class="rs-panel">
    <div class="rs-panel-head"><h2>Schedule filters</h2><span class="rs-muted">Rental dates include both pickup and final day</span></div>
    <form class="rs-filters" method="get" action="rental_schedule.php">
      <div class="rs-field"><label for="rs-from">Start date</label><input id="rs-from" type="date" name="from" value="<?= rsEscape($startSql) ?>" required></div>
      <div class="rs-field"><label for="rs-days">Show</label><select id="rs-days" name="days"><option value="7" <?= $days === 7 ? 'selected' : '' ?>>7 days</option><option value="14" <?= $days === 14 ? 'selected' : '' ?>>14 days</option><option value="30" <?= $days === 30 ? 'selected' : '' ?>>30 days</option></select></div>
      <div class="rs-field"><label for="rs-car">Vehicle</label><select id="rs-car" name="car_id"><option value="0">All vehicles</option><?php foreach ($allCars as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $selectedCar ? 'selected' : '' ?>><?= rsEscape($c['brand'] . ' ' . $c['model'] . ' · ' . $c['registration_number']) ?></option><?php endforeach; ?></select></div>
      <button class="rs-btn" type="submit">Show Schedule</button>
      <a class="rs-btn rs-btn-light" href="rental_schedule.php">Reset</a>
    </form>
    <div class="rs-nav">
      <a class="rs-btn rs-btn-light" href="<?= rsEscape($urlParams($prev)) ?>">← Previous <?= $days ?> days</a>
      <strong><?= rsEscape($startDate->format('j M Y')) ?> – <?= rsEscape($lastDate->format('j M Y')) ?></strong>
      <a class="rs-btn rs-btn-light" href="<?= rsEscape($urlParams($next)) ?>">Next <?= $days ?> days →</a>
    </div>
    <div class="rs-scroll" role="region" aria-label="Daily schedule by car" tabindex="0">
      <table class="rs-calendar">
        <thead><tr><th class="rs-car" scope="col">Vehicle</th><?php foreach ($calendarDays as $day): ?><th scope="col" class="<?= $day['today'] ? 'rs-today' : '' ?>" title="<?= rsEscape($day['iso']) ?>"><?= rsEscape($day['weekday']) ?><br><?= rsEscape($day['number']) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($visibleCars as $car): ?>
          <tr>
            <th class="rs-car" scope="row"><strong><?= rsEscape($car['brand'] . ' ' . $car['model']) ?></strong><small><?= rsEscape($car['registration_number']) ?></small></th>
            <?php foreach ($calendarDays as $day): $cell = $grid[(int)$car['id']][$day['iso']] ?? []; ?>
              <td class="<?= $day['today'] ? 'rs-today' : '' ?>">
              <?php if (count($cell) > 1): ?>
                <span class="rs-booking rs-conflict" title="Multiple confirmed bookings on one day">Conflict!</span>
              <?php elseif (count($cell) === 1): $item = $cell[0]; ?>
                <a class="rs-booking <?= $item['status'] === 'collected' ? 'rs-collected' : 'rs-approved' ?>" href="booking_history.php?booking_id=<?= (int)$item['id'] ?>" title="Booking #<?= (int)$item['id'] ?> · <?= rsEscape($item['customer_name']) ?> · <?= rsEscape($item['start_date']) ?> to <?= rsEscape($item['end_date']) ?>">#<?= (int)$item['id'] ?></a>
              <?php else: ?><span aria-label="No confirmed booking">–</span><?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        <?php if (!$visibleCars): ?><tr><td colspan="<?= $days + 1 ?>" class="rs-empty">No cars registered yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="rs-legend"><span><span class="rs-dot rs-approved"></span>Approved</span><span><span class="rs-dot rs-collected"></span>Collected</span><span><span class="rs-dot rs-conflict"></span>Overlapping confirmed reservations — investigate</span></div>
  </section>
  <section class="rs-panel">
    <div class="rs-panel-head"><h2>Confirmed reservations in view</h2><a class="rs-btn rs-btn-light" href="staff_bookings.php">Manage Bookings →</a></div>
    <div class="rs-scroll"><table class="rs-table"><thead><tr><th>Booking</th><th>Customer</th><th>Car</th><th>Pickup date</th><th>Final day</th><th>Status</th><th>History</th></tr></thead><tbody>
      <?php foreach ($reservations as $b): ?><tr>
        <td>#<?= (int)$b['id'] ?></td><td><?= rsEscape($b['customer_name']) ?></td>
        <td><?= rsEscape($b['brand'] . ' ' . $b['model']) ?><br><span class="rs-muted"><?= rsEscape($b['registration_number']) ?></span></td>
        <td><?= rsEscape($b['start_date']) ?></td><td><?= rsEscape($b['end_date']) ?></td>
        <td><span class="rs-pill <?= $b['status'] === 'collected' ? 'rs-collected' : 'rs-approved' ?>"><?= rsEscape($b['status']) ?></span></td>
        <td><a href="booking_history.php?booking_id=<?= (int)$b['id'] ?>">View history</a></td>
      </tr><?php endforeach; ?>
      <?php if (!$reservations): ?><tr><td colspan="7" class="rs-empty">No approved or collected rentals overlap this period. Try another date or vehicle.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
  <p class="rs-note">This schedule lists only Approved and Collected bookings. Pending requests, Rejected, Cancelled and Returned bookings are not shown as current reservations. Staff continue to manage booking decisions on the Booking Management page.</p>
</main>
</body>
</html>
<?php mysqli_close($dbc); ?>
