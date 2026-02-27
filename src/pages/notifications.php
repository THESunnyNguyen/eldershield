<?php
// pages/notifications.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
$user          = currentUser();
$notifications = getNotificationsForUser((int)$user['user_id']);

// Mark all read on page visit
$db = getDB();
$db->prepare('UPDATE notifications SET is_read=1 WHERE recipient_user_id=?')
   ->execute([$user['user_id']]);

$pageTitle = 'Notifications';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-container">
    <h1>🔔 Notifications</h1>

    <?php if (empty($notifications)): ?>
        <div class="empty-state"><p>No notifications yet. You're all clear!</p></div>
    <?php else: ?>
        <div class="notif-list">
        <?php foreach ($notifications as $n): ?>
            <div class="notif-item notif-<?= e($n['notification_type']) ?> <?= $n['is_read'] ? '' : 'notif-unread' ?>">
                <div class="notif-icon">
                    <?= match($n['notification_type']) {
                        'high_risk'    => '🔴',
                        'medium_risk'  => '🟡',
                        'admin_action' => '👮',
                        default        => '🔵',
                    } ?>
                </div>
                <div class="notif-body">
                    <p class="notif-message"><?= e($n['message_text']) ?></p>
                    <div class="notif-footer">
                        <span class="notif-time"><?= timeAgo($n['created_at']) ?></span>
                        <a href="<?= APP_URL ?>/pages/incident_detail.php?id=<?= $n['incident_id'] ?>"
                           class="btn btn-sm">View Incident</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
