<?php
// Copy this file to database.php, then configure your local database connection.
// Do not commit database.php or real credentials to GitHub.
$dbc = mysqli_connect(
    'localhost',
    'root',          // Set your local DB username
    '',              // Set your local DB password
    'car_rental_db'
);

if (!$dbc) {
    error_log('DriveLane database connection failed: ' . mysqli_connect_error());
    http_response_code(500);
    exit('Database connection unavailable.');
}

mysqli_set_charset($dbc, 'utf8mb4');
