<?php
session_start();
require 'database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$userId = (int) $_SESSION['user_id'];
$stmt = mysqli_prepare($dbc, 'SELECT role FROM users WHERE id = ?');
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$user || $user['role'] !== 'staff') {
    http_response_code(403);
    exit('Access denied. Staff only.');
}
function e($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$carId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$carId || $carId < 1) {
    http_response_code(400);
    exit('Invalid car ID.');
}
$stmt = mysqli_prepare($dbc, 'SELECT * FROM cars WHERE id = ?');
mysqli_stmt_bind_param($stmt, 'i', $carId);
mysqli_stmt_execute($stmt);
$car = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$car) {
    http_response_code(404);
    exit('Car not found.');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        http_response_code(403);
        exit('Invalid form token.');
    }
    $brand = is_string($_POST['brand'] ?? null) ? trim($_POST['brand']) : '';
    $model = is_string($_POST['model'] ?? null) ? trim($_POST['model']) : '';
    $yearRaw = is_string($_POST['production_year'] ?? null) ? $_POST['production_year'] : '';
    $year = filter_var($yearRaw, FILTER_VALIDATE_INT);
    $registration = is_string($_POST['registration_number'] ?? null) ? strtoupper(trim($_POST['registration_number'])) : '';
    $priceInput = is_string($_POST['price_per_day'] ?? null) ? trim($_POST['price_per_day']) : '';
    $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
    if ($brand === '' || $model === '' || $registration === '') {
        $error = 'All fields are required.';
    } elseif (strlen($brand) > 50 || strlen($model) > 50 || strlen($registration) > 30) {
        $error = 'One or more fields are too long.';
    } elseif ($year === false || $year < 1900 || $year > (int) date('Y') + 1) {
        $error = 'Invalid production year.';
    } elseif (!preg_match('/^\d+(\.\d{1,2})?$/', $priceInput) || (float) $priceInput <= 0 || (float) $priceInput > 99999999.99) {
        $error = 'Invalid price per day.';
    } elseif (!in_array($status, ['available', 'maintenance', 'inactive'], true)) {
        $error = 'Invalid car status.';
    } else {
        $stmt = mysqli_prepare($dbc, 'SELECT id FROM cars WHERE registration_number = ? AND id <> ?');
        mysqli_stmt_bind_param($stmt, 'si', $registration, $carId);
        mysqli_stmt_execute($stmt);
        $duplicate = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
        mysqli_stmt_close($stmt);
        if ($duplicate) {
            $error = 'Registration number already belongs to another car.';
        } else {
            $price = (float) $priceInput;
            try {
                $stmt = mysqli_prepare($dbc,
                    'UPDATE cars SET brand = ?, model = ?, production_year = ?, registration_number = ?, price_per_day = ?, status = ? WHERE id = ?');
                mysqli_stmt_bind_param($stmt, 'ssisdsi', $brand, $model, $year, $registration, $price, $status, $carId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                header('Location: manage_cars.php?updated=1');
                exit;
            } catch (mysqli_sql_exception $ex) {
                error_log('Edit car failed: ' . $ex->getMessage());
                $error = $ex->getCode() == 1062
                    ? 'Registration number already belongs to another car.'
                    : 'Could not update the car. Please try again.';
            }
        }
    }
    // Preserve entered values when validation fails.
    $car['brand'] = $brand;
    $car['model'] = $model;
    $car['production_year'] = $yearRaw;
    $car['registration_number'] = $registration;
    $car['price_per_day'] = $priceInput;
    $car['status'] = $status;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Car | DriveLane</title>
    <link rel="stylesheet" href="car_form.css">
</head>
<body>
<?php require __DIR__ . '/header.php'; ?>
<main class="car-wrap">
    <a class="car-back" href="manage_cars.php">&larr; Back to Manage Cars</a>
    <div class="car-top">
        <div>
            <p class="car-kicker">Fleet management</p>
            <h1>Edit <?= e($car['brand'] . ' ' . $car['model']) ?></h1>
            <p class="car-subtitle">Vehicle #<?= (int) $carId ?> &middot; Update vehicle details, pricing and availability.</p>
        </div>
        <a class="car-link" href="car_photos.php?id=<?= (int) $carId ?>">Manage Photos &rarr;</a>
    </div>
    <?php if ($error !== ''): ?><div class="car-alert" role="alert"><?= e($error) ?></div><?php endif; ?>
    <section class="car-card">
        <h2>Vehicle details</h2>
        <p class="hint">Changes to the vehicle's status determine whether new rental requests can be made.</p>
        <form method="post" action="edit_car.php?id=<?= (int) $carId ?>">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <div class="car-fields">
                <div class="car-field"><label for="brand">Brand</label><input id="brand" name="brand" type="text" maxlength="50" required value="<?= e($car['brand']) ?>"></div>
                <div class="car-field"><label for="model">Model</label><input id="model" name="model" type="text" maxlength="50" required value="<?= e($car['model']) ?>"></div>
                <div class="car-field"><label for="production_year">Production year</label><input id="production_year" name="production_year" type="number" min="1900" max="<?= (int) date('Y') + 1 ?>" required value="<?= e($car['production_year']) ?>"></div>
                <div class="car-field"><label for="registration_number">Registration number</label><input id="registration_number" name="registration_number" type="text" maxlength="30" required value="<?= e($car['registration_number']) ?>"></div>
                <div class="car-field"><label for="price_per_day">Daily rental price (€)</label><input id="price_per_day" name="price_per_day" type="number" min="0.01" max="99999999.99" step="0.01" required value="<?= e($car['price_per_day']) ?>"></div>
                <div class="car-field"><label for="status">Availability status</label><select id="status" name="status" required><?php foreach (['available', 'maintenance', 'inactive'] as $option): ?><option value="<?= e($option) ?>" <?= $car['status'] === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="car-actions"><button class="car-primary" type="submit">Save Changes</button><a class="car-secondary" href="manage_cars.php">Cancel</a></div>
        </form>
    </section>
    <p class="car-footnote">To change the vehicle's main photograph or remove images, use <a href="car_photos.php?id=<?= (int) $carId ?>">Manage Photos</a>.</p>
</main>
</body>
</html>
