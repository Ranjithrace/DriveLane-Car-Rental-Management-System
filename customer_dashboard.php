<?php
session_start();
require 'database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$userId = (int)$_SESSION['user_id'];
$stmt = mysqli_prepare($dbc, 'SELECT id, username, role FROM users WHERE id = ?');
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$user || $user['role'] !== 'customer') {
    http_response_code(403);
    exit('Access denied. Customers only.');
}
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// These totals belong only to the currently logged-in customer.
$stmt = mysqli_prepare($dbc,
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(status = 'pending'), 0) AS pending_count,
            COALESCE(SUM(status IN ('approved', 'collected')), 0) AS active_count,
            COALESCE(SUM(status = 'returned'), 0) AS completed_count
     FROM bookings WHERE customer_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$stmt = mysqli_prepare($dbc,
    'SELECT b.id, b.start_date, b.end_date, b.total_price, b.status,
            c.brand, c.model
     FROM bookings b
     INNER JOIN cars c ON c.id = b.car_id
     WHERE b.customer_id = ?
     ORDER BY b.created_at DESC, b.id DESC LIMIT 4');
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$recentBookings = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

$featured = mysqli_query($dbc,
    "SELECT c.id, c.brand, c.model, c.production_year, c.price_per_day,
            (SELECT ci.image_path FROM car_images ci
             WHERE ci.car_id = c.id
             ORDER BY ci.is_primary DESC, ci.sort_order ASC, ci.id ASC
             LIMIT 1) AS image_path
     FROM cars c WHERE c.status = 'available'
     ORDER BY c.id DESC LIMIT 3");
if (!$featured) {
    http_response_code(500);
    exit('Could not load cars.');
}
$featuredCars = mysqli_fetch_all($featured, MYSQLI_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Dashboard | DriveLane</title>
<style>
:root{--ink:#162337;--muted:#657285;--brand:#1769e0;--navy:#102039;--line:#e1e7ef;--soft:#f5f7fa}
*{box-sizing:border-box}body{margin:0;font-family:Segoe UI,Arial,sans-serif;color:var(--ink);background:#f7f9fc}a{text-decoration:none;color:inherit}
.container{width:min(1140px,calc(100% - 36px));margin:auto}
header{background:white;border-bottom:1px solid var(--line)}.nav{min-height:76px;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;padding:12px 0}
.logo{font-size:25px;letter-spacing:-1px;font-weight:800}.logo span{color:var(--brand)}nav{display:flex;gap:22px;flex-wrap:wrap;align-items:center}nav a{font-size:14px;font-weight:600;color:#34455d}nav a:hover{color:var(--brand)}
.button{display:inline-block;background:var(--brand);color:white;border-radius:11px;padding:12px 19px;font-weight:700;font-size:14px}.button:hover{background:#125ac3}.button.light{background:white;color:var(--brand)}
.hero{background:linear-gradient(110deg,#102039,#193a66);color:#fff;padding:51px 0 55px}.eyebrow{color:#a9c9ff;text-transform:uppercase;letter-spacing:2px;font-weight:700;font-size:12px}.hero h1{font-size:clamp(30px,4vw,47px);letter-spacing:-1.3px;margin:12px 0}.hero p{color:#d7e1ef;max-width:570px;line-height:1.6;margin:0 0 25px}.hero .links{display:flex;gap:10px;flex-wrap:wrap}
.section{padding-top:34px}.section-head{display:flex;align-items:end;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:18px}.section h2{margin:0;font-size:26px;letter-spacing:-.5px}.more{color:var(--brand);font-weight:700;font-size:14px}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px}.stat{padding:22px;border:1px solid var(--line);background:white;border-radius:14px}.stat .number{font-size:31px;font-weight:800;margin-top:10px}.stat small{font-size:14px;color:var(--muted)}
.panel{background:white;border:1px solid var(--line);border-radius:14px;overflow:hidden}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;text-align:left}th,td{padding:16px 18px;border-bottom:1px solid var(--line);font-size:14px}th{background:#f0f4fa;color:#41516b}tr:last-child td{border-bottom:0}
.pill{display:inline-block;font-size:12px;font-weight:700;border-radius:100px;padding:6px 10px;background:#edf2f7;color:#40526b}.pending{background:#fff1d7;color:#946000}.approved,.returned{background:#e0f6e9;color:#12643b}.rejected,.cancelled{background:#ffe7e7;color:#a42323}.collected{background:#e1efff;color:#1459a2}
.cars{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.car{background:white;border:1px solid var(--line);border-radius:15px;overflow:hidden}.car-image{width:100%;height:190px;object-fit:cover;display:block;background:#e9eef5}.no-image{height:190px;display:grid;place-items:center;background:linear-gradient(130deg,#e9eef7,#dce6f3);color:#71829b;font-weight:700}.car-body{padding:20px}.car h3{margin:0 0 8px;font-size:21px}.car-info{color:var(--muted);font-size:14px}.car-bottom{border-top:1px solid var(--line);margin-top:17px;padding-top:17px;display:flex;justify-content:space-between;align-items:center;gap:10px}.price{font-size:22px;font-weight:800}.price small{font-size:12px;font-weight:400;color:var(--muted)}
.empty{padding:25px;color:var(--muted)}footer{padding:45px 0 30px;color:var(--muted);font-size:13px}
@media(max-width:850px){.stats{grid-template-columns:repeat(2,1fr)}.cars{grid-template-columns:repeat(2,1fr)}}
@media(max-width:580px){.stats,.cars{grid-template-columns:1fr}.hero{padding:36px 0}.nav{justify-content:center}nav{justify-content:center}th,td{padding:11px}}
</style>
</head>
<body>
<?php require __DIR__ . '/header.php'; ?>

<section class="hero"><div class="container">
<div class="eyebrow">Customer dashboard</div>
<h1>Welcome back, <?= e($user['username']) ?>!</h1>
<p>Find your next ride, track rental requests, and manage your trips in one place.</p>
<div class="links"><a class="button" href="index.php#fleet">Explore Cars &rarr;</a><a class="button light" href="my_bookings.php">My Bookings</a></div>
</div></section>
<main class="container">
<section class="section">
<div class="section-head"><h2>Your rental overview</h2></div>
<div class="stats">
<div class="stat"><small>Total bookings</small><div class="number"><?= (int)$stats['total'] ?></div></div>
<div class="stat"><small>Pending requests</small><div class="number"><?= (int)$stats['pending_count'] ?></div></div>
<div class="stat"><small>Approved / collected</small><div class="number"><?= (int)$stats['active_count'] ?></div></div>
<div class="stat"><small>Returned rentals</small><div class="number"><?= (int)$stats['completed_count'] ?></div></div>
</div></section>
<section class="section">
<div class="section-head"><h2>Recent bookings</h2><a href="my_bookings.php" class="more">View all bookings &rarr;</a></div>
<div class="panel table-wrap">
<?php if (!$recentBookings): ?>
<p class="empty">You haven't requested a car yet. <a class="more" href="index.php#fleet">Explore the fleet &rarr;</a></p>
<?php else: ?>
<table><thead><tr><th>Car</th><th>Rental period</th><th>Total</th><th>Status</th></tr></thead><tbody>
<?php foreach ($recentBookings as $booking): ?>
<tr><td><strong><?= e($booking['brand'].' '.$booking['model']) ?></strong></td>
<td><?= e($booking['start_date']) ?> – <?= e($booking['end_date']) ?></td>
<td>€<?= number_format((float)$booking['total_price'], 2) ?></td>
<td><span class="pill <?= e($booking['status']) ?>"><?= e(ucfirst($booking['status'])) ?></span></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
</div></section>
<section class="section">
<div class="section-head"><h2>Explore available cars</h2><a class="more" href="index.php#fleet">Full fleet &rarr;</a></div>
<?php if (!$featuredCars): ?>
<div class="panel empty">No cars are currently listed as available. Check back soon.</div>
<?php else: ?>
<div class="cars">
<?php foreach ($featuredCars as $car): ?>
<article class="car">
<?php if (!empty($car['image_path'])): ?>
<img class="car-image" src="<?= e($car['image_path']) ?>" alt="<?= e($car['brand'].' '.$car['model']) ?>" loading="lazy">
<?php else: ?><div class="no-image">Photo coming soon</div><?php endif; ?>
<div class="car-body"><h3><?= e($car['brand'].' '.$car['model']) ?></h3>
<div class="car-info"><?= (int)$car['production_year'] ?> model · Listed available</div>
<p style="margin:12px 0"><a class="more" href="car_details.php?id=<?= (int)$car['id'] ?>">View Details &rarr;</a></p>
<div class="car-bottom"><div class="price">€<?= number_format((float)$car['price_per_day'],2) ?><small> / day</small></div>
<a class="button" href="book_car.php?id=<?= (int)$car['id'] ?>">Request Rental</a></div></div>
</article>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>
</main>
<footer><div class="container">&copy; <?= date('Y') ?> DriveLane · Car Rental Management System</div></footer>
</body></html>
<?php mysqli_close($dbc); ?>
