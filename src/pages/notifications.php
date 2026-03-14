<?php
// pages/notifications.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
$user    = currentUser();
$db      = getDB();
$errors  = [];
$success = [];

// ── Handle POST actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form token.';
    } else {
        $action = $_POST['action'] ?? '';

        // ── Delete single notification ────────────────────────
        if ($action === 'delete_notification') {
            $nid = (int)($_POST['notification_id'] ?? 0);
            if ($nid) {
                if ($user['role'] === 'admin') {
                    $db->prepare('DELETE FROM notifications WHERE notification_id = ?')
                       ->execute([$nid]);
                } else {
                    $db->prepare('DELETE FROM notifications WHERE notification_id = ? AND recipient_user_id = ?')
                       ->execute([$nid, $user['user_id']]);
                }
                $success[] = 'Notification deleted.';
            }
        }

        // ── Delete all ────────────────────────────────────────
        if ($action === 'delete_all') {
            if ($user['role'] === 'admin') {
                $db->exec('DELETE FROM notifications');
            } else {
                $db->prepare('DELETE FROM notifications WHERE recipient_user_id = ?')
                   ->execute([$user['user_id']]);
            }
            $success[] = 'All notifications cleared.';
        }

        // ── Admin: broadcast ──────────────────────────────────
        if ($action === 'broadcast' && $user['role'] === 'admin') {
            $msgText  = trim($_POST['message_text'] ?? '');
            $msgType  = $_POST['notification_type'] ?? 'admin_action';
            $validTypes = ['high_risk','medium_risk','info','admin_action'];
            if (!in_array($msgType, $validTypes, true)) $msgType = 'admin_action';

            if (strlen($msgText) < 5) {
                $errors[] = 'Message must be at least 5 characters.';
            } else {
                try {
                    $allUsers = $db->query('SELECT user_id FROM users WHERE is_active = 1')->fetchAll();
                    $ins = $db->prepare(
                        'INSERT INTO notifications
                            (recipient_user_id, incident_id, notification_type, message_text, is_read, created_at)
                         VALUES (?, NULL, ?, ?, 0, NOW())'
                    );
                    foreach ($allUsers as $u) {
                        $ins->execute([$u['user_id'], $msgType, $msgText]);
                    }
                    $success[] = 'Notification broadcast to ' . count($allUsers) . ' user(s).';
                } catch (\Throwable $ex) {
                    $errors[] = 'Broadcast failed: ' . $ex->getMessage();
                }
            }
        }

        // ── Admin: edit a notification ────────────────────────
        if ($action === 'edit_notification' && $user['role'] === 'admin') {
            $nid      = (int)($_POST['notification_id'] ?? 0);
            $msgText  = trim($_POST['message_text'] ?? '');
            $msgType  = $_POST['notification_type'] ?? 'info';
            $isRead   = isset($_POST['is_read']) ? 1 : 0;

            $validTypes = ['high_risk','medium_risk','info','admin_action'];
            if (!in_array($msgType, $validTypes, true)) $msgType = 'info';

            if (!$nid) {
                $errors[] = 'Invalid notification.';
            } elseif (strlen($msgText) < 1) {
                $errors[] = 'Message cannot be empty.';
            } else {
                $db->prepare(
                    'UPDATE notifications
                     SET message_text = ?, notification_type = ?, is_read = ?
                     WHERE notification_id = ?'
                )->execute([$msgText, $msgType, $isRead, $nid]);
                $success[] = 'Notification #' . $nid . ' updated.';
            }
        }
    }
}

// Admin sees ALL notifications; others see only their own
if ($user['role'] === 'admin') {
    $stmt = $db->query(
        'SELECT n.*, u.full_name AS recipient_name, u.email AS recipient_email
         FROM notifications n
         JOIN users u ON n.recipient_user_id = u.user_id
         ORDER BY n.created_at DESC
         LIMIT 200'
    );
    $notifications = $stmt->fetchAll();
} else {
    $notifications = getNotificationsForUser((int)$user['user_id']);
}

$pageTitle = 'Notifications';
include __DIR__ . '/../includes/header.php';

$validTypes = ['high_risk','medium_risk','info','admin_action'];
$typeLabels = [
    'high_risk'    => '🔴 High Risk',
    'medium_risk'  => '🟡 Medium Risk',
    'info'         => '🔵 Info',
    'admin_action' => '👮 Admin Action',
];
?>

