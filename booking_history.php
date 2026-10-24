<?php
session_start();
require 'database.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$roleStmt = mysqli_prepare($dbc, 'SELECT username, role FROM users WHERE id = ?');
mysqli_stmt_bind_param($roleStmt, 'i', $userId);
mysqli_stmt_execute($roleStmt);
$staffUser = mysqli_fetch_assoc(mysqli_stmt_get_result($roleStmt));
mysqli_stmt_close($roleStmt);
if (!$staffUser || $staffUser['role'] !== 'staff') {
    http_response_code(403);
    exit('Access denied. Rental Staff only.');
}

function historyEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// An optional booking ID opens the complete history of one request.
$bookingIdText = trim((string)($_GET['booking_id'] ?? ''));
$bookingId = null;
$filterError = '';
if ($bookingIdText !== '') {
    $parsedId = filter_var($bookingIdText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($parsedId === false) {
        $filterError = 'Booking ID must be a positive whole number.';
    } else {
        $bookingId = (int)$parsedId;
    }
}
$searchText = trim((string)($_GET['search'] ?? ''));
$searchText = substr($searchText, 0, 100);
$filterStatus = (string)($_GET['status'] ?? '');
$statuses = ['pending', 'approved', 'rejected', 'cancelled', 'collected', 'returned'];
if (!in_array($filterStatus, array_merge([''], $statuses), true)) {
    $filterStatus = '';
}

// Read-only log view. Every status transition is recorded in booking_history.
$sql = "SELECT h.id AS history_id, h.booking_id, h.old_status, h.new_status,
               h.changed_at, h.changed_by,
               actor.username AS changed_by_name, actor.role AS actor_current_role,
               customer.username AS customer_name,
               c.brand, c.model, c.registration_number,
               b.start_date, b.end_date, b.status AS current_status
        FROM booking_history h
        INNER JOIN bookings b ON b.id = h.booking_id
        INNER JOIN users actor ON actor.id = h.changed_by
        INNER JOIN users customer ON customer.id = b.customer_id
        INNER JOIN cars c ON c.id = b.car_id
        ORDER BY h.changed_at DESC, h.id DESC";
$result = mysqli_query($dbc, $sql);
if (!$result) {
    http_response_code(500);
    exit('Unable to load booking history.');
}
$allEntries = mysqli_fetch_all($result, MYSQLI_ASSOC);
mysqli_free_result($result);

$entries = [];
if ($filterError === '') {
    foreach ($allEntries as $item) {
        if ($bookingId !== null && (int)$item['booking_id'] !== $bookingId) {
            continue;
        }
        if ($filterStatus !== '' && $item['new_status'] !== $filterStatus) {
            continue;
        }
        if ($searchText !== '') {
            $haystack = implode(' ', [
                $item['booking_id'], $item['customer_name'], $item['changed_by_name'],
                $item['brand'], $item['model'], $item['registration_number']
            ]);
            if (stripos($haystack, $searchText) === false) {
                continue;
            }
        }
        $entries[] = $item;
    }
}
$actionsTotal = count($allEntries);
$shown = count($entries);
$staffActions = 0;
foreach ($allEntries as $item) {
    if ($item['old_status'] !== null) {
        $staffActions++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Booking History | DriveLane</title>
<style>
* {box-sizing:border-box}
body {margin:0;background:#f4f7fc;color:#102544;font-family:Arial,Helvetica,sans-serif}
.bh-container {max-width:1200px;margin:auto;padding:34px 20px 70px}
.bh-eyebrow {font-weight:800;color:#2563eb;font-size:12px;letter-spacing:1.5px}
.bh-top {display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;margin:12px 0 26px}
h1 {font-size:clamp(25px,3vw,36px);margin:0 0 8px}
.bh-muted {color:#60718a;font-size:13px;line-height:1.55}
.bh-button {display:inline-flex;align-items:center;justify-content:center;background:#155de2;color:white;text-decoration:none;padding:12px 17px;border-radius:9px;font-size:14px;font-weight:700}
.bh-stats {display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:26px}
.bh-stat {background:white;border:1px solid #e0e8f2;padding:20px;border-radius:14px}
.bh-stat label {display:block;color:#64748b;font-size:13px;margin-bottom:10px}
.bh-stat strong {font-size:29px}
.bh-panel {background:white;border:1px solid #e0e8f2;border-radius:14px;overflow:hidden}
.bh-panel-head {display:flex;justify-content:space-between;gap:14px;align-items:center;flex-wrap:wrap;padding:20px 22px}
.bh-panel-head h2 {margin:0;font-size:20px}
.bh-filters {display:grid;grid-template-columns:1.5fr 1.7fr 1fr auto;gap:12px;padding:0 22px 22px;align-items:end}
.bh-field {display:flex;flex-direction:column;gap:7px;min-width:0}
.bh-field label {font-size:12px;font-weight:700;color:#475569}
.bh-field input,.bh-field select {width:100%;height:42px;border:1px solid #cdd9e8;border-radius:8px;background:white;padding:8px 11px;color:#102544;font-size:14px}
.bh-apply {background:#155de2;color:white;border:0;border-radius:8px;padding:12px 19px;font-weight:700;cursor:pointer}
.bh-reset {display:inline-block;padding:12px 8px;color:#155de2;text-decoration:none;font-size:13px;font-weight:700}
.bh-scroll {overflow-x:auto}
.bh-table {border-collapse:collapse;width:100%;min-width:1050px}
.bh-table th {background:#eff3f9;text-align:left;padding:15px 13px;color:#445876;font-size:12px}
.bh-table td {padding:16px 13px;border-top:1px solid #e5ebf3;vertical-align:top;font-size:13px}
.bh-table small {display:block;color:#78869a;margin-top:5px}
.bh-pill {display:inline-block;padding:6px 10px;border-radius:999px;font-weight:700;font-size:12px;text-transform:capitalize}
.bh-pending {background:#fff1cb;color:#895607}
.bh-approved {background:#ddf8eb;color:#10613a}
.bh-collected {background:#dcecff;color:#144e9a}
.bh-returned {background:#e6ecf2;color:#45546a}
.bh-rejected,.bh-cancelled {background:#ffe3e3;color:#a51e2c}
.bh-created {background:#edf2fa;color:#354b68}
.bh-error {padding:15px 22px;color:#a51e2c}
.bh-empty {padding:25px;color:#64748b}
.bh-table a {color:#155de2;text-decoration:none;font-weight:700}
.bh-note {margin-top:16px;color:#66788f;font-size:13px;line-height:1.6}
@media(max-width:760px) {.bh-filters{grid-template-columns:1fr 1fr}.bh-stats{grid-template-columns:1fr 1fr}}
@media(max-width:500px) {.bh-filters,.bh-stats{grid-template-columns:1fr}.bh-container{padding:24px 14px 55px}}
</style>
</head>
<body>
<?php require __DIR__ . '/header.php'; ?>
<main class="bh-container">
  <div class="bh-eyebrow">STAFF OPERATIONS</div>
  <div class="bh-top">
    <div>
      <h1>Booking Activity History</h1>
      <p class="bh-muted">Track who submitted, approved, rejected, cancelled, collected or returned each booking.</p>
    </div>
    <a class="bh-button" href="staff_bookings.php">← Back to Bookings</a>
  </div>
  <section class="bh-stats" aria-label="History summary">
    <div class="bh-stat"><label>Recorded activities</label><strong><?= $actionsTotal ?></strong></div>
    <div class="bh-stat"><label>Status changes after request</label><strong><?= $staffActions ?></strong></div>
    <div class="bh-stat"><label>Currently displayed</label><strong><?= $shown ?></strong></div>
  </section>
  <section class="bh-panel">
    <div class="bh-panel-head"><h2>Activity log</h2><span class="bh-muted">Most recent actions first</span></div>
    <form class="bh-filters" method="get" action="booking_history.php">
      <div class="bh-field">
        <label for="bh-booking">Booking ID (optional)</label>
        <input id="bh-booking" type="number" min="1" name="booking_id" value="<?= historyEscape($bookingIdText) ?>" placeholder="e.g. 8">
      </div>
      <div class="bh-field">
        <label for="bh-search">Search customer, staff or vehicle</label>
        <input id="bh-search" type="search" name="search" maxlength="100" value="<?= historyEscape($searchText) ?>" placeholder="e.g. Andri, staff1, BMW">
      </div>
      <div class="bh-field">
        <label for="bh-status">New status</label>
        <select id="bh-status" name="status">
          <option value="">All changes</option>
          <?php foreach ($statuses as $status): ?>
            <option value="<?= historyEscape($status) ?>" <?= $filterStatus === $status ? 'selected' : '' ?>><?= historyEscape(ucfirst($status)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><button class="bh-apply" type="submit">Filter</button> <a class="bh-reset" href="booking_history.php">Reset</a></div>
    </form>
    <?php if ($filterError !== ''): ?><div class="bh-error" role="alert"><?= historyEscape($filterError) ?></div><?php endif; ?>
    <?php if (!$entries): ?>
      <div class="bh-empty">No matching booking activity found.</div>
    <?php else: ?>
      <div class="bh-scroll">
        <table class="bh-table">
          <thead><tr>
            <th>When</th><th>Booking</th><th>Customer</th><th>Vehicle</th><th>Changed By</th><th>Previous</th><th>New Status</th><th>Current Booking</th>
          </tr></thead>
          <tbody>
          <?php foreach ($entries as $entry): ?>
            <tr>
              <td><strong><?= historyEscape($entry['changed_at']) ?></strong></td>
              <td><a href="booking_history.php?booking_id=<?= (int)$entry['booking_id'] ?>">#<?= (int)$entry['booking_id'] ?></a></td>
              <td><?= historyEscape($entry['customer_name']) ?></td>
              <td><strong><?= historyEscape($entry['brand'].' '.$entry['model']) ?></strong><small><?= historyEscape($entry['registration_number']) ?></small></td>
              <td><strong><?= historyEscape($entry['changed_by_name']) ?></strong><small>Current role: <?= historyEscape(ucfirst($entry['actor_current_role'])) ?></small></td>
              <td>
                <?php if ($entry['old_status'] === null): ?>
                  <span class="bh-pill bh-created">New request</span>
                <?php else: ?>
                  <span class="bh-pill bh-<?= historyEscape($entry['old_status']) ?>"><?= historyEscape(ucfirst($entry['old_status'])) ?></span>
                <?php endif; ?>
              </td>
              <td><span class="bh-pill bh-<?= historyEscape($entry['new_status']) ?>"><?= historyEscape(ucfirst($entry['new_status'])) ?></span></td>
              <td><span class="bh-pill bh-<?= historyEscape($entry['current_status']) ?>"><?= historyEscape(ucfirst($entry['current_status'])) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
  <p class="bh-note">This page is read-only. Activity timestamps come from the database. The displayed user role is the account's current role; it may differ from the role held when the action occurred.</p>
</main>
</body>
</html>
<?php mysqli_close($dbc); ?>
