
<?php
session_start();
require 'database.php';

// Check login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];

// Verify staff role from MySQL
$stmt = mysqli_prepare($dbc,
    "SELECT username, role FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$user || $user['role'] !== 'staff') {
    http_response_code(403);
    exit('Access denied. Staff only.');
}

function e($value) {
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

// Form security token
if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$error = '';
$success = '';

// Handle staff decision
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals(
        $_SESSION['csrf'],
        $_POST['csrf'] ?? ''
    )) {
        die('Invalid form token.');
    }

    $bookingId = filter_var(
        $_POST['booking_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $action = $_POST['action'] ?? '';

    if (!$bookingId || $bookingId <= 0) {
        $error = 'Invalid booking ID.';

    } elseif (!in_array(
        $action,
        ['approve', 'reject', 'collect', 'return'],
        true
    )) {
        $error = 'Invalid booking action.';

    } else {

        try {
            mysqli_begin_transaction($dbc);

            // Find which car belongs to this booking.
            $stmt = mysqli_prepare($dbc,
                "SELECT car_id FROM bookings WHERE id = ?");

            mysqli_stmt_bind_param(
                $stmt, 'i', $bookingId
            );
            mysqli_stmt_execute($stmt);

            $bookingRef = mysqli_fetch_assoc(
                mysqli_stmt_get_result($stmt)
            );

            if (!$bookingRef) {
                throw new Exception('Booking not found.');
            }

            $carId = (int)$bookingRef['car_id'];

            // Lock the car to serialize decisions
            // for the same vehicle.
            $stmt = mysqli_prepare($dbc,
                "SELECT status FROM cars
                 WHERE id = ? FOR UPDATE");

            mysqli_stmt_bind_param($stmt, 'i', $carId);
            mysqli_stmt_execute($stmt);

            $car = mysqli_fetch_assoc(
                mysqli_stmt_get_result($stmt)
            );

            if (!$car) {
                throw new Exception('Car not found.');
            }

            // Lock and recheck the booking.
            $stmt = mysqli_prepare($dbc,
                "SELECT car_id, start_date, end_date, status
                 FROM bookings
                 WHERE id = ? FOR UPDATE");

            mysqli_stmt_bind_param(
                $stmt, 'i', $bookingId
            );
            mysqli_stmt_execute($stmt);

            $booking = mysqli_fetch_assoc(
                mysqli_stmt_get_result($stmt)
            );

            if (!$booking ||
    (int)$booking['car_id'] !== $carId) {

        throw new Exception('Booking not found or changed.');
    }

    $oldStatus = $booking['status'];

    // Use MySQL's date to avoid a PHP/server timezone mismatch.
    $todayResult = mysqli_query($dbc, 'SELECT CURRENT_DATE() AS today');
    $today = mysqli_fetch_assoc($todayResult)['today'];

    // A vehicle cannot be handed over before the rental begins.
    if ($action === 'collect' &&
        ($today < $booking['start_date'] || $today > $booking['end_date'])) {
        throw new Exception('Collection is only allowed during the scheduled rental period.');
    }
    if ($action === 'return' && $today < $booking['start_date']) {
        throw new Exception('Cannot return a vehicle before its rental period begins.');
    }


    // Check which changes are allowed
    $allowedActions = [
        'pending' => ['approve', 'reject'],
        'approved' => ['collect'],
        'collected' => ['return']
    ];

    if (!in_array(
        $action,
        $allowedActions[$oldStatus] ?? [],
        true
    )) {
        throw new Exception(
            'This action is not allowed for the current booking status.'
        );
    }

            if ($action === 'approve') {

                if ($booking['end_date'] < $today) {
                    throw new Exception('Cannot approve an expired rental request.');
                }

                if ($car['status'] !== 'available') {
                    throw new Exception(
                        'Car is not available for rental.'
                    );
                }

                // All scheduled dates remain reserved even after early return.
                // This prevents a second approved booking for the same calendar day.
                $stmt = mysqli_prepare($dbc,
                    "SELECT id FROM bookings
                     WHERE car_id = ?
                       AND id <> ?
                       AND status IN ('approved', 'collected', 'returned')
                       AND start_date <= ?
                       AND end_date >= ?
                     LIMIT 1");

                mysqli_stmt_bind_param(
                    $stmt,
                    'iiss',
                    $carId,
                    $bookingId,
                    $booking['end_date'],
                    $booking['start_date']
                );

                mysqli_stmt_execute($stmt);

                $conflict = mysqli_fetch_assoc(
                    mysqli_stmt_get_result($stmt)
                );

                if ($conflict) {
                    throw new Exception(
                        'Cannot approve: another confirmed ' .
                        'booking overlaps these dates.'
                    );
                }

                $newStatus = 'approved';

} elseif ($action === 'reject') {

    $newStatus = 'rejected';

} elseif ($action === 'collect') {

    $newStatus = 'collected';

} elseif ($action === 'return') {

    $newStatus = 'returned';
}

            // Change the booking status.
           $stmt = mysqli_prepare($dbc,
    "UPDATE bookings
     SET status = ?, reviewed_by = ?
     WHERE id = ? AND status = ?");

            mysqli_stmt_bind_param(
    $stmt,
    'siis',
    $newStatus,
    $userId,
    $bookingId,
    $oldStatus
);

            mysqli_stmt_execute($stmt);

            if (mysqli_stmt_affected_rows($stmt) !== 1) {
                throw new Exception(
                    'Could not update booking status.'
                );
            }

            

            $stmt = mysqli_prepare($dbc,
                "INSERT INTO booking_history
                 (booking_id, changed_by,
                  old_status, new_status)
                 VALUES (?, ?, ?, ?)");

            mysqli_stmt_bind_param(
                $stmt,
                'iiss',
                $bookingId,
                $userId,
                $oldStatus,
                $newStatus
            );

            mysqli_stmt_execute($stmt);

            // Save both updates together.
            mysqli_commit($dbc);

            header(
                'Location: staff_bookings.php?success=' .
                $newStatus
            );
            exit;

        } catch (Throwable $ex) {

            mysqli_rollback($dbc);
            $error = $ex->getMessage();
        }
    }
}

// Display all booking requests
$query =
    "SELECT b.id, b.start_date, b.end_date,
            b.total_price, b.status, b.created_at,
            u.username AS customer_name,
            c.brand, c.model, c.registration_number
     FROM bookings b
     INNER JOIN users u ON b.customer_id = u.id
     INNER JOIN cars c ON b.car_id = c.id
     ORDER BY
         CASE WHEN b.status = 'pending' THEN 0 ELSE 1 END,
         b.created_at DESC,
         b.id DESC";

$bookings = mysqli_query($dbc, $query);

if (!$bookings) {
    die('Could not load bookings.');
}

// Calculate statistics from the actual booking records.
$rows = [];
$counts = ['pending' => 0, 'approved' => 0, 'collected' => 0, 'returned' => 0];
while ($record = mysqli_fetch_assoc($bookings)) {
    $rows[] = $record;
    if (isset($counts[$record['status']])) {
        $counts[$record['status']]++;
    }
}
$today = mysqli_fetch_assoc(mysqli_query($dbc, 'SELECT CURRENT_DATE() AS today'))['today'];

// Staff-side search and filters (read-only; booking status updates remain unchanged).
// Summary counters above are intentionally calculated from ALL bookings.
$searchTerm = trim((string)($_GET['search'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? '');
$dateFrom = (string)($_GET['from'] ?? '');
$dateTo = (string)($_GET['to'] ?? '');
$validStatuses = ['pending', 'approved', 'rejected', 'cancelled', 'collected', 'returned'];
$filterError = '';

if (strlen($searchTerm) > 100) {
    $searchTerm = substr($searchTerm, 0, 100);
}
if ($statusFilter !== '' && !in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
    $filterError = 'Invalid status filter was ignored.';
}
function isValidFilterDate(string $value): bool {
    if ($value === '') return true;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}
if (!isValidFilterDate($dateFrom) || !isValidFilterDate($dateTo) ||
    ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo)) {
    $dateFrom = '';
    $dateTo = '';
    $filterError = 'Invalid date range. The date filter was ignored.';
}
$filteredRows = array_values(array_filter($rows, static function (array $booking) use
    ($searchTerm, $statusFilter, $dateFrom, $dateTo): bool {
    if ($statusFilter !== '' && $booking['status'] !== $statusFilter) return false;

    // Match bookings whose rental periods intersect the selected date window.
    if ($dateFrom !== '' && $booking['end_date'] < $dateFrom) return false;
    if ($dateTo !== '' && $booking['start_date'] > $dateTo) return false;

    if ($searchTerm !== '') {
        $haystack = implode(' ', [
            (string)$booking['id'],
            '#'.(string)$booking['id'],
            $booking['customer_name'],
            $booking['brand'],
            $booking['model'],
            $booking['registration_number']
        ]);
        if (stripos($haystack, $searchTerm) === false) return false;
    }
    return true;
}));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Staff Bookings | DriveLane</title>
<style>
* { box-sizing:border-box; }
body { margin:0; background:#f4f7fc; color:#102544; font-family:Arial,Helvetica,sans-serif; }
.sb-wrap { max-width:1230px; margin:0 auto; padding:32px 20px 75px; }
.sb-kicker { letter-spacing:1.5px; font-size:12px; color:#2563eb; font-weight:800; }
.sb-heading { display:flex; justify-content:space-between; flex-wrap:wrap; align-items:center; gap:18px; margin:12px 0 28px; }
.sb-heading h1 { font-size:clamp(26px,4vw,36px); margin:0 0 9px; }
.sb-heading p { color:#64748b; margin:0; }
.sb-button { display:inline-flex; padding:12px 18px; border-radius:9px; text-decoration:none; background:#155de2; color:white; font-weight:700; font-size:14px; }
.sb-cards { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; margin-bottom:30px; }
.sb-card { background:white; border:1px solid #e0e8f2; border-radius:14px; padding:22px; }
.sb-card span { display:block; color:#66778f; font-size:14px; margin-bottom:13px; }
.sb-card strong { font-size:30px; }
.sb-panel { background:white; border:1px solid #e0e8f2; border-radius:14px; overflow:hidden; }
.sb-panel-header { padding:21px 22px; display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:12px; }
.sb-panel-header h2 { margin:0; font-size:21px; }
.sb-muted { color:#74839a; font-size:13px; }
.sb-scroll { overflow-x:auto; }
.sb-table { width:100%; border-collapse:collapse; min-width:1030px; }
.sb-table th { background:#eff3f9; text-align:left; font-size:12px; color:#445876; padding:16px 13px; white-space:nowrap; }
.sb-table td { border-top:1px solid #e5ebf3; padding:15px 13px; vertical-align:middle; font-size:13px; }
.sb-table td small { color:#75859a; display:block; margin-top:5px; }
.sb-badge { display:inline-block; padding:7px 10px; border-radius:50px; font-weight:bold; font-size:12px; text-transform:capitalize; }
.sb-pending { background:#fff1cb; color:#895607; }
.sb-approved { background:#ddf8eb; color:#10613a; }
.sb-collected { background:#dcecff; color:#144e9a; }
.sb-returned { background:#e6ecf2; color:#45546a; }
.sb-rejected, .sb-cancelled { background:#ffe3e3; color:#a51e2c; }
.sb-actions { display:flex; flex-wrap:wrap; gap:6px; min-width:130px; }
.sb-actions form { margin:0; }
.sb-action { cursor:pointer; border:0; background:#1565de; color:white; border-radius:7px; padding:9px 11px; font-weight:700; font-size:12px; white-space:nowrap; }
.sb-action.reject { background:#b52c38; }
.sb-action.approve { background:#16734c; }
.sb-empty { padding:24px; color:#677991; }
.sb-alert { background:#fff; border:1px solid #e0e8f2; border-left:4px solid #1b67d9; padding:15px 18px; border-radius:9px; margin-bottom:20px; }
.sb-alert.error { border-left-color:#c02637; color:#92192a; }
.sb-alert.success { border-left-color:#13935f; color:#116944; }
/* Filter panel */
.sb-filters { display:grid; grid-template-columns:minmax(190px,2fr) minmax(140px,1fr) minmax(130px,1fr) minmax(130px,1fr) auto; gap:12px; align-items:end; padding:0 22px 20px; }
.sb-field { display:flex; flex-direction:column; gap:6px; min-width:0; }
.sb-field label { font-size:12px; font-weight:700; color:#445876; }
.sb-field input, .sb-field select { width:100%; min-width:0; height:41px; padding:9px 11px; font:inherit; font-size:13px; color:#102544; background:white; border:1px solid #cbd7e6; border-radius:8px; }
.sb-filter-actions { display:flex; gap:8px; align-items:center; }
.sb-filter-submit { height:41px; border:0; border-radius:8px; background:#155de2; color:white; padding:0 14px; cursor:pointer; font-weight:700; }
.sb-filter-clear { height:41px; display:inline-flex; align-items:center; padding:0 13px; border:1px solid #cbd7e6; border-radius:8px; color:#25436a; text-decoration:none; font-weight:700; font-size:13px; }
.sb-footnote { color:#60718a; margin-top:18px; font-size:13px; line-height:1.5; }
@media(max-width:1100px) { .sb-filters { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media(max-width:560px) { .sb-filters { grid-template-columns:1fr; padding:0 16px 18px; } }
@media(max-width:720px) { .sb-cards { grid-template-columns:repeat(2,minmax(0,1fr)); } .sb-wrap { padding:25px 14px 60px; } }
</style>
</head>
<body>
<?php require __DIR__ . '/header.php'; ?>
<main class="sb-wrap">
  <div class="sb-kicker">STAFF OPERATIONS</div>
  <div class="sb-heading">
    <div>
      <h1>Booking Management</h1>
      <p>Review rental requests and manage vehicle collection and returns.</p>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center"><a class="sb-button" href="booking_history.php">View Activity History →</a><a class="sb-button" href="staff_dashboard.php">← Staff Dashboard</a></div>
  </div>
  <section class="sb-cards" aria-label="Booking summary">
    <div class="sb-card"><span>Pending requests</span><strong><?= $counts['pending'] ?></strong></div>
    <div class="sb-card"><span>Approved bookings</span><strong><?= $counts['approved'] ?></strong></div>
    <div class="sb-card"><span>Collected vehicles</span><strong><?= $counts['collected'] ?></strong></div>
    <div class="sb-card"><span>Returned rentals</span><strong><?= $counts['returned'] ?></strong></div>
  </section>

  <?php if (isset($_GET['success']) && in_array($_GET['success'], ['approved','rejected','collected','returned'], true)): ?>
    <div class="sb-alert success" role="status">Booking <?= e($_GET['success']) ?> successfully.</div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="sb-alert error" role="alert"><?= e($error) ?></div>
  <?php endif; ?>

  <section class="sb-panel">
    <div class="sb-panel-header">
      <h2>All rental requests</h2>
      <span class="sb-muted">Showing <?= count($filteredRows) ?> of <?= count($rows) ?> bookings</span>
    </div>
    <form class="sb-filters" method="get" action="staff_bookings.php" aria-label="Search and filter rental bookings">
      <div class="sb-field">
        <label for="sb-search">Search customer, vehicle, registration or booking ID</label>
        <input id="sb-search" type="search" name="search" maxlength="100"
               placeholder="e.g. Andri, BMW, KTU003, #8" value="<?= e($searchTerm) ?>">
      </div>
      <div class="sb-field">
        <label for="sb-status">Booking status</label>
        <select id="sb-status" name="status">
          <option value="">All statuses</option>
          <?php foreach ($validStatuses as $statusOption): ?>
            <option value="<?= e($statusOption) ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>><?= e(ucfirst($statusOption)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="sb-field">
        <label for="sb-from">Rental period from</label>
        <input id="sb-from" type="date" name="from" value="<?= e($dateFrom) ?>">
      </div>
      <div class="sb-field">
        <label for="sb-to">Rental period to</label>
        <input id="sb-to" type="date" name="to" value="<?= e($dateTo) ?>">
      </div>
      <div class="sb-filter-actions">
        <button class="sb-filter-submit" type="submit">Search</button>
        <a class="sb-filter-clear" href="staff_bookings.php">Reset</a>
      </div>
    </form>
    <?php if ($filterError !== ''): ?>
      <div class="sb-alert error" role="alert" style="margin:0 22px 18px"><?= e($filterError) ?></div>
    <?php endif; ?>
    <?php if (!$filteredRows): ?>
      <div class="sb-empty"><?= $rows ? 'No bookings match the selected filters.' : 'No rental requests have been submitted yet.' ?></div>
    <?php else: ?>
      <div class="sb-scroll">
        <table class="sb-table">
          <thead><tr>
            <th>ID</th><th>Customer</th><th>Vehicle</th><th>Rental period</th><th>Total</th><th>Status</th><th>Requested</th><th>Actions</th>
          </tr></thead>
          <tbody>
          <?php foreach ($filteredRows as $booking): ?>
            <tr>
              <td><strong>#<?= (int)$booking['id'] ?></strong><small><a href="booking_history.php?booking_id=<?= (int)$booking['id'] ?>" style="color:#155de2;text-decoration:none;font-weight:700">History →</a></small></td>
              <td><strong><?= e($booking['customer_name']) ?></strong></td>
              <td><strong><?= e($booking['brand'] . ' ' . $booking['model']) ?></strong><small><?= e($booking['registration_number']) ?></small></td>
              <td><?= e($booking['start_date']) ?><small>to <?= e($booking['end_date']) ?></small></td>
              <td>€<?= number_format((float)$booking['total_price'], 2) ?></td>
              <td><span class="sb-badge sb-<?= e($booking['status']) ?>"><?= e(ucfirst($booking['status'])) ?></span></td>
              <td><?= e($booking['created_at']) ?></td>
              <td>
                <div class="sb-actions">
                <?php
                  $actions = [];
                  if ($booking['status'] === 'pending') {
                      $actions = ['approve' => 'Approve', 'reject' => 'Reject'];
                  } elseif ($booking['status'] === 'approved' && $today >= $booking['start_date'] && $today <= $booking['end_date']) {
                      $actions = ['collect' => 'Mark Collected'];
                  } elseif ($booking['status'] === 'collected' && $today >= $booking['start_date']) {
                      $actions = ['return' => 'Mark Returned'];
                  }
                ?>
                <?php if (!$actions): ?>
                  <span class="sb-muted"><?= $booking['status'] === 'approved' ? 'Collection outside rental dates' : 'No action' ?></span>
                <?php endif; ?>
                <?php foreach ($actions as $actionName => $actionLabel): ?>
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>">
                    <button class="sb-action <?= $actionName === 'reject' ? 'reject' : ($actionName === 'approve' ? 'approve' : '') ?>"
                            type="submit" name="action" value="<?= e($actionName) ?>"><?= e($actionLabel) ?></button>
                  </form>
                <?php endforeach; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
  <p class="sb-footnote">Collection is permitted only from the scheduled start date through the end date. A return cannot be recorded before the rental begins. These restrictions are checked again when an action is submitted.</p>
</main>
</body>
</html>
<?php mysqli_close($dbc); ?>
