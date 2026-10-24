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
    exit('Access denied. Staff only.');
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Use the selected primary image, or the first image if there is no primary.
// This returns only one image path per car, so cars are not duplicated.
$query = "SELECT c.id, c.brand, c.model, c.production_year,
                 c.registration_number, c.price_per_day, c.status,
                 (SELECT ci.image_path
                  FROM car_images ci
                  WHERE ci.car_id = c.id
                  ORDER BY ci.is_primary DESC, ci.sort_order ASC, ci.id ASC
                  LIMIT 1) AS photo_path
          FROM cars c
          ORDER BY c.id DESC";

$result = mysqli_query($dbc, $query);
if (!$result) {
    http_response_code(500);
    exit('Could not load cars.');
}
$carCount = mysqli_num_rows($result);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage Cars | DriveLane</title>
<style>
* { box-sizing: border-box; }
body { margin: 0; font-family: Arial, sans-serif; background: #f4f7fb; color: #172b4d; }
.main { max-width: 1200px; margin: 0 auto; padding: 36px 20px 60px; }
.breadcrumb { color: #64748b; font-size: 14px; margin-bottom: 16px; }
.breadcrumb a { color: #255ac6; text-decoration: none; }
.heading { display: flex; justify-content: space-between; align-items: center; gap: 18px; flex-wrap: wrap; margin-bottom: 28px; }
h1 { margin: 0 0 10px; font-size: clamp(28px, 4vw, 38px); }
.subtitle { margin: 0; color: #64748b; line-height: 1.5; }
.btn { display: inline-block; padding: 11px 16px; border-radius: 8px; background: #245cde; color: white; text-decoration: none; font-weight: bold; font-size: 14px; }
.btn:hover { background: #1748bb; }
.panel { background: white; border: 1px solid #e0e6ef; border-radius: 14px; overflow: hidden; box-shadow: 0 5px 24px rgba(20, 42, 78, .04); }
.panel-top { display: flex; justify-content: space-between; gap: 10px; align-items: center; padding: 18px 22px; border-bottom: 1px solid #e8edf3; }
.panel-top h2 { margin: 0; font-size: 18px; }
.count { font-size: 13px; color: #64748b; }
.table-wrap { overflow-x: auto; }
table { border-collapse: collapse; width: 100%; min-width: 900px; }
th, td { padding: 15px 16px; text-align: left; border-bottom: 1px solid #e8edf3; vertical-align: middle; }
th { color: #64748b; font-size: 12px; text-transform: uppercase; letter-spacing: .045em; background: #fafbfd; }
td { font-size: 14px; }
tr:last-child td { border-bottom: 0; }
.vehicle { display: flex; gap: 13px; align-items: center; min-width: 210px; }
.thumb { width: 104px; height: 74px; flex: 0 0 104px; border-radius: 9px; object-fit: cover; background: #e7edf5; }
.thumb-placeholder { display: flex; align-items: center; justify-content: center; color: #667991; font-size: 12px; text-align: center; padding: 8px; }
.vehicle strong { display: block; font-size: 15px; margin-bottom: 5px; }
.vehicle small { color: #64748b; }
.price { font-weight: bold; white-space: nowrap; }
.status { border-radius: 20px; display: inline-block; padding: 6px 10px; font-size: 12px; font-weight: bold; white-space: nowrap; }
.status.available { background: #e9f8ee; color: #176a37; }
.status.maintenance { background: #fff5df; color: #986112; }
.status.inactive { background: #fce9ed; color: #ac2741; }
.actions { white-space: nowrap; }
.actions a { display: inline-block; padding: 8px 10px; border: 1px solid #ccd8eb; border-radius: 7px; color: #1d4fba; text-decoration: none; margin: 3px 5px 3px 0; font-weight: bold; font-size: 13px; }
.actions a:hover { background: #edf3ff; }
.empty { padding: 28px; color: #64748b; }
</style>
</head>
<body>
<?php require __DIR__ . '/header.php'; ?>
<main class="main">
  <div class="breadcrumb"><a href="staff_dashboard.php">Staff Dashboard</a> / Manage Cars</div>
  <section class="heading">
    <div>
      <h1>Manage Cars</h1>
      <p class="subtitle">Manage your rental fleet, prices, availability and photographs.</p>
    </div>
    <a class="btn" href="add_car.php">+ Add New Car</a>
  </section>
  <section class="panel">
    <div class="panel-top">
      <h2>Vehicle Fleet</h2>
      <span class="count"><?= $carCount ?> <?= $carCount === 1 ? 'vehicle' : 'vehicles' ?></span>
    </div>
    <?php if ($carCount === 0): ?>
      <div class="empty">No cars have been added yet. Use “Add New Car” to create your first vehicle.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Vehicle</th><th>ID</th><th>Registration</th>
              <th>Price / Day</th><th>Status</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php while ($car = mysqli_fetch_assoc($result)): ?>
            <tr>
              <td>
                <div class="vehicle">
                  <?php
                    // Only render photos stored using our generated upload naming format.
                    $photo = (string)($car['photo_path'] ?? '');
                    $validPhoto = (bool)preg_match('~^uploads/cars/[a-f0-9]{32}\.(jpg|png|webp)$~i', $photo);
                  ?>
                  <?php if ($validPhoto): ?>
                    <img class="thumb" src="<?= e($photo) ?>" alt="<?= e($car['brand'] . ' ' . $car['model']) ?>" loading="lazy">
                  <?php else: ?>
                    <div class="thumb thumb-placeholder">No photo</div>
                  <?php endif; ?>
                  <div>
                    <strong><?= e($car['brand']) ?> <?= e($car['model']) ?></strong>
                    <small><?= (int)$car['production_year'] ?></small>
                  </div>
                </div>
              </td>
              <td>#<?= (int)$car['id'] ?></td>
              <td><?= e($car['registration_number']) ?></td>
              <td class="price">€<?= number_format((float)$car['price_per_day'], 2) ?></td>
              <td><span class="status <?= e($car['status']) ?>"><?= ucfirst(e($car['status'])) ?></span></td>
              <td class="actions">
                <a href="edit_car.php?id=<?= (int)$car['id'] ?>">Edit</a>
                <a href="car_photos.php?id=<?= (int)$car['id'] ?>">Photos</a>
                <a href="car_details.php?id=<?= (int)$car['id'] ?>">View</a>
              </td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
<?php mysqli_close($dbc); ?>
