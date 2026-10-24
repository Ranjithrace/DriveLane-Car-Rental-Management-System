<?php
session_start();
require 'database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$stmt = mysqli_prepare($dbc, 'SELECT id, username, role FROM users WHERE id = ?');
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$admin = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$admin || $admin['role'] !== 'admin') {
    http_response_code(403);
    exit('Access denied. Administrators only.');
}

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        http_response_code(403);
        exit('Invalid form token.');
    }

    $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
    $newRole = $_POST['role'] ?? '';

    if (!$targetId || $targetId <= 0) {
        $error = 'Invalid user ID.';
    } elseif ($targetId === $userId) {
        $error = 'You cannot change your own administrator role.';
    } elseif (!in_array($newRole, ['customer', 'staff'], true)) {
        $error = 'Invalid role selected.';
    } else {
        // Conditional UPDATE prevents changing an administrator account,
        // even if its role has changed since the page was loaded.
        $stmt = mysqli_prepare($dbc,
            "UPDATE users SET role = ? WHERE id = ? AND role IN ('customer', 'staff')"
        );
        mysqli_stmt_bind_param($stmt, 'si', $newRole, $targetId);
        if (!mysqli_stmt_execute($stmt)) {
            $error = 'Could not update user role.';
            mysqli_stmt_close($stmt);
        } else {
            $changed = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);
            if ($changed === 1) {
                header('Location: admin_users.php?updated=1');
                exit;
            }
            $check = mysqli_prepare($dbc, 'SELECT role FROM users WHERE id = ?');
            mysqli_stmt_bind_param($check, 'i', $targetId);
            mysqli_stmt_execute($check);
            $current = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
            mysqli_stmt_close($check);
            if (!$current) {
                $error = 'User not found.';
            } elseif ($current['role'] === 'admin') {
                $error = 'Administrator accounts cannot be modified.';
            } elseif ($current['role'] === $newRole) {
                header('Location: admin_users.php?unchanged=1');
                exit;
            } else {
                $error = 'Could not update user role. Please try again.';
            }
        }
    }
}

