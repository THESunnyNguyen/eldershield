<?php
// pages/subscription.php — Caregiver subscription plans
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/subscription_helper.php';

requireLogin();
$user   = currentUser();
$errors = [];

// Elders don't need this page
if ($user['role'] === 'elder') {
    setFlash('info', 'Elder accounts are always free with no limitations.');
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

// FIX: use $currentPlan — never overwritten by POST data
$currentPlan = getUserPlan((int)$user['user_id']);
$linkCount   = caregiverLinkCount((int)$user['user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        $errors[] = 'Invalid request. Please try again.';
    } else {
        // FIX: use separate $selectedPlan variable, never touch $currentPlan
        $selectedPlan = trim($_POST['plan'] ?? '');
        if (!in_array($selectedPlan, ['free', 'premium'], true)) {
            $errors[] = 'Invalid plan selected.';
        }

        if (empty($errors) && $selectedPlan === 'premium') {
            $cardName   = trim($_POST['card_name']   ?? '');
            $cardNumber = preg_replace('/\D/', '', $_POST['card_number'] ?? '');
            $cardExpiry = trim($_POST['card_expiry'] ?? '');
            $cardCvc    = trim($_POST['card_cvc']    ?? '');
            $cvcInt     = filter_var($cardCvc, FILTER_VALIDATE_INT,
                            ['options' => ['min_range' => 100, 'max_range' => 9999]]);

            if (empty($cardName) || strlen($cardName) > 100)
                $errors[] = 'Cardholder name is required.';
            if (strlen($cardNumber) < 13 || strlen($cardNumber) > 19)
                $errors[] = 'Enter a valid card number.';
            if (!preg_match('/^\d{2}\/\d{2}$/', $cardExpiry))
                $errors[] = 'Expiry must be MM/YY.';
            if ($cvcInt === false)
                $errors[] = 'CVC must be 3–4 digits.';
        }

        if (empty($errors)) {
            if (setUserPlan((int)$user['user_id'], $selectedPlan)) {
                setFlash('success', $selectedPlan === 'premium'
                    ? '⭐ Welcome to Premium! You can now link unlimited elder accounts.'
                    : 'Switched to Free plan.');
                header('Location: ' . APP_URL . '/pages/subscription.php');
                exit;
            }
            $errors[] = 'Could not update your plan. Please try again.';
        }
    }
}

$pageTitle = 'Subscription Plans';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-container">
    <div class="page-header">
        <h1>⭐ Subscription Plans</h1>
        <span>Current plan:
            <span class="plan-badge plan-<?= e($currentPlan) ?>">
                <?= $currentPlan === 'premium' ? '⭐ Premium' : '🔓 Free' ?>
            </span>
        </span>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($user['role'] === 'admin'): ?>
    <div class="alert alert-info">
        <strong>Admin view:</strong> Elder accounts are always free with unlimited scam reports.
        Subscription plans apply to caregiver accounts only.
    </div>
    <?php endif; ?>

    <?php if ($user['role'] === 'caregiver'): ?>
    <div class="card" style="margin-bottom:1.5rem; background:#f0f9ff; border-color:#bae6fd;">
        <h3 style="margin-bottom:.5rem;">📊 Your Current Usage</h3>
        <p>
            You are currently linked to <strong><?= $linkCount ?> elder<?= $linkCount !== 1 ? 's' : '' ?></strong>.
            <?php if ($currentPlan === 'free'): ?>
                Your free plan includes up to <strong><?= FREE_LINK_LIMIT ?></strong> linked elders.
                <?php if ($linkCount >= FREE_LINK_LIMIT): ?>
                    <span style="color:var(--color-danger);font-weight:700;">⚠️ Limit reached — upgrade to add more.</span>
                <?php else: ?>
                    You can add <?= FREE_LINK_LIMIT - $linkCount ?> more on your current plan.
                <?php endif; ?>
            <?php else: ?>
                Your Premium plan gives you <strong>unlimited</strong> linked elders.
            <?php endif; ?>
        </p>
    </div>
    <?php endif; ?>

    <div class="plan-grid">

        <!-- Free Plan -->
        <div class="card plan-card <?= $currentPlan === 'free' ? 'plan-card-active' : '' ?>">
            <h2>🔓 Free</h2>
            <p class="plan-price">$0 <span class="plan-period">/month</span></p>
            <ul class="plan-features">
                <li>✅ Up to <?= FREE_LINK_LIMIT ?> linked elder accounts</li>
                <li>✅ Incident monitoring dashboard</li>
                <li>✅ High-risk scam alerts</li>
                <li>❌ More than <?= FREE_LINK_LIMIT ?> elders</li>
            </ul>
            <?php if ($currentPlan !== 'free'): ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="plan" value="free">
                    <button class="btn btn-secondary btn-full"
                            onclick="return confirm('Downgrade to Free? You will be limited to <?= FREE_LINK_LIMIT ?> linked elders.')">
                        Switch to Free
                    </button>
                </form>
            <?php else: ?>
                <button class="btn btn-primary btn-full" disabled>✓ Current Plan</button>
            <?php endif; ?>
        </div>

        <!-- Premium Plan -->
        <div class="card plan-card <?= $currentPlan === 'premium' ? 'plan-card-active' : '' ?>"
             style="position:relative;">
            <?php if ($currentPlan !== 'premium'): ?>
                <div style="position:absolute;top:-14px;left:50%;transform:translateX(-50%);
                            background:var(--color-primary);color:#fff;padding:.25rem 1.25rem;
                            border-radius:999px;font-size:.8rem;font-weight:700;white-space:nowrap;">
                    ⭐ Recommended for Nursing Homes
                </div>
            <?php endif; ?>
            <h2>⭐ Premium</h2>
            <p class="plan-price">$9.99 <span class="plan-period">/month</span></p>
            <ul class="plan-features">
                <li>✅ <strong>Unlimited</strong> linked elder accounts</li>
                <li>✅ Incident monitoring dashboard</li>
                <li>✅ High-risk scam alerts</li>
                <li>✅ Perfect for nursing home staff</li>
            </ul>
            <?php if ($currentPlan !== 'premium'): ?>
                <button class="btn btn-primary btn-full" id="showPaymentBtn"
                        onclick="showPaymentForm()">
                    Upgrade to Premium — $9.99/mo
                </button>
            <?php else: ?>
                <button class="btn btn-success btn-full" disabled>✓ Current Plan</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment form — always rendered when not premium, shown/hidden via JS -->
    <?php if ($currentPlan !== 'premium'): ?>
    <div class="card" id="paymentForm"
         style="max-width:480px; margin-top:1.5rem; display:none;">
        <h2>Payment Details
            <small style="font-size:.9rem; color:var(--color-muted);">(demo only)</small>
        </h2>
        <form method="POST" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="plan" value="premium">

            <div class="form-group">
                <label for="card_name">Cardholder Name</label>
                <input type="text" id="card_name" name="card_name"
                       maxlength="100" autocomplete="cc-name"
                       placeholder="Jane Smith" required>
            </div>

            <div class="form-group">
                <label for="card_number">Card Number</label>
                <input type="text" id="card_number" name="card_number"
                       maxlength="19" inputmode="numeric"
                       autocomplete="cc-number"
                       placeholder="•••• •••• •••• ••••" required>
            </div>

            <div class="form-inline">
                <div class="form-group" style="flex:1;">
                    <label for="card_expiry">Expiry (MM/YY)</label>
                    <input type="text" id="card_expiry" name="card_expiry"
                           maxlength="5" placeholder="MM/YY"
                           autocomplete="cc-exp" required>
                </div>
                <div class="form-group" style="flex:1;">
                    <label for="card_cvc">CVC</label>
                    <input type="text" id="card_cvc" name="card_cvc"
                           maxlength="4" inputmode="numeric"
                           autocomplete="cc-csc" placeholder="•••" required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-full">
                Pay $9.99 &amp; Activate Premium
            </button>
            <button type="button" class="btn btn-outline btn-full"
                    style="margin-top:.5rem;" onclick="hidePaymentForm()">
                Cancel
            </button>
        </form>
    </div>
    <?php endif; ?>

    <div class="card" style="margin-top:1.5rem; background:#f0fdf4; border-color:#86efac;">
        <h3>👴 What about Elder accounts?</h3>
        <p style="margin-top:.5rem; color:var(--color-muted);">
            Elder accounts are <strong>always completely free</strong> with no submission limits —
            because protecting seniors should never come with a price tag.
            This subscription is only for caregiver accounts that need to monitor
            more than <?= FREE_LINK_LIMIT ?> elders.
        </p>
    </div>
</div>

<script>
// FIX: use display:none/block instead of classList.toggle('hidden')
// so it works regardless of CSS load order or class conflicts.
function showPaymentForm() {
    const form = document.getElementById('paymentForm');
    const btn  = document.getElementById('showPaymentBtn');
    if (form) form.style.display = 'block';
    if (btn)  btn.style.display  = 'none';
    // Scroll to form smoothly
    if (form) form.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function hidePaymentForm() {
    const form = document.getElementById('paymentForm');
    const btn  = document.getElementById('showPaymentBtn');
    if (form) form.style.display = 'none';
    if (btn)  btn.style.display  = '';
}

// Auto-format card number with spaces as user types
document.getElementById('card_number')?.addEventListener('input', function() {
    let v = this.value.replace(/\D/g, '').substring(0, 16);
    this.value = v.match(/.{1,4}/g)?.join(' ') || v;
});

// Auto-format expiry MM/YY
document.getElementById('card_expiry')?.addEventListener('input', function() {
    let v = this.value.replace(/\D/g, '').substring(0, 4);
    if (v.length >= 3) v = v.substring(0,2) + '/' + v.substring(2);
    this.value = v;
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>