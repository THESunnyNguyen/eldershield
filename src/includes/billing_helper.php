<?php
// ============================================================
// includes/billing_helper.php
// Caregiver billing logic — $0.99/month per active linked elder
// Amounts stored and calculated in INTEGER CENTS.
// ============================================================

require_once __DIR__ . '/../config/db.php';

const RATE_CENTS = 99; // $0.99 per elder per month

// ─────────────────────────────────────────────────────────────
// PRORATION
// ─────────────────────────────────────────────────────────────

/**
 * Calculate prorated cents for a partial month.
 * Uses integer math: ROUND(99 * daysActive / daysInMonth).
 * Never returns negative values.
 */
function proratedCents(int $daysActive, int $daysInMonth): int {
    if ($daysInMonth <= 0 || $daysActive <= 0) return 0;
    $daysActive   = min($daysActive, $daysInMonth); // cap at full month
    return (int)round(RATE_CENTS * $daysActive / $daysInMonth);
}

/**
 * Number of days a link was/is active within a given billing month.
 * $billingMonth: 'Y-m-01' format.
 * Uses UTC-normalised DATETIME arithmetic.
 */
function daysActiveInMonth(string $linkedAt, ?string $unlinkedAt, string $billingMonth): int {
    $monthStart = new DateTimeImmutable($billingMonth . ' 00:00:00', new DateTimeZone('UTC'));
    $monthEnd   = $monthStart->modify('last day of this month')->setTime(23, 59, 59);
    $daysInMonth = (int)$monthStart->format('t');

    $linkStart  = new DateTimeImmutable($linkedAt, new DateTimeZone('UTC'));
    $linkEnd    = $unlinkedAt
        ? new DateTimeImmutable($unlinkedAt, new DateTimeZone('UTC'))
        : new DateTimeImmutable('now', new DateTimeZone('UTC'));

    // Clamp to the billing month window
    $start = max($monthStart, $linkStart);
    $end   = min($monthEnd,   $linkEnd);

    if ($start > $end) return 0;

    // Days active = difference in full days, minimum 1 if any overlap exists
    $diff = $start->diff($end);
    return max(1, (int)$diff->days + ($diff->h > 0 || $diff->i > 0 ? 1 : 0));
}

// ─────────────────────────────────────────────────────────────
// CAREGIVER BILLING STATUS
// ─────────────────────────────────────────────────────────────

/**
 * Get billing summary for a caregiver: active link count + monthly amount.
 * Returns amount in cents.
 */
function getCaregiverBillingSummary(int $caregiverId): array {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM account_links
         WHERE caregiver_user_id = ? AND status = "active"'
    );
    $stmt->execute([$caregiverId]);
    $activeElders = (int)$stmt->fetchColumn();

    return [
        'active_elders' => $activeElders,
        'monthly_cents' => $activeElders * RATE_CENTS,
        'monthly_fmt'   => formatCents($activeElders * RATE_CENTS),
    ];
}

/**
 * Check if a caregiver has a failed/unpaid invoice — used to restrict access.
 * Returns true if access should be restricted.
 */
