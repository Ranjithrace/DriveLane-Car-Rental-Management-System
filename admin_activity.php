<?php
// DriveLane Administrator — read-only booking activity logs.
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

function validDate(string $value): bool {
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $dt !== false && $dt->format('Y-m-d') === $value;
}

$search = trim((string) ($_GET['search'] ?? ''));
if (strlen($search) > 100) {
    $search = substr($search, 0, 100);
}
$statuses = ['pending', 'approved', 'rejected', 'cancelled', 'collected', 'returned'];
$status = (string) ($_GET['status'] ?? '');
if (!in_array($status, $statuses, true)) {
    $status = '';
}
$from = (string) ($_GET['from'] ?? '');
$to = (string) ($_GET['to'] ?? '');
$from = validDate($from) ? $from : '';
$to = validDate($to) ? $to : '';
if ($from !== '' && $to !== '' && $from > $to) {
    [$from, $to] = [$to, $from];
}
$bookingId = filter_var($_GET['booking_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$bookingId = $bookingId ?: null;
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$page = min($page, 100000);
$pageSize = 25;

$joins = " FROM booking_history h
          INNER JOIN bookings b ON b.id = h.booking_id
          INNER JOIN users actor ON actor.id = h.changed_by
          INNER JOIN users customer ON customer.id = b.customer_id
          INNER JOIN cars c ON c.id = b.car_id";
$where = [];
$params = [];
if ($bookingId !== null) {
    $where[] = 'h.booking_id = ?';
    $params[] = $bookingId;
}
if ($search !== '') {
    $where[] = '(actor.username LIKE ? OR customer.username LIKE ? OR c.brand LIKE ? OR c.model LIKE ? OR c.registration_number LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term, $term);
}
if ($status !== '') {
    $where[] = 'h.new_status = ?';
    $params[] = $status;
}
if ($from !== '') {
    $where[] = 'h.changed_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[] = 'h.changed_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $params[] = $to;
}
$conditions = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countSql = 'SELECT COUNT(*) AS total' . $joins . $conditions;
$countStmt = mysqli_prepare($dbc, $countSql);
if (!$countStmt || !mysqli_stmt_execute($countStmt, $params)) {
    error_log('DriveLane admin activity count failed: ' . mysqli_error($dbc));
    http_response_code(500);
    exit('Unable to load activity records.');
}
$total = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt))['total'] ?? 0);
mysqli_stmt_close($countStmt);
$totalPages = max(1, (int) ceil($total / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;

$sql = 'SELECT h.id, h.booking_id, h.old_status, h.new_status, h.changed_at,
               actor.username AS actor_name, actor.role AS actor_current_role,
               customer.username AS customer_name, c.brand, c.model, c.registration_number'
      . $joins . $conditions . ' ORDER BY h.changed_at DESC, h.id DESC'
      . ' LIMIT ' . $pageSize . ' OFFSET ' . $offset;
$stmt = mysqli_prepare($dbc, $sql);
if (!$stmt || !mysqli_stmt_execute($stmt, $params)) {
    error_log('DriveLane admin activity list failed: ' . mysqli_error($dbc));
    http_response_code(500);
    exit('Unable to load activity records.');
}
$activity = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

function pageLink(int $target): string {
    $query = $_GET;
    $query['page'] = $target;
    return 'admin_activity.php?' . http_build_query($query);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Activity Logs | DriveLane Administrator</title>
<style>
* { box-sizing: border-box; }
body { margin: 0; background: #f4f7fb; color: #112846; font-family: Inter, 'Segoe UI', Arial, sans-serif; }
.al-container { width: min(1250px, calc(100% - 36px)); margin: auto; }
.al-main { padding: 36px 0 70px; }
.al-breadcrumb { color: #1769e0; font-size: 13px; text-decoration: none; font-weight: 700; }
h1 { font-size: clamp(28px, 4vw, 40px); letter-spacing: -1px; margin: 17px 0 9px; }
.al-subtitle { color: #60718a; line-height: 1.6; margin: 0 0 25px; }
.al-panel { background: white; border: 1px solid #dfe7f1; border-radius: 17px; overflow: hidden; margin-bottom: 23px; }
.al-filters { padding: 24px; display: grid; grid-template-columns: minmax(200px, 2fr) minmax(145px, 1fr) repeat(2,minmax(140px,1fr)) auto; gap: 13px; align-items: end; }
.al-field label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 8px; color: #314962; }
.al-field input, .al-field select { width: 100%; padding: 11px 12px; min-height: 43px; border: 1px solid #cdd9e8; border-radius: 9px; font-size: 14px; background: white; color: #112846; }
.al-actions { display: flex; gap: 8px; align-items: center; }
.al-button { border: 0; border-radius: 9px; background: #1769e0; color: white; padding: 12px 15px; min-height: 43px; font-weight: 700; cursor: pointer; }
.al-reset { border-radius: 9px; background: #ebf1fa; color: #1452a6; padding: 12px 15px; font-weight: 700; text-decoration: none; white-space: nowrap; }
.al-panel-top { padding: 19px 23px; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px; align-items: center; }
.al-panel-top h2 { font-size: 21px; margin: 0; }
.al-panel-top small { color: #60718a; }
.al-table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; min-width: 1000px; font-size: 14px; }
th,td { text-align: left; padding: 14px 16px; border-top: 1px solid #e5ebf2; vertical-align: top; }
th { background: #edf2f9; color: #3b536e; font-size: 12px; }
td { color: #243953; }
.al-pill { display: inline-block; background: #e9eff7; border-radius: 999px; padding: 6px 10px; font-weight: 700; font-size: 12px; white-space: nowrap; }
.al-pill.approved, .al-pill.returned { background: #def6e9; color: #14643a; }
.al-pill.pending { background: #fff0d5; color: #966007; }
.al-pill.rejected, .al-pill.cancelled { background: #ffe6e7; color: #a8212b; }
.al-pill.collected { background: #e6efff; color: #205aba; }
.al-muted { color: #62748b; font-size: 12px; }
.al-id { color: #1769e0; text-decoration: none; font-weight: 700; }
.al-empty { color: #62748b; padding: 24px; }
.al-pages { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 17px 22px; border-top: 1px solid #e5ebf2; }
.al-pages a { color: #1769e0; text-decoration: none; font-weight: 700; }
.al-pages .disabled { color: #9aa8b7; }
.al-note { color: #61738b; font-size: 13px; line-height: 1.6; }
@media(max-width:1100px){.al-filters { grid-template-columns: repeat(2,minmax(0,1fr)); }.al-actions { grid-column:1 / -1; }}
@media(max-width:550px){.al-filters { grid-template-columns:1fr; }.al-main { padding-top:24px; }}
</style>
</head>
<body>
<?php require __DIR__ . '/header.php'; ?>
<main class="al-main">
<div class="al-container">
  <a href="admin_dashboard.php" class="al-breadcrumb">&larr; Back to Administrator Dashboard</a>
  <h1>Booking Activity Logs</h1>
  <p class="al-subtitle">Review the complete recorded history of booking requests, staff decisions, collection and return actions.</p>
  <section class="al-panel">
    <form class="al-filters" method="get" action="admin_activity.php">
      <div class="al-field"><label for="search">Search person or vehicle</label><input id="search" name="search" type="search" maxlength="100" placeholder="Customer, staff, brand or registration" value="<?= e($search) ?>"></div>
      <div class="al-field"><label for="status">Action / new status</label><select name="status" id="status"><option value="">All actions</option><?php foreach ($statuses as $option): ?><option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option><?php endforeach; ?></select></div>
      <div class="al-field"><label for="from">Changed from</label><input id="from" name="from" type="date" value="<?= e($from) ?>"></div>
      <div class="al-field"><label for="to">Changed to</label><input id="to" name="to" type="date" value="<?= e($to) ?>"></div>
      <?php if ($bookingId !== null): ?><input type="hidden" name="booking_id" value="<?= (int) $bookingId ?>"><?php endif; ?>
      <div class="al-actions"><button class="al-button" type="submit">Apply filters</button><a class="al-reset" href="admin_activity.php">Reset</a></div>
    </form>
  </section>
  <section class="al-panel">
    <div class="al-panel-top"><h2>Recorded events</h2><small><?= number_format($total) ?> matching events &middot; Page <?= $page ?> of <?= $totalPages ?></small></div>
    <?php if (!$activity): ?>
      <div class="al-empty">No booking activity matches these filters.</div>
    <?php else: ?>
      <div class="al-table-wrap"><table>
        <thead><tr><th>Date and time</th><th>Booking</th><th>Customer</th><th>Vehicle</th><th>Performed by</th><th>Previous status</th><th>New status</th></tr></thead>
        <tbody><?php foreach ($activity as $entry): ?>
          <?php $newStatus = in_array($entry['new_status'], $statuses, true) ? $entry['new_status'] : ''; ?>
          <tr>
            <td><?= e($entry['changed_at']) ?></td>
            <td><a class="al-id" href="admin_activity.php?booking_id=<?= (int)$entry['booking_id'] ?>">#<?= (int) $entry['booking_id'] ?></a></td>
            <td><?= e($entry['customer_name']) ?></td>
            <td><?= e($entry['brand'] . ' ' . $entry['model']) ?><div class="al-muted"><?= e($entry['registration_number']) ?></div></td>
            <td><strong><?= e($entry['actor_name']) ?></strong><div class="al-muted">Current role: <?= e(ucfirst($entry['actor_current_role'])) ?></div></td>
            <td><?= $entry['old_status'] === null ? '— (Created)' : e(ucfirst($entry['old_status'])) ?></td>
            <td><span class="al-pill <?= e($newStatus) ?>"><?= e(ucfirst($entry['new_status'])) ?></span></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
    <div class="al-pages">
      <?php if ($page > 1): ?><a href="<?= e(pageLink($page - 1)) ?>">&larr; Previous</a><?php else: ?><span class="disabled">&larr; Previous</span><?php endif; ?>
      <span class="al-muted">Showing up to <?= $pageSize ?> events per page</span>
      <?php if ($page < $totalPages): ?><a href="<?= e(pageLink($page + 1)) ?>">Next &rarr;</a><?php else: ?><span class="disabled">Next &rarr;</span><?php endif; ?>
    </div>
  </section>
  <p class="al-note">These logs are sourced from booking_history. Account role changes, profile changes and vehicle edits are not recorded in this table, so they are not shown here. Logs are read-only.</p>
</div>
</main>
</body>
</html>
<?php mysqli_close($dbc); ?>
