<?php
// pages/admin_subscriptions.php — Admin subscription management for caregivers
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/subscription_helper.php';

requireLogin();
requireRole('admin');

$user    = currentUser();
$db      = getDB();
$errors  = [];
$success = [];

// ── Subscription notification helper (local to this page) ─────
function notifyCaregiver(int $caregiverId, string $message): void {
    getDB()->prepare(
        'INSERT INTO notifications
            (incident_id, recipient_user_id, message_text, notification_type)
         VALUES (NULL, ?, ?, "admin_action")'
    )->execute([$caregiverId, $message]);
}

// ── Handle POST actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        $errors[] = 'Invalid form token.';
    } else {
        $action   = $_POST['action']        ?? '';
        $targetId = (int)($_POST['target_user_id'] ?? 0);

        // Verify target is a caregiver before any action
        $caregiverCheck = null;
        if ($targetId) {
            $stmt = $db->prepare('SELECT user_id, full_name, email, plan FROM users WHERE user_id = ? AND role = "caregiver" LIMIT 1');
            $stmt->execute([$targetId]);
            $caregiverCheck = $stmt->fetch();
        }

        if ($targetId && !$caregiverCheck && $action !== '') {
            $errors[] = 'Caregiver not found.';
        }

        // ── Upgrade to premium ────────────────────────────────
        if ($action === 'upgrade' && $caregiverCheck) {
            $expiresInput = trim($_POST['plan_expires'] ?? '');
            $expiresAt    = $expiresInput
                ? date('Y-m-d H:i:s', strtotime($expiresInput))
                : date('Y-m-d H:i:s', strtotime('+30 days'));

            if (adminSetPlan($targetId, 'premium', $expiresAt)) {
                $expFmt = date('M j, Y', strtotime($expiresAt));
                notifyCaregiver($targetId,
                    "Your account has been upgraded to Premium by an admin. " .
                    "Your premium access is active until {$expFmt}. " .
                    "You can now link unlimited elder accounts."
                );
                $success[] = "Upgraded {$caregiverCheck['full_name']} to Premium until {$expFmt}.";
            } else {
                $errors[] = 'Upgrade failed. Please try again.';
            }
        }

        // ── Downgrade to free ─────────────────────────────────
        if ($action === 'downgrade' && $caregiverCheck) {
            if (adminSetPlan($targetId, 'free')) {
                notifyCaregiver($targetId,
                    "Your subscription has been changed to the Free plan by an admin. " .
                    "You can link up to " . FREE_LINK_LIMIT . " elder accounts on the free plan."
                );
                $success[] = "Downgraded {$caregiverCheck['full_name']} to Free plan.";
            } else {
                $errors[] = 'Downgrade failed. Please try again.';
            }
        }

        // ── Pause subscription ────────────────────────────────
        if ($action === 'pause' && $caregiverCheck) {
            if (setPlanPaused($targetId, true)) {
                notifyCaregiver($targetId,
                    "Your Premium subscription has been temporarily paused by an admin. " .
                    "Your account is currently limited to the Free plan features. " .
                    "Please contact support if you believe this is an error."
                );
                $success[] = "Paused premium subscription for {$caregiverCheck['full_name']}.";
            } else {
                $errors[] = 'Pause failed. Please try again.';
            }
        }

        // ── Unpause subscription ──────────────────────────────
        if ($action === 'unpause' && $caregiverCheck) {
            if (setPlanPaused($targetId, false)) {
                notifyCaregiver($targetId,
                    "Your Premium subscription has been reactivated by an admin. " .
                    "Your full premium access has been restored. Welcome back!"
                );
                $success[] = "Unpaused premium subscription for {$caregiverCheck['full_name']}.";
            } else {
                $errors[] = 'Unpause failed. Please try again.';
            }
        }

        // ── Edit expiry date ──────────────────────────────────
        if ($action === 'edit_expiry' && $caregiverCheck) {
            $newExpiry = trim($_POST['plan_expires'] ?? '');
            if (!$newExpiry) {
                $errors[] = 'Please enter a valid expiry date.';
            } else {
                $expiresAt = date('Y-m-d H:i:s', strtotime($newExpiry));
                $db->prepare('UPDATE users SET plan_expires = ? WHERE user_id = ?')
                   ->execute([$expiresAt, $targetId]);
                $expFmt = date('M j, Y', strtotime($expiresAt));
                notifyCaregiver($targetId,
                    "Your Premium subscription expiry date has been updated to {$expFmt} by an admin."
                );
                $success[] = "Updated expiry for {$caregiverCheck['full_name']} to {$expFmt}.";
            }
        }

        // ── Delete subscription entirely (downgrade + notify) ─
        if ($action === 'delete_subscription' && $caregiverCheck) {
            $db->prepare(
                'UPDATE users SET plan = "free", plan_expires = NULL, plan_paused = 0 WHERE user_id = ?'
            )->execute([$targetId]);
            notifyCaregiver($targetId,
                "Your Premium subscription has been removed by an admin. " .
                "Your account has been moved to the Free plan. " .
                "Contact support to reinstate your subscription."
            );
            $success[] = "Removed subscription for {$caregiverCheck['full_name']} — moved to Free.";
        }
    }
}

