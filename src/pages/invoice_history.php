<?php
// ============================================================
// pages/invoice_history.php — Caregiver invoice history
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/billing_helper.php';

requireLogin();
requireRole('caregiver');

$user     = currentUser();
$flash    = getFlash();
$invoices = getInvoicesForCaregiver((int)$user['user_id']);

// Optional: drill into a specific invoice's line items
// SECURITY: always verify invoice belongs to this caregiver
$selectedInvoice   = null;
$selectedLineItems = [];

if (isset($_GET['invoice_id'])) {
    $invoiceId = (int)$_GET['invoice_id'];
    if ($invoiceId && invoiceBelongsToCaregiver($invoiceId, (int)$user['user_id'])) {
        foreach ($invoices as $inv) {
            if ((int)$inv['invoice_id'] === $invoiceId) {
                $selectedInvoice   = $inv;
                $selectedLineItems = getInvoiceLineItems($invoiceId);
                break;
            }
        }
    }
}

$pageTitle = 'Invoice History';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-container">
    <div class="page-header">
        <h1>📄 Invoice History</h1>
        <a href="<?= e(APP_URL) ?>/pages/subscription.php" class="btn btn-secondary">
            ← Back to Billing
        </a>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>">
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($invoices)): ?>
        <div class="empty-state">
            <h2>No invoices yet</h2>
            <p>Invoices are generated automatically on the 1st of each month based on your linked elders.</p>
            <a href="<?= e(APP_URL) ?>/pages/subscription.php" class="btn btn-primary">
                View Billing Details
            </a>
        </div>

    <?php else: ?>

    <!-- ── INVOICE LIST ─────────────────────────────────────── -->
    <div class="card">
        <h2>All Invoices</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Elders</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Paid</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($invoices as $inv): ?>
                <tr class="<?= $inv['status'] === 'failed' ? 'row-danger' : '' ?>">
                    <td><?= e(date('F Y', strtotime($inv['billing_month']))) ?></td>
                    <td><?= (int)$inv['elder_count'] ?></td>
                    <td><?= e(formatCents($inv['amount_cents'])) ?></td>
                    <td>
                        <span class="invoice-status invoice-<?= e($inv['status']) ?>">
                            <?php if ($inv['status'] === 'paid'):     echo '✅ Paid';
                            elseif ($inv['status'] === 'failed'): echo '❌ Failed';
                            else:                                  echo '⏳ Pending'; endif; ?>
                        </span>
                    </td>
                    <td>
                        <?= $inv['paid_at']
                            ? e(date('M j, Y', strtotime($inv['paid_at'])))
                            : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td>
                        <a href="?invoice_id=<?= (int)$inv['invoice_id'] ?>"
                           class="btn btn-sm btn-secondary">
                            View Details
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ── INVOICE DETAIL (line items) ──────────────────────── -->
    <?php if ($selectedInvoice): ?>
    <div class="card">
        <div class="page-header" style="margin-bottom:1rem;">
            <h2>
                Invoice — <?= e(date('F Y', strtotime($selectedInvoice['billing_month']))) ?>
            </h2>
            <span class="invoice-status invoice-<?= e($selectedInvoice['status']) ?>">
                <?php if ($selectedInvoice['status'] === 'paid'):     echo '✅ Paid';
                elseif ($selectedInvoice['status'] === 'failed'): echo '❌ Failed';
                else:                                              echo '⏳ Pending'; endif; ?>
            </span>
        </div>

        <?php if ($selectedInvoice['status'] === 'failed'): ?>
        <div class="alert alert-danger">
            <strong>Payment failed:</strong>
            <?= e($selectedInvoice['failure_reason'] ?? 'Unknown error') ?>.
            <a href="<?= e(APP_URL) ?>/pages/subscription.php" class="btn btn-sm btn-danger" style="margin-left:.5rem;">
                Retry Payment
            </a>
        </div>
        <?php endif; ?>

        <?php if (!empty($selectedLineItems)): ?>
        <table class="data-table billing-line-table">
            <thead>
                <tr>
                    <th>Elder</th>
                    <th>Linked On</th>
                    <th>Unlinked On</th>
                    <th>Days Active</th>
                    <th>Days in Month</th>
                    <th>Rate</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($selectedLineItems as $line): ?>
                <tr>
                    <td><?= e($line['elder_name']) ?></td>
                    <td><?= e(date('M j, Y', strtotime($line['linked_at']))) ?></td>
                    <td><?= $line['unlinked_at']
                        ? e(date('M j, Y', strtotime($line['unlinked_at'])))
                        : '<span class="text-muted">Full month</span>' ?></td>
                    <td><?= (int)$line['days_active'] ?> / <?= (int)$line['days_in_month'] ?></td>
                    <td><?= (int)$line['days_in_month'] ?></td>
                    <td><?= e(formatCents($line['unit_cents'])) ?>/mo</td>
                    <td><?= e(formatCents($line['line_amount_cents'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="billing-total-row">
                    <td colspan="6"><strong>Total</strong></td>
                    <td><strong><?= e(formatCents($selectedInvoice['amount_cents'])) ?></strong></td>
                </tr>
            </tfoot>
        </table>
        <?php endif; ?>

        <?php if ($selectedInvoice['payment_method']): ?>
        <p class="billing-rate-note">
            Charged to card <?= e($selectedInvoice['payment_method']) ?>
            <?= $selectedInvoice['paid_at']
                ? 'on ' . e(date('F j, Y g:i A', strtotime($selectedInvoice['paid_at'])))
                : '' ?>
        </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