$result = mysqli_query($dbc,
    'SELECT id, username, email, role, created_at FROM users ORDER BY id ASC'
);
if (!$result) {
    http_response_code(500);
    exit('Could not load users.');
}
$users = mysqli_fetch_all($result, MYSQLI_ASSOC);
mysqli_free_result($result);
$totalUsers = count($users);
$customerCount = count(array_filter($users, fn($u) => $u['role'] === 'customer'));
$staffCount = count(array_filter($users, fn($u) => $u['role'] === 'staff'));
$adminCount = count(array_filter($users, fn($u) => $u['role'] === 'admin'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage Users | DriveLane</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f5f7fb;color:#172b4d;font-family:Inter,Segoe UI,Arial,sans-serif}
.admin-container{width:min(1180px,calc(100% - 32px));margin:0 auto}
.admin-main{padding:44px 0 70px}
.crumb{font-size:13px;color:#61728b;margin-bottom:17px}.crumb a{color:#1769e0;text-decoration:none}
.page-heading{display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap;margin-bottom:26px}
.kicker{font-size:12px;color:#1769e0;font-weight:800;letter-spacing:1.6px}
h1{font-size:clamp(27px,4vw,38px);letter-spacing:-1.2px;margin:8px 0}
.subtitle{color:#657285;margin:0;line-height:1.65}
.back-link{display:inline-flex;text-decoration:none;background:#e7effb;color:#1452a4;padding:12px 16px;border-radius:10px;font-size:14px;font-weight:700}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:28px}
.stat{background:#fff;border:1px solid #e1e7ef;border-radius:16px;padding:22px;box-shadow:0 7px 20px #10203908}
.stat-label{display:block;color:#6a778a;font-size:13px;font-weight:600}.stat-number{display:block;font-size:30px;font-weight:800;margin-top:9px;color:#112744}
.panel{background:#fff;border:1px solid #e1e7ef;border-radius:18px;box-shadow:0 10px 28px #10203908;overflow:hidden}
.panel-head{padding:23px 24px;border-bottom:1px solid #e7ecf2}
.panel-head h2{font-size:20px;margin:0 0 6px}.panel-head p{margin:0;font-size:13px;color:#657285}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:790px}
th{background:#f8faff;color:#657285;text-transform:uppercase;letter-spacing:.6px;font-size:11px;text-align:left;padding:16px 19px}
td{padding:17px 19px;border-top:1px solid #e9eef4;font-size:14px;vertical-align:middle}
tbody tr:hover{background:#fafcff}.username{font-weight:750;color:#1b304c}.secondary{color:#68788c;font-size:13px}
.role{display:inline-block;border-radius:999px;padding:7px 11px;font-size:12px;font-weight:750;background:#e9efff;color:#2453af}
.role.staff{background:#e9f5ff;color:#12649a}.role.admin{background:#f1e9ff;color:#7042a4}.role.customer{background:#edf7ee;color:#217249}
.role-form{display:flex;align-items:center;gap:9px;flex-wrap:nowrap}
.role-form select{border:1px solid #d5dfed;border-radius:9px;background:#fff;padding:9px 10px;font:inherit;font-size:13px;color:#172b4d;min-width:130px}
.role-form button{border:0;border-radius:9px;background:#1769e0;color:#fff;font-weight:750;padding:10px 15px;cursor:pointer}
.role-form button:hover{background:#1057bd}.protected{font-size:12px;color:#697b90;font-weight:600}
.notice{padding:15px 18px;border-radius:11px;margin:0 0 22px;font-size:14px;font-weight:650}
.notice.error{background:#fff0ee;color:#9d302c;border:1px solid #f4cac6}.notice.success{background:#eaf8ee;color:#217244;border:1px solid #c9e9d0}
.helper{padding:16px 24px;background:#fafcff;color:#69788b;font-size:13px;border-top:1px solid #e7ecf2}
@media(max-width:800px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.admin-main{padding-top:28px}}
@media(max-width:450px){.stats{grid-template-columns:1fr 1fr;gap:9px}.stat{padding:16px}.stat-number{font-size:25px}}
</style>
</head>
<body>
<?php require __DIR__ . '/header.php'; ?>
<main class="admin-container admin-main">
  <div class="crumb"><a href="admin_dashboard.php">Administrator Dashboard</a> / Manage Users</div>
  <div class="page-heading">
    <div>
      <span class="kicker">ACCOUNT MANAGEMENT</span>
      <h1>Manage Users</h1>
      <p class="subtitle">Review registered accounts and assign Customer or Rental Staff access.</p>
    </div>
    <a class="back-link" href="admin_dashboard.php">← Dashboard</a>
  </div>

  <?php if (isset($_GET['updated']) && $_GET['updated'] === '1'): ?>
    <div class="notice success" role="status">User role updated successfully.</div>
  <?php elseif (isset($_GET['unchanged']) && $_GET['unchanged'] === '1'): ?>
    <div class="notice success" role="status">This account already has the selected role.</div>
  <?php endif; ?>
  <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>

  <section class="stats" aria-label="Account statistics">
    <div class="stat"><span class="stat-label">Total Users</span><span class="stat-number"><?= $totalUsers ?></span></div>
    <div class="stat"><span class="stat-label">Customers</span><span class="stat-number"><?= $customerCount ?></span></div>
    <div class="stat"><span class="stat-label">Rental Staff</span><span class="stat-number"><?= $staffCount ?></span></div>
    <div class="stat"><span class="stat-label">Administrators</span><span class="stat-number"><?= $adminCount ?></span></div>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Registered Accounts</h2><p>Only customer and staff accounts can be changed. Administrator accounts are protected.</p></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Registered</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($users as $row): ?>
          <tr>
            <td class="secondary">#<?= (int)$row['id'] ?></td>
            <td class="username"><?= e($row['username']) ?></td>
            <td class="secondary"><?= e($row['email']) ?></td>
            <td><span class="role <?= e($row['role']) ?>"><?= e(match($row['role']) {'staff'=>'Rental Staff','admin'=>'Administrator',default=>'Customer'}) ?></span></td>
            <td class="secondary"><?= e($row['created_at']) ?></td>
            <td>
              <?php if ($row['role'] === 'admin'): ?>
                <span class="protected">🔒 Protected administrator</span>
              <?php else: ?>
                <form method="post" class="role-form">
                  <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                  <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
                  <select name="role" aria-label="Role for <?= e($row['username']) ?>">
                    <option value="customer" <?= $row['role'] === 'customer' ? 'selected' : '' ?>>Customer</option>
                    <option value="staff" <?= $row['role'] === 'staff' ? 'selected' : '' ?>>Rental Staff</option>
                  </select>
                  <button type="submit">Save</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?><tr><td colspan="6">No registered accounts found.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="helper">Changes take effect the next time the affected user loads a page that checks their role.</div>
  </section>
</main>
</body>
</html>
<?php mysqli_close($dbc); ?>
