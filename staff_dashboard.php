<?php
session_start();
require 'database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$userId = (int)$_SESSION['user_id'];
$stmt = mysqli_prepare($dbc, 'SELECT username, role FROM users WHERE id = ?');
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$user || $user['role'] !== 'staff') {
    http_response_code(403);
    exit('Access denied. Rental staff only.');
}
function esc($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function countRows($dbc, $query) {
    $result = mysqli_query($dbc, $query);
    if (!$result) { throw new RuntimeException('Unable to load dashboard statistics.'); }
    return (int)mysqli_fetch_assoc($result)['total'];
}
$totalCars = countRows($dbc, 'SELECT COUNT(*) AS total FROM cars');
$availableCars = countRows($dbc, "SELECT COUNT(*) AS total FROM cars WHERE status = 'available'");
$pendingRequests = countRows($dbc, "SELECT COUNT(*) AS total FROM bookings WHERE status = 'pending'");
$activeRentals = countRows($dbc, "SELECT COUNT(*) AS total FROM bookings WHERE status IN ('approved', 'collected')");
$recent = mysqli_query($dbc, "SELECT b.id, b.status, b.start_date, b.end_date, u.username, c.brand, c.model
 FROM bookings b JOIN users u ON u.id = b.customer_id JOIN cars c ON c.id = b.car_id
 ORDER BY CASE WHEN b.status='pending' THEN 0 ELSE 1 END, b.created_at DESC, b.id DESC LIMIT 6");
if (!$recent) { throw new RuntimeException('Unable to load recent bookings.'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Staff Dashboard | DriveLane</title>
<style>
* { box-sizing: border-box; }
body { margin: 0; background: #f5f7fb; color: #102544; font-family: Arial, sans-serif; }
.container { max-width: 1200px; margin: auto; padding: 0 24px; }
.hero { background: linear-gradient(110deg, #102544, #1f426e); color: #fff; padding: 56px 0; }
.eyebrow { color: #adcbfb; letter-spacing: .12em; font-size: 12px; font-weight: 700; }
h1 { font-size: clamp(30px, 4vw, 44px); margin: 12px 0; }
.hero p { color: #d8e5f8; margin-bottom: 26px; }
.actions { display: flex; gap: 12px; flex-wrap: wrap; }
.button { display: inline-block; padding: 12px 18px; background: #1e6be6; color: #fff; border-radius: 10px; text-decoration: none; font-weight: 700; }
.button.secondary { background: #fff; color: #1257c5; }
main { padding: 42px 0 70px; }
h2 { font-size: 25px; margin: 0 0 20px; }
.stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-bottom: 42px; }
.stat { background: #fff; border: 1px solid #dce3ee; border-radius: 15px; padding: 24px; }
.stat .label { color: #63758d; font-size: 14px; }
.stat strong { display: block; font-size: 32px; margin-top: 12px; }
.tools { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 44px; }
.tool { display: block; background: white; padding: 23px; border: 1px solid #dce3ee; border-radius: 15px; text-decoration: none; color: #102544; }
.tool strong { display: block; font-size: 19px; margin-bottom: 8px; }
.tool span { color: #64748b; line-height: 1.5; }
.tool:hover { border-color: #1e6be6; }
.heading-line { display: flex; justify-content: space-between; gap: 14px; align-items: center; }
.heading-line a { color: #1257c5; font-weight: bold; text-decoration: none; }
.table-wrap { background: white; overflow-x: auto; border: 1px solid #dce3ee; border-radius: 15px; }
table { width: 100%; border-collapse: collapse; min-width: 700px; }
th, td { text-align: left; padding: 16px; border-bottom: 1px solid #e5eaf2; }
th { color: #52637d; font-size: 13px; background: #edf2f9; }
tr:last-child td { border-bottom: 0; }
.status { display: inline-block; padding: 6px 10px; border-radius: 20px; background: #e8eef7; text-transform: capitalize; font-weight: 700; font-size: 12px; }
.status.pending { color: #93600d; background: #fff4d6; }
.status.approved, .status.returned { color: #086647; background: #dcf6e9; }
.status.rejected, .status.cancelled { color: #a52b34; background: #ffe4e8; }
.status.collected { color: #1453bc; background: #e3eeff; }
@media(max-width: 800px) { .stats { grid-template-columns: repeat(2, 1fr); } .tools { grid-template-columns: 1fr; } }
@media(max-width: 480px) { .stats { grid-template-columns: 1fr; } .container { padding: 0 16px; } }
</style>
</head>
<body>
<?php require __DIR__ . '/header.php'; ?>
<section class="hero">
  <div class="container">
    <div class="eyebrow">RENTAL STAFF DASHBOARD</div>
    <h1>Welcome back, <?= esc($user['username']) ?>!</h1>
    <p>Manage the fleet, upload car photos, and process customer rental requests.</p>
    <div class="actions">
      <a class="button" href="staff_bookings.php">Review Bookings →</a>
      <a class="button secondary" href="add_car.php">Add New Car</a>
    </div>
  </div>
</section>
<main class="container">
  <h2>Operations overview</h2>
  <div class="stats">
    <div class="stat"><span class="label">Total cars</span><strong><?= $totalCars ?></strong></div>
    <div class="stat"><span class="label">Cars listed available</span><strong><?= $availableCars ?></strong></div>
    <div class="stat"><span class="label">Pending requests</span><strong><?= $pendingRequests ?></strong></div>
    <div class="stat"><span class="label">Approved / collected</span><strong><?= $activeRentals ?></strong></div>
  </div>
  <h2>Staff tools</h2>
  <div class="tools">
    <a class="tool" href="add_car.php"><strong>＋ Add a car</strong><span>Register a vehicle and continue to photo upload.</span></a>
    <a class="tool" href="manage_cars.php"><strong>▦ Manage cars & photos</strong><span>Edit car details, status, and open each car's Photos page.</span></a>
    <a class="tool" href="staff_bookings.php"><strong>✓ Manage bookings</strong><span>Approve, reject, collect and return rentals.</span></a>
    <a class="tool" href="booking_history.php"><strong>◷ Booking history</strong><span>Review who changed booking statuses and when.</span></a>
    <a class="tool" href="rental_schedule.php"><strong>▦ Rental schedule</strong><span>View the pickup and return calendar for confirmed rentals.</span></a>
  </div>
  <div class="heading-line"><h2>Recent rental requests</h2><a href="staff_bookings.php">View all →</a></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Booking</th><th>Customer</th><th>Car</th><th>Rental period</th><th>Status</th></tr></thead>
      <tbody>
      <?php $any = false; while ($row = mysqli_fetch_assoc($recent)): $any = true; ?>
        <tr>
          <td>#<?= (int)$row['id'] ?></td>
          <td><?= esc($row['username']) ?></td>
          <td><?= esc($row['brand'] . ' ' . $row['model']) ?></td>
          <td><?= esc($row['start_date']) ?> – <?= esc($row['end_date']) ?></td>
          <td><span class="status <?= esc($row['status']) ?>"><?= esc($row['status']) ?></span></td>
        </tr>
      <?php endwhile; ?>
      <?php if (!$any): ?><tr><td colspan="5">No bookings yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</main>
</body>
</html>
<?php mysqli_close($dbc); ?>
