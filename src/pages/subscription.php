<?php
// ============================================================
// pages/subscription.php — View plans & subscribe
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/subscription_helper.php';

requireLogin();
$user   = currentUser();
$sub    = getUserSubscription($user['user_id']);
$flash  = getFlash();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF failure → 403 immediately (OWASP best practice)
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        $errors[] = 'Invalid request. Please try again.';
    } else {
        // Whitelist plan — never trust raw POST value
        $plan = trim($_POST['plan'] ?? '');
        if (!in_array($plan, ['free', 'premium'], true)) {
            $errors[] = 'Invalid plan selected.';
        }

        // Simulated payment validation for premium
        if (empty($errors) && $plan === 'premium') {
            $cardName   = trim($_POST['card_name'] ?? '');
            // Strip all non-digits from card number before length check
            $cardNumber = preg_replace('/\D/', '', $_POST['card_number'] ?? '');
            $cardExpiry = trim($_POST['card_expiry'] ?? '');
            $cardCvc    = trim($_POST['card_cvc'] ?? '');

            // filter_var for integer-range checks (OWASP C3)
            $cvcInt = filter_var($cardCvc, FILTER_VALIDATE_INT,
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
            if (setUserPlan($user['user_id'], $plan)) {
                setFlash('success', $plan === 'premium'
                    ? 'Welcome to Premium! Your subscription is now active.'
                    : 'You have switched to the Free plan.');
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
        <h1>Subscription Plans</h1>
        <span>Current plan:
            <span class="plan-badge plan-<?= e($sub['plan_name']) ?>">
                <?= $sub['plan_name'] === 'premium' ? '⭐ Premium' : '🔓 Free' ?>
            </span>
        </span>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>">
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $err): ?>
                <div><?= e($err) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Plan cards -->
    <div class="plan-grid">

        <!-- Free Plan -->
        <div class="card plan-card <?= $sub['plan_name'] === 'free' ? 'plan-card-active' : '' ?>">
            <h2>🔓 Free</h2>
            <p class="plan-price">$0 <span class="plan-period">/month</span></p>
            <ul class="plan-features">
                <li>✅ Up to <?= (int)FREE_INCIDENT_LIMIT ?> incident reports/month</li>
                <li>✅ AI risk analysis</li>
                <li>❌ Caregiver notifications</li>
            </ul>
            <?php if ($sub['plan_name'] !== 'free'): ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="plan" value="free">
                    <button class="btn btn-secondary btn-full"
                            onclick="return confirm('Switch to Free plan? Caregiver notifications will be disabled.')">
                        Switch to Free
                    </button>
                </form>
            <?php else: ?>
                <button class="btn btn-primary btn-full" disabled>✓ Current Plan</button>
            <?php endif; ?>
        </div>

        <!-- Premium Plan -->
        <div class="card plan-card <?= $sub['plan_name'] === 'premium' ? 'plan-card-active' : '' ?>">
            <h2>⭐ Premium</h2>
            <p class="plan-price">$9.99 <span class="plan-period">/month</span></p>
            <ul class="plan-features">
                <li>✅ Unlimited incident reports</li>
                <li>✅ AI risk analysis</li>
                <li>✅ Caregiver notifications</li>
            </ul>
            <?php if ($sub['plan_name'] !== 'premium'): ?>
                <!-- Toggle payment form visibility with plain JS — no Bootstrap needed -->
                <button class="btn btn-primary btn-full" id="showPaymentBtn"
                        onclick="document.getElementById('paymentForm').classList.toggle('hidden'); this.classList.add('hidden');">
                    Upgrade to Premium
                </button>
            <?php else: ?>
                <button class="btn btn-success btn-full" disabled>✓ Current Plan</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Simulated payment form (hidden by default, toggled via JS above) -->
    <?php if ($sub['plan_name'] !== 'premium'): ?>
    <div class="card hidden" id="paymentForm" style="max-width:480px; margin-top:1.5rem;">
        <h2>Payment Details <small style="font-size:.9rem; color:var(--color-muted);">(demo only)</small></h2>
        <form method="POST" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="plan" value="premium">

            <div class="form-group">
                <label for="card_name">Cardholder Name</label>
                <input type="text" id="card_name" name="card_name"
                       maxlength="100" autocomplete="cc-name" required>
            </div>
            <div class="form-group">
                <label for="card_number">Card Number</label>
                <input type="text" id="card_number" name="card_number"
                       maxlength="19" inputmode="numeric"
                       autocomplete="cc-number" placeholder="•••• •••• •••• ••••" required>
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
        </form>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
