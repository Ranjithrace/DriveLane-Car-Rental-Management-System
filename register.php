
<?php
require 'database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($username === '' || $email === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } elseif (strlen($username) > 50) {
        $error = 'Username must be 50 characters or fewer.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($email) > 100) {
        $error = 'Email must be 100 characters or fewer.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must have at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {

        // Check whether username or email already exists.
        $stmt = mysqli_prepare($dbc,
            "SELECT id FROM users
             WHERE username = ? OR email = ?");

        mysqli_stmt_bind_param($stmt, 'ss', $username, $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if (mysqli_num_rows($result) > 0) {
            $error = 'Username or email is already registered.';
        } else {

            // Store a hash, never a plain-text password.
            $hashedPassword = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            // Public registration creates customer accounts only.
            $stmt = mysqli_prepare($dbc,
                "INSERT INTO users
                 (username, email, password, role)
                 VALUES (?, ?, ?, 'customer')");

            mysqli_stmt_bind_param(
                $stmt,
                'sss',
                $username,
                $email,
                $hashedPassword
            );

            if (mysqli_stmt_execute($stmt)) {
                $success = 'Registration successful! You can now log in.';
            } else {
                $error = 'Registration failed. Please try again.';
            }
        }
    }
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Customer Registration</title>

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

.success {
    color: green;
}
</style>
</head>

<body>

<h2>Car Rental — Register</h2>

<?php if ($error): ?>
    <p class="error"><?= e($error) ?></p>
<?php endif; ?>

<?php if ($success): ?>
    <p class="success"><?= e($success) ?></p>
<?php endif; ?>

<form method="post">

    <label>Username:</label>
    <input type="text" name="username" maxlength="50"
           value="<?= e($_POST['username'] ?? '') ?>" required>

    <label>Email:</label>
    <input type="email" name="email" maxlength="100"
           value="<?= e($_POST['email'] ?? '') ?>" required>

    <label>Password:</label>
    <input type="password" name="password" minlength="8" required>

    <label>Confirm Password:</label>
    <input type="password" name="confirm_password" required>

    <button type="submit">Register</button>

</form>

<p><a href="index.php">Back to Home</a></p>

</body>
</html>

<?php mysqli_close($dbc); ?>
