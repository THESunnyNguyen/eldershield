<?php
// ============================================================
// pages/admin_users.php — User management + caregiver linking + billing overview
// ============================================================
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/billing_helper.php';

requireLogin();
$user = currentUser();
$db   = getDB();

if (!in_array($user['role'], ['admin', 'caregiver'])) {
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

$errors  = [];
$success = [];

// ── Handle POST actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        $errors[] = 'Invalid form token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'toggle_active' && $user['role'] === 'admin') {
            $uid = (int)($_POST['target_user_id'] ?? 0);
            if ($uid && $uid !== (int)$user['user_id']) {
                $db->prepare('UPDATE users SET is_active = NOT is_active WHERE user_id=?')->execute([$uid]);
                $success[] = 'User status updated.';
            }
        }

        if ($action === 'change_role' && $user['role'] === 'admin') {
            $uid     = (int)($_POST['target_user_id'] ?? 0);
            $newRole = $_POST['new_role'] ?? '';
            if ($uid && in_array($newRole, ['elder', 'caregiver', 'admin'], true)) {
                $db->prepare('UPDATE users SET role=? WHERE user_id=?')->execute([$newRole, $uid]);
                $success[] = 'Role updated.';
            }
        }

        if ($action === 'link') {
            $elderEmail  = trim($_POST['elder_email'] ?? '');
            $caregiverId = ($user['role'] === 'admin')
                ? (int)($_POST['caregiver_id'] ?? $user['user_id'])
                : (int)$user['user_id'];

            $elderStmt = $db->prepare('SELECT user_id FROM users WHERE email=? AND role="elder" AND is_active=1');
            $elderStmt->execute([$elderEmail]);
            $elder = $elderStmt->fetch();

            if (!$elder) {
                $errors[] = 'No active elder account found with that email.';
            } else {
                $result = linkCaregiverToElder((int)$elder['user_id'], $caregiverId);
                $result['success']
                    ? $success[] = 'Link request sent. The elder must approve it.'
                    : $errors[]  = $result['message'];
            }
        }

        if ($action === 'approve_link') {
            approveLink((int)($_POST['link_id'] ?? 0));
            $success[] = 'Link approved.';
        }

        if ($action === 'revoke_link') {
            revokeLink((int)($_POST['link_id'] ?? 0));
            $success[] = 'Link revoked.';
        }

        if ($action === 'delete_user' && $user['role'] === 'admin') {
            $uid = (int)($_POST['target_user_id'] ?? 0);
            if ($uid && $uid !== (int)$user['user_id']) {
                $db->prepare('DELETE FROM users WHERE user_id=?')->execute([$uid]);
                $success[] = 'User deleted.';
            }
        }
    }
}

// ── Fetch data ────────────────────────────────────────────────
if ($user['role'] === 'admin') {
    $users = $db->query('SELECT * FROM users ORDER BY created_at DESC')->fetchAll();
    $links = $db->query(
        'SELECT al.*, e.full_name AS elder_name, e.email AS elder_email,
                c.full_name AS caregiver_name
         FROM account_links al
         JOIN users e ON al.elder_user_id = e.user_id
         JOIN users c ON al.caregiver_user_id = c.user_id
         ORDER BY al.created_at DESC'
    )->fetchAll();
    $caregivers = array_filter($users, fn($u) => $u['role'] === 'caregiver');

    // ── Billing overview: all caregivers + their latest invoice ──
    $billingStmt = $db->query(
        "SELECT u.user_id, u.full_name, u.email,
                COUNT(DISTINCT al.link_id) AS active_elder_count,
                latest.billing_month, latest.amount_cents, latest.status AS invoice_status,
                pm.card_last4
         FROM users u
         LEFT JOIN account_links al
               ON al.caregiver_user_id = u.user_id AND al.status = 'active'
         LEFT JOIN caregiver_payment_methods pm
               ON pm.caregiver_id = u.user_id
         LEFT JOIN invoices latest
               ON latest.invoice_id = (
                   SELECT invoice_id FROM invoices
                   WHERE caregiver_id = u.user_id
                   ORDER BY billing_month DESC LIMIT 1
               )
         WHERE u.role = 'caregiver' AND u.is_active = 1
         GROUP BY u.user_id, u.full_name, u.email,
                  latest.billing_month, latest.amount_cents, latest.status, pm.card_last4
         ORDER BY u.full_name"
    );
    $caregiverBilling = $billingStmt->fetchAll();

} else {
    $stmt = $db->prepare(
        'SELECT al.*, u.full_name AS elder_name, u.email AS elder_email
         FROM account_links al
         JOIN users u ON al.elder_user_id = u.user_id
         WHERE al.caregiver_user_id = ?'
    );
    $stmt->execute([$user['user_id']]);
    $links    = $stmt->fetchAll();
    $users    = [];
    $caregiverBilling = [];
}

