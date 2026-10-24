<?php
/**
 * READ-ONLY CLI checks for DriveLane Project 2.
 * Run from Windows: E:\xampp\php\php.exe E:\xampp\htdocs\car_rental\tests\drivelane_self_test.php
 * Never open through the browser (blocked below).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/database.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$failures = 0;
$warnings = 0;
function checkLine(bool $ok, string $message, bool $warnOnly = false): void {
    global $failures, $warnings;
    echo ($ok ? '[PASS] ' : ($warnOnly ? '[WARN] ' : '[FAIL] ')) . $message . PHP_EOL;
    if (!$ok) { if ($warnOnly) $warnings++; else $failures++; }
}
function numQuery(mysqli $db, string $sql): int {
    $row = mysqli_fetch_row(mysqli_query($db, $sql));
    return (int)$row[0];
}
try {
    echo "DriveLane read-only database smoke test\n==================================\n";
    foreach (['users','cars','bookings','booking_history','car_images'] as $table) {
        $n = numQuery($dbc, "SELECT COUNT(*) FROM `$table`");
        checkLine(true, "$table exists; rows = $n");
    }
    $roleCount = numQuery($dbc, "SELECT COUNT(DISTINCT role) FROM users");
    checkLine($roleCount >= 3, "At least 3 role types exist in users table ($roleCount found)");
    $invalidHistory = numQuery($dbc, "SELECT COUNT(*) FROM bookings b WHERE NOT EXISTS (
        SELECT 1 FROM booking_history h WHERE h.booking_id = b.id AND h.new_status = b.status
        AND h.id = (SELECT MAX(h2.id) FROM booking_history h2 WHERE h2.booking_id = b.id))");
    checkLine($invalidHistory === 0, "Latest booking history status equals booking status ($invalidHistory mismatches)");
    $photoRes = mysqli_query($dbc, 'SELECT car_id, COUNT(*) as total, SUM(is_primary) AS primaries FROM car_images GROUP BY car_id');
    $photoLimitProblems = 0; $primaryProblems = 0;
    while ($row = mysqli_fetch_assoc($photoRes)) {
        if ((int)$row['total'] > 8) $photoLimitProblems++;
        if ((int)$row['primaries'] !== 1) $primaryProblems++;
    }
    checkLine($photoLimitProblems === 0, "At most 8 photos per car ($photoLimitProblems violations)");
    checkLine($primaryProblems === 0, "Exactly one primary per car with photos ($primaryProblems violations)");
    $paths = mysqli_query($dbc,'SELECT image_path FROM car_images'); $missing = [];
    while ($row = mysqli_fetch_assoc($paths)) {
        $path = $row['image_path'];
        if (!preg_match('~^uploads/cars/[a-f0-9]{32}\\.(jpg|jpeg|png|webp)$~i', $path)
            || !is_file(dirname(__DIR__) . '/' . $path)) $missing[] = $path;
    }
    checkLine(count($missing) === 0, 'Image files exist and have expected relative filenames (' . count($missing) . ' missing/invalid)');
    $overlaps = numQuery($dbc, "SELECT COUNT(*) FROM bookings a JOIN bookings b
        ON a.id < b.id AND a.car_id = b.car_id
        AND a.start_date <= b.end_date AND a.end_date >= b.start_date
        WHERE a.status IN ('approved','collected','returned')
          AND b.status IN ('approved','collected','returned')");
    checkLine($overlaps === 0, "No overlapping reserved-date bookings ($overlaps pairs)", true);
    $earlyReturn = numQuery($dbc, "SELECT COUNT(*) FROM bookings b JOIN booking_history h
        ON h.booking_id = b.id AND h.new_status = 'returned'
        WHERE DATE(h.changed_at) < b.start_date");
    checkLine($earlyReturn === 0, "No historic returns before rental start date ($earlyReturn records)", true);
    $badDates = numQuery($dbc, 'SELECT COUNT(*) FROM bookings WHERE start_date > end_date OR total_price <= 0');
    checkLine($badDates === 0, "Booking dates and totals valid ($badDates invalid records)");
    echo "==================================\nFatal checks failed: $failures; data-quality warnings: $warnings\n";
    exit($failures > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Smoke test could not complete: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}
