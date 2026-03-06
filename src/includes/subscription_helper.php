<?php
// ============================================================
// includes/subscription_helper.php
// ============================================================
// Under the new billing model:
//   - Elders are ALWAYS free with NO incident limits
//   - Caregivers are billed $0.99/month per linked elder (prorated)
//   - This file retains only elder-facing helpers that other pages
//     already depend on (getUserSubscription, canSubmitIncident)
//     but now they always return "unlimited / free" for elders.
//   - Caregiver billing logic lives in billing_helper.php
// ============================================================

require_once __DIR__ . '/../config/db.php';

/**
 * Returns a normalised "subscription" array for any user.
 * Elders are always free with unlimited incidents.
 * Caregivers and admins are never restricted here.
 * Kept for backward compatibility with pages that call it.
 */
function getUserSubscription(int $userId): array {
    return [
        'plan_name'               => 'free',
        'price'                   => 0.00,
        'max_incidents_per_month' => -1,   // unlimited for everyone
        'notifications_enabled'   => 1,    // always enabled
        'status'                  => 'active',
    ];
}

/**
 * Elders can always submit incidents — no monthly cap.
 */
function canSubmitIncident(int $userId): bool {
    return true;
}

/**
 * How many incidents has a user submitted this month?
 * Kept for display purposes on the dashboard.
 */
function getMonthlyIncidentCount(int $userId): int {
    $stmt = getDB()->prepare(
        "SELECT COUNT(*) FROM incidents
         WHERE user_id = ?
           AND submitted_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
    );
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}
