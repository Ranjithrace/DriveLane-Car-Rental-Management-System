<?php
session_start();
require 'database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$userId = (int)$_SESSION['user_id'];
$stmt = mysqli_prepare($dbc, "SELECT username, role FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$user || $user['role'] !== 'customer') {
    http_response_code(403);
    exit('Access denied. Customers only.');
}

// Customer may cancel only their own pending booking.
if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        http_response_code(403);
        exit('Invalid form token.');
    }
    $bookingId = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$bookingId || $bookingId < 1) {
        $error = 'Invalid booking.';
    } else {
        try {
            mysqli_begin_transaction($dbc);
            $lock = mysqli_prepare($dbc, "SELECT status FROM bookings WHERE id = ? AND customer_id = ? FOR UPDATE");
            mysqli_stmt_bind_param($lock, 'ii', $bookingId, $userId);
            mysqli_stmt_execute($lock);
            $target = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
            mysqli_stmt_close($lock);
            if (!$target || $target['status'] !== 'pending') {
                throw new RuntimeException('Only your pending bookings can be cancelled.');
            }
            $update = mysqli_prepare($dbc, "UPDATE bookings SET status = 'cancelled' WHERE id = ? AND customer_id = ? AND status = 'pending'");
            mysqli_stmt_bind_param($update, 'ii', $bookingId, $userId);
            mysqli_stmt_execute($update);
            if (mysqli_stmt_affected_rows($update) !== 1) {
                throw new RuntimeException('Booking could not be cancelled.');
            }
            mysqli_stmt_close($update);
            $oldStatus = 'pending';
            $newStatus = 'cancelled';
            $history = mysqli_prepare($dbc, 'INSERT INTO booking_history (booking_id, changed_by, old_status, new_status) VALUES (?, ?, ?, ?)');
            mysqli_stmt_bind_param($history, 'iiss', $bookingId, $userId, $oldStatus, $newStatus);
            mysqli_stmt_execute($history);
            mysqli_stmt_close($history);
            mysqli_commit($dbc);
            header('Location: my_bookings.php?cancelled=1');
            exit;
        } catch (Throwable $ex) {
            mysqli_rollback($dbc);
            error_log($ex->getMessage());
            $error = 'Unable to cancel booking. Only your pending requests can be cancelled.';
        }
    }
}

