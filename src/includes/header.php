<?php
// includes/header.php
// Usage: include with $pageTitle set beforehand
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$user          = currentUser();
$unreadCount   = isLoggedIn() ? countUnreadNotifications((int)$user['user_id']) : 0;
$pageTitle     = $pageTitle ?? APP_NAME;
$flash         = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body class="role-<?= e($user['role'] ?? 'guest') ?>">

<nav class="navbar">
    <div class="nav-brand">
        <a href="<?= APP_URL ?>/pages/dashboard.php">🛡️ ElderShield</a>
    </div>
    <?php if (isLoggedIn()): ?>
    <ul class="nav-links">
        <?php if ($user['role'] === 'elder'): ?>
            <li><a href="<?= APP_URL ?>/pages/submit.php">Report Scam</a></li>
            <li><a href="<?= APP_URL ?>/pages/my_incidents.php">My Reports</a></li>
        <?php elseif (in_array($user['role'], ['caregiver','admin'])): ?>
            <li><a href="<?= APP_URL ?>/pages/dashboard.php">Dashboard</a></li>
            <li><a href="<?= APP_URL ?>/pages/incidents.php">All Reports</a></li>
            <?php if ($user['role'] === 'admin'): ?>
            <li><a href="<?= APP_URL ?>/pages/admin_users.php">Users</a></li>
            <?php endif; ?>
        <?php endif; ?>
        <li>
            <a href="<?= APP_URL ?>/pages/notifications.php" class="notif-link">
                🔔 <?= $unreadCount > 0 ? "<span class=\"badge-notif\">{$unreadCount}</span>" : '' ?>
            </a>
        </li>
        <li><a href="<?= APP_URL ?>/pages/profile.php"><?= e($user['full_name']) ?></a></li>
        <li><a href="<?= APP_URL ?>/pages/logout.php">Logout</a></li>
    </ul>
    <?php else: ?>
    <ul class="nav-links">
        <li><a href="<?= APP_URL ?>/pages/login.php">Login</a></li>
        <li><a href="<?= APP_URL ?>/pages/register.php">Register</a></li>
    </ul>
    <?php endif; ?>
</nav>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>">
    <?= e($flash['message']) ?>
</div>
<?php endif; ?>

<main class="main-content">