// ── Load data ─────────────────────────────────────────────────
$caregivers = getAllCaregiverSubscriptions();

// Split into groups for tabs
$premium  = array_filter($caregivers, fn($c) => $c['plan'] === 'premium' && !$c['plan_paused']);
$paused   = array_filter($caregivers, fn($c) => $c['plan'] === 'premium' &&  $c['plan_paused']);
$free     = array_filter($caregivers, fn($c) => $c['plan'] === 'free');

$activeTab = $_GET['tab'] ?? 'all';

$pageTitle = 'Subscription Management';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-container">
    <div class="page-header">
        <div>
            <h1>⭐ Subscription Management</h1>
            <p style="color:var(--color-muted);margin-top:.25rem;font-size:.95rem;">
                Manage caregiver subscription plans, pausing, and expiry dates.
            </p>
        </div>
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
            <span class="badge badge-success"><?= count($premium) ?> Premium</span>
            <span class="badge badge-warning"><?= count($paused) ?> Paused</span>
            <span class="badge badge-secondary"><?= count($free) ?> Free</span>
        </div>
    </div>

    <?php foreach ($errors  as $e): ?><div class="alert alert-danger"><?= e($e) ?></div><?php endforeach; ?>
    <?php foreach ($success as $s): ?><div class="alert alert-success"><?= e($s) ?></div><?php endforeach; ?>

    <!-- ── Tabs ───────────────────────────────────────────────── -->
    <div class="sub-tabs">
        <a href="?tab=all"     class="sub-tab <?= $activeTab === 'all'     ? 'sub-tab-active' : '' ?>">All (<?= count($caregivers) ?>)</a>
        <a href="?tab=premium" class="sub-tab <?= $activeTab === 'premium' ? 'sub-tab-active' : '' ?>">⭐ Premium (<?= count($premium) ?>)</a>
        <a href="?tab=paused"  class="sub-tab <?= $activeTab === 'paused'  ? 'sub-tab-active' : '' ?>">⏸ Paused (<?= count($paused) ?>)</a>
        <a href="?tab=free"    class="sub-tab <?= $activeTab === 'free'    ? 'sub-tab-active' : '' ?>">🔓 Free (<?= count($free) ?>)</a>
    </div>

    <!-- ── Caregiver subscription table ───────────────────────── -->
    <?php
    $display = match($activeTab) {
        'premium' => $premium,
        'paused'  => $paused,
        'free'    => $free,
        default   => $caregivers,
    };
    ?>

    <?php if (empty($display)): ?>
        <div class="empty-state"><p>No caregivers in this category.</p></div>
    <?php else: ?>
    <div class="card" style="padding:0;overflow:hidden;">
        <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Caregiver</th>
                    <th>Plan</th>
                    <th>Status</th>
                    <th>Expires</th>
                    <th>Elders Linked</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($display as $cg): ?>
                <?php
                $isPremium = $cg['plan'] === 'premium';
                $isPaused  = (bool)$cg['plan_paused'];
                $isExpired = $isPremium && $cg['plan_expires']
                             && strtotime($cg['plan_expires']) < time();
                $rowClass  = $isPaused ? 'row-warning' : ($isExpired ? 'row-danger' : '');
                ?>
                <tr class="<?= $rowClass ?>">
                    <td>
                        <strong><?= e($cg['full_name']) ?></strong><br>
                        <small style="color:var(--color-muted)"><?= e($cg['email']) ?></small>
                    </td>
                    <td>
                        <?php if ($isPremium && !$isPaused && !$isExpired): ?>
                            <span class="plan-badge plan-premium">⭐ Premium</span>
                        <?php elseif ($isPaused): ?>
                            <span class="plan-badge" style="background:#fef3c7;color:#92400e;">⏸ Paused</span>
                        <?php elseif ($isExpired): ?>
                            <span class="plan-badge" style="background:#fee2e2;color:#991b1b;">⚠️ Expired</span>
                        <?php else: ?>
                            <span class="plan-badge plan-free">🔓 Free</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="status-badge <?= $cg['is_active'] ? 'status-active' : 'status-dismissed' ?>">
                            <?= $cg['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td style="font-size:.875rem;">
                        <?php if ($cg['plan_expires']): ?>
                            <?= e(date('M j, Y', strtotime($cg['plan_expires']))) ?>
                            <?php if ($isExpired): ?>
                                <span style="color:var(--color-danger);font-weight:700;"> (Expired)</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--color-muted)">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;"><?= (int)$cg['link_count'] ?></td>
                    <td>
                        <div style="display:flex;gap:.4rem;flex-wrap:wrap;">

                            <!-- Edit button opens modal -->
                            <button class="btn btn-sm btn-secondary"
                                    onclick="openModal(
                                        <?= (int)$cg['user_id'] ?>,
                                        <?= htmlspecialchars(json_encode($cg['full_name']), ENT_QUOTES) ?>,
                                        '<?= e($cg['plan']) ?>',
                                        <?= $cg['plan_paused'] ? 'true' : 'false' ?>,
                                        '<?= $cg['plan_expires'] ? e(date('Y-m-d', strtotime($cg['plan_expires']))) : '' ?>'
                                    )">
                                ✏️ Edit
                            </button>

                            <!-- Quick pause/unpause -->
                            <?php if ($isPremium && !$isPaused): ?>
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="pause">
                                <input type="hidden" name="target_user_id" value="<?= $cg['user_id'] ?>">
                                <button class="btn btn-sm btn-warning"
                                        onclick="return confirm('Pause premium for <?= e($cg['full_name']) ?>?')">
                                    ⏸ Pause
                                </button>
                            </form>
                            <?php elseif ($isPaused): ?>
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="unpause">
                                <input type="hidden" name="target_user_id" value="<?= $cg['user_id'] ?>">
                                <button class="btn btn-sm btn-success"
                                        onclick="return confirm('Restore premium for <?= e($cg['full_name']) ?>?')">
                                    ▶ Unpause
                                </button>
                            </form>
                            <?php endif; ?>

                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════
     EDIT MODAL
     ══════════════════════════════════════════════════════════ -->
