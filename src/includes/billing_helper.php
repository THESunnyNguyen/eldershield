<?php
// ============================================================
// includes/billing_helper.php
// Caregiver billing — flat $9.99/month for premium plan.
// Invoices table: one row per caregiver per billing month.
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/subscription_helper.php';

const PREMIUM_MONTHLY_CENTS = 999; // $9.99

// ── Billing summary for a caregiver ──────────────────────────
function getCaregiverBillingSummary(int $caregiverId): array {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM account_links
         WHERE caregiver_user_id = ? AND status = "active"'
    );
    $stmt->execute([$caregiverId]);
    $activeElders = (int)$stmt->fetchColumn();

    $plan     = getUserPlan($caregiverId);
    $monthly  = $plan === 'premium' ? PREMIUM_MONTHLY_CENTS : 0;

    return [
        'active_elders' => $activeElders,
        'monthly_cents' => $monthly,
        'monthly_fmt'   => formatCents($monthly),
        'plan'          => $plan,
    ];
}

// ── Check for failed invoices (restricts caregiver access) ───
function caregiverAccessRestricted(int $caregiverId): bool {
    try {
        $stmt = getDB()->prepare(
            'SELECT COUNT(*) FROM invoices WHERE caregiver_id = ? AND status = "failed"'
        );
        $stmt->execute([$caregiverId]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// ── Get most recent failed invoice ───────────────────────────
function getFailedInvoice(int $caregiverId): ?array {
    try {
        $stmt = getDB()->prepare(
            'SELECT * FROM invoices WHERE caregiver_id = ? AND status = "failed"
             ORDER BY billing_month DESC LIMIT 1'
        );
        $stmt->execute([$caregiverId]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

// ── Generate monthly invoices for all premium caregivers ──────
function generateMonthlyInvoices(string $billingMonth): array {
    $db   = getDB();
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $billingMonth);
    if (!$date || $date->format('d') !== '01') {
        return ['error' => 'billingMonth must be Y-m-01 format'];
    }

    // Only bill premium caregivers
    $caregivers = $db->query(
        'SELECT user_id FROM users
         WHERE role = "caregiver" AND is_active = 1 AND plan = "premium"'
    )->fetchAll();

    $created = 0;
    $skipped = 0;

    foreach ($caregivers as $cg) {
        $caregiverId = (int)$cg['user_id'];

        $stmt = $db->prepare(
            'INSERT INTO invoices (caregiver_id, billing_month, amount_cents, status)
             VALUES (?, ?, ?, "pending")
             ON DUPLICATE KEY UPDATE invoice_id = invoice_id'
        );
        $stmt->execute([$caregiverId, $billingMonth, PREMIUM_MONTHLY_CENTS]);

        if ($stmt->rowCount() === 0) { $skipped++; continue; }

        $invoiceId = (int)$db->lastInsertId();
        $created++;
        simulatePayment($invoiceId, $caregiverId);
    }

    return ['created' => $created, 'skipped' => $skipped];
}

// ── Simulate payment (95% success for demo) ──────────────────
function simulatePayment(int $invoiceId, int $caregiverId): bool {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT invoice_id, amount_cents, status FROM invoices
         WHERE invoice_id = ? AND caregiver_id = ? LIMIT 1'
    );
    $stmt->execute([$invoiceId, $caregiverId]);
    $invoice = $stmt->fetch();

    if (!$invoice)                          return false;
    if ($invoice['status'] === 'paid')      return true;

    $success = (rand(1, 100) <= 95);

    if ($success) {
        $db->prepare(
            'UPDATE invoices SET status = "paid", paid_at = UTC_TIMESTAMP() WHERE invoice_id = ?'
        )->execute([$invoiceId]);
        sendBillingNotification($caregiverId, $invoiceId, 'billing_success');
    } else {
        $db->prepare('UPDATE invoices SET status = "failed" WHERE invoice_id = ?')
           ->execute([$invoiceId]);
        sendBillingNotification($caregiverId, $invoiceId, 'billing_failed');
    }

    return $success;
}

// ── Retry a failed payment ────────────────────────────────────
function retryPayment(int $invoiceId, int $caregiverId): bool {
    $stmt = getDB()->prepare(
        'SELECT invoice_id FROM invoices
         WHERE invoice_id = ? AND caregiver_id = ? AND status = "failed" LIMIT 1'
    );
    $stmt->execute([$invoiceId, $caregiverId]);
    if (!$stmt->fetch()) return false;
    return simulatePayment($invoiceId, $caregiverId);
}

// ── Invoice history for a caregiver ──────────────────────────
function getInvoiceHistory(int $caregiverId, int $limit = 24): array {
    try {
        $stmt = getDB()->prepare(
            'SELECT * FROM invoices WHERE caregiver_id = ?
             ORDER BY billing_month DESC LIMIT ?'
        );
        $stmt->execute([$caregiverId, $limit]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

// ── Admin: all invoices ───────────────────────────────────────
function getAllInvoicesAdmin(int $limit = 100): array {
    return getDB()->query(
        'SELECT i.*, u.full_name AS caregiver_name, u.email AS caregiver_email
         FROM invoices i
         JOIN users u ON i.caregiver_id = u.user_id
         ORDER BY i.billing_month DESC
         LIMIT ' . (int)$limit
    )->fetchAll();
}

// ── Admin: billing overview ───────────────────────────────────
function getAdminBillingOverview(): array {
    return getDB()->query(
        'SELECT u.user_id, u.full_name, u.email, u.plan,
                COUNT(DISTINCT al.link_id) AS active_elders,
                MAX(i.billing_month)       AS last_billed,
                (SELECT status FROM invoices i2
                 WHERE i2.caregiver_id = u.user_id
                 ORDER BY i2.billing_month DESC LIMIT 1) AS last_status
         FROM users u
         LEFT JOIN account_links al
               ON al.caregiver_user_id = u.user_id AND al.status = "active"
         LEFT JOIN invoices i ON i.caregiver_id = u.user_id
         WHERE u.role = "caregiver" AND u.is_active = 1
         GROUP BY u.user_id, u.full_name, u.email, u.plan
         ORDER BY u.full_name'
    )->fetchAll();
}

// ── Send billing notification ─────────────────────────────────
function sendBillingNotification(int $caregiverId, int $invoiceId, string $type): void {
    $db  = getDB();
    $inv = $db->prepare('SELECT * FROM invoices WHERE invoice_id = ?');
    $inv->execute([$invoiceId]);
    $invoice = $inv->fetch();
    if (!$invoice) return;

    $month   = date('F Y', strtotime($invoice['billing_month']));
    $amount  = formatCents((int)$invoice['amount_cents']);
    $messages = [
        'billing_success' => "Your invoice for {$month} ({$amount}) has been paid successfully.",
        'billing_failed'  => "Payment failed for your {$month} invoice ({$amount}). Please check your payment method.",
    ];
    $message = $messages[$type] ?? "Billing update for {$month}.";

    $db->prepare(
        'INSERT INTO notifications (incident_id, recipient_user_id, message_text, notification_type)
         VALUES (NULL, ?, ?, ?)'
    )->execute([$caregiverId, $message, 'admin_action']);
}

// ── Formatting ────────────────────────────────────────────────
function formatCents(int $cents): string {
    return '$' . number_format($cents / 100, 2);
}

function formatBillingMonth(string $date): string {
    return date('F Y', strtotime($date));
}
