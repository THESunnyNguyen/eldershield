<?php
// ============================================================
// pages/subscription.php — Billing page
//
// ELDER view:   "Your account is free. No charges ever."
// CAREGIVER view: current bill preview + save payment method
//                 + failed invoice warning + retry payment
// ADMIN view:   redirected (admins use admin_users.php)
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/billing_helper.php';

requireLogin();
$user   = currentUser();
$flash  = getFlash();
$errors = [];

// Admins have no billing page
if ($user['role'] === 'admin') {
    header('Location: ' . APP_URL . '/pages/admin_users.php');
    exit;
}

// ── POST handlers (caregiver only) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user['role'] === 'caregiver') {

    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        $errors[] = 'Invalid request. Please try again.';
    } else {

        $action = $_POST['action'] ?? '';

        // ── Save / update payment method ──────────────────────
        if ($action === 'save_payment_method') {
            $cardName   = trim($_POST['card_name']   ?? '');
            $cardNumber = preg_replace('/\D/', '', $_POST['card_number'] ?? '');
            $cardExpiry = trim($_POST['card_expiry'] ?? '');
            $cardCvc    = trim($_POST['card_cvc']    ?? '');

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

            if (empty($errors)) {
                // Store ONLY last 4 digits — never the full number
                $last4 = substr($cardNumber, -4);
                if (savePaymentMethod((int)$user['user_id'], $last4, $cardExpiry, $cardName)) {
                    setFlash('success', 'Payment method saved successfully.');
                } else {
                    $errors[] = 'Could not save payment method. Please try again.';
                }
                if (empty($errors)) {
                    header('Location: ' . APP_URL . '/pages/subscription.php');
                    exit;
                }
            }
        }

        // ── Retry failed invoice ───────────────────────────────
        if ($action === 'retry_payment') {
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            if (!$invoiceId) {
                $errors[] = 'Invalid invoice.';
            } else {
                $result = retryInvoicePayment($invoiceId, (int)$user['user_id']);
                if ($result['success']) {
                    setFlash('success', $result['message']);
                } else {
                    $errors[] = $result['message'];
                }
                if (empty($errors)) {
                    header('Location: ' . APP_URL . '/pages/subscription.php');
                    exit;
                }
            }
        }
    }
}

// ── Data for caregiver view ───────────────────────────────────
$paymentMethod  = null;
$currentBill    = null;
$failedInvoice  = null;

if ($user['role'] === 'caregiver') {
    $paymentMethod = getPaymentMethod((int)$user['user_id']);
    $currentBill   = previewCurrentBill((int)$user['user_id']);
    $failedInvoice = getFailedInvoice((int)$user['user_id']);
}

$pageTitle = 'Billing';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-container">
    <div class="page-header">
        <h1>💳 Billing</h1>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>">
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($user['role'] === 'elder'): ?>
    <!-- ── ELDER: free forever ─────────────────────────────── -->
    <div class="card billing-free-card">
        <div class="billing-free-icon">🎉</div>
        <h2>Your account is completely free</h2>
        <p>ElderShield is free for all elder accounts — no charges, no limits, no credit card required.</p>
        <p>You can submit as many scam reports as you need, and your caregivers will always be notified.</p>
    </div>

    <?php elseif ($user['role'] === 'caregiver'): ?>

    <!-- ── FAILED INVOICE WARNING BANNER ───────────────────── -->
    <?php if ($failedInvoice): ?>
    <div class="alert alert-danger billing-failed-banner">
        <strong>⚠️ Payment Failed</strong> — Your invoice of
        <strong><?= e(formatCents($failedInvoice['amount_cents'])) ?></strong>
        for <?= e(date('F Y', strtotime($failedInvoice['billing_month']))) ?>
        could not be processed.
        <?php if (!$paymentMethod): ?>
            Please add a payment method below, then retry.
        <?php else: ?>
            Please retry the payment below.
        <?php endif; ?>
        <form method="POST" style="display:inline; margin-left:1rem;">
            <?= csrfField() ?>
            <input type="hidden" name="action"     value="retry_payment">
            <input type="hidden" name="invoice_id" value="<?= (int)$failedInvoice['invoice_id'] ?>">
            <button class="btn btn-sm btn-danger"
                    <?= !$paymentMethod ? 'disabled' : '' ?>>
                Retry Payment
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- ── CURRENT BILL PREVIEW ────────────────────────────── -->
    <div class="card">
        <h2>Current Month Preview — <?= e(date('F Y')) ?></h2>

        <?php if ($currentBill['elder_count'] === 0): ?>
            <p class="text-muted">No linked elders yet. You will not be charged this month.</p>
        <?php else: ?>
            <table class="data-table billing-line-table">
                <thead>
                    <tr>
                        <th>Elder</th>
                        <th>Linked</th>
                        <th>Unlinked</th>
                        <th>Days Active</th>
                        <th>Days in Month</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($currentBill['line_items'] as $line): ?>
                    <tr>
                        <td><?= e($line['elder_name']) ?></td>
                        <td><?= e(date('M j', strtotime($line['linked_at']))) ?></td>
                        <td><?= $line['unlinked_at'] ? e(date('M j', strtotime($line['unlinked_at']))) : '<span class="text-muted">Active</span>' ?></td>
                        <td><?= (int)$line['days_active'] ?></td>
                        <td><?= (int)$line['days_in_month'] ?></td>
                        <td><?= e(formatCents($line['line_amount_cents'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="billing-total-row">
                        <td colspan="5"><strong>Estimated Total</strong>
                            <small class="text-muted"> — billed automatically on the 1st</small>
                        </td>
                        <td><strong><?= e(formatCents($currentBill['amount_cents'])) ?></strong></td>
                    </tr>
                </tfoot>
            </table>
            <p class="billing-rate-note">Rate: $0.99 per elder per month, prorated daily.</p>
        <?php endif; ?>
    </div>

    <!-- ── PAYMENT METHOD ──────────────────────────────────── -->
    <div class="card">
        <h2>Payment Method</h2>

        <?php if ($paymentMethod): ?>
            <div class="billing-saved-card">
                <span class="billing-card-icon">💳</span>
                <div>
                    <strong><?= e($paymentMethod['cardholder_name']) ?></strong><br>
                    <span class="text-muted">•••• •••• •••• <?= e($paymentMethod['card_last4']) ?>
                        &nbsp;·&nbsp; Expires <?= e($paymentMethod['card_expiry']) ?>
                    </span>
                </div>
                <button class="btn btn-sm btn-secondary" id="showUpdateCardBtn"
                        onclick="document.getElementById('updateCardForm').classList.toggle('hidden'); this.classList.add('hidden');">
                    Update Card
                </button>
            </div>
        <?php else: ?>
            <p class="text-muted">No payment method saved. Add one below to enable automatic billing.</p>
        <?php endif; ?>

        <!-- Add / Update card form -->
        <div id="updateCardForm" class="<?= $paymentMethod ? 'hidden' : '' ?>" style="margin-top:1.25rem; max-width:480px;">
            <form method="POST" novalidate>
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_payment_method">

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
                    💾 Save Payment Method
                </button>
                <p><small class="text-muted">Your full card number is never stored. Only the last 4 digits are saved for display.</small></p>
            </form>
        </div>
    </div>

    <!-- ── INVOICE HISTORY LINK ────────────────────────────── -->
    <div class="card">
        <h2>Invoice History</h2>
        <p>View all past invoices, payment status, and line-item breakdowns.</p>
        <a href="<?= e(APP_URL) ?>/pages/invoice_history.php" class="btn btn-secondary">
            📄 View Invoice History
        </a>
    </div>

    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
