<?php
session_start();
require_once __DIR__ . '/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function profileEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$userId = (int)$_SESSION['user_id'];
$stmt = mysqli_prepare($dbc, 'SELECT id, username, email, password, role FROM users WHERE id = ?');
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$currentUser = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$currentUser) {
    // Invalid/deleted account: don't expose account-management functionality.
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

if (!isset($_SESSION['profile_csrf'])) {
    $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$postedUsername = $currentUser['username'];
$postedEmail = $currentUser['email'];
$roles = ['customer' => 'Customer', 'staff' => 'Rental Staff', 'admin' => 'Administrator'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? null;
    if (!is_string($csrf) || !hash_equals($_SESSION['profile_csrf'], $csrf)) {
        http_response_code(403);
        exit('Invalid form token.');
    }
    $action = $_POST['action'] ?? '';
    $currentPassword = $_POST['current_password'] ?? null;

    if (!is_string($currentPassword) || !password_verify($currentPassword, $currentUser['password'])) {
        $error = 'The current password is incorrect.';
    } elseif ($action === 'details') {
        $postedUsername = trim((string)($_POST['username'] ?? ''));
        $postedEmail = trim((string)($_POST['email'] ?? ''));
        if ($postedUsername === '' || $postedEmail === '') {
            $error = 'Username and email are required.';
        } elseif (strlen($postedUsername) > 50) {
            $error = 'Username must be 50 characters or fewer.';
        } elseif (!filter_var($postedEmail, FILTER_VALIDATE_EMAIL) || strlen($postedEmail) > 100) {
            $error = 'Enter a valid email address (100 characters maximum).';
        } else {
            try {
                $stmt = mysqli_prepare($dbc,
                    'UPDATE users SET username = ?, email = ? WHERE id = ?');
                mysqli_stmt_bind_param($stmt, 'ssi', $postedUsername, $postedEmail, $userId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $_SESSION['username'] = $postedUsername;
                header('Location: profile.php?updated=details');
                exit;
            } catch (mysqli_sql_exception $ex) {
                // Database has unique indexes for username and email.
                if ((int)$ex->getCode() === 1062) {
                    $error = 'That username or email is already in use.';
                } else {
                    error_log($ex->getMessage());
                    $error = 'Unable to save profile. Please try again.';
                }
            }
        }
    } elseif ($action === 'password') {
        $newPassword = $_POST['new_password'] ?? null;
        $confirmPassword = $_POST['confirm_password'] ?? null;
        if (!is_string($newPassword) || !is_string($confirmPassword)) {
            $error = 'Enter the new password and confirmation.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'Your new password must contain at least 8 characters.';
        } elseif (strlen($newPassword) > 4096) {
            $error = 'Your new password is too long.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'The new passwords do not match.';
        } elseif (password_verify($newPassword, $currentUser['password'])) {
            $error = 'Choose a password different from your current one.';
        } else {
            try {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = mysqli_prepare($dbc, 'UPDATE users SET password = ? WHERE id = ?');
                mysqli_stmt_bind_param($stmt, 'si', $newHash, $userId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                session_regenerate_id(true);
                header('Location: profile.php?updated=password');
                exit;
            } catch (Throwable $ex) {
                error_log($ex->getMessage());
                $error = 'Unable to update password. Please try again.';
            }
        }
    } else {
        $error = 'Invalid profile action.';
    }
}

$success = '';
if (($_GET['updated'] ?? '') === 'details') {
    $success = 'Profile details updated successfully.';
} elseif (($_GET['updated'] ?? '') === 'password') {
    $success = 'Password changed successfully. Use your new password next time you log in.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Profile | DriveLane</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Arial,Segoe UI,sans-serif;color:#102544;background:#f4f7fc}
.profile-hero{background:linear-gradient(120deg,#112746,#234a7d);color:#fff;padding:45px 24px}
.profile-wrap{width:min(100% - 36px,1040px);margin:auto}
.profile-hero small{letter-spacing:2px;font-weight:700;color:#aacaef}
.profile-hero h1{font-size:clamp(30px,4vw,43px);margin:12px 0}
.profile-hero p{color:#d1dfec;margin:0;line-height:1.6}
.profile-main{padding:32px 0 65px}
.profile-card{background:white;padding:26px;border:1px solid #dce6f2;border-radius:16px;box-shadow:0 8px 25px #1025440a}
.profile-layout{display:grid;grid-template-columns:1fr 1fr;gap:22px;margin-top:22px}
.profile-card h2{font-size:21px;margin:0 0 8px}
.profile-card p{color:#5b6d83;line-height:1.5;margin:0 0 18px;font-size:14px}
.profile-field{display:block;margin:15px 0 0;font-size:14px;font-weight:700}
.profile-input{margin-top:7px;width:100%;padding:12px 13px;border:1px solid #cbd7e6;border-radius:9px;font:inherit;color:#142640;background:#fff}
.profile-input:focus{outline:2px solid #91b8fb;border-color:#2563eb}
.profile-button{padding:12px 18px;border:0;border-radius:9px;background:#1c67e8;color:white;font-weight:750;margin-top:22px;cursor:pointer}
.profile-button:hover{background:#0f56ca}
.profile-notice{padding:15px 17px;border-radius:9px;margin:0 0 18px;background:#e3f6eb;color:#08623c}
.profile-notice.error{background:#ffe9e9;color:#a11728}
.profile-identity{display:flex;justify-content:space-between;gap:15px;align-items:center;flex-wrap:wrap}
.profile-role{padding:8px 12px;border-radius:25px;background:#eaf2ff;color:#1b59b3;font-size:13px;font-weight:700}
.profile-back{color:#1b59b3;text-decoration:none;font-weight:700}
@media(max-width:740px){.profile-layout{grid-template-columns:1fr}.profile-hero{padding:34px 18px}.profile-card{padding:20px}}
</style>
</head>
<body>
<?php require __DIR__ . '/header.php'; ?>
<section class="profile-hero"><div class="profile-wrap">
<small>ACCOUNT SETTINGS</small>
<h1>My Profile</h1>
<p>Update your account details and keep your password secure.</p>
</div></section>
<main class="profile-wrap profile-main">
<?php if ($success): ?><div class="profile-notice" role="status"><?= profileEscape($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="profile-notice error" role="alert"><?= profileEscape($error) ?></div><?php endif; ?>
<div class="profile-card profile-identity">
  <div><strong><?= profileEscape($currentUser['username']) ?></strong><p style="margin:8px 0 0"><?= profileEscape($currentUser['email']) ?></p></div>
  <span class="profile-role"><?= profileEscape($roles[$currentUser['role']] ?? 'User') ?></span>
</div>
<div class="profile-layout">
<section class="profile-card">
<h2>Edit account details</h2><p>Change your username or email. Enter your current password to confirm.</p>
<form method="post" autocomplete="on">
<input type="hidden" name="action" value="details"><input type="hidden" name="csrf" value="<?= profileEscape($_SESSION['profile_csrf']) ?>">
<label class="profile-field" for="username">Username</label>
<input class="profile-input" id="username" type="text" name="username" maxlength="50" value="<?= profileEscape($postedUsername) ?>" autocomplete="username" required>
<label class="profile-field" for="email">Email address</label>
<input class="profile-input" id="email" type="email" name="email" maxlength="100" value="<?= profileEscape($postedEmail) ?>" autocomplete="email" required>
<label class="profile-field" for="details_password">Current password</label>
<input class="profile-input" id="details_password" name="current_password" type="password" autocomplete="current-password" required>
<button class="profile-button" type="submit">Save Profile Changes</button>
</form>
</section>
<section class="profile-card">
<h2>Change password</h2><p>Choose a new password with at least eight characters.</p>
<form method="post" autocomplete="on">
<input type="hidden" name="action" value="password"><input type="hidden" name="csrf" value="<?= profileEscape($_SESSION['profile_csrf']) ?>">
<label class="profile-field" for="current_password">Current password</label>
<input class="profile-input" id="current_password" name="current_password" type="password" autocomplete="current-password" required>
<label class="profile-field" for="new_password">New password</label>
<input class="profile-input" id="new_password" name="new_password" type="password" minlength="8" autocomplete="new-password" required>
<label class="profile-field" for="confirm_password">Confirm new password</label>
<input class="profile-input" id="confirm_password" name="confirm_password" type="password" minlength="8" autocomplete="new-password" required>
<button class="profile-button" type="submit">Update Password</button>
</form>
</section>
</div>
<p style="margin-top:24px"><a class="profile-back" href="<?= profileEscape(['customer'=>'customer_dashboard.php','staff'=>'staff_dashboard.php','admin'=>'admin_dashboard.php'][$currentUser['role']] ?? 'index.php') ?>">← Back to dashboard</a></p>
</main>
</body>
</html>
<?php mysqli_close($dbc); ?>
