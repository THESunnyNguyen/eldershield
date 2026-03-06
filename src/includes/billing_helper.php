<?php
// ============================================================
// includes/billing_helper.php — Caregiver billing logic
// ============================================================
// BILLING MODEL:
//   - Elders:     always free, no charges ever
//   - Caregivers: $0.99/month per active linked elder (prorated)
//   - Admins:     never charged
//
// PRORATION:
//   If a caregiver links an elder on the 20th of a 31-day month:
//     days_active = 12, charge = round(99 * 12/31) cents
//   If they unlink on the 15th, unlinked_at is recorded and the
//   next billing run calculates partial credit automatically.
//
// CURRENCY:
//   All amounts stored as integer cents to avoid float rounding.
//   Use formatCents() for display only.
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

const BILLING_RATE_CENTS = 99; // $0.99 per elder per month

// ════════════════════════════════════════════════════════════
// DISPLAY HELPERS
// ════════════════════════════════════════════════════════════

/**
 * Format integer cents as a dollar string: 99 → "$0.99"
 */
function formatCents(int $cents): string {
    return '$' . number_format($cents / 100, 2);
}

// ════════════════════════════════════════════════════════════
// PAYMENT METHOD
// ════════════════════════════════════════════════════════════

/**
 * Get saved payment method for a caregiver. Returns null if none saved.
 */