<div id="subModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:300;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:10px;padding:2rem;width:100%;max-width:500px;margin:1rem;box-shadow:0 8px 32px rgba(0,0,0,.25);max-height:90vh;overflow-y:auto;">
        <h2 style="margin-bottom:1.5rem;">✏️ Edit Subscription — <span id="modalName"></span></h2>

        <form method="POST" id="modalForm">
            <?= csrfField() ?>
            <input type="hidden" name="target_user_id" id="modal_uid">

            <!-- Plan selector -->
            <div class="form-group">
                <label>Plan</label>
                <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:500;">
                        <input type="radio" name="modal_plan" value="free" id="r_free">
                        🔓 Free
                    </label>
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:500;">
                        <input type="radio" name="modal_plan" value="premium" id="r_premium">
                        ⭐ Premium
                    </label>
                </div>
            </div>

            <!-- Expiry date — shown only for premium -->
            <div class="form-group" id="expiryGroup">
                <label for="modal_expires">Expiry Date</label>
                <input type="date" id="modal_expires" name="plan_expires"
                       min="<?= date('Y-m-d') ?>">
                <small>Leave blank to auto-set 30 days from today.</small>
            </div>

            <!-- Action buttons -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-top:1.5rem;">
                <button type="button" class="btn btn-primary" id="btn_upgrade"
                        onclick="submitModal('upgrade')">
                    ⭐ Upgrade to Premium
                </button>
                <button type="button" class="btn btn-secondary" id="btn_downgrade"
                        onclick="submitModal('downgrade')">
                    🔓 Downgrade to Free
                </button>
                <button type="button" class="btn btn-warning" id="btn_pause"
                        onclick="submitModal('pause')">
                    ⏸ Pause Subscription
                </button>
                <button type="button" class="btn btn-success" id="btn_unpause"
                        onclick="submitModal('unpause')">
                    ▶ Unpause Subscription
                </button>
                <button type="button" class="btn btn-secondary" id="btn_expiry"
                        onclick="submitModal('edit_expiry')" style="grid-column:span 2;">
                    📅 Update Expiry Date Only
                </button>
                <button type="button" class="btn btn-danger" id="btn_delete"
                        onclick="submitModal('delete_subscription')"
                        style="grid-column:span 2;">
                    🗑 Remove Subscription (→ Free)
                </button>
            </div>

            <input type="hidden" name="action" id="modal_action">

            <button type="button" class="btn btn-outline btn-full"
                    style="margin-top:1rem;" onclick="closeModal()">
                Cancel
            </button>
        </form>
    </div>
