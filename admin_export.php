<?php
/**
 * DriveLane CSV exports. Access: administrator only.
 * Read-only queries; no credentials/password hashes are exported.
 * Run before any HTML is written so CSV headers remain valid.
 */
session_start();
require_once __DIR__ . '/database.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$adminId = (int) $_SESSION['user_id'];
$auth = mysqli_prepare($dbc, 'SELECT role FROM users WHERE id = ? LIMIT 1');
if (!$auth) {
    http_response_code(500);
    exit('Authentication could not be verified.');
}
mysqli_stmt_bind_param($auth, 'i', $adminId);
mysqli_stmt_execute($auth);
$admin = mysqli_fetch_assoc(mysqli_stmt_get_result($auth));
mysqli_stmt_close($auth);
if (!$admin || $admin['role'] !== 'admin') {
    http_response_code(403);
    exit('Access denied. Administrators only.');
}

$type = $_GET['type'] ?? '';
if (!is_string($type) || !in_array($type, ['bookings', 'users', 'cars', 'activity'], true)) {
    http_response_code(400);
    exit('Invalid export type.');
}

$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
if (!is_string($from) || !is_string($to)) {
    http_response_code(400);
    exit('Invalid date format.');
}
$from = trim($from);
$to = trim($to);

function csvValidDate(string $value): bool {
    if ($value === '') {
        return true;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}
if (!csvValidDate($from) || !csvValidDate($to) || ($from !== '' && $to !== '' && $from > $to)) {
    http_response_code(400);
    exit('Invalid reporting period. Select valid rental start dates.');
}

// A spreadsheet can interpret values starting with formula characters as commands.
// Quote them as literal text before writing CSV.
function csvSafeValue($value): string {
    if ($value === null) {
        return '';
    }
    $text = (string) $value;
    if (preg_match('/^[\s\x00-\x1F]*[=+\-@]/u', $text) === 1) {
        return "'" . $text;
    }
    return $text;
}

// Period filters intentionally match the Reports page: booking rental-start date.
// Users and cars are full snapshots; for those datasets date filters are ignored.
$where = [];
$parameters = [];
if (in_array($type, ['bookings', 'activity'], true)) {
    if ($from !== '') {
        $where[] = 'b.start_date >= ?';
        $parameters[] = $from;
    }
    if ($to !== '') {
        $where[] = 'b.start_date <= ?';
        $parameters[] = $to;
    }
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

switch ($type) {
    case 'bookings':
        $columns = ['Booking ID','Customer','Car','Registration','Rental Start','Rental End','Days','Total (EUR)','Status','Reviewed By','Requested At'];
        $sql = "SELECT b.id, customer.username AS customer,
                       CONCAT(c.brand, ' ', c.model) AS car,
                       c.registration_number, b.start_date, b.end_date,
                       DATEDIFF(b.end_date, b.start_date) + 1 AS rental_days,
                       b.total_price, b.status, reviewer.username AS reviewer,
                       b.created_at
                FROM bookings b
                JOIN users customer ON customer.id = b.customer_id
                JOIN cars c ON c.id = b.car_id
                LEFT JOIN users reviewer ON reviewer.id = b.reviewed_by"
               . $whereSql . ' ORDER BY b.created_at DESC, b.id DESC';
        break;
    case 'users':
        $columns = ['User ID','Username','Email','Role','Registered At'];
        $sql = 'SELECT id, username, email, role, created_at FROM users ORDER BY id';
        break;
    case 'cars':
        $columns = ['Car ID','Brand','Model','Production Year','Registration','Daily Price (EUR)','Status','Added At'];
        $sql = 'SELECT id, brand, model, production_year, registration_number, price_per_day, status, created_at FROM cars ORDER BY id';
        break;
    case 'activity':
        $columns = ['Log ID','Booking ID','Customer','Car','Rental Start','Rental End','Changed By','Old Status','New Status','Changed At'];
        $sql = "SELECT h.id, b.id AS booking_id,
                       customer.username AS customer,
                       CONCAT(c.brand, ' ', c.model) AS car,
                       b.start_date, b.end_date,
                       actor.username AS changed_by,
                       h.old_status, h.new_status, h.changed_at
                FROM booking_history h
                JOIN bookings b ON b.id = h.booking_id
                JOIN users customer ON customer.id = b.customer_id
                JOIN cars c ON c.id = b.car_id
                JOIN users actor ON actor.id = h.changed_by"
               . $whereSql . ' ORDER BY h.changed_at DESC, h.id DESC';
        break;
}

try {
    $stmt = mysqli_prepare($dbc, $sql);
    if (!$stmt || !mysqli_stmt_execute($stmt, $parameters)) {
        throw new RuntimeException('Export database query failed.');
    }
    $result = mysqli_stmt_get_result($stmt);
    if (!$result) {
        throw new RuntimeException('Export results could not be loaded.');
    }
} catch (Throwable $ex) {
    error_log('DriveLane CSV export failed: ' . $ex->getMessage());
    http_response_code(500);
    exit('Unable to generate export.');
}

$filename = 'drivelane_' . $type . '_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');

$out = fopen('php://output', 'wb');
if ($out === false) {
    exit;
}
// BOM lets Excel detect UTF-8 correctly (including Lithuanian characters).
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, $columns, ',', '"', '');
while ($record = mysqli_fetch_row($result)) {
    fputcsv($out, array_map('csvSafeValue', $record), ',', '"', '');
}
fclose($out);
mysqli_free_result($result);
mysqli_stmt_close($stmt);
mysqli_close($dbc);
exit;