$pageTitle = 'User Management';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-container">
    <h1><?= $user['role'] === 'admin' ? 'User Management' : 'Manage My Elders' ?></h1>

    <?php foreach ($errors  as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>
    <?php foreach ($success as $msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endforeach; ?>

    <!-- ── LINK NEW ELDER ─────────────────────────────────────── -->
    <div class="card">
        <h2>Link an Elder Account</h2>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="link">
            <div class="form-inline">
                <input type="email" name="elder_email" placeholder="Elder's email address" required>
                <?php if ($user['role'] === 'admin'): ?>
                <select name="caregiver_id">
                    <?php foreach ($caregivers as $cg): ?>
                        <option value="<?= (int)$cg['user_id'] ?>"><?= e($cg['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary">Send Link Request</button>
            </div>
        </form>
    </div>

    <!-- ── ACCOUNT LINKS ──────────────────────────────────────── -->
    <?php if (!empty($links)): ?>
    <div class="card">
        <h2>Caregiver–Elder Relationships</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Elder</th>
                    <?php if ($user['role'] === 'admin'): ?><th>Caregiver</th><?php endif; ?>
                    <th>Status</th>
                    <th>Linked</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($links as $l): ?>
                <tr>
                    <td><?= e($l['elder_name']) ?><br><small><?= e($l['elder_email']) ?></small></td>
                    <?php if ($user['role'] === 'admin'): ?><td><?= e($l['caregiver_name']) ?></td><?php endif; ?>
                    <td><span class="status-badge status-<?= e($l['status']) ?>"><?= e(ucfirst($l['status'])) ?></span></td>
                    <td><?= $l['linked_at'] ? e(date('M j, Y', strtotime($l['linked_at']))) : '—' ?></td>
                    <td>
                        <?php if ($l['status'] === 'pending'): ?>
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"  value="approve_link">
                                <input type="hidden" name="link_id" value="<?= (int)$l['link_id'] ?>">
                                <button class="btn btn-sm btn-success">Approve</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($l['status'] === 'active'): ?>
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"  value="revoke_link">
                                <input type="hidden" name="link_id" value="<?= (int)$l['link_id'] ?>">
                                <button class="btn btn-sm btn-danger"
                                        onclick="return confirm('Revoke this relationship? This will be recorded for billing proration.')">
                                    Revoke
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ── BILLING OVERVIEW (admin only) ──────────────────────── -->
    <?php if ($user['role'] === 'admin' && !empty($caregiverBilling)): ?>
    <div class="card">
        <h2>💳 Caregiver Billing Overview</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Caregiver</th>
                    <th>Active Elders</th>
                    <th>Est. Monthly</th>
                    <th>Last Invoice</th>
                    <th>Last Status</th>
                    <th>Payment Method</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($caregiverBilling as $cg): ?>
                <tr class="<?= $cg['invoice_status'] === 'failed' ? 'row-danger' : '' ?>">
                    <td>
                        <?= e($cg['full_name']) ?>
                        <br><small><?= e($cg['email']) ?></small>
                    </td>
                    <td><?= (int)$cg['active_elder_count'] ?></td>
                    <td><?= e(formatCents((int)$cg['active_elder_count'] * 99)) ?></td>
                    <td>
                        <?= $cg['billing_month']
                            ? e(date('F Y', strtotime($cg['billing_month'])))
                            : '<span class="text-muted">None yet</span>' ?>
                    </td>
                    <td>
                        <?php if ($cg['invoice_status'] === 'paid'):
                            echo '<span class="invoice-status invoice-paid">✅ Paid</span>';
                        elseif ($cg['invoice_status'] === 'failed'):
                            echo '<span class="invoice-status invoice-failed">❌ Failed</span>';
                        elseif ($cg['invoice_status'] === 'pending'):
                            echo '<span class="invoice-status invoice-pending">⏳ Pending</span>';
                        else:
                            echo '<span class="text-muted">—</span>';
                        endif; ?>
                    </td>
                    <td>
                        <?= $cg['card_last4']
                            ? '•••• ' . e($cg['card_last4'])
                            : '<span class="text-muted">None saved</span>' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ── ALL USERS (admin only) ─────────────────────────────── -->
    <?php if ($user['role'] === 'admin' && !empty($users)): ?>
    <div class="card">
        <h2>All Users (<?= (int)count($users) ?>)</h2>
        <table class="data-table">
            <thead>
                <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr class="<?= !$u['is_active'] ? 'row-muted' : '' ?>">
                    <td><?= e($u['full_name']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td>
                        <form method="POST" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="change_role">
                            <input type="hidden" name="target_user_id" value="<?= (int)$u['user_id'] ?>">
                            <select name="new_role" onchange="this.form.submit()">
                                <?php foreach (['elder', 'caregiver', 'admin'] as $r): ?>
                                    <option value="<?= e($r) ?>" <?= $u['role'] === $r ? 'selected' : '' ?>>
                                        <?= ucfirst($r) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td>
                        <form method="POST" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="target_user_id" value="<?= (int)$u['user_id'] ?>">
                            <button class="btn btn-sm <?= $u['is_active'] ? 'btn-warning' : 'btn-success' ?>"
                                    <?= $u['user_id'] == $user['user_id'] ? 'disabled' : '' ?>>
                                <?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>
                            </button>
                        </form>
                    </td>
                    <td><?= e(date('M j, Y', strtotime($u['created_at']))) ?></td>
                    <td>
                        <?php if ($u['user_id'] != $user['user_id']): ?>
                        <form method="POST" style="display:inline"
                              onsubmit="return confirm('Permanently delete <?= e($u['full_name']) ?>?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_user">
                            <input type="hidden" name="target_user_id" value="<?= (int)$u['user_id'] ?>">
                            <button class="btn btn-sm btn-danger">Delete</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
