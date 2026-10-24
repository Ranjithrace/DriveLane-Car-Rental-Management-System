<?php
session_start();
require 'database.php';

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function parseRentalDate($value) {
    if (!is_string($value)) return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $date : null;
}

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$userId = (int)$_SESSION['user_id'];
$stmt = mysqli_prepare($dbc, 'SELECT username, role FROM users WHERE id = ?');
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$user || $user['role'] !== 'customer') {
    http_response_code(403);
    exit('Access denied. Customers only.');
}

$carId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$carId || $carId <= 0) {
    http_response_code(400);
    exit('Invalid car ID.');
}

$stmt = mysqli_prepare($dbc,
    "SELECT c.id, c.brand, c.model, c.production_year, c.registration_number,
            c.price_per_day, c.status,
            (SELECT ci.image_path FROM car_images ci WHERE ci.car_id = c.id
             ORDER BY ci.is_primary DESC, ci.sort_order ASC, ci.id ASC LIMIT 1) AS photo_path
     FROM cars c WHERE c.id = ?");
mysqli_stmt_bind_param($stmt, 'i', $carId);
mysqli_stmt_execute($stmt);
$car = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$car) {
    http_response_code(404);
    exit('Car not found.');
}
if ($car['status'] !== 'available') {
    http_response_code(409);
    exit('This car is not currently available for rental.');
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$error = '';
$startInput = $_POST['start_date'] ?? '';
$endInput = $_POST['end_date'] ?? '';
$today = new DateTimeImmutable('today');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf'] ?? '';
    if (!is_string($postedToken) || !hash_equals($_SESSION['csrf'], $postedToken)) {
        http_response_code(403);
        exit('Invalid form token.');
    }
    $start = parseRentalDate($startInput);
    $end = parseRentalDate($endInput);
    if (!$start || !$end) {
        $error = 'Please enter valid rental dates.';
    } elseif ($start < $today) {
        $error = 'Rental start date cannot be in the past.';
    } elseif ($end < $start) {
        $error = 'Rental end date cannot be before the start date.';
    } else {
        // Both the first and last calendar days count toward the rental.
        $days = (int)$start->diff($end)->days + 1;
        try {
            mysqli_begin_transaction($dbc);
            // Serializes booking submissions with staff's car-locked approval flow.
            // Read price inside the transaction, so it cannot change during creation.
            $stmt = mysqli_prepare($dbc,
                'SELECT price_per_day, status FROM cars WHERE id = ? FOR UPDATE');
            mysqli_stmt_bind_param($stmt, 'i', $carId);
            mysqli_stmt_execute($stmt);
            $lockedCar = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$lockedCar || $lockedCar['status'] !== 'available') {
                throw new RuntimeException('Car is no longer available for rental.');
            }
            $totalPrice = round($days * (float)$lockedCar['price_per_day'], 2);
            if ($totalPrice <= 0 || $totalPrice > 99999999.99) {
                throw new RuntimeException('Rental total is outside the allowed range.');
            }
            $stmt = mysqli_prepare($dbc,
                "INSERT INTO bookings (customer_id, car_id, start_date, end_date, total_price, status)
                 VALUES (?, ?, ?, ?, ?, 'pending')");
            mysqli_stmt_bind_param($stmt, 'iissd', $userId, $carId, $startInput, $endInput, $totalPrice);
            if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Could not create booking.');
            mysqli_stmt_close($stmt);
            $bookingId = mysqli_insert_id($dbc);
            $newStatus = 'pending';
            $stmt = mysqli_prepare($dbc,
                'INSERT INTO booking_history (booking_id, changed_by, old_status, new_status) VALUES (?, ?, NULL, ?)');
            mysqli_stmt_bind_param($stmt, 'iis', $bookingId, $userId, $newStatus);
            if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Could not record booking history.');
            mysqli_stmt_close($stmt);
            mysqli_commit($dbc);
            header('Location: my_bookings.php');
            exit;
        } catch (Throwable $ex) {
            mysqli_rollback($dbc);
            error_log('Booking request failure: ' . $ex->getMessage());
            $error = 'Could not create the booking. Please check the details and try again.';
        }
    }
}
$todayText = $today->format('Y-m-d');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Request <?= e($car['brand'] . ' ' . $car['model']) ?> | DriveLane</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f4f7fb;color:#102544;font-family:Inter,Segoe UI,Arial,sans-serif}
.booking-container{width:min(1100px,calc(100% - 36px));margin:44px auto 80px}
.back-link{display:inline-block;color:#1664d9;font-weight:650;text-decoration:none;margin-bottom:20px}
.page-kicker{font-size:12px;color:#1769e0;font-weight:800;letter-spacing:1.8px}
h1{font-size:clamp(30px,4vw,42px);margin:9px 0 10px;letter-spacing:-1.1px}
.intro{color:#62748d;line-height:1.6;margin:0 0 28px}
.booking-layout{display:grid;grid-template-columns:minmax(0,.95fr) minmax(0,1.05fr);gap:24px;align-items:start}
.panel{background:#fff;border:1px solid #dfe7f1;border-radius:18px;overflow:hidden;box-shadow:0 9px 25px rgba(18,40,70,.05)}
.photo{height:310px;background:linear-gradient(135deg,#e1eaf4,#f2f6fa);display:grid;place-items:center;position:relative}
.photo img{width:100%;height:100%;object-fit:cover}
.photo .placeholder{font-size:74px;color:#8494a9}
.tag{position:absolute;top:17px;left:17px;padding:8px 13px;border-radius:22px;background:#dcfce7;color:#166534;font-weight:800;font-size:12px}
.vehicle-info{padding:26px}
.vehicle-info h2{font-size:25px;margin:0 0 10px}
.vehicle-meta{color:#697c92;margin:0 0 24px}
.vehicle-price{border-top:1px solid #e3eaf2;padding-top:20px;display:flex;align-items:center;justify-content:space-between;gap:12px}
.vehicle-price strong{font-size:27px}.vehicle-price small{color:#697c92}
.form-panel{padding:28px}
.form-panel h2{margin:0 0 8px;font-size:23px}
.help{font-size:14px;color:#66788d;line-height:1.6;margin:0 0 20px}
.date-grid{display:grid;grid-template-columns:1fr 1fr;gap:15px}
label{display:block;font-size:14px;font-weight:700;margin-bottom:8px}
input[type=date]{width:100%;border:1px solid #ccd7e6;border-radius:9px;padding:12px;color:#152c4b;font:inherit;min-height:48px}
input:focus{outline:2px solid #b1cefc;border-color:#1769e0}
.estimate{margin-top:22px;background:#f1f6fe;border:1px solid #d9e8fc;border-radius:13px;padding:19px;display:flex;align-items:center;justify-content:space-between;gap:16px}
.estimate small{display:block;color:#647995;margin-top:5px;font-size:12px}
.estimate strong{font-size:25px;white-space:nowrap}
.notice{margin-top:18px;color:#64748b;font-size:13px;line-height:1.6}
.submit-btn{display:block;width:100%;border:0;border-radius:10px;background:#1769e0;color:white;font:inherit;font-weight:750;padding:15px;cursor:pointer;margin-top:23px}
.submit-btn:hover{background:#1058c3}
.message{padding:14px 17px;border-radius:10px;margin-bottom:20px;font-weight:600}
.error{background:#fef0f0;color:#a11b25;border:1px solid #ffd3d3}
@media(max-width:780px){.booking-layout{grid-template-columns:1fr}.photo{height:270px}}
@media(max-width:480px){.date-grid{grid-template-columns:1fr}.form-panel{padding:20px}.estimate{align-items:flex-start}.vehicle-info{padding:20px}}
</style>
</head>
<body>
<?php require __DIR__ . '/header.php'; ?>
<main class="booking-container">
  <a class="back-link" href="index.php#fleet">← Back to available cars</a>
  <div class="page-kicker">CUSTOMER RENTALS</div>
  <h1>Request a rental</h1>
  <p class="intro">Choose your dates and send a rental request. Our staff will review your booking.</p>
  <?php if ($error !== ''): ?><div class="message error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <div class="booking-layout">
    <section class="panel" aria-label="Selected car">
      <div class="photo">
        <?php if (!empty($car['photo_path'])): ?>
          <img src="<?= e($car['photo_path']) ?>" alt="<?= e($car['brand'] . ' ' . $car['model']) ?>" onerror="this.hidden=true;this.nextElementSibling.hidden=false">
          <span class="placeholder" hidden aria-hidden="true">🚘</span>
        <?php else: ?>
          <span class="placeholder" aria-hidden="true">🚘</span>
        <?php endif; ?>
        <span class="tag">Available</span>
      </div>
      <div class="vehicle-info">
        <h2><?= e($car['brand']) ?> <?= e($car['model']) ?></h2>
        <p class="vehicle-meta"><?= (int)$car['production_year'] ?> model · <?= e($car['registration_number']) ?></p>
        <div class="vehicle-price"><span>Daily rental rate</span><div><strong>€<?= number_format((float)$car['price_per_day'], 2) ?></strong><small> / day</small></div></div>
      </div>
    </section>
    <section class="panel form-panel" aria-label="Booking details">
      <h2>Select your rental dates</h2>
      <p class="help">Both the starting and ending dates count as rental days. The final total is confirmed when you submit.</p>
      <form method="post" id="rentalForm">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <div class="date-grid">
          <div><label for="start_date">Start date</label><input id="start_date" name="start_date" type="date" min="<?= e($todayText) ?>" value="<?= e($startInput) ?>" required></div>
          <div><label for="end_date">End date</label><input id="end_date" name="end_date" type="date" min="<?= e($todayText) ?>" value="<?= e($endInput) ?>" required></div>
        </div>
        <div class="estimate" aria-live="polite">
          <div><b>Estimated total</b><small id="duration">Select both dates to calculate</small></div>
          <strong id="estimateValue">—</strong>
        </div>
        <p class="notice">Your booking starts as <strong>Pending</strong>. Staff approval is required, and a car listed as available may already have requests for your selected dates.</p>
        <button class="submit-btn" type="submit">Submit booking request →</button>
      </form>
    </section>
  </div>
</main>
<script>
(function () {
    const start = document.getElementById('start_date');
    const end = document.getElementById('end_date');
    const value = document.getElementById('estimateValue');
    const duration = document.getElementById('duration');
    const price = <?= json_encode((float)$car['price_per_day']) ?>;
    function dateNumber(text) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(text)) return NaN;
        const [y, m, d] = text.split('-').map(Number);
        const result = Date.UTC(y, m - 1, d);
        const date = new Date(result);
        if (date.getUTCFullYear() !== y || date.getUTCMonth() !== m - 1 || date.getUTCDate() !== d) return NaN;
        return result;
    }
    function update() {
        end.min = start.value || <?= json_encode($todayText) ?>;
        const first = dateNumber(start.value);
        const last = dateNumber(end.value);
        if (!Number.isFinite(first) || !Number.isFinite(last) || last < first) {
            value.textContent = '—';
            duration.textContent = 'Choose a valid start and end date';
            return;
        }
        const days = Math.round((last - first) / 86400000) + 1;
        const total = days * price;
        value.textContent = Number.isFinite(total) && total <= 99999999.99
            ? new Intl.NumberFormat('en-IE', {style:'currency', currency:'EUR'}).format(total)
            : 'Total too large';
        duration.textContent = days + (days === 1 ? ' rental day' : ' rental days') + ' × €' + price.toFixed(2);
    }
    start.addEventListener('change', update);
    end.addEventListener('change', update);
    update();
})();
</script>
</body>
</html>
<?php mysqli_close($dbc); ?>
