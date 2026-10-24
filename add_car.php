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
if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
function e($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }

$error = '';
$values = ['brand' => '', 'model' => '', 'production_year' => '', 'registration_number' => '', 'price_per_day' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        http_response_code(403);
        exit('Invalid form token.');
    }
    foreach ($values as $key => $_) {
        $values[$key] = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
    }
    $brand = $values['brand'];
    $model = $values['model'];
    $year = filter_var($values['production_year'], FILTER_VALIDATE_INT);
    $registration = strtoupper($values['registration_number']);
    $values['registration_number'] = $registration;
    $priceInput = $values['price_per_day'];
    $currentYear = (int) date('Y');

    if ($brand === '' || $model === '' || $registration === '') {
        $error = 'Please fill in all fields.';
    } elseif (strlen($brand) > 50 || strlen($model) > 50 || strlen($registration) > 30) {
        $error = 'One or more fields are too long.';
    } elseif ($year === false || $year < 1900 || $year > $currentYear + 1) {
        $error = 'Please enter a valid production year.';
    } elseif (!preg_match('/^\d+(\.\d{1,2})?$/', $priceInput)) {
        $error = 'Enter a valid daily price with up to 2 decimals.';
    } elseif ((float) $priceInput <= 0 || (float) $priceInput > 99999999.99) {
        $error = 'Daily price must be greater than zero.';
    } else {
        $stmt = mysqli_prepare($dbc, 'SELECT id FROM cars WHERE registration_number = ?');
        mysqli_stmt_bind_param($stmt, 's', $registration);
        mysqli_stmt_execute($stmt);
        $existing = mysqli_stmt_get_result($stmt);
        $alreadyExists = mysqli_num_rows($existing) > 0;
        mysqli_stmt_close($stmt);
        if ($alreadyExists) {
            $error = 'A car with this registration number already exists.';
        } else {
            $price = (float) $priceInput;
            try {
                $stmt = mysqli_prepare($dbc,
                    "INSERT INTO cars (brand, model, production_year, registration_number, price_per_day, status)
                     VALUES (?, ?, ?, ?, ?, 'available')");
                mysqli_stmt_bind_param($stmt, 'ssisd', $brand, $model, $year, $registration, $price);
                mysqli_stmt_execute($stmt);
                $newCarId = mysqli_insert_id($dbc);
                mysqli_stmt_close($stmt);
                header('Location: car_photos.php?id=' . $newCarId);
                exit;
            } catch (mysqli_sql_exception $ex) {
                error_log('Add car failed: ' . $ex->getMessage());
                $error = $ex->getCode() == 1062
                    ? 'A car with this registration number already exists.'
                    : 'Could not add the car. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Add Car | DriveLane</title>
    <link rel="stylesheet" href="car_form.css">
</head>
<body>
<?php require __DIR__ . '/header.php'; ?>
<main class="car-wrap">
    <a class="car-back" href="manage_cars.php">&larr; Back to Manage Cars</a>
    <div class="car-top">
        <div>
            <p class="car-kicker">Fleet management</p>
            <h1>Add a New Car</h1>
            <p class="car-subtitle">Add a vehicle to the DriveLane fleet, then upload its photos.</p>
        </div>
        <a class="car-link" href="staff_dashboard.php">Staff Dashboard</a>
    </div>
    <?php if ($error !== ''): ?><div class="car-alert" role="alert"><?= e($error) ?></div><?php endif; ?>
    <section class="car-card">
        <h2>Vehicle details</h2>
        <p class="hint">All fields are required. New cars are initially set to Available.</p>
        <form method="post" action="add_car.php">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <div class="car-fields">
                <div class="car-field"><label for="brand">Brand</label><input id="brand" name="brand" type="text" maxlength="50" placeholder="e.g. Toyota" required value="<?= e($values['brand']) ?>"></div>
                <div class="car-field"><label for="model">Model</label><input id="model" name="model" type="text" maxlength="50" placeholder="e.g. Corolla" required value="<?= e($values['model']) ?>"></div>
                <div class="car-field"><label for="production_year">Production year</label><input id="production_year" name="production_year" type="number" min="1900" max="<?= (int) date('Y') + 1 ?>" required value="<?= e($values['production_year']) ?>"></div>
                <div class="car-field"><label for="registration_number">Registration number</label><input id="registration_number" name="registration_number" type="text" maxlength="30" placeholder="e.g. KTU004" required value="<?= e($values['registration_number']) ?>"></div>
                <div class="car-field"><label for="price_per_day">Daily rental price (€)</label><input id="price_per_day" name="price_per_day" type="number" min="0.01" max="99999999.99" step="0.01" placeholder="e.g. 45.00" required value="<?= e($values['price_per_day']) ?>"></div>
            </div>
            <div class="car-actions"><button class="car-primary" type="submit">Add Car &amp; Continue to Photos &rarr;</button><a class="car-secondary" href="manage_cars.php">Cancel</a></div>
        </form>
    </section>
    <p class="car-footnote">After saving, you'll go directly to the car photo manager, where you can upload up to 8 images.</p>
</main>
</body>
</html>
