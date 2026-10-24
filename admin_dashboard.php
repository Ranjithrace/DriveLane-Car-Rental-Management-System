<?php
session_start();
require_once __DIR__ . '/database.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$stmt = mysqli_prepare($dbc, 'SELECT username, role FROM users WHERE id = ?');
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$admin = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$admin || $admin['role'] !== 'admin') {
    http_response_code(403);
    exit('Access denied. Administrators only.');
}

function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function getCount(mysqli $connection, string $query): int {
    $result = mysqli_query($connection, $query);
    if (!$result) {
        error_log('Admin dashboard count query failed: ' . mysqli_error($connection));
        http_response_code(500);
        exit('Unable to load dashboard statistics.');
    }
    $row = mysqli_fetch_assoc($result);
    mysqli_free_result($result);
    return (int) ($row['total'] ?? 0);
}

$stats = [
    'users' => getCount($dbc, 'SELECT COUNT(*) AS total FROM users'),
    'customers' => getCount($dbc, "SELECT COUNT(*) AS total FROM users WHERE role = 'customer'"),
    'staff' => getCount($dbc, "SELECT COUNT(*) AS total FROM users WHERE role = 'staff'"),
    'cars' => getCount($dbc, 'SELECT COUNT(*) AS total FROM cars'),
    'available_cars' => getCount($dbc, "SELECT COUNT(*) AS total FROM cars WHERE status = 'available'"),
    'bookings' => getCount($dbc, 'SELECT COUNT(*) AS total FROM bookings'),
    'pending' => getCount($dbc, "SELECT COUNT(*) AS total FROM bookings WHERE status = 'pending'"),
    'approved' => getCount($dbc, "SELECT COUNT(*) AS total FROM bookings WHERE status = 'approved'"),
];

$recentResult = mysqli_query($dbc,
    "SELECT b.id, b.status, b.start_date, b.end_date,
            u.username AS customer_name, c.brand, c.model
     FROM bookings b
     INNER JOIN users u ON b.customer_id = u.id
     INNER JOIN cars c ON b.car_id = c.id
     ORDER BY b.created_at DESC, b.id DESC
     LIMIT 5"
);
if (!$recentResult) {
    error_log('Admin recent bookings query failed: ' . mysqli_error($dbc));
    http_response_code(500);
    exit('Unable to load recent bookings.');
}
$recentBookings = mysqli_fetch_all($recentResult, MYSQLI_ASSOC);
mysqli_free_result($recentResult);

