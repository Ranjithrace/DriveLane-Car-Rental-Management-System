<?php
session_start();
require 'database.php';
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function photoUrl($path) {
    // DB paths come from validated staff uploads, but do not trust path values blindly.
    if (!is_string($path) || !preg_match('~^uploads/cars/[a-zA-Z0-9_-]+\.(?:jpg|jpeg|png|webp)$~i', $path)) return null;
    return $path;
}
$carId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$carId || $carId < 1) { http_response_code(400); exit('Invalid car ID.'); }
$stmt = mysqli_prepare($dbc, 'SELECT id, brand, model, production_year, registration_number, price_per_day, status FROM cars WHERE id = ?');
mysqli_stmt_bind_param($stmt, 'i', $carId);
mysqli_stmt_execute($stmt);
$car = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$car) { http_response_code(404); exit('Car not found.'); }
$stmt = mysqli_prepare($dbc, 'SELECT image_path FROM car_images WHERE car_id = ? ORDER BY is_primary DESC, sort_order ASC, id ASC');
mysqli_stmt_bind_param($stmt, 'i', $carId);
mysqli_stmt_execute($stmt);
$photos = [];
$res = mysqli_stmt_get_result($stmt);
while ($r = mysqli_fetch_assoc($res)) {
    $url = photoUrl($r['image_path']);
    if ($url !== null) $photos[] = $url;
}
$account = null;
if (!empty($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $stmt = mysqli_prepare($dbc, 'SELECT username, role FROM users WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $uid);
    mysqli_stmt_execute($stmt);
    $account = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}
$dashboards = ['customer' => 'customer_dashboard.php', 'staff' => 'staff_dashboard.php', 'admin' => 'admin_dashboard.php'];
$isAvailable = ($car['status'] === 'available');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($car['brand'] . ' ' . $car['model']) ?> | DriveLane</title>
<style>
:root{--ink:#162337;--muted:#657285;--line:#e1e7ef;--blue:#1769e0}
*{box-sizing:border-box}body{margin:0;font-family:Inter,'Segoe UI',Arial,sans-serif;color:var(--ink);background:#f6f8fb}
a{text-decoration:none;color:inherit}.container{width:min(1130px,calc(100% - 36px));margin:auto}
.header{background:white;border-bottom:1px solid var(--line)}.nav{min-height:76px;display:flex;align-items:center;justify-content:space-between;gap:22px;flex-wrap:wrap;padding:12px 0}.logo{font-weight:800;font-size:26px;letter-spacing:-1px}.logo span{color:var(--blue)}.nav-links{display:flex;gap:17px;align-items:center;flex-wrap:wrap;font-size:14px;font-weight:650}
main{padding:34px 0 75px}.back{font-size:14px;color:#43658b;display:inline-block;margin-bottom:22px}.page-head{display:flex;justify-content:space-between;align-items:start;gap:18px;flex-wrap:wrap;margin-bottom:24px}.page-head h1{font-size:clamp(30px,4vw,43px);letter-spacing:-1.5px;margin:0 0 8px}.sub{color:var(--muted);margin:0}.pill{display:inline-block;padding:9px 14px;border-radius:30px;background:#e1f4e9;color:#16683e;font-weight:750;font-size:13px}.pill.maintenance{background:#fff1d8;color:#935a10}.pill.inactive{background:#f1e7e7;color:#a12626}
.layout{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(290px,1fr);gap:25px}.panel{background:white;border:1px solid var(--line);border-radius:19px;overflow:hidden}.gallery{padding:16px}.main-photo{height:420px;background:linear-gradient(135deg,#e6edf5,#d7e2ed);display:flex;align-items:center;justify-content:center;border-radius:13px;overflow:hidden;color:#637c98;font-weight:600}.main-photo img{width:100%;height:100%;object-fit:contain}.placeholder{font-size:60px}.thumbs{display:flex;gap:11px;margin-top:14px;overflow-x:auto;padding-bottom:5px}.thumb{flex:0 0 100px;height:78px;border:2px solid transparent;border-radius:10px;background:#eff4f9;padding:0;overflow:hidden;cursor:pointer}.thumb.active{border-color:var(--blue)}.thumb img{height:100%;width:100%;object-fit:cover}.photo-count{color:var(--muted);font-size:13px;margin-top:8px}
.details{padding:26px}.details h2{font-size:22px;margin:0 0 22px}.price{font-size:38px;font-weight:800;letter-spacing:-1px}.price small{font-size:14px;color:var(--muted);font-weight:400;letter-spacing:0}.info{margin:20px 0;padding:16px 0;border-top:1px solid var(--line);border-bottom:1px solid var(--line);display:grid;gap:15px}.line{display:flex;justify-content:space-between;gap:18px}.line span:first-child{color:var(--muted)}.button{display:block;background:var(--blue);color:white;padding:15px 18px;border-radius:10px;font-weight:750;text-align:center}.button.secondary{background:#e8f0fc;color:#1757a8}.button.disabled{background:#e7eaf0;color:#707985;cursor:not-allowed}.note{font-size:13px;color:var(--muted);line-height:1.65;margin:15px 0 0}
@media(max-width:800px){.layout{grid-template-columns:1fr}.main-photo{height:320px}}@media(max-width:480px){.main-photo{height:230px}.thumb{flex-basis:78px;height:61px}.details{padding:20px}}
</style>
</head>
<body>
<header class="header"><div class="container nav"><a class="logo" href="index.php">Drive<span>Lane</span>.</a><nav class="nav-links"><a href="index.php#fleet">Our Cars</a><?php if ($account): ?><a href="<?= e($dashboards[$account['role']] ?? 'index.php') ?>">My Dashboard</a><a href="logout.php">Logout</a><?php else: ?><a href="login.php">Login</a><a href="register.php">Register</a><?php endif; ?></nav></div></header>
<main class="container">
<a class="back" href="index.php#fleet">← Back to all cars</a>
<div class="page-head"><div><h1><?= e($car['brand']) ?> <?= e($car['model']) ?></h1><p class="sub"><?= (int)$car['production_year'] ?> model · <?= e($car['registration_number']) ?></p></div><span class="pill <?= e($car['status']) ?>"><?= e(ucfirst($car['status'])) ?></span></div>
<div class="layout">
<section class="panel gallery" aria-label="Car photographs">
<div class="main-photo" id="mainPhoto">
<?php if ($photos): ?><img id="featuredImage" src="<?= e($photos[0]) ?>" alt="<?= e($car['brand'] . ' ' . $car['model']) ?> - photo 1"><?php else: ?><span class="placeholder" aria-label="No car photos uploaded">🚘</span><?php endif; ?>
</div>
<?php if ($photos): ?>
<div class="thumbs" aria-label="Choose photo">
<?php foreach ($photos as $i => $path): ?>
<button type="button" class="thumb <?= $i === 0 ? 'active' : '' ?>" data-photo="<?= e($path) ?>" data-number="<?= $i + 1 ?>" aria-label="Show car photo <?= $i + 1 ?>" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>"><img src="<?= e($path) ?>" alt="Car thumbnail <?= $i + 1 ?>" loading="lazy"></button>
<?php endforeach; ?>
</div><p class="photo-count"><?= count($photos) ?> photo<?= count($photos) === 1 ? '' : 's' ?> uploaded by rental staff</p>
<?php else: ?><p class="photo-count">No photographs have been uploaded for this car yet.</p><?php endif; ?>
</section>
<aside class="panel details"><h2>Rental information</h2><div class="price">€<?= number_format((float)$car['price_per_day'], 2) ?><small> / day</small></div>
<div class="info"><div class="line"><span>Brand</span><strong><?= e($car['brand']) ?></strong></div><div class="line"><span>Model</span><strong><?= e($car['model']) ?></strong></div><div class="line"><span>Production year</span><strong><?= (int)$car['production_year'] ?></strong></div><div class="line"><span>Registration</span><strong><?= e($car['registration_number']) ?></strong></div><div class="line"><span>Catalogue status</span><strong><?= e(ucfirst($car['status'])) ?></strong></div></div>
<?php if (!$isAvailable): ?>
<span class="button disabled">Currently unavailable</span>
<?php elseif (!$account): ?>
<a class="button" href="login.php">Login to Request Rental →</a><p class="note">Guests can explore every car. Create an account or log in to request rental dates.</p>
<?php elseif ($account['role'] === 'customer'): ?>
<a class="button" href="book_car.php?id=<?= (int)$car['id'] ?>">Request Rental →</a>
<?php else: ?>
<a class="button secondary" href="<?= e($dashboards[$account['role']] ?? 'index.php') ?>">Back to Dashboard →</a>
<?php endif; ?>
<p class="note">A car listed as Available may already be reserved for certain dates. Rental staff confirms booking requests after reviewing availability.</p>
</aside></div></main>
<script>
const mainImage = document.getElementById('featuredImage');
if (mainImage) {
  const thumbs = document.querySelectorAll('.thumb[data-photo]');
  thumbs.forEach(button => button.addEventListener('click', () => {
    mainImage.src = button.dataset.photo;
    mainImage.alt = <?= json_encode($car['brand'] . ' ' . $car['model'] . ' - photo ', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> + button.dataset.number;
    thumbs.forEach(other => { other.classList.remove('active'); other.setAttribute('aria-pressed', 'false'); });
    button.classList.add('active'); button.setAttribute('aria-pressed', 'true');
  }));
}
</script>
</body></html>
<?php mysqli_close($dbc); ?>
