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
$admin = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$admin || $admin['role'] !== 'admin') {
    http_response_code(403);
    exit('Access denied. Administrators only.');
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Read-only fleet overview. A subquery chooses one uploaded picture per car.
$sql = "SELECT c.id, c.brand, c.model, c.production_year,
               c.registration_number, c.price_per_day, c.status, c.created_at,
               (SELECT ci.image_path FROM car_images ci
                WHERE ci.car_id = c.id
                ORDER BY ci.is_primary DESC, ci.sort_order ASC, ci.id ASC
                LIMIT 1) AS photo_path
        FROM cars c
        ORDER BY c.id DESC";
$result = mysqli_query($dbc, $sql);
if (!$result) {
    http_response_code(500);
    exit('Could not load cars.');
}
$cars = mysqli_fetch_all($result, MYSQLI_ASSOC);
$counts = ['total' => count($cars), 'available' => 0, 'maintenance' => 0, 'inactive' => 0];
foreach ($cars as $car) {
    if (array_key_exists($car['status'], $counts)) {
        $counts[$car['status']]++;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>All Cars | DriveLane Administrator</title>
<style>
* { box-sizing: border-box; }
body { margin: 0; font-family: Inter, Segoe UI, Arial, sans-serif; color: #172b4d; background: #f5f7fb; }
.ac-container { width: min(1200px, calc(100% - 36px)); margin: 0 auto; }
.ac-hero { background: linear-gradient(115deg, #142e50, #214b79); color: #fff; padding: 48px 0; }
.ac-kicker { color: #aad0ff; text-transform: uppercase; letter-spacing: .14em; font-weight: 750; font-size: 12px; }
.ac-hero h1 { margin: 12px 0; font-size: clamp(30px, 4vw, 43px); letter-spacing: -.035em; }
.ac-hero p { color: #d9e7f6; margin: 0; line-height: 1.6; }
.ac-main { padding: 32px 0 66px; }
.ac-actions { display: flex; flex-wrap: wrap; gap: 10px; margin: 0 0 25px; }
.ac-link { padding: 11px 15px; border: 1px solid #cbd8ea; background: white; border-radius: 10px; color: #1754a5; text-decoration: none; font-weight: 700; font-size: 14px; }
.ac-link:hover { background: #ebf3ff; }
.ac-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 30px; }
.ac-stat { background: white; border: 1px solid #e0e6ee; border-radius: 14px; padding: 21px; box-shadow: 0 5px 22px #20345508; }
.ac-stat span { color: #69778b; font-size: 13px; font-weight: 700; }
.ac-stat strong { display: block; margin-top: 12px; font-size: 32px; color: #17365c; }
.ac-section-head { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
.ac-section-head h2 { margin: 0; font-size: 23px; }
.ac-section-head p { color: #657285; margin: 0; font-size: 13px; }
.ac-table-wrap { background: white; border: 1px solid #e0e6ee; border-radius: 15px; overflow-x: auto; box-shadow: 0 5px 22px #20345508; }
.ac-table { border-collapse: collapse; width: 100%; min-width: 930px; }
.ac-table th { background: #f0f4fa; color: #4c5c72; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; text-align: left; }
.ac-table th, .ac-table td { padding: 14px; border-bottom: 1px solid #e8edf4; vertical-align: middle; }
.ac-table tbody tr:last-child td { border-bottom: 0; }
.ac-table tbody tr:hover { background: #fafcff; }
.ac-vehicle { display: flex; align-items: center; gap: 12px; min-width: 190px; }
.ac-photo { width: 82px; height: 59px; border-radius: 9px; background: #e8eef5; flex-shrink: 0; object-fit: cover; }
.ac-photo-placeholder { display: flex; align-items: center; justify-content: center; font-size: 28px; }
.ac-vehicle-name { font-weight: 750; color: #142a47; }
.ac-sub { display: block; color: #6d7b8d; font-size: 12px; margin-top: 4px; }
.ac-price { font-weight: 750; white-space: nowrap; }
.ac-badge { display: inline-block; padding: 7px 10px; border-radius: 30px; font-size: 12px; font-weight: 800; white-space: nowrap; }
.ac-available { background: #e3f5eb; color: #15633f; }
.ac-maintenance { background: #fff0d9; color: #925500; }
.ac-inactive { background: #fce7e9; color: #a92233; }
.ac-empty { background: white; border-radius: 14px; border: 1px dashed #c6d2df; padding: 40px; text-align: center; color: #60718a; }
.ac-view { color: #195cbb; font-weight: 750; text-decoration: none; white-space: nowrap; }
.ac-view:hover { text-decoration: underline; }
@media (max-width: 760px) { .ac-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } .ac-hero { padding: 35px 0; } }
</style>
</head>
<body>
<?php require __DIR__ . '/header.php'; ?>
<section class="ac-hero"><div class="ac-container">
    <div class="ac-kicker">Administrator · Fleet oversight</div>
    <h1>All Rental Cars</h1>
    <p>View vehicles, current listing status and daily prices. Vehicle changes are handled by Rental Staff.</p>
</div></section>
<main class="ac-container ac-main">
    <div class="ac-actions">
        <a class="ac-link" href="admin_dashboard.php">← Dashboard</a>
        <a class="ac-link" href="admin_users.php">Manage Users</a>
        <a class="ac-link" href="admin_bookings.php">All Bookings</a>
    </div>
    <div class="ac-stats">
        <div class="ac-stat"><span>Total Cars</span><strong><?= $counts['total'] ?></strong></div>
        <div class="ac-stat"><span>Available</span><strong><?= $counts['available'] ?></strong></div>
        <div class="ac-stat"><span>Maintenance</span><strong><?= $counts['maintenance'] ?></strong></div>
        <div class="ac-stat"><span>Inactive</span><strong><?= $counts['inactive'] ?></strong></div>
    </div>
    <div class="ac-section-head">
        <h2>Registered Vehicles</h2>
        <p>Read-only overview · <?= $counts['total'] ?> <?= $counts['total'] === 1 ? 'vehicle' : 'vehicles' ?></p>
    </div>
    <?php if (!$cars): ?>
        <div class="ac-empty">No cars have been registered yet.</div>
    <?php else: ?>
    <div class="ac-table-wrap"><table class="ac-table">
        <thead><tr>
            <th>ID</th><th>Vehicle</th><th>Registration</th><th>Price / Day</th><th>Status</th><th>Added On</th><th>Details</th>
        </tr></thead>
        <tbody>
        <?php foreach ($cars as $car): ?>
            <tr>
                <td>#<?= (int)$car['id'] ?></td>
                <td><div class="ac-vehicle">
                    <?php if (!empty($car['photo_path'])): ?>
                        <img class="ac-photo" src="<?= e($car['photo_path']) ?>" alt="<?= e($car['brand'] . ' ' . $car['model']) ?>" loading="lazy" onerror="this.style.display='none'">
                    <?php else: ?>
                        <span class="ac-photo ac-photo-placeholder" aria-hidden="true">🚘</span>
                    <?php endif; ?>
                    <div><span class="ac-vehicle-name"><?= e($car['brand']) ?> <?= e($car['model']) ?></span>
                    <span class="ac-sub"><?= (int)$car['production_year'] ?> model</span></div>
                </div></td>
                <td><?= e($car['registration_number']) ?></td>
                <td class="ac-price">€<?= number_format((float)$car['price_per_day'], 2) ?></td>
                <td><span class="ac-badge ac-<?= e($car['status']) ?>"><?= e(ucfirst($car['status'])) ?></span></td>
                <td><?= e($car['created_at']) ?></td>
                <td><a class="ac-view" href="car_details.php?id=<?= (int)$car['id'] ?>">View Details →</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</main>
</body>
</html>
<?php mysqli_close($dbc); ?>