function caregiverAccessRestricted(int $caregiverId): bool {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM invoices
         WHERE caregiver_id = ? AND status = "failed"'
    );
    $stmt->execute([$caregiverId]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Get the most recent failed invoice for a caregiver (for the warning banner).
 */
function getFailedInvoice(int $caregiverId): ?array {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT * FROM invoices
         WHERE caregiver_id = ? AND status = "failed"
         ORDER BY billing_month DESC LIMIT 1'
    );
    $stmt->execute([$caregiverId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ─────────────────────────────────────────────────────────────
// INVOICE GENERATION (called by run_billing.php on the 1st)
// ─────────────────────────────────────────────────────────────

/**
 * Generate invoices for ALL caregivers for a given billing month.
 * Idempotent: the UNIQUE KEY on (caregiver_id, billing_month) means
 * calling this twice for the same month silently skips existing rows.
 *
 * $billingMonth: 'Y-m-01' string, e.g. '2026-03-01'
 */
function generateMonthlyInvoices(string $billingMonth): array {
    $db = getDB();

    // Validate format
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $billingMonth);
    if (!$date || $date->format('d') !== '01') {
        return ['error' => 'billingMonth must be Y-m-01 format'];
    }

    $daysInMonth = (int)$date->format('t');

    // Fetch all caregivers
    $caregivers = $db->query(
        'SELECT user_id FROM users WHERE role = "caregiver" AND is_active = 1'
    )->fetchAll();

    $created = 0;
    $skipped = 0;

    foreach ($caregivers as $cg) {
        $caregiverId = (int)$cg['user_id'];

        // Fetch all links for this caregiver that were active at any point this month
        $stmt = $db->prepare(
            'SELECT al.link_id, al.elder_user_id, al.linked_at, al.unlinked_at,
                    u.full_name AS elder_name
             FROM account_links al
             JOIN users u ON al.elder_user_id = u.user_id
             WHERE al.caregiver_user_id = ?
               AND al.status IN ("active","revoked")
               AND al.linked_at < DATE_ADD(?, INTERVAL 1 MONTH)
               AND (al.unlinked_at IS NULL OR al.unlinked_at >= ?)'
        );
        $stmt->execute([$caregiverId, $billingMonth, $billingMonth]);
        $links = $stmt->fetchAll();

        if (empty($links)) {
            $skipped++;
            continue; // no linked elders this month — no invoice
        }

        // Build line items and total
        $lines      = [];
        $totalCents = 0;
        foreach ($links as $link) {
            $days  = daysActiveInMonth(
                $link['linked_at'],
                $link['unlinked_at'],
                $billingMonth
            );
            $cents = proratedCents($days, $daysInMonth);
            $lines[] = [
                'elder_id'    => (int)$link['elder_user_id'],
                'elder_name'  => $link['elder_name'],
                'days_active' => $days,
                'amount_cents'=> $cents,
            ];
            $totalCents += $cents;
        }

        // Insert invoice — ON DUPLICATE KEY UPDATE is a no-op (idempotent)
        $invoiceStmt = $db->prepare(
            'INSERT INTO invoices
                (caregiver_id, billing_month, elder_count, amount_cents, status)
             VALUES (?, ?, ?, ?, "pending")
             ON DUPLICATE KEY UPDATE invoice_id = invoice_id' // no-op update = skip duplicate
        );
        $invoiceStmt->execute([
            $caregiverId,
            $billingMonth,
            count($lines),
            $totalCents,
        ]);

        // If row already existed, skip line items too
        if ($invoiceStmt->rowCount() === 0) {
            $skipped++;
            continue;
        }

        $invoiceId = (int)$db->lastInsertId();
        $created++;

        // Insert line items
        $lineStmt = $db->prepare(
            'INSERT INTO invoice_line_items
                (invoice_id, elder_id, elder_name, days_active, days_in_month, amount_cents)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($lines as $line) {
            $lineStmt->execute([
                $invoiceId,
                $line['elder_id'],
                $line['elder_name'],
                $line['days_active'],
                $daysInMonth,
                $line['amount_cents'],
            ]);
        }

        // Simulate auto-payment (always succeeds in demo)
        simulatePayment($invoiceId, $caregiverId);
    }

    return ['created' => $created, 'skipped' => $skipped];
}

// ─────────────────────────────────────────────────────────────
// PAYMENT SIMULATION
// ─────────────────────────────────────────────────────────────

/**
 * Simulate payment processing for an invoice.
 * In a real system this would call a payment gateway.
 * For demo: 95% success rate to allow testing the failure path.
 *
 * Security: invoice ownership is verified before calling this.
 */
function simulatePayment(int $invoiceId, int $caregiverId): bool {
    $db = getDB();

    // Always verify the invoice belongs to this caregiver (prevents IDOR)
    $stmt = $db->prepare(
        'SELECT invoice_id, amount_cents, status FROM invoices
         WHERE invoice_id = ? AND caregiver_id = ? LIMIT 1'
    );
    $stmt->execute([$invoiceId, $caregiverId]);
    $invoice = $stmt->fetch();

    if (!$invoice) return false;                     // invoice not found or wrong caregiver
    if ($invoice['status'] === 'paid') return true;  // already paid — idempotent

    // Simulate: 95% success (change to false to force failure in testing)
    $success = (rand(1, 100) <= 95);

    if ($success) {
        $db->prepare(
            'UPDATE invoices
             SET status = "paid", paid_at = UTC_TIMESTAMP(),
                 payment_method = "Demo Card ••••4242"
             WHERE invoice_id = ?'
        )->execute([$invoiceId]);

        sendBillingNotification($caregiverId, $invoiceId, 'billing_success');
    } else {
        $db->prepare(
            'UPDATE invoices SET status = "failed" WHERE invoice_id = ?'
        )->execute([$invoiceId]);

        sendBillingNotification($caregiverId, $invoiceId, 'billing_failed');
    }

    return $success;
}

/**
 * Retry payment on a failed invoice (triggered by caregiver via UI).
 * Verifies ownership before attempting.
 */
function retryPayment(int $invoiceId, int $caregiverId): bool {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT invoice_id FROM invoices
         WHERE invoice_id = ? AND caregiver_id = ? AND status = "failed" LIMIT 1'
    );
    $stmt->execute([$invoiceId, $caregiverId]);
    if (!$stmt->fetch()) return false; // not found, wrong owner, or not failed

    return simulatePayment($invoiceId, $caregiverId);
}

// ─────────────────────────────────────────────────────────────
// INVOICE RETRIEVAL
// ─────────────────────────────────────────────────────────────

/**
 * Get paginated invoice history for a caregiver.
 * Always scoped to the authenticated caregiver — never trusts URL params.
 */
function getInvoiceHistory(int $caregiverId, int $limit = 24): array {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT * FROM invoices
         WHERE caregiver_id = ?
         ORDER BY billing_month DESC
         LIMIT ?'
    );
    $stmt->execute([$caregiverId, $limit]);
    return $stmt->fetchAll();
}

/**
 * Get a single invoice with its line items.
 * Ownership check is mandatory.
 */
function getInvoiceWithLines(int $invoiceId, int $caregiverId): ?array {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT * FROM invoices WHERE invoice_id = ? AND caregiver_id = ? LIMIT 1'
    );
    $stmt->execute([$invoiceId, $caregiverId]);
    $invoice = $stmt->fetch();
    if (!$invoice) return null;

    $lineStmt = $db->prepare(
        'SELECT * FROM invoice_line_items WHERE invoice_id = ? ORDER BY elder_name'
    );
    $lineStmt->execute([$invoiceId]);
    $invoice['lines'] = $lineStmt->fetchAll();

    return $invoice;
}

