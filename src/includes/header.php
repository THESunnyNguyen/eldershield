<?php
// ============================================================
// includes/header.php
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/billing_helper.php';

$user        = currentUser();
$unreadCount = isLoggedIn() ? countUnreadNotifications((int)$user['user_id']) : 0;
$pageTitle   = $pageTitle ?? APP_NAME;
$flash       = getFlash();

// ── Billing runner trigger ────────────────────────────────────
// Checks on every page load whether the 1st-of-month billing
// cycle needs to run. billingRunNeeded() is cheap (one indexed
// query) and runBillingCycle() only fires once per month.
if (isLoggedIn() && billingRunNeeded()) {
    runBillingCycle();
}

// ── Caregiver payment failure check ──────────────────────────
$hasBillingFailure = false;
if (isLoggedIn() && $user['role'] === 'caregiver') {
    $hasBillingFailure = hasFailedInvoice((int)$user['user_id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/style.css">
</head>
<body class="role-<?= e($user['role'] ?? 'guest') ?>">

<nav class="navbar">
    <div class="nav-brand">
        <a href="<?= e(APP_URL) ?>/pages/dashboard.php">🛡️ ElderShield</a>
    </div>

    <?php if (isLoggedIn()): ?>
    <ul class="nav-links">
        <?php if ($user['role'] === 'elder'): ?>
            <li><a href="<?= e(APP_URL) ?>/pages/submit.php">Report Scam</a></li>
            <li><a href="<?= e(APP_URL) ?>/pages/my_incidents.php">My Reports</a></li>

        <?php elseif ($user['role'] === 'caregiver'): ?>
            <li><a href="<?= e(APP_URL) ?>/pages/dashboard.php">Dashboard</a></li>
            <li><a href="<?= e(APP_URL) ?>/pages/incidents.php">All Reports</a></li>

        <?php elseif ($user['role'] === 'admin'): ?>
            <li><a href="<?= e(APP_URL) ?>/pages/dashboard.php">Dashboard</a></li>
            <li><a href="<?= e(APP_URL) ?>/pages/incidents.php">All Reports</a></li>
            <li><a href="<?= e(APP_URL) ?>/pages/admin_users.php">Users</a></li>
        <?php endif; ?>

        <li>
            <a href="<?= e(APP_URL) ?>/pages/notifications.php" class="notif-link">
                🔔<?php if ($unreadCount > 0): ?>
                    <span class="badge-notif"><?= (int)$unreadCount ?></span>
                <?php endif; ?>
            </a>
        </li>

        <?php if ($user['role'] === 'caregiver'): ?>
        <!-- Billing link — shows warning icon if payment has failed -->
        <li>
            <a href="<?= e(APP_URL) ?>/pages/subscription.php"
               class="<?= $hasBillingFailure ? 'billing-nav-alert' : '' ?>">
                💳 Billing<?= $hasBillingFailure ? ' ⚠️' : '' ?>
            </a>
        </li>
        <?php endif; ?>

        <li><a href="<?= e(APP_URL) ?>/pages/profile.php"><?= e($user['full_name']) ?></a></li>
        <li><a href="<?= e(APP_URL) ?>/pages/logout.php">Logout</a></li>
    </ul>

    <?php else: ?>
    <ul class="nav-links">
        <li><a href="<?= e(APP_URL) ?>/pages/login.php">Login</a></li>
        <li><a href="<?= e(APP_URL) ?>/pages/register.php">Register</a></li>
    </ul>
    <?php endif; ?>
</nav>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>">
    <?= e($flash['message']) ?>
</div>
<?php endif; ?>

<?php if ($hasBillingFailure): ?>
<div class="billing-failure-bar">
    ⚠️ <strong>Payment issue:</strong> One or more invoices could not be processed.
    <a href="<?= e(APP_URL) ?>/pages/subscription.php">Resolve now →</a>
</div>
<?php endif; ?>

<main class="main-content">
