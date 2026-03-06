<?php
// ============================================================
// pages/subscription.php — View plans & subscribe
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/subscription_helper.php';

requireLogin();
$user   = currentUser();
$sub    = getUserSubscription($user['user_id']);
$flash  = getFlash();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        // Whitelist plan selection
        $plan = trim($_POST['plan'] ?? '');
        if (!in_array($plan, ['free', 'premium'], true)) {
            $errors[] = 'Invalid plan selected.';
        }

        // Simulated payment validation for premium
        if (empty($errors) && $plan === 'premium') {
            $cardName   = trim($_POST['card_name']   ?? '');
            $cardNumber = preg_replace('/\D/', '', $_POST['card_number'] ?? '');
            $cardExpiry = trim($_POST['card_expiry'] ?? '');
            $cardCvc    = trim($_POST['card_cvc']    ?? '');

            if (empty($cardName) || strlen($cardName) > 100)
                $errors[] = 'Cardholder name is required.';
            if (strlen($cardNumber) < 13 || strlen($cardNumber) > 19)
                $errors[] = 'Enter a valid card number.';
            if (!preg_match('/^\d{2}\/\d{2}$/', $cardExpiry))
                $errors[] = 'Expiry must be MM/YY.';
            if (!preg_match('/^\d{3,4}$/', $cardCvc))
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

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container py-5">
    <h2 class="mb-4">Subscription Plans</h2>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>">
            <?= htmlspecialchars($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $e): ?>
                <div><?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p class="text-muted mb-4">
        Current plan: <strong><?= htmlspecialchars(ucfirst($sub['plan_name'])) ?></strong>
    </p>

    <div class="row g-4 mb-5">
        <!-- Free Plan -->
        <div class="col-md-5">
            <div class="card h-100 <?= $sub['plan_name'] === 'free' ? 'border-primary' : '' ?>">
                <div class="card-body">
                    <h4 class="card-title">Free</h4>
                    <p class="display-6">$0<small class="fs-6 text-muted">/mo</small></p>
                    <ul class="list-unstyled">
                        <li>✅ Up to 5 incidents/month</li>
                        <li>✅ AI risk analysis</li>
                        <li>❌ Caregiver notifications</li>
                    </ul>
                    <?php if ($sub['plan_name'] !== 'free'): ?>
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="plan" value="free">
                            <button class="btn btn-outline-secondary w-100"
                                    onclick="return confirm('Downgrade to Free?')">
                                Switch to Free
                            </button>
                        </form>
                    <?php else: ?>
                        <button class="btn btn-primary w-100" disabled>Current Plan</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Premium Plan -->
        <div class="col-md-5">
            <div class="card h-100 <?= $sub['plan_name'] === 'premium' ? 'border-primary' : '' ?>">
                <div class="card-body">
                    <h4 class="card-title">Premium</h4>
                    <p class="display-6">$9.99<small class="fs-6 text-muted">/mo</small></p>
                    <ul class="list-unstyled">
                        <li>✅ Unlimited incidents</li>
                        <li>✅ AI risk analysis</li>
                        <li>✅ Caregiver notifications</li>
                    </ul>
                    <?php if ($sub['plan_name'] !== 'premium'): ?>
                        <button class="btn btn-primary w-100" data-bs-toggle="collapse"
                                data-bs-target="#paymentForm">
                            Upgrade to Premium
                        </button>
                    <?php else: ?>
                        <button class="btn btn-success w-100" disabled>Current Plan ✓</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Simulated payment form (collapsed by default) -->
    <?php if ($sub['plan_name'] !== 'premium'): ?>
    <div class="collapse" id="paymentForm">
        <div class="card card-body" style="max-width:480px;">
            <h5 class="mb-3">Payment Details <small class="text-muted fs-6">(demo only)</small></h5>
            <form method="POST" novalidate>
                <?= csrfField() ?>
                <input type="hidden" name="plan" value="premium">

                <div class="mb-3">
                    <label class="form-label">Cardholder Name</label>
                    <input type="text" name="card_name" class="form-control"
                           maxlength="100" autocomplete="cc-name" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Card Number</label>
                    <input type="text" name="card_number" class="form-control"
                           maxlength="19" inputmode="numeric"
                           autocomplete="cc-number" placeholder="•••• •••• •••• ••••" required>
                </div>
                <div class="row">
                    <div class="col mb-3">
                        <label class="form-label">Expiry (MM/YY)</label>
                        <input type="text" name="card_expiry" class="form-control"
                               maxlength="5" placeholder="MM/YY"
                               autocomplete="cc-exp" required>
                    </div>
                    <div class="col mb-3">
                        <label class="form-label">CVC</label>
                        <input type="text" name="card_cvc" class="form-control"
                               maxlength="4" inputmode="numeric"
                               autocomplete="cc-csc" placeholder="•••" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100">
                    Pay $9.99 &amp; Activate Premium
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
