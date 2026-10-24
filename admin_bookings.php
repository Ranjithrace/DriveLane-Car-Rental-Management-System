<?php
session_start();
require __DIR__ . '/database.php';

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

$query = "SELECT b.id, b.start_date, b.end_date,
                 b.total_price, b.status, b.created_at,
                 customer.username AS customer_name,
                 c.brand, c.model, c.registration_number,
                 reviewer.username AS reviewed_by_name
          FROM bookings b
          INNER JOIN users customer ON b.customer_id = customer.id
          INNER JOIN cars c ON b.car_id = c.id
          LEFT JOIN users reviewer ON b.reviewed_by = reviewer.id
          ORDER BY b.created_at DESC, b.id DESC";
$bookingsResult = mysqli_query($dbc, $query);
if (!$bookingsResult) {
    http_response_code(500);
    exit('Could not load bookings.');
}
$bookings = mysqli_fetch_all($bookingsResult, MYSQLI_ASSOC);
mysqli_free_result($bookingsResult);

$counts = array_fill_keys(['pending', 'approved', 'rejected', 'cancelled', 'collected', 'returned'], 0);
foreach ($bookings as $booking) {
    if (isset($counts[$booking['status']])) {
        $counts[$booking['status']]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>All Bookings | DriveLane Administrator</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f5f7fb; color: #172b4d; font-family: Inter, Segoe UI, Arial, sans-serif; }
        .admin-main { max-width: 1240px; margin: 0 auto; padding: 38px 20px 70px; }
        .eyebrow { color: #1769e0; font-size: 12px; font-weight: 800; letter-spacing: 1.7px; text-transform: uppercase; }
        .page-heading { display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 18px; margin: 10px 0 28px; }
        .page-heading h1 { margin: 0 0 8px; font-size: clamp(28px, 4vw, 37px); letter-spacing: -.8px; }
        .subtext { margin: 0; color: #65758d; line-height: 1.6; }
        .admin-link { display: inline-flex; padding: 11px 16px; border-radius: 10px; background: white; color: #195ab6; border: 1px solid #cddcf0; font-weight: 700; font-size: 14px; text-decoration: none; }
        .stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-bottom: 25px; }
        .stat { padding: 21px 22px; background: white; border: 1px solid #e1e8f1; border-radius: 15px; box-shadow: 0 5px 22px rgba(16,39,68,.035); }
        .stat-label { color: #66778f; font-size: 13px; font-weight: 650; }
        .stat-value { margin-top: 9px; font-size: 30px; font-weight: 800; color: #132a49; }
        .card { background: white; border: 1px solid #e1e8f1; border-radius: 17px; overflow: hidden; box-shadow: 0 7px 24px rgba(16,39,68,.04); }
        .card-top { padding: 22px 24px; border-bottom: 1px solid #e8edf4; display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap; }
        .card-top h2 { margin: 0 0 5px; font-size: 20px; }
        .card-top p { margin: 0; color: #69788d; font-size: 13px; }
        .read-only { background: #edf3fb; color: #23518c; padding: 7px 11px; border-radius: 30px; font-size: 12px; font-weight: 700; }
        .table-wrap { overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; min-width: 1050px; }
        th, td { padding: 15px 16px; text-align: left; border-bottom: 1px solid #e9eef5; font-size: 13px; vertical-align: middle; }
        th { color: #52657d; background: #f8fafd; font-weight: 750; white-space: nowrap; }
        tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #f9fbfe; }
        td strong { font-size: 13px; color: #172b4d; }
        .secondary { display: block; margin-top: 4px; color: #697a90; font-size: 12px; }
        .money { font-weight: 750; white-space: nowrap; }
        .badge { display: inline-block; padding: 7px 11px; border-radius: 30px; font-size: 12px; font-weight: 750; text-transform: capitalize; white-space: nowrap; }
        .badge.pending { color: #9a620f; background: #fff4d9; }
        .badge.approved { color: #17603d; background: #e4f6eb; }
        .badge.collected { color: #2057b7; background: #e8f0ff; }
        .badge.returned { color: #536071; background: #edf0f4; }
        .badge.rejected, .badge.cancelled { color: #a92a39; background: #ffeced; }
        .empty { padding: 50px 20px; text-align: center; color: #65758d; }
        @media (max-width: 800px) { .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 480px) { .stats { grid-template-columns: 1fr 1fr; gap: 10px; } .stat { padding: 15px; } .stat-value { font-size: 25px; } .admin-main { padding: 26px 14px 50px; } }
    </style>
</head>
<body>
<?php require __DIR__ . '/header.php'; ?>
<main class="admin-main">
    <span class="eyebrow">Administration / Booking oversight</span>
    <div class="page-heading">
        <div>
            <h1>All Rental Bookings</h1>
            <p class="subtext">Review booking activity, current statuses, and staff decisions across DriveLane.</p>
        </div>
        <a class="admin-link" href="admin_dashboard.php">← Admin Dashboard</a>
    </div>

    <section class="stats" aria-label="Booking overview">
        <div class="stat"><div class="stat-label">Total bookings</div><div class="stat-value"><?= count($bookings) ?></div></div>
        <div class="stat"><div class="stat-label">Pending</div><div class="stat-value"><?= $counts['pending'] ?></div></div>
        <div class="stat"><div class="stat-label">Approved</div><div class="stat-value"><?= $counts['approved'] ?></div></div>
        <div class="stat"><div class="stat-label">Completed returns</div><div class="stat-value"><?= $counts['returned'] ?></div></div>
    </section>

    <section class="card">
        <div class="card-top">
            <div>
                <h2>Rental Records</h2>
                <p>All booking requests and their most recently recorded reviewer.</p>
            </div>
            <span class="read-only">View only · Managed by Rental Staff</span>
        </div>

        <?php if (!$bookings): ?>
            <p class="empty">No rental bookings found.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>ID</th><th>Customer</th><th>Car</th><th>Registration</th>
                        <th>Start</th><th>End</th><th>Total</th><th>Status</th>
                        <th>Reviewed By</th><th>Requested On</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($bookings as $booking): ?>
                    <tr>
                        <td><strong>#<?= (int) $booking['id'] ?></strong></td>
                        <td><?= e($booking['customer_name']) ?></td>
                        <td><strong><?= e($booking['brand'] . ' ' . $booking['model']) ?></strong></td>
                        <td><?= e($booking['registration_number']) ?></td>
                        <td><?= e($booking['start_date']) ?></td>
                        <td><?= e($booking['end_date']) ?></td>
                        <td class="money">€<?= number_format((float) $booking['total_price'], 2) ?></td>
                        <td><span class="badge <?= e($booking['status']) ?>"><?= e($booking['status']) ?></span></td>
                        <td><?= $booking['reviewed_by_name'] === null ? '<span class="secondary">Not reviewed</span>' : e($booking['reviewed_by_name']) ?></td>
                        <td><?= e($booking['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
<?php mysqli_close($dbc); ?>