/**
 * Admin: get all invoices across all caregivers with caregiver name.
 */
function getAllInvoicesAdmin(int $limit = 100): array {
    $db   = getDB();
    return $db->query(
        'SELECT i.*, u.full_name AS caregiver_name, u.email AS caregiver_email
         FROM invoices i
         JOIN users u ON i.caregiver_id = u.user_id
         ORDER BY i.billing_month DESC, i.caregiver_id
         LIMIT ' . (int)$limit
    )->fetchAll();
}

/**
 * Admin: billing overview — one row per caregiver with current link count + last invoice.
 */
function getAdminBillingOverview(): array {
    $db = getDB();
    return $db->query(
        'SELECT
            u.user_id,
            u.full_name,
            u.email,
            COUNT(DISTINCT al.link_id) AS active_elders,
            COUNT(DISTINCT al.link_id) * ' . RATE_CENTS . ' AS monthly_cents,
            MAX(i.billing_month)       AS last_billed,
            (SELECT status FROM invoices i2
             WHERE i2.caregiver_id = u.user_id
             ORDER BY i2.billing_month DESC LIMIT 1) AS last_status
         FROM users u
         LEFT JOIN account_links al
               ON al.caregiver_user_id = u.user_id AND al.status = "active"
         LEFT JOIN invoices i ON i.caregiver_id = u.user_id
         WHERE u.role = "caregiver" AND u.is_active = 1
         GROUP BY u.user_id, u.full_name, u.email
         ORDER BY u.full_name'
    )->fetchAll();
}

// ─────────────────────────────────────────────────────────────
// BILLING NOTIFICATIONS
// ─────────────────────────────────────────────────────────────

/**
 * Send a billing notification to a caregiver.
 * Uses the notifications table with incident_id = NULL (allowed after migration).
 */
function sendBillingNotification(int $caregiverId, int $invoiceId, string $type): void {
    $db      = getDB();
    $invoice = $db->prepare('SELECT * FROM invoices WHERE invoice_id = ?');
    $invoice->execute([$invoiceId]);
    $inv = $invoice->fetch();
    if (!$inv) return;

    $month  = date('F Y', strtotime($inv['billing_month']));
    $amount = formatCents((int)$inv['amount_cents']);

    $messages = [
        'billing_success' => "Your invoice for {$month} ({$amount}) has been paid successfully.",
        'billing_failed'  => "Payment failed for your {$month} invoice ({$amount}). Please update your payment method.",
        'billing_overdue' => "Your {$month} invoice ({$amount}) is overdue. Access may be restricted.",
    ];

    $message = $messages[$type] ?? "Billing update for {$month}.";

    $stmt = $db->prepare(
        'INSERT INTO notifications
            (incident_id, recipient_user_id, message_text, notification_type, notification_category)
         VALUES (NULL, ?, ?, ?, "billing")'
    );
    $stmt->execute([$caregiverId, $message, $type]);
}

// ─────────────────────────────────────────────────────────────
// FORMATTING HELPERS
// ─────────────────────────────────────────────────────────────

/** Format integer cents as a dollar string: 99 → "$0.99" */
function formatCents(int $cents): string {
    return '$' . number_format($cents / 100, 2);
}

/** Format billing_month DATE as human label: '2026-03-01' → 'March 2026' */
function formatBillingMonth(string $date): string {
    return date('F Y', strtotime($date));
}