function getPaymentMethod(int $caregiverId): ?array {
    $stmt = getDB()->prepare(
        'SELECT * FROM caregiver_payment_methods WHERE caregiver_id = ? LIMIT 1'
    );
    $stmt->execute([$caregiverId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Save or update a caregiver's simulated payment method.
 * Stores ONLY last4 + expiry — never the full card number.
 */
function savePaymentMethod(int $caregiverId, string $cardLast4, string $cardExpiry, string $cardholderName): bool {
    $cardLast4       = substr(preg_replace('/\D/', '', $cardLast4), -4);
    $cardExpiry      = trim($cardExpiry);
    $cardholderName  = trim(strip_tags($cardholderName));

    if (strlen($cardLast4) !== 4)          return false;
    if (!preg_match('/^\d{2}\/\d{2}$/', $cardExpiry)) return false;
    if (empty($cardholderName) || strlen($cardholderName) > 100) return false;

    $stmt = getDB()->prepare(
        'INSERT INTO caregiver_payment_methods
            (caregiver_id, card_last4, card_expiry, cardholder_name)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            card_last4      = VALUES(card_last4),
            card_expiry     = VALUES(card_expiry),
            cardholder_name = VALUES(cardholder_name),
            updated_at      = NOW()'
    );
    return $stmt->execute([$caregiverId, $cardLast4, $cardExpiry, $cardholderName]);
}

// ════════════════════════════════════════════════════════════
// BILLING STATUS
// ════════════════════════════════════════════════════════════

/**
 * Check if a caregiver has an unpaid (failed) invoice.
 * Used to restrict access and show warning banners.
 */
function hasFailedInvoice(int $caregiverId): bool {
    $stmt = getDB()->prepare(
        "SELECT COUNT(*) FROM invoices
         WHERE caregiver_id = ? AND status = 'failed'"
    );
    $stmt->execute([$caregiverId]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Get the most recent failed invoice for display in the warning banner.
 */
function getFailedInvoice(int $caregiverId): ?array {
    $stmt = getDB()->prepare(
        "SELECT * FROM invoices
         WHERE caregiver_id = ? AND status = 'failed'
         ORDER BY billing_month DESC
         LIMIT 1"
    );
    $stmt->execute([$caregiverId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Calculate what a caregiver's current month bill would be,
 * based on their active links right now. Used for the billing preview.
 * Returns array: ['elder_count' => int, 'amount_cents' => int, 'line_items' => array]
 */
function previewCurrentBill(int $caregiverId): array {
    $db          = getDB();
    $today       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $monthStart  = new DateTimeImmutable($today->format('Y-m-01'), new DateTimeZone('UTC'));
    $daysInMonth = (int)$today->format('t');

    $stmt = $db->prepare(
        "SELECT al.link_id, al.linked_at, al.unlinked_at, al.status,
                u.full_name, u.user_id
         FROM account_links al
         JOIN users u ON al.elder_user_id = u.user_id
         WHERE al.caregiver_id = ?
           AND al.linked_at IS NOT NULL
           AND al.linked_at < ?
           AND (al.unlinked_at IS NULL OR al.unlinked_at >= ?)
         ORDER BY u.full_name"
    );
    $stmt->execute([
        $caregiverId,
        $today->format('Y-m-d H:i:s'),
        $monthStart->format('Y-m-d H:i:s'),
    ]);
    $links = $stmt->fetchAll();

    $lineItems   = [];
    $totalCents  = 0;

    foreach ($links as $link) {
        $linkedAt   = new DateTimeImmutable($link['linked_at'],   new DateTimeZone('UTC'));
        $unlinkedAt = $link['unlinked_at']
            ? new DateTimeImmutable($link['unlinked_at'], new DateTimeZone('UTC'))
            : null;

        // Clamp to current month boundaries
        $effectiveStart = max($linkedAt,   $monthStart);
        $effectiveEnd   = $unlinkedAt ? min($unlinkedAt, $today) : $today;

        $daysActive  = max(0, (int)$effectiveStart->diff($effectiveEnd)->days + 1);
        $lineCents   = (int)round(BILLING_RATE_CENTS * $daysActive / $daysInMonth);
        $totalCents += $lineCents;

        $lineItems[] = [
            'elder_id'          => $link['user_id'],
            'elder_name'        => $link['full_name'],
            'linked_at'         => $link['linked_at'],
            'unlinked_at'       => $link['unlinked_at'],
            'days_active'       => $daysActive,
            'days_in_month'     => $daysInMonth,
            'line_amount_cents' => $lineCents,
        ];
    }

    return [
        'elder_count'  => count($lineItems),
        'amount_cents' => $totalCents,
        'line_items'   => $lineItems,
    ];
}

// ════════════════════════════════════════════════════════════
// INVOICE HISTORY
// ════════════════════════════════════════════════════════════

/**
 * Get all invoices for a caregiver, newest first.
 * SECURITY: always scoped to $caregiverId from session — never from user input.
 */
function getInvoicesForCaregiver(int $caregiverId): array {
    $stmt = getDB()->prepare(
        'SELECT * FROM invoices
         WHERE caregiver_id = ?
         ORDER BY billing_month DESC'
    );
    $stmt->execute([$caregiverId]);
    return $stmt->fetchAll();
}

/**
 * Get line items for a specific invoice.
 * SECURITY: verify invoice belongs to $caregiverId before calling.
 */
function getInvoiceLineItems(int $invoiceId): array {
    $stmt = getDB()->prepare(
        'SELECT * FROM invoice_line_items WHERE invoice_id = ? ORDER BY elder_name'
    );
    $stmt->execute([$invoiceId]);
    return $stmt->fetchAll();
}

/**
 * Verify an invoice belongs to a caregiver. Use before any invoice action.
 */
function invoiceBelongsToCaregiver(int $invoiceId, int $caregiverId): bool {
    $stmt = getDB()->prepare(
        'SELECT COUNT(*) FROM invoices WHERE invoice_id = ? AND caregiver_id = ?'
    );
    $stmt->execute([$invoiceId, $caregiverId]);
    return (int)$stmt->fetchColumn() > 0;
}

// ════════════════════════════════════════════════════════════
// RETRY PAYMENT
// ════════════════════════════════════════════════════════════

/**
 * Simulate retrying a failed invoice payment.
 * In production this would call a real payment processor.
 * Here we always succeed (simulated) unless no payment method is saved.
 */
function retryInvoicePayment(int $invoiceId, int $caregiverId): array {
    // Security: ensure invoice belongs to this caregiver
    if (!invoiceBelongsToCaregiver($invoiceId, $caregiverId)) {
        return ['success' => false, 'message' => 'Invoice not found.'];
    }

    $pm = getPaymentMethod($caregiverId);
    if (!$pm) {
        return ['success' => false, 'message' => 'No payment method on file. Please add a card first.'];
    }

    $db   = getDB();
    $stmt = $db->prepare(
        "UPDATE invoices
         SET status = 'paid', paid_at = NOW(),
             payment_method = ?, failure_reason = NULL, failed_at = NULL
         WHERE invoice_id = ? AND status = 'failed'"
    );
    $ok = $stmt->execute(['**** ' . $pm['card_last4'], $invoiceId]);

    if ($ok && $stmt->rowCount() > 0) {
        // Send billing notification to caregiver
        $invoice = $db->prepare('SELECT * FROM invoices WHERE invoice_id = ?');
        $invoice->execute([$invoiceId]);
        $inv = $invoice->fetch();
        if ($inv) {
            createBillingNotification(
                $caregiverId,
                'Payment of ' . formatCents($inv['amount_cents']) . ' for ' .
                date('F Y', strtotime($inv['billing_month'])) . ' was successful.',
                'billing'
            );
        }
        return ['success' => true, 'message' => 'Payment successful.'];
    }

    return ['success' => false, 'message' => 'Could not process payment. Please try again.'];
}

// ════════════════════════════════════════════════════════════
// NOTIFICATIONS (billing-specific)
// ════════════════════════════════════════════════════════════

/**
 * Create a billing notification for a caregiver.
 * incident_id is NULL since billing has no associated incident.
 */
function createBillingNotification(int $recipientId, string $message, string $type = 'billing'): void {
    $db   = getDB();
    $stmt = $db->prepare(
        'INSERT INTO notifications
            (incident_id, recipient_user_id, message_text, notification_type)
         VALUES (NULL, ?, ?, ?)'
    );
    $stmt->execute([$recipientId, $message, $type]);
}

// ════════════════════════════════════════════════════════════
// BILLING RUNNER (called by billing_runner.php on page-load trigger)
// ════════════════════════════════════════════════════════════

/**
 * Check if billing should run for the current month and hasn't yet.
 * Returns true if today is the 1st and no run exists for this month.
 */
function billingRunNeeded(): bool {
    $today = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($today->format('j') !== '1') return false; // not the 1st

    $billingMonth = $today->format('Y-m-01');
    $stmt = getDB()->prepare(
        "SELECT COUNT(*) FROM billing_run_log WHERE billing_month = ?"
    );
    $stmt->execute([$billingMonth]);
    return (int)$stmt->fetchColumn() === 0;
}

/**
 * Run the billing cycle for the current month.
 * Idempotent: the unique key on billing_run_log prevents double-runs.
 *
 * For each caregiver:
 *   1. Calculate prorated charge for each active/recently-unlinked elder
 *   2. Insert invoice + line items (skips if already exists)
 *   3. Simulate payment attempt
 *   4. Send notification
 */
function runBillingCycle(): void {
    $db          = getDB();
    $today       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $billingMonth = $today->format('Y-m-01');
    $daysInMonth = (int)$today->format('t');
    $monthStart  = new DateTimeImmutable($billingMonth, new DateTimeZone('UTC'));
    $monthEnd    = new DateTimeImmutable($today->format('Y-m-') . $daysInMonth, new DateTimeZone('UTC'));

    // Idempotency lock: insert run log row; if it already exists, bail out
    try {
        $db->prepare(
            "INSERT INTO billing_run_log (billing_month, status) VALUES (?, 'running')"
        )->execute([$billingMonth]);
        $runId = (int)$db->lastInsertId();
    } catch (PDOException $e) {
        // Duplicate key = already ran this month
        error_log("[Billing] Skipped — already ran for $billingMonth");
        return;
    }

    $invoicesCreated = 0;

    try {
        // Get all active caregivers
        $caregivers = $db->query(
            "SELECT user_id FROM users WHERE role = 'caregiver' AND is_active = 1"
        )->fetchAll();

        foreach ($caregivers as $cg) {
            $caregiverId = (int)$cg['user_id'];

            // Find all links active at any point this month
            $stmt = $db->prepare(
                "SELECT al.link_id, al.linked_at, al.unlinked_at,
                        u.user_id AS elder_id, u.full_name AS elder_name
                 FROM account_links al
                 JOIN users u ON al.elder_user_id = u.user_id
                 WHERE al.caregiver_user_id = ?
                   AND al.linked_at IS NOT NULL
                   AND al.linked_at < ?
                   AND (al.unlinked_at IS NULL OR al.unlinked_at >= ?)
                 ORDER BY u.full_name"
            );
            $stmt->execute([
                $caregiverId,
                $monthEnd->format('Y-m-d') . ' 23:59:59',
                $monthStart->format('Y-m-d H:i:s'),
            ]);
            $links = $stmt->fetchAll();

            if (empty($links)) continue; // no elders, no charge

            // Calculate line items
            $lineItems  = [];
            $totalCents = 0;

            foreach ($links as $link) {
                $linkedAt   = new DateTimeImmutable($link['linked_at'],   new DateTimeZone('UTC'));
                $unlinkedAt = $link['unlinked_at']
                    ? new DateTimeImmutable($link['unlinked_at'], new DateTimeZone('UTC'))
                    : null;

                $effectiveStart = max($linkedAt,   $monthStart);
                $effectiveEnd   = $unlinkedAt ? min($unlinkedAt, $monthEnd) : $monthEnd;

                $daysActive  = max(1, (int)$effectiveStart->diff($effectiveEnd)->days + 1);
                $lineCents   = (int)round(BILLING_RATE_CENTS * $daysActive / $daysInMonth);
                $totalCents += $lineCents;

                $lineItems[] = [
                    'elder_id'          => $link['elder_id'],
                    'elder_name'        => $link['elder_name'],
                    'linked_at'         => $link['linked_at'],
                    'unlinked_at'       => $link['unlinked_at'],
                    'days_active'       => $daysActive,
                    'days_in_month'     => $daysInMonth,
                    'line_amount_cents' => $lineCents,
                ];
            }

            // Insert invoice (skip if already exists — idempotent)
            try {
                $db->prepare(
                    "INSERT INTO invoices
                        (caregiver_id, billing_month, elder_count, amount_cents, status)
                     VALUES (?, ?, ?, ?, 'pending')"
                )->execute([$caregiverId, $billingMonth, count($lineItems), $totalCents]);
                $invoiceId = (int)$db->lastInsertId();
            } catch (PDOException $e) {
                // Invoice already exists for this month — skip
                continue;
            }

            // Insert line items
            $lineStmt = $db->prepare(
                "INSERT INTO invoice_line_items
                    (invoice_id, elder_id, elder_name, linked_at, unlinked_at,
                     days_active, days_in_month, unit_cents, line_amount_cents)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($lineItems as $line) {
                $lineStmt->execute([
                    $invoiceId,
                    $line['elder_id'],
                    $line['elder_name'],
                    $line['linked_at'],
                    $line['unlinked_at'],
                    $line['days_active'],
                    $line['days_in_month'],
                    BILLING_RATE_CENTS,
                    $line['line_amount_cents'],
                ]);
            }

            // Simulate payment
            $pm = getPaymentMethod($caregiverId);
            if ($pm) {
                // Simulated: always succeeds
                $db->prepare(
                    "UPDATE invoices SET status = 'paid', paid_at = NOW(),
                     payment_method = ? WHERE invoice_id = ?"
                )->execute(['**** ' . $pm['card_last4'], $invoiceId]);

                createBillingNotification(
                    $caregiverId,
                    '💳 Your monthly invoice of ' . formatCents($totalCents) .
                    ' for ' . count($lineItems) . ' linked elder(s) has been paid. ' .
                    'Billing period: ' . date('F Y', strtotime($billingMonth)) . '.',
                    'billing'
                );
            } else {
                // No payment method — mark as failed
                $db->prepare(
                    "UPDATE invoices SET status = 'failed', failed_at = NOW(),
                     failure_reason = 'No payment method on file'
                     WHERE invoice_id = ?"
                )->execute([$invoiceId]);

                createBillingNotification(
                    $caregiverId,
                    '⚠️ Your invoice of ' . formatCents($totalCents) .
                    ' for ' . date('F Y', strtotime($billingMonth)) .
                    ' could not be processed — no payment method on file. ' .
                    'Please update your billing details.',
                    'billing'
                );
            }

            $invoicesCreated++;
        }

        // Mark run as completed
        $db->prepare(
            "UPDATE billing_run_log
             SET status = 'completed', completed_at = NOW(), invoices_created = ?
             WHERE run_id = ?"
        )->execute([$invoicesCreated, $runId]);

        error_log("[Billing] Completed for $billingMonth — $invoicesCreated invoices created.");

    } catch (Exception $e) {
        $db->prepare(
            "UPDATE billing_run_log SET status = 'failed' WHERE run_id = ?"
        )->execute([$runId]);
        error_log("[Billing] FAILED for $billingMonth: " . $e->getMessage());
    }
}
