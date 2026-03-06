<?php
// pages/notifications.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
$user          = currentUser();
$notifications = getNotificationsForUser((int)$user['user_id']);

// Read/unread state is toggled individually via mark_notification.php.

$pageTitle = 'Notifications';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-container">

    <!-- Page header row -->
    <div class="page-header">
        <div>
            <h1>🔔 Notifications</h1>
            <p style="color:var(--color-muted);margin-top:.25rem;font-size:.95rem">
                Stay updated with your latest scam alerts and account activity.
            </p>
        </div>
        <?php if (!empty($notifications)): ?>
        <button class="btn btn-primary" id="mark-all-read-btn">✔ Mark All as Read</button>
        <?php endif; ?>
    </div>

    <?php if (empty($notifications)): ?>
        <div class="empty-state"><p>No notifications yet. You're all clear!</p></div>
    <?php else: ?>

    <!-- Table layout matching screenshot structure -->
    <div class="card" style="padding:0;overflow:hidden;">
        <table class="data-table" id="notif-table">
            <thead>
                <tr>
                    <th style="width:55%">Notification</th>
                    <th>Type</th>
                    <th>Time</th>
                    <th style="text-align:center">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($notifications as $n): ?>
                <tr class="notif-row <?= $n['is_read'] ? 'notif-row-read' : 'notif-row-unread' ?>"
                    data-id="<?= (int)$n['notification_id'] ?>"
                    data-read="<?= $n['is_read'] ? '1' : '0' ?>">

                    <!-- Notification: icon + message -->
                    <td>
                        <div style="display:flex;align-items:center;gap:.75rem">
                            <div class="notif-icon-cell">
                                <?= match($n['notification_type']) {
                                    'high_risk'    => '🔴',
                                    'medium_risk'  => '🟡',
                                    'admin_action' => '👮',
                                    default        => '🔵',
                                } ?>
                            </div>
                            <div>
                                <div class="notif-title">
                                    <?= match($n['notification_type']) {
                                        'high_risk'    => 'High Risk Alert',
                                        'medium_risk'  => 'Medium Risk Alert',
                                        'admin_action' => 'Admin Action',
                                        default        => 'Notification',
                                    } ?>
                                </div>
                                <div class="notif-message-text"><?= e($n['message_text']) ?></div>
                            </div>
                        </div>
                    </td>

                    <!-- Type badge -->
                    <td>
                        <span class="notif-type-badge notif-type-<?= e($n['notification_type']) ?>">
                            <?= e(ucfirst(str_replace('_', ' ', $n['notification_type']))) ?>
                        </span>
                    </td>

                    <!-- Time + view link -->
                    <td>
                        <div><?= timeAgo($n['created_at']) ?></div>
                        <a href="<?= APP_URL ?>/pages/incident_detail.php?id=<?= $n['incident_id'] ?>"
                           class="btn btn-sm" style="margin-top:.4rem"
                           onclick="event.stopPropagation()">View</a>
                    </td>

                    <!-- Read/unread toggle -->
                    <td style="text-align:center">
                        <button class="notif-status-btn <?= $n['is_read'] ? 'status-read' : 'status-unread' ?>"
                                onclick="toggleRead(event, this)">
                            <?= $n['is_read'] ? 'read' : 'unread' ?>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<style>
/* ── Notification table rows ───────────────────────────── */
.notif-row { transition: background .15s; cursor: default; }
.notif-row-unread { background: #eff6ff; }
.notif-row-unread:hover { background: #dbeafe; }
.notif-row-read { background: var(--color-surface); }
.notif-row-read:hover { background: var(--color-bg); }

.notif-icon-cell {
    font-size: 1.4rem;
    flex-shrink: 0;
    width: 2.2rem;
    text-align: center;
}

.notif-title {
    font-weight: 700;
    font-size: .95rem;
    color: var(--color-text);
    margin-bottom: .15rem;
}
.notif-message-text {
    font-size: .875rem;
    color: var(--color-muted);
    line-height: 1.4;
}

/* ── Type badge ────────────────────────────────────────── */
.notif-type-badge {
    display: inline-block;
    padding: .2rem .65rem;
    border-radius: var(--radius);
    font-size: .8rem;
    font-weight: 600;
    background: var(--color-border);
    color: var(--color-text);
}
.notif-type-high_risk    { background: #fee2e2; color: #991b1b; }
.notif-type-medium_risk  { background: #fef3c7; color: #92400e; }
.notif-type-admin_action { background: #ede9fe; color: #5b21b6; }

/* ── Read/unread toggle button ─────────────────────────── */
.notif-status-btn {
    display: inline-block;
    padding: .2rem .75rem;
    border-radius: 999px;
    font-size: .8rem;
    font-weight: 700;
    border: none;
    cursor: pointer;
    transition: background .15s, color .15s;
    white-space: nowrap;
}
.status-unread {
    background: #dbeafe;
    color: #1e40af;
}
.status-unread:hover {
    background: #bfdbfe;
}
.status-read {
    background: #f3f4f6;
    color: #6b7280;
}
.status-read:hover {
    background: #e5e7eb;
    color: var(--color-text);
}
</style>

<script>
async function toggleRead(event, btn) {
    event.stopPropagation();

    const row     = btn.closest('.notif-row');
    const id      = row.dataset.id;
    const isRead  = row.dataset.read === '1';
    const newRead = isRead ? 0 : 1;

    try {
        const res = await fetch('<?= APP_URL ?>/pages/mark_notification.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ notification_id: id, is_read: newRead })
        });
        if (!res.ok) throw new Error('Request failed');

        // Update row state
        row.dataset.read = String(newRead);
        if (newRead === 1) {
            row.classList.replace('notif-row-unread', 'notif-row-read');
            btn.classList.replace('status-unread', 'status-read');
            btn.textContent = 'read';
        } else {
            row.classList.replace('notif-row-read', 'notif-row-unread');
            btn.classList.replace('status-read', 'status-unread');
            btn.textContent = 'unread';
        }

        // Update navbar bell badge
        updateBadge(newRead === 1 ? -1 : 1);

    } catch (err) {
        console.error('Could not update notification:', err);
    }
}

// Mark all as read
document.getElementById('mark-all-read-btn')?.addEventListener('click', async () => {
    const unreadRows = document.querySelectorAll('.notif-row[data-read="0"]');
    if (!unreadRows.length) return;

    await Promise.all([...unreadRows].map(row =>
        fetch('<?= APP_URL ?>/pages/mark_notification.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ notification_id: row.dataset.id, is_read: 1 })
        })
    ));

    let changed = 0;
    unreadRows.forEach(row => {
        row.dataset.read = '1';
        row.classList.replace('notif-row-unread', 'notif-row-read');
        const btn = row.querySelector('.notif-status-btn');
        btn.classList.replace('status-unread', 'status-read');
        btn.textContent = 'read';
        changed++;
    });

    updateBadge(-changed);
});

function updateBadge(delta) {
    const badge = document.getElementById('notif-badge');
    if (!badge) return;
    let count = Math.max(0, (parseInt(badge.textContent) || 0) + delta);
    badge.textContent    = count;
    badge.style.display  = count > 0 ? '' : 'none';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>