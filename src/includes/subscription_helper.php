<?php
// ============================================================
// includes/subscription_helper.php — Subscription logic
// ============================================================

require_once __DIR__ . '/../config/db.php';

// Free tier incident cap
const FREE_INCIDENT_LIMIT = 5;

/**
 * Fetch the active subscription + plan for a user.
 * Always reads from DB — never trust session for plan status.
 * Returns plan data or the 'free' plan as a safe default.
 */
function getUserSubscription(int $userId): array {
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT s.*, p.name AS plan_name, p.price, p.max_incidents_per_month, p.notifications_enabled
        FROM subscriptions s
        JOIN subscription_plans p ON s.plan_id = p.plan_id
        WHERE s.user_id = ? AND s.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    // If no subscription exists, return free plan defaults
    if (!$row) {
        return [
            'plan_name'               => 'free',
            'price'                   => 0.00,
            'max_incidents_per_month' => FREE_INCIDENT_LIMIT,
            'notifications_enabled'   => 0,
            'status'                  => 'none',
        ];
    }
    return $row;
}

/**
 * Check if the user can still submit an incident this month.
 * Premium users (-1 limit) are always allowed.
 */
function canSubmitIncident(int $userId): bool {
    $sub = getUserSubscription($userId);

    // -1 means unlimited (premium)
    if ((int)$sub['max_incidents_per_month'] === -1) return true;

    $db   = getDB();
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM incidents
        WHERE user_id = ?
          AND submitted_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
    ");
    $stmt->execute([$userId]);
    $count = (int)$stmt->fetchColumn();

    return $count < (int)$sub['max_incidents_per_month'];
}

/**
 * How many incidents has the user submitted this month?
 */
function getMonthlyIncidentCount(int $userId): int {
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM incidents
        WHERE user_id = ?
          AND submitted_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
    ");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Upgrade or create a subscription for a user.
 * Uses INSERT ... ON DUPLICATE KEY UPDATE for atomicity.
 *
 * @param string $planName  Must be 'free' or 'premium'
 */
function setUserPlan(int $userId, string $planName): bool {
    // Whitelist — never pass raw user input to the query
    $allowed = ['free', 'premium'];
    if (!in_array($planName, $allowed, true)) return false;

    $db   = getDB();
    $stmt = $db->prepare("SELECT plan_id FROM subscription_plans WHERE name = ? LIMIT 1");
    $stmt->execute([$planName]);
    $plan = $stmt->fetch();
    if (!$plan) return false;

    // Set expiry 30 days out for premium; NULL for free
    $expires = ($planName === 'premium')
        ? date('Y-m-d H:i:s', strtotime('+30 days'))
        : null;

    $stmt = $db->prepare("
        INSERT INTO subscriptions (user_id, plan_id, status, started_at, expires_at)
        VALUES (?, ?, 'active', NOW(), ?)
        ON DUPLICATE KEY UPDATE
            plan_id    = VALUES(plan_id),
            status     = 'active',
            started_at = NOW(),
            expires_at = VALUES(expires_at)
    ");
    return $stmt->execute([$userId, $plan['plan_id'], $expires]);
}

/**
 * Cancel a user's subscription (downgrades to free behaviour).
 */
function cancelSubscription(int $userId): bool {
    $db   = getDB();
    $stmt = $db->prepare("
        UPDATE subscriptions SET status = 'cancelled' WHERE user_id = ?
    ");
    return $stmt->execute([$userId]);
}