$cards = [
    ['label' => 'Total users', 'value' => $stats['users'], 'icon' => '👥'],
    ['label' => 'Customers', 'value' => $stats['customers'], 'icon' => '👤'],
    ['label' => 'Rental staff', 'value' => $stats['staff'], 'icon' => '🛠️'],
    ['label' => 'Total cars', 'value' => $stats['cars'], 'icon' => '🚘'],
    ['label' => 'Available cars', 'value' => $stats['available_cars'], 'icon' => '✓'],
    ['label' => 'Total bookings', 'value' => $stats['bookings'], 'icon' => '📋'],
    ['label' => 'Pending bookings', 'value' => $stats['pending'], 'icon' => '◷'],
    ['label' => 'Approved bookings', 'value' => $stats['approved'], 'icon' => '✓'],
];
$validStatuses = ['pending', 'approved', 'rejected', 'cancelled', 'collected', 'returned'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administrator Dashboard | DriveLane</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f4f7fb; color: #112846; font-family: Inter, 'Segoe UI', Arial, sans-serif; }
        .adm-container { width: min(1240px, calc(100% - 40px)); margin: 0 auto; }
        .adm-main { padding: 40px 0 72px; }
        .adm-eyebrow { color: #2563eb; font-size: 12px; font-weight: 800; letter-spacing: 2px; text-transform: uppercase; }
        .adm-top { display: flex; align-items: center; justify-content: space-between; gap: 24px; flex-wrap: wrap; margin: 12px 0 28px; }
        .adm-top h1 { font-size: clamp(30px, 4vw, 44px); letter-spacing: -1.5px; margin: 0 0 10px; }
        .adm-subtitle { margin: 0; color: #60718a; line-height: 1.6; }
        .adm-link { display: inline-flex; justify-content: center; align-items: center; text-decoration: none; background: #1769e0; color: white; padding: 12px 18px; font-weight: 700; border-radius: 11px; font-size: 14px; }
        .adm-link:hover { background: #115bc5; }
        .adm-actions { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 16px; margin-bottom: 32px; }
        .adm-action { display: flex; gap: 14px; align-items: center; padding: 20px; border-radius: 16px; border: 1px solid #dfe7f1; background: #fff; text-decoration: none; color: #122b4d; box-shadow: 0 7px 24px #10274408; }
        .adm-action:hover { border-color: #8fb6f0; }
        .adm-action-icon { display: grid; place-items: center; flex: 0 0 44px; width: 44px; height: 44px; border-radius: 13px; background: #ecf3ff; font-size: 22px; }
        .adm-action strong, .adm-action small { display: block; }
        .adm-action small { color: #697a90; margin-top: 5px; line-height: 1.45; }
        .adm-heading { margin: 0 0 18px; font-size: 23px; letter-spacing: -.5px; }
        .adm-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-bottom: 36px; }
        .adm-stat { border: 1px solid #dfe7f1; border-radius: 17px; padding: 23px; background: white; }
        .adm-stat-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 14px; }
        .adm-stat-label { color: #60718a; font-size: 14px; }
        .adm-stat-icon { color: #2468d7; font-size: 21px; }
        .adm-stat-number { margin: 0; font-size: 34px; font-weight: 800; font-variant-numeric: tabular-nums; }
        .adm-panel { border: 1px solid #dfe7f1; border-radius: 18px; background: #fff; overflow: hidden; }
        .adm-panel-header { padding: 22px 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .adm-panel-header h2 { margin: 0; font-size: 22px; }
        .adm-secondary-link { color: #1769e0; font-size: 14px; font-weight: 700; text-decoration: none; }
        .adm-table-wrap { overflow-x: auto; }
        .adm-table { width: 100%; border-collapse: collapse; font-size: 14px; min-width: 730px; }
        .adm-table th { background: #edf2f9; text-align: left; color: #3d5572; font-size: 13px; }
        .adm-table th, .adm-table td { padding: 17px 20px; border-top: 1px solid #e5ebf2; }
        .adm-table td { color: #203750; }
        .adm-vehicle { font-weight: 700; }
        .adm-badge { display: inline-flex; padding: 8px 12px; border-radius: 999px; font-size: 12px; font-weight: 800; background: #e8edf3; color: #40536c; }
        .adm-badge.pending { color: #945a06; background: #fff1d9; }
        .adm-badge.approved { color: #14633b; background: #dbf7e8; }
        .adm-badge.rejected, .adm-badge.cancelled { color: #a8212b; background: #ffe6e7; }
        .adm-badge.collected { color: #235ac2; background: #e5efff; }
        .adm-badge.returned { color: #4d6077; background: #e9eef4; }
        .adm-empty { padding: 26px 24px; color: #62728a; border-top: 1px solid #e5ebf2; }
        .adm-note { margin: 18px 0 0; color: #62728a; font-size: 13px; }
        @media (max-width: 900px) { .adm-stats { grid-template-columns: repeat(2, minmax(0,1fr)); } .adm-actions { grid-template-columns: 1fr; } }
        @media (max-width: 540px) { .adm-container { width: calc(100% - 28px); } .adm-main { padding-top: 26px; } .adm-stats { gap: 10px; } .adm-stat { padding: 16px; } .adm-stat-number { font-size: 28px; } }
    </style>
</head>
<body>
<?php require __DIR__ . '/header.php'; ?>
<main class="adm-main">
    <div class="adm-container">
        <div class="adm-eyebrow">Administrator / System oversight</div>
        <div class="adm-top">
            <div>
                <h1>Welcome back, <?= e($admin['username']) ?>!</h1>
                <p class="adm-subtitle">Manage access and monitor the DriveLane rental system in one place.</p>
            </div>
            <a class="adm-link" href="admin_users.php">Manage Users &rarr;</a>
        </div>

        <div class="adm-actions" aria-label="Administrator sections">
            <a class="adm-action" href="admin_users.php"><span class="adm-action-icon" aria-hidden="true">👥</span><span><strong>User management</strong><small>Review accounts and manage staff permissions</small></span></a>
            <a class="adm-action" href="admin_cars.php"><span class="adm-action-icon" aria-hidden="true">🚘</span><span><strong>All cars</strong><small>View vehicles, rates and fleet status</small></span></a>
            <a class="adm-action" href="admin_bookings.php"><span class="adm-action-icon" aria-hidden="true">📋</span><span><strong>All bookings</strong><small>Monitor rental requests and statuses</small></span></a>
            <a class="adm-action" href="admin_activity.php"><span class="adm-action-icon" aria-hidden="true">🕒</span><span><strong>Activity logs</strong><small>See who changed each booking and when</small></span></a>
            <a class="adm-action" href="admin_reports.php"><span class="adm-action-icon" aria-hidden="true">📊</span><span><strong>Reports &amp; statistics</strong><small>Rental trends, booking statuses and most requested cars</small></span></a>
        </div>

        <h2 class="adm-heading">System overview</h2>
        <div class="adm-stats">
            <?php foreach ($cards as $card): ?>
                <div class="adm-stat">
                    <div class="adm-stat-top"><span class="adm-stat-label"><?= e($card['label']) ?></span><span class="adm-stat-icon" aria-hidden="true"><?= e($card['icon']) ?></span></div>
                    <p class="adm-stat-number"><?= (int) $card['value'] ?></p>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="adm-panel">
            <div class="adm-panel-header">
                <h2>Recent rental bookings</h2>
                <a href="admin_bookings.php" class="adm-secondary-link">View all bookings &rarr;</a>
            </div>
            <?php if ($recentBookings): ?>
                <div class="adm-table-wrap">
                    <table class="adm-table">
                        <thead><tr><th>Booking</th><th>Customer</th><th>Vehicle</th><th>Start date</th><th>End date</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentBookings as $booking): ?>
                            <?php $status = in_array($booking['status'], $validStatuses, true) ? $booking['status'] : ''; ?>
                            <tr>
                                <td>#<?= (int) $booking['id'] ?></td>
                                <td><?= e($booking['customer_name']) ?></td>
                                <td class="adm-vehicle"><?= e($booking['brand'] . ' ' . $booking['model']) ?></td>
                                <td><?= e($booking['start_date']) ?></td>
                                <td><?= e($booking['end_date']) ?></td>
                                <td><span class="adm-badge <?= e($status) ?>"><?= e(ucfirst($booking['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="adm-empty">No bookings have been submitted yet.</div>
            <?php endif; ?>
        </div>
        <p class="adm-note">Administrator access is supervisory. Rental staff approve requests and manage vehicle handovers.</p>
    </div>
</main>
</body>
</html>
<?php mysqli_close($dbc); ?>
