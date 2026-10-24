
<?php
session_start();
require 'database.php';

$error = '';

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Existing sessions must use the CURRENT database role.
// The cached session role may be stale after an administrator changes it.
if (!empty($_SESSION['user_id'])) {
    $sessionUserId = (int) $_SESSION['user_id'];
    $sessionStmt = mysqli_prepare($dbc,
        'SELECT username, role FROM users WHERE id = ?');
    mysqli_stmt_bind_param($sessionStmt, 'i', $sessionUserId);
    mysqli_stmt_execute($sessionStmt);
    $sessionUser = mysqli_fetch_assoc(mysqli_stmt_get_result($sessionStmt));
    mysqli_stmt_close($sessionStmt);
    if ($sessionUser) {
        $_SESSION['username'] = $sessionUser['username'];
        $_SESSION['role'] = $sessionUser['role'];
        $destination = [
            'admin' => 'admin_dashboard.php',
            'staff' => 'staff_dashboard.php',
            'customer' => 'customer_dashboard.php'
        ];
        header('Location: ' . ($destination[$sessionUser['role']] ?? 'index.php'));
        exit;
    }
    // The account no longer exists; clear only this invalid session.
    $_SESSION = [];
    session_regenerate_id(true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } else {

        // Find the account by username.
        $stmt = mysqli_prepare($dbc,
            "SELECT id, username, password, role
             FROM users
             WHERE username = ?");

        mysqli_stmt_bind_param($stmt, 's', $username);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result);

        // Compare password with the stored hash.
        if ($user && password_verify($password, $user['password'])) {

            // Generate a new session ID after successful login.
            session_regenerate_id(true);

            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            if ($user['role'] === 'admin') {
                header('Location: admin_dashboard.php');
            } elseif ($user['role'] === 'staff') {
                header('Location: staff_dashboard.php');
            } else {
                header('Location: customer_dashboard.php');
            }
            exit;

        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Car Rental - Login</title>

<style>
body {
    font-family: Arial, sans-serif;
    max-width: 450px;
    margin: 50px auto;
    padding: 0 15px;
}

form {
    background: #f3f3f3;
    padding: 25px;
    border-radius: 8px;
}

label {
    display: block;
    margin-top: 15px;
    font-weight: bold;
}

input {
    width: 100%;
    padding: 10px;
    box-sizing: border-box;
    margin-top: 5px;
}

button {
    margin-top: 20px;
    padding: 10px 20px;
    background: #2563eb;
    color: white;
    border: none;
    cursor: pointer;
}

.error {
    color: #b00020;
}
</style>
</head>

<body>

<h2>Car Rental — Login</h2>

<?php if ($error): ?>
    <p class="error"><?= e($error) ?></p>
<?php endif; ?>

<form method="post">

    <label>Username:</label>
    <input type="text" name="username" required>

    <label>Password:</label>
    <input type="password" name="password" required>

    <button type="submit">Login</button>

</form>

<p>Don't have an account? <a href="register.php">Register here</a></p>

<p><a href="index.php">Back to Home</a></p>

</body>
</html>

<?php mysqli_close($dbc); ?>
