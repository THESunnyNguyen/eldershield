<?php
// pages/unauthorized.php — 403 Forbidden page (required by rubric Section 3)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

http_response_code(403);

$pageTitle = 'Unauthorized';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-container" style="text-align:center; padding:4rem 1rem;">
    <div style="font-size:4rem; margin-bottom:0.5rem;">&#128683;</div>
    <h1>403 — Access Denied</h1>
    <p style="color:var(--color-muted); margin:1rem 0 2rem; max-width:480px; margin-left:auto; margin-right:auto;">
        You do not have permission to view this page.
        Your current role does not grant access to this resource.
        If you believe this is an error, please contact an administrator.
    </p>
    <?php if (isLoggedIn()): ?>
        <a href="<?= APP_URL ?>/pages/dashboard.php" class="btn btn-primary">Return to Dashboard</a>
    <?php else: ?>
        <a href="<?= APP_URL ?>/pages/login.php" class="btn btn-primary">Sign In</a>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
