<?php
// ============================================================
// includes/subscription_helper.php
// Plan stored directly on users columns:
//   users.plan          ENUM('free','premium')
//   users.plan_expires  DATETIME nullable
//   users.plan_paused   TINYINT(1)  — admin can pause premium
//
// Elder accounts: always free, unlimited, never touched here.
// Caregiver free:    up to FREE_LINK_LIMIT linked elders
// Caregiver premium: unlimited linked elders ($9.99/mo)
//   — unless plan_paused = 1, treated as free until unpaused
// ============================================================

require_once __DIR__ . '/../config/db.php';

const FREE_LINK_LIMIT = 2;

// ── Get full plan row for a user ──────────────────────────────
function getUserPlanRow(int $userId): array {
    try {
        $stmt = getDB()->prepare(
            'SELECT plan, plan_expires, plan_paused FROM users WHERE user_id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: ['plan' => 'free', 'plan_expires' => null, 'plan_paused' => 0];
    } catch (PDOException $e) {
        error_log('[ElderShield] getUserPlanRow: ' . $e->getMessage());
        return ['plan' => 'free', 'plan_expires' => null, 'plan_paused' => 0];
    }
}

// ── Get effective plan — respects paused state ────────────────
// Returns 'premium' only if plan=premium AND not paused AND not expired
function getUserPlan(int $userId): string {
    $row = getUserPlanRow($userId);
    if ($row['plan'] !== 'premium')    return 'free';
    if ($row['plan_paused'])           return 'free'; // paused = treated as free
    if ($row['plan_expires'] !== null
        && strtotime($row['plan_expires']) < time()) return 'free'; // expired
    return 'premium';
}

// ── Backwards-compatible wrapper ──────────────────────────────
function getUserSubscription(int $userId): array {
    $row  = getUserPlanRow($userId);
    $plan = getUserPlan($userId); // effective plan
    return [
        'plan_name'             => $plan,
        'raw_plan'              => $row['plan'],      // actual DB value
        'plan_paused'           => (bool)$row['plan_paused'],
        'plan_expires'          => $row['plan_expires'],
        'price'                 => $row['plan'] === 'premium' ? 9.99 : 0.00,
        'max_links'             => $plan === 'premium' ? -1 : FREE_LINK_LIMIT,
        'notifications_enabled' => 1,
        'status'                => $row['plan_paused'] ? 'paused' : 'active',
    ];
}

// ── Elders always free ────────────────────────────────────────
function canSubmitIncident(int $userId): bool { return true; }

// ── Monthly incident count (UI display) ──────────────────────
function getMonthlyIncidentCount(int $userId): int {
    try {
        $stmt = getDB()->prepare(
            "SELECT COUNT(*) FROM incidents
             WHERE user_id = ? AND submitted_at >= DATE_FORMAT(NOW(),'%Y-%m-01')"
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) { return 0; }
}

// ── Can a caregiver link more elders? ────────────────────────
function caregiverCanLink(int $caregiverId): bool {
    if (getUserPlan($caregiverId) === 'premium') return true;
    return caregiverLinkCount($caregiverId) < FREE_LINK_LIMIT;
}

// ── Active link count for a caregiver ────────────────────────
function caregiverLinkCount(int $caregiverId): int {
    try {
        $stmt = getDB()->prepare(
            'SELECT COUNT(*) FROM account_links
             WHERE caregiver_user_id = ? AND status = "active"'
        );
        $stmt->execute([$caregiverId]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) { return 0; }
}

// ── Set plan (used by caregiver self-upgrade & admin) ─────────
function setUserPlan(int $userId, string $planName): bool {
    if (!in_array($planName, ['free','premium'], true)) return false;
    $expires = $planName === 'premium'
        ? date('Y-m-d H:i:s', strtotime('+30 days'))
        : null;
    try {
        return getDB()->prepare(
            'UPDATE users SET plan = ?, plan_expires = ?, plan_paused = 0 WHERE user_id = ?'
        )->execute([$planName, $expires, $userId]);
    } catch (PDOException $e) {
        error_log('[ElderShield] setUserPlan: ' . $e->getMessage());
        return false;
    }
}

// ── Admin: upgrade a caregiver to premium with custom expiry ──
function adminSetPlan(int $targetId, string $planName, ?string $expiresAt = null): bool {
    if (!in_array($planName, ['free','premium'], true)) return false;
    if ($planName === 'premium' && $expiresAt === null) {
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
    }
    if ($planName === 'free') $expiresAt = null;
    try {
        return getDB()->prepare(
            'UPDATE users SET plan = ?, plan_expires = ?, plan_paused = 0 WHERE user_id = ?'
        )->execute([$planName, $expiresAt, $targetId]);
    } catch (PDOException $e) {
        error_log('[ElderShield] adminSetPlan: ' . $e->getMessage());
        return false;
    }
}

// ── Admin: pause / unpause a premium subscription ─────────────
function setPlanPaused(int $userId, bool $paused): bool {
    try {
        return getDB()->prepare(
            'UPDATE users SET plan_paused = ? WHERE user_id = ?'
        )->execute([$paused ? 1 : 0, $userId]);
    } catch (PDOException $e) {
        error_log('[ElderShield] setPlanPaused: ' . $e->getMessage());
        return false;
    }
}

// ── Cancel (downgrade to free) ────────────────────────────────
function cancelSubscription(int $userId): bool {
    return setUserPlan($userId, 'free');
}

// ── Get all caregivers with subscription info (admin view) ────
function getAllCaregiverSubscriptions(): array {
    try {
        return getDB()->query(
            'SELECT u.user_id, u.full_name, u.email, u.plan,
                    u.plan_expires, u.plan_paused, u.is_active, u.created_at,
                    (SELECT COUNT(*) FROM account_links al
                     WHERE al.caregiver_user_id = u.user_id AND al.status = "active") AS link_count
             FROM users u
             WHERE u.role = "caregiver"
             ORDER BY u.plan DESC, u.full_name ASC'
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('[ElderShield] getAllCaregiverSubscriptions: ' . $e->getMessage());
        return [];
    }
}