$stmt = mysqli_prepare($dbc,
    "SELECT b.id, b.start_date, b.end_date, b.total_price, b.status, b.created_at,
            c.brand, c.model, c.registration_number
     FROM bookings b
     INNER JOIN cars c ON b.car_id = c.id
     WHERE b.customer_id = ?
     ORDER BY b.created_at DESC, b.id DESC");
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$bookings = mysqli_stmt_get_result($stmt);

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Bookings | DriveLane</title>
<style>
* { box-sizing: border-box; }
body { margin: 0; font-family: Arial, sans-serif; background: #f5f7fb; color: #112441; }
.page-top { background: linear-gradient(110deg,#122642,#234878); color: #fff; padding: 50px 24px; }
.wrap { max-width: 1220px; margin: 0 auto; }
.eyebrow { color: #afceff; font-size: 12px; font-weight: 800; letter-spacing: 2px; text-transform: uppercase; }
h1 { font-size: clamp(29px,4vw,43px); margin: 11px 0; }
.page-top p { color: #dae6fa; margin: 8px 0 0; line-height: 1.6; }
.main { padding: 32px 24px 65px; }
.actions { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px; }
.actions h2 { margin:0; font-size:23px; }
.btn { display:inline-block; padding: 12px 17px; border-radius:9px; text-decoration:none; background:#1c67e8; color:white; font-weight:700; font-size:14px; }
.box { background:white; border: 1px solid #dce4ef; border-radius:15px; overflow:hidden; box-shadow:0 5px 18px #142c4608; }
.table-scroll { overflow-x:auto; }
table { width:100%; border-collapse:collapse; min-width:820px; }
th { background:#edf2f9; text-align:left; font-size:13px; color:#344866; padding:16px 17px; }
td { padding:19px 17px; border-top:1px solid #e7edf5; font-size:14px; }
tr:hover td { background:#fbfcff; }
.car { font-weight:800; }
.sub { color:#60738e; font-size:12px; margin-top:5px; }
.badge { display:inline-block; border-radius:30px; padding:8px 12px; font-size:12px; font-weight:800; white-space:nowrap; }
.pending {background:#fff2d1;color:#8b5700;}
.approved, .returned {background:#dff6e8;color:#07643a;}
.rejected, .cancelled {background:#ffe4e5;color:#a01721;}
.collected {background:#e1ecff;color:#1b50aa;}
.cancel-btn {border:0;border-radius:8px;background:#b42332;color:white;padding:9px 12px;font-weight:700;cursor:pointer;white-space:nowrap;}
.notice {padding:14px 18px;border-radius:10px;margin:0 0 20px;background:#e3f6eb;color:#08623c;}
.notice.error {background:#ffe9e9;color:#a11728;}
.empty { text-align:center; padding:55px 20px; }
.empty h3 { margin:0 0 8px; }
.empty p { color:#63758d; }
@media(max-width:640px) {.page-top{padding:35px 18px}.main{padding:24px 14px}.actions h2{font-size:20px}}
</style>
</head>
<body>
<?php require __DIR__ . '/header.php'; ?>
<section class="page-top">
 <div class="wrap">
  <div class="eyebrow">Customer dashboard / Rental history</div>
  <h1>My bookings</h1>
  <p>Welcome, <?= e($user['username']) ?>. Track your rental requests and every status update in one place.</p>
 </div>
</section>
<main class="main wrap">
 <?php if (isset($_GET['cancelled'])): ?><p class="notice">Booking cancelled successfully.</p><?php endif; ?>
 <?php if ($error !== ''): ?><p class="notice error"><?= e($error) ?></p><?php endif; ?>
 <div class="actions">
  <h2>Rental history</h2>
  <a class="btn" href="index.php#fleet">Explore cars &rarr;</a>
 </div>
 <?php if (mysqli_num_rows($bookings) === 0): ?>
   <div class="box empty">
    <h3>No bookings yet</h3>
    <p>Find a car you like and submit your first rental request.</p>
    <a class="btn" href="index.php#fleet">Browse our fleet</a>
   </div>
 <?php else: ?>
 <div class="box table-scroll">
  <table>
   <thead><tr><th>Booking</th><th>Vehicle</th><th>Rental period</th><th>Total</th><th>Status</th><th>Requested on</th><th>Action</th></tr></thead>
   <tbody>
    <?php while ($booking = mysqli_fetch_assoc($bookings)): ?>
    <tr>
     <td><strong>#<?= (int)$booking['id'] ?></strong></td>
     <td><div class="car"><?= e($booking['brand']) ?> <?= e($booking['model']) ?></div><div class="sub"><?= e($booking['registration_number']) ?></div></td>
     <td><?= e($booking['start_date']) ?> &ndash; <?= e($booking['end_date']) ?></td>
     <td><strong>&euro;<?= number_format((float)$booking['total_price'], 2) ?></strong></td>
     <td><span class="badge <?= e($booking['status']) ?>"><?= e(ucfirst($booking['status'])) ?></span></td>
     <td><?= e($booking['created_at']) ?></td>
     <td><?php if ($booking['status'] === 'pending'): ?>
       <form method="post" onsubmit="return confirm('Cancel this pending booking request?');">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>">
        <button class="cancel-btn" type="submit">Cancel request</button>
       </form>
     <?php else: ?><span class="sub">No action</span><?php endif; ?></td>
    </tr>
    <?php endwhile; ?>
   </tbody>
  </table>
 </div>
 <?php endif; ?>
</main>
</body>
</html>
<?php mysqli_close($dbc); ?>