<div class="page-container">

    <div class="page-header">
        <div>
            <h1>🔔 Notifications</h1>
            <p style="color:var(--color-muted);margin-top:.25rem;font-size:.95rem">
                <?= $user['role'] === 'admin'
                    ? 'Viewing all notifications across all users.'
                    : 'Stay updated with your latest scam alerts and account activity.' ?>
            </p>
        </div>
        <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;">
            <?php if (!empty($notifications)): ?>
                <?php if ($user['role'] !== 'admin'): ?>
                    <button class="btn btn-secondary" id="mark-all-read-btn">✔ Mark All as Read</button>
                <?php endif; ?>
                <form method="POST" onsubmit="return confirm('Clear all notifications?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_all">
                    <button type="submit" class="btn btn-outline-danger">🗑 Clear All</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php foreach ($errors  as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>
    <?php foreach ($success as $s):   ?><div class="alert alert-success"><?= e($s) ?></div><?php endforeach; ?>

    <!-- ── ADMIN: Broadcast panel ──────────────────────────── -->
    <?php if ($user['role'] === 'admin'): ?>
    <div class="card broadcast-card">
        <h2>📢 Send Notification to All Users</h2>
        <p style="color:var(--color-muted);font-size:.925rem;margin-bottom:1rem;">
            Broadcasts a message to every active user.
        </p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="broadcast">
            <div class="form-inline">
                <div class="form-group" style="flex:3;">
                    <label for="broadcast_msg">Message</label>
                    <textarea id="broadcast_msg" name="message_text" rows="2"
                        placeholder="e.g. We've updated our privacy policy."
                        required minlength="5"></textarea>
                </div>
                <div class="form-group" style="flex:1;min-width:170px;">
                    <label for="broadcast_type">Type</label>
                    <select id="broadcast_type" name="notification_type">
                        <?php foreach ($typeLabels as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $val === 'admin_action' ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"
                    onclick="return confirm('Send this notification to ALL active users?')">
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
                    <th style="width:<?= $user['role'] === 'admin' ? '25%' : '45%' ?>">Message</th>
                    <?php if ($user['role'] === 'admin'): ?>
                        <th>Recipient</th>
                    <?php endif; ?>
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

                    <!-- Message -->
                    <td>
                        <div style="display:flex;align-items:flex-start;gap:.65rem">
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

                    <!-- Recipient (admin only) -->
                    <?php if ($user['role'] === 'admin'): ?>
                    <td style="font-size:.875rem;">
                        <strong><?= e($n['recipient_name']) ?></strong><br>
                        <span style="color:var(--color-muted)"><?= e($n['recipient_email']) ?></span>
                    </td>
                    <?php endif; ?>

                    <!-- Type badge -->
                    <td>
                        <span class="notif-type-badge notif-type-<?= e($n['notification_type']) ?>">
                            <?= e(ucfirst(str_replace('_', ' ', $n['notification_type']))) ?>
                        </span>
                    </td>

                    <!-- Time + view incident link -->
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
                        <?php if ($user['role'] !== 'admin'): ?>
                        <button class="notif-status-btn <?= $n['is_read'] ? 'status-read' : 'status-unread' ?>"
                                onclick="toggleRead(event, this)">
                            <?= $n['is_read'] ? 'read' : 'unread' ?>
                        </button>
                        <?php else: ?>
                        <span class="notif-status-btn <?= $n['is_read'] ? 'status-read' : 'status-unread' ?>"
                              style="cursor:default;">
                            <?= $n['is_read'] ? 'read' : 'unread' ?>
                        </span>
                        <?php endif; ?>
                    </td>

                    <!-- Actions -->
                    <td style="text-align:center;white-space:nowrap;">
                        <?php if ($user['role'] === 'admin'): ?>
                        <button class="btn btn-sm btn-secondary"
                                title="Edit notification"
                                onclick="openEditPanel(
                                    <?= (int)$n['notification_id'] ?>,
                                    <?= htmlspecialchars(json_encode($n['message_text']), ENT_QUOTES) ?>,
                                    '<?= e($n['notification_type']) ?>',
                                    <?= $n['is_read'] ? 1 : 0 ?>
                                )">
                            ✏️ Edit
                        </button>
                        <?php endif; ?>

                        <form method="POST" class="notif-delete-form"
                              onsubmit="return confirmDelete(event, this)"
                              style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_notification">
                            <input type="hidden" name="notification_id" value="<?= (int)$n['notification_id'] ?>">
                            <button type="submit" class="btn btn-sm btn-delete-notif" title="Delete">🗑</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── Admin edit panel (slide-in overlay) ─────────────────── -->
<?php if ($user['role'] === 'admin'): ?>
<div id="editOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:10px;padding:2rem;width:100%;max-width:520px;margin:1rem;box-shadow:0 8px 32px rgba(0,0,0,.2);">
        <h2 style="margin-bottom:1.25rem;">✏️ Edit Notification</h2>
        <form method="POST" id="editForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit_notification">
            <input type="hidden" name="notification_id" id="edit_nid">

            <div class="form-group">
                <label for="edit_message">Message Text</label>
                <textarea id="edit_message" name="message_text" rows="4" required minlength="1"
                          style="width:100%;"></textarea>
            </div>

            <div class="form-group">
                <label for="edit_type">Notification Type</label>
                <select id="edit_type" name="notification_type" style="width:100%;">
                    <?php foreach ($typeLabels as $val => $label): ?>
                        <option value="<?= $val ?>"><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="display:flex;align-items:center;gap:.75rem;">
                <input type="checkbox" id="edit_is_read" name="is_read"
                       style="width:1.25rem;height:1.25rem;cursor:pointer;">
                <label for="edit_is_read" style="margin:0;font-weight:600;cursor:pointer;">
                    Mark as Read
                </label>
            </div>

            <div style="display:flex;gap:.75rem;margin-top:1.5rem;">
                <button type="submit" class="btn btn-primary" style="flex:1;">
                    💾 Save Changes
                </button>
                <button type="button" class="btn btn-outline" style="flex:1;"
                        onclick="closeEditPanel()">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<style>
.notif-row { transition: background .15s; cursor: default; }
.notif-row-unread { background: #eff6ff; }
.notif-row-unread:hover { background: #dbeafe; }
.notif-row-read { background: var(--color-surface); }
.notif-row-read:hover { background: var(--color-bg); }
.notif-icon-cell { font-size: 1.3rem; flex-shrink: 0; width: 2rem; text-align: center; padding-top: .1rem; }
.notif-title { font-weight: 700; font-size: .92rem; margin-bottom: .15rem; }
.notif-message-text { font-size: .875rem; color: var(--color-muted); line-height: 1.4; }
.notif-type-badge { display:inline-block; padding:.2rem .65rem; border-radius:var(--radius); font-size:.8rem; font-weight:600; background:var(--color-border); color:var(--color-text); }
.notif-type-high_risk    { background:#fee2e2; color:#991b1b; }
.notif-type-medium_risk  { background:#fef3c7; color:#92400e; }
.notif-type-admin_action { background:#ede9fe; color:#5b21b6; }
.notif-status-btn { display:inline-block; padding:.2rem .75rem; border-radius:999px; font-size:.8rem; font-weight:700; border:none; cursor:pointer; transition:background .15s; white-space:nowrap; }
.status-unread { background:#dbeafe; color:#1e40af; }
.status-unread:hover { background:#bfdbfe; }
.status-read { background:#f3f4f6; color:#6b7280; }
.status-read:hover { background:#e5e7eb; }
.btn-delete-notif { background:transparent; color:var(--color-muted); border:1px solid transparent; padding:.25rem .55rem; font-size:1rem; line-height:1; border-radius:var(--radius); transition:background .15s,color .15s; cursor:pointer; }
.btn-delete-notif:hover { background:#fee2e2; color:var(--color-danger); border-color:#fca5a5; }
.notif-delete-form { display:inline; margin:0; padding:0; }
.notif-row.removing { transition:opacity .3s,transform .3s; opacity:0; transform:translateX(20px); pointer-events:none; }
.broadcast-card { border-left:4px solid #7c3aed; background:#faf5ff; }
.broadcast-card h2 { color:#5b21b6; }
</style>

<script>
// ── Edit panel ────────────────────────────────────────────────
function openEditPanel(id, message, type, isRead) {
    document.getElementById('edit_nid').value     = id;
    document.getElementById('edit_message').value = message;
    document.getElementById('edit_type').value    = type;
    document.getElementById('edit_is_read').checked = isRead === 1;

    const overlay = document.getElementById('editOverlay');
    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    // Focus the textarea
    setTimeout(() => document.getElementById('edit_message').focus(), 50);
}

function closeEditPanel() {
    document.getElementById('editOverlay').style.display = 'none';
    document.body.style.overflow = '';
}

// Close when clicking the dark backdrop
document.getElementById('editOverlay')?.addEventListener('click', function(e) {
    if (e.target === this) closeEditPanel();
});

// Close on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeEditPanel();
});

// ── Toggle read (non-admin only) ──────────────────────────────
async function toggleRead(event, btn) {
    event.stopPropagation();
    const row    = btn.closest('.notif-row');
    const id     = row.dataset.id;
    const isRead = row.dataset.read === '1';
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

// ── Delete with animation ─────────────────────────────────────
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

// ── Mark all read ─────────────────────────────────────────────
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
        if (btn) { btn.classList.replace('status-unread', 'status-read'); btn.textContent = 'read'; }
        changed++;
    });
    updateBadge(-changed);
});

// ── Badge counter ─────────────────────────────────────────────
function updateBadge(delta) {
    const badge = document.getElementById('notif-badge');
    if (!badge) return;
    let count = Math.max(0, (parseInt(badge.textContent) || 0) + delta);
    badge.textContent   = count;
    badge.style.display = count > 0 ? '' : 'none';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>