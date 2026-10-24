
<?php
// Shared navigation header.
// Include this file inside the HTML body,
// after the page has finished processing redirects.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';

$headerUser = null;
$headerDashboard = 'index.php';

if (!empty($_SESSION['user_id'])) {

    $headerUserId = (int)$_SESSION['user_id'];

    $headerStmt = mysqli_prepare($dbc,
        "SELECT username, email, role
         FROM users WHERE id = ?");

    mysqli_stmt_bind_param(
        $headerStmt, 'i', $headerUserId
    );

    mysqli_stmt_execute($headerStmt);

    $headerUser = mysqli_fetch_assoc(
        mysqli_stmt_get_result($headerStmt)
    );

    mysqli_stmt_close($headerStmt);

    if ($headerUser) {
        switch ($headerUser['role']) {
            case 'customer':
                $headerDashboard = 'customer_dashboard.php';
                break;
            case 'staff':
                $headerDashboard = 'staff_dashboard.php';
                break;
            case 'admin':
                $headerDashboard = 'admin_dashboard.php';
                break;
        }
    }
}

$headerRoles = [
    'customer' => 'Customer',
    'staff' => 'Rental Staff',
    'admin' => 'Administrator'
];
?>

<style>
.dr-header {
    background: #102544;
    color: white;
    padding: 15px 25px;
    font-family: Arial, sans-serif;
}

.dr-header * {
    box-sizing: border-box;
}

.dr-header-inner {
    max-width: 1200px;
    margin: auto;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.dr-logo {
    font-size: 25px;
    font-weight: bold;
    color: white;
    text-decoration: none;
}

.dr-links {
    display: flex;
    align-items: center;
    gap: 18px;
    flex-wrap: wrap;
}

.dr-links a {
    color: white;
    text-decoration: none;
    font-size: 14px;
}

.dr-profile {
    position: relative;
}

.dr-profile summary {
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 10px;
    list-style: none;
}

.dr-profile summary::-webkit-details-marker {
    display: none;
}

.dr-avatar {
    background: #2563eb;
    color: white;
    height: 43px;
    width: 43px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    font-weight: bold;
    font-size: 19px;
}

.dr-user {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.dr-user small {
    color: #b9c9e0;
}

.dr-menu {
    position: absolute;
    top: 55px;
    right: 0;
    width: 230px;
    background: white;
    color: #102544;
    padding: 15px;
    border-radius: 10px;
    box-shadow: 0 8px 25px #0003;
    z-index: 100;
    overflow-wrap: anywhere;
}

.dr-menu p {
    margin: 5px 0 12px;
    color: #555;
    font-size: 13px;
}

.dr-menu a {
    display: block;
    color: #102544;
    padding: 10px 0;
    text-decoration: none;
    border-top: 1px solid #eee;
}
</style>

<header class="dr-header">
    <div class="dr-header-inner">

        <a class="dr-logo" href="index.php">
            🚘 DriveLane
        </a>

        <nav class="dr-links">
            <a href="index.php">Home</a>
            <a href="index.php#fleet">Our Fleet</a>

            <?php if ($headerUser): ?>

                <a href="<?= htmlspecialchars(
                    $headerDashboard,
                    ENT_QUOTES, 'UTF-8'
                ) ?>">Dashboard</a>

                <details class="dr-profile">
                    <summary>
                        <span class="dr-user">
                            <strong><?= htmlspecialchars(
                                $headerUser['username'],
                                ENT_QUOTES, 'UTF-8'
                            ) ?></strong>

                            <small><?= htmlspecialchars(
                                $headerRoles[$headerUser['role']]
                                    ?? 'User',
                                ENT_QUOTES, 'UTF-8'
                            ) ?></small>
                        </span>

                        <span class="dr-avatar">
                            <?= htmlspecialchars(
                                strtoupper(substr(
                                    $headerUser['username'], 0, 1
                                )),
                                ENT_QUOTES, 'UTF-8'
                            ) ?>
                        </span>
                    </summary>

                    <div class="dr-menu">
                        <strong><?= htmlspecialchars(
                            $headerUser['username'],
                            ENT_QUOTES, 'UTF-8'
                        ) ?></strong>

                        <p><?= htmlspecialchars(
                            $headerUser['email'],
                            ENT_QUOTES, 'UTF-8'
                        ) ?></p>

                        <p><?= htmlspecialchars(
                            $headerRoles[$headerUser['role']]
                                ?? 'User',
                            ENT_QUOTES, 'UTF-8'
                        ) ?></p>

                        <a href="<?= htmlspecialchars(
                            $headerDashboard,
                            ENT_QUOTES, 'UTF-8'
                        ) ?>">My Dashboard</a>

                        <a href="profile.php">Edit Profile</a>

                        <a href="logout.php">Logout</a>
                    </div>
                </details>

            <?php else: ?>

                <a href="login.php">Login</a>
                <a href="register.php">Register</a>

            <?php endif; ?>
        </nav>

    </div>
</header>