</div>

<style>
/* ── Tabs ─────────────────────────────────────────── */
.sub-tabs {
    display: flex; gap: 0;
    border-bottom: 2px solid var(--color-border);
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
}
.sub-tab {
    padding: .6rem 1.25rem;
    font-weight: 600; font-size: .95rem;
    color: var(--color-muted);
    text-decoration: none;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    transition: color .15s, border-color .15s;
    white-space: nowrap;
}
.sub-tab:hover { color: var(--color-primary); text-decoration: none; }
.sub-tab-active { color: var(--color-primary); border-bottom-color: var(--color-primary); }

/* ── Row highlights ───────────────────────────────── */
.row-warning td { background: #fffbeb !important; }
</style>

<script>
let currentPlan   = 'free';
let currentPaused = false;

function openModal(uid, name, plan, paused, expires) {
    currentPlan   = plan;
    currentPaused = paused;

    document.getElementById('modal_uid').value  = uid;
    document.getElementById('modalName').textContent = name;
    document.getElementById('modal_expires').value   = expires || '';

    // Set radio
    document.getElementById('r_free').checked    = plan === 'free';
    document.getElementById('r_premium').checked = plan === 'premium';

    // Show/hide expiry based on plan
    updateExpiryVisibility();

    // Show/hide relevant action buttons
    const isPremium = plan === 'premium';
    document.getElementById('btn_upgrade').style.display   = !isPremium          ? '' : 'none';
    document.getElementById('btn_downgrade').style.display = isPremium            ? '' : 'none';
    document.getElementById('btn_pause').style.display     = isPremium && !paused ? '' : 'none';
    document.getElementById('btn_unpause').style.display   = isPremium && paused  ? '' : 'none';
    document.getElementById('btn_expiry').style.display    = isPremium            ? '' : 'none';
    document.getElementById('btn_delete').style.display    = isPremium            ? '' : 'none';

    document.getElementById('subModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('subModal').style.display = 'none';
    document.body.style.overflow = '';
}

function updateExpiryVisibility() {
    const isPremium = document.getElementById('r_premium').checked;
    document.getElementById('expiryGroup').style.display = isPremium ? '' : 'none';
}

document.querySelectorAll('[name="modal_plan"]').forEach(r => {
    r.addEventListener('change', updateExpiryVisibility);
});

function submitModal(action) {
    const confirmMessages = {
        downgrade:          'Downgrade this caregiver to Free? They will be notified.',
        pause:              'Pause this premium subscription? The caregiver will be notified.',
        delete_subscription:'Remove the subscription entirely? The caregiver will be moved to Free.',
    };
    if (confirmMessages[action]) {
        if (!confirm(confirmMessages[action])) return;
    }
    document.getElementById('modal_action').value = action;
    document.getElementById('modalForm').submit();
}

// Close on backdrop click
document.getElementById('subModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>