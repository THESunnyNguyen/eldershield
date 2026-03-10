<?php
// pages/notifications.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
$user          = currentUser();
$db            = getDB();
$errors        = [];
$success       = [];

// ── Handle POST actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form token.';
    } else {
        $action = $_POST['action'] ?? '';

        // Delete a single notification (owner or admin)
        if ($action === 'delete_notification') {
            $nid = (int)($_POST['notification_id'] ?? 0);
            if ($nid) {
                if ($user['role'] === 'admin') {
                    $db->prepare('DELETE FROM notifications WHERE notification_id = ?')->execute([$nid]);
                } else {
                    $db->prepare('DELETE FROM notifications WHERE notification_id = ? AND recipient_user_id = ?')
                       ->execute([$nid, $user['user_id']]);
                }
                $success[] = 'Notification deleted.';
            }
        }

        // Delete ALL notifications for current user
        if ($action === 'delete_all') {
            if ($user['role'] === 'admin') {
                $db->exec('DELETE FROM notifications');
            } else {
                $db->prepare('DELETE FROM notifications WHERE recipient_user_id = ?')
                   ->execute([$user['user_id']]);
            }
            $success[] = 'All notifications cleared.';
        }

		// Admin: broadcast a notification to all users
        if ($action === 'broadcast' && $user['role'] === 'admin') {
            $msgText   = trim($_POST['message_text'] ?? '');
            $notifType = 'admin_action';
            if (strlen($msgText) < 5) {
                $errors[] = 'Message must be at least 5 characters.';
            } else {
                try {
                    $allUsers = $db->query('SELECT user_id FROM users WHERE is_active = 1')->fetchAll();
                    $ins = $db->prepare(
                        'INSERT INTO notifications (recipient_user_id, incident_id, notification_type, message_text, is_read, created_at)
                         VALUES (?, NULL, ?, ?, 0, NOW())'
                    );
                    foreach ($allUsers as $u) {
                        $ins->execute([$u['user_id'], $notifType, $msgText]);
                    }
                    $success[] = 'Notification broadcast to ' . count($allUsers) . ' user(s).';
                } catch (\Throwable $ex) {
                    $errors[] = 'Broadcast failed: ' . $ex->getMessage();
                }
            }
        }
    }
}

$notifications = getNotificationsForUser((int)$user['user_id']);

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
        <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;">
            <?php if (!empty($notifications)): ?>
                <button class="btn btn-secondary" id="mark-all-read-btn">✔ Mark All as Read</button>
                <form method="POST" onsubmit="return confirm('Clear all notifications?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_all">
                    <button type="submit" class="btn btn-outline-danger">🗑 Clear All</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php foreach ($errors  as $e): ?><div class="alert alert-danger"><?= e($e) ?></div><?php endforeach; ?>
    <?php foreach ($success as $s): ?><div class="alert alert-success"><?= e($s) ?></div><?php endforeach; ?>

    <!-- ── ADMIN: Broadcast panel ──────────────────────────── -->
    <?php if ($user['role'] === 'admin'): ?>
    <div class="card broadcast-card">
        <h2>📢 Send Notification to All Users</h2>
        <p style="color:var(--color-muted);font-size:.925rem;margin-bottom:1rem;">
            This message will be sent as an <strong>Admin Action</strong> notification to every active user.
        </p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="broadcast">
            <div class="form-group">
                <label for="broadcast_msg">Message</label>
                <textarea id="broadcast_msg" name="message_text" rows="3"
                    placeholder="e.g. We've updated our privacy policy. Please review it in your account settings."
                    required minlength="5"></textarea>
            </div>
            <button type="submit" class="btn btn-primary" onclick="return confirm('Send this notification to ALL active users?')">
                📤 Broadcast to All Users
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- ── Notification table ──────────────────────────────── -->
    <?php if (empty($notifications)): ?>
        <div class="empty-state"><p>No notifications yet. You're all clear!</p></div>
    <?php else: ?>

    <div class="card" style="padding:0;overflow:hidden;">
        <table class="data-table" id="notif-table">
            <thead>
                <tr>
                    <th style="width:50%">Notification</th>
                    <th>Type</th>
                    <th>Time</th>
                    <th style="text-align:center">Status</th>
                    <th style="text-align:center">Actions</th>
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
                        <?php if (!empty($n['incident_id'])): ?>
                        <a href="<?= APP_URL ?>/pages/incident_detail.php?id=<?= $n['incident_id'] ?>"
                           class="btn btn-sm" style="margin-top:.4rem"
                           onclick="event.stopPropagation()">View</a>
                        <?php endif; ?>
                    </td>

                    <!-- Read/unread toggle -->
                    <td style="text-align:center">
                        <button class="notif-status-btn <?= $n['is_read'] ? 'status-read' : 'status-unread' ?>"
                                onclick="toggleRead(event, this)">
                            <?= $n['is_read'] ? 'read' : 'unread' ?>
                        </button>
                    </td>

                    <!-- Delete -->
                    <td style="text-align:center">
                        <form method="POST" class="notif-delete-form"
                              onsubmit="return confirmDelete(event, this)">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_notification">
                            <input type="hidden" name="notification_id" value="<?= (int)$n['notification_id'] ?>">
                            <button type="submit" class="btn btn-sm btn-delete-notif" title="Delete notification">
                                🗑
                            </button>
                        </form>
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
.status-unread { background: #dbeafe; color: #1e40af; }
.status-unread:hover { background: #bfdbfe; }
.status-read { background: #f3f4f6; color: #6b7280; }
.status-read:hover { background: #e5e7eb; color: var(--color-text); }

/* ── Delete button ─────────────────────────────────────── */
.btn-delete-notif {
    background: transparent;
    color: var(--color-muted);
    border: 1px solid transparent;
    padding: .25rem .55rem;
    font-size: 1rem;
    line-height: 1;
    border-radius: var(--radius);
    transition: background .15s, color .15s, border-color .15s;
}
.btn-delete-notif:hover {
    background: #fee2e2;
    color: var(--color-danger);
    border-color: #fca5a5;
}
.notif-delete-form { display: inline; margin: 0; padding: 0; }

/* ── Row exit animation ────────────────────────────────── */
.notif-row.removing {
    transition: opacity .3s, transform .3s;
    opacity: 0;
    transform: translateX(20px);
    pointer-events: none;
}

/* ── Broadcast card ────────────────────────────────────── */
.broadcast-card {
    border-left: 4px solid #7c3aed;
    background: #faf5ff;
}
.broadcast-card h2 { color: #5b21b6; }
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
        updateBadge(newRead === 1 ? -1 : 1);
    } catch (err) {
        console.error('Could not update notification:', err);
    }
}

// Animate row out, then submit the delete form
function confirmDelete(event, form) {
    event.preventDefault();
    const row = form.closest('.notif-row');
    const wasUnread = row.dataset.read === '0';

    row.classList.add('removing');
    setTimeout(() => {
        if (wasUnread) updateBadge(-1);
        form.submit();
    }, 300);
    return false;
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
    badge.textContent   = count;
    badge.style.display = count > 0 ? '' : 'none';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>