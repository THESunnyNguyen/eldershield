<?php
// ============================================================
// includes/helpers.php  —  Incident, notification, upload helpers
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// ════════════════════════════════════════════════════════════
// UPLOAD HELPERS
// ════════════════════════════════════════════════════════════

function handleImageUpload(array $file): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'File upload failed.'];
    }

    if (!in_array($file['type'], ALLOWED_IMAGE_TYPES, true)) {
        return ['success' => false, 'message' => 'Only JPG, PNG, GIF, WEBP images are allowed.'];
    }

    $maxBytes = UPLOAD_MAX_MB * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        return ['success' => false, 'message' => 'Image must be under ' . UPLOAD_MAX_MB . 'MB.'];
    }

    if (!@getimagesize($file['tmp_name'])) {
        return ['success' => false, 'message' => 'Uploaded file is not a valid image.'];
    }

    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = bin2hex(random_bytes(16)) . '.' . strtolower($ext);
    $destPath = UPLOAD_DIR . $filename;

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return ['success' => false, 'message' => 'Could not save uploaded file.'];
    }

    return ['success' => true, 'path' => $destPath, 'filename' => $filename];
}

// ════════════════════════════════════════════════════════════
// INCIDENT CRUD
// ════════════════════════════════════════════════════════════

function createIncident(int $userId, string $content, ?string $imagePath = null): int {
    $db   = getDB();
    $stmt = $db->prepare(
        'INSERT INTO incidents (user_id, content, image_path, status) VALUES (?, ?, ?, "pending")'
    );
    $stmt->execute([$userId, $content, $imagePath]);
    return (int)$db->lastInsertId();
}

function saveAnalysis(int $incidentId, array $result): int {
    $db   = getDB();
    $stmt = $db->prepare(
        'INSERT INTO analysis
            (incident_id, scam_probability, scam_category, manipulation_tactics,
             explanation_simple, recommended_action, ai_raw_response)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $incidentId,
        $result['scam_probability'],
        $result['scam_category'],
        json_encode($result['manipulation_tactics']),
        $result['explanation_simple'],
        $result['recommended_action'],
        json_encode($result['ai_raw_response']),
    ]);

    $db->prepare('UPDATE incidents SET status="analyzed" WHERE incident_id=?')
       ->execute([$incidentId]);

    return (int)$db->lastInsertId();
}

function getIncidentById(int $incidentId): ?array {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT i.*, a.scam_probability, a.scam_category, a.manipulation_tactics,
                a.explanation_simple, a.recommended_action, a.analysis_id, a.admin_override
         FROM incidents i
         LEFT JOIN analysis a ON i.incident_id = a.incident_id
         WHERE i.incident_id = ?'
    );
    $stmt->execute([$incidentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getIncidentsByUser(int $userId, int $limit = 20): array {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT i.*, a.scam_probability, a.scam_category
         FROM incidents i
         LEFT JOIN analysis a ON i.incident_id = a.incident_id
         WHERE i.user_id = ?
         ORDER BY i.submitted_at DESC
         LIMIT ?'
    );
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}

function getAllIncidents(int $limit = 50): array {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT i.*, u.full_name, u.email, a.scam_probability, a.scam_category
         FROM incidents i
         JOIN users u ON i.user_id = u.user_id
         LEFT JOIN analysis a ON i.incident_id = a.incident_id
         ORDER BY i.submitted_at DESC
         LIMIT ?'
    );
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function getIncidentsForCaregiver(int $caregiverId, int $limit = 50): array {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT i.*, u.full_name, u.email, a.scam_probability, a.scam_category
         FROM incidents i
         JOIN users u ON i.user_id = u.user_id
         LEFT JOIN analysis a ON i.incident_id = a.incident_id
         JOIN account_links al ON al.elder_user_id = i.user_id
         WHERE al.caregiver_user_id = ? AND al.status = "active"
         ORDER BY i.submitted_at DESC
         LIMIT ?'
    );
    $stmt->execute([$caregiverId, $limit]);
    return $stmt->fetchAll();
}

function updateIncidentStatus(int $incidentId, string $status): void {
    $allowed = ['pending','analyzed','reviewed','dismissed'];
    if (!in_array($status, $allowed, true)) return;
    getDB()->prepare('UPDATE incidents SET status=? WHERE incident_id=?')
           ->execute([$status, $incidentId]);
}

/**
 * Update the content text of an incident.
 * Only the owning elder (or admin) may call this.
 */
function updateIncidentContent(int $incidentId, int $userId, string $role, string $newContent): bool {
    $db = getDB();
    if ($role === 'admin') {
        $stmt = $db->prepare('UPDATE incidents SET content = ? WHERE incident_id = ?');
        $stmt->execute([$newContent, $incidentId]);
    } else {
        $stmt = $db->prepare('UPDATE incidents SET content = ? WHERE incident_id = ? AND user_id = ?');
        $stmt->execute([$newContent, $incidentId, $userId]);
    }
    return $stmt->rowCount() > 0;
}

function deleteIncident(int $incidentId, int $userId, string $role): bool {
    $db = getDB();
    if ($role === 'admin') {
        $stmt = $db->prepare('DELETE FROM incidents WHERE incident_id=?');
        $stmt->execute([$incidentId]);
    } else {
        $stmt = $db->prepare('DELETE FROM incidents WHERE incident_id=? AND user_id=?');
        $stmt->execute([$incidentId, $userId]);
    }
    return $stmt->rowCount() > 0;
}

// ════════════════════════════════════════════════════════════
// ANALYSIS CRUD
// ════════════════════════════════════════════════════════════

/**
 * Delete analysis for an incident and reset incident to pending.
 */
function deleteAnalysis(int $incidentId): bool {
    $db   = getDB();
    $stmt = $db->prepare('DELETE FROM analysis WHERE incident_id = ?');
    $stmt->execute([$incidentId]);
    $deleted = $stmt->rowCount() > 0;
    if ($deleted) {
        $db->prepare('UPDATE incidents SET status = "pending" WHERE incident_id = ?')
           ->execute([$incidentId]);
    }
    return $deleted;
}

// ════════════════════════════════════════════════════════════
// NOTIFICATIONS
// ════════════════════════════════════════════════════════════

function createNotification(int $incidentId, int $recipientId, string $message, string $type = 'info'): void {
    $db   = getDB();
    $stmt = $db->prepare(
        'INSERT INTO notifications
            (incident_id, recipient_user_id, message_text, notification_type, notification_category)
         VALUES (?, ?, ?, ?, "incident")'
    );
    $stmt->execute([$incidentId, $recipientId, $message, $type]);
}

function notifyCaregivers(int $incidentId, int $elderUserId, int $probability, string $category): void {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT al.caregiver_user_id FROM account_links al
         WHERE al.elder_user_id = ? AND al.status = "active"'
    );
    $stmt->execute([$elderUserId]);
    $caregivers = $stmt->fetchAll();

    $nameStmt = $db->prepare('SELECT full_name FROM users WHERE user_id=?');
    $nameStmt->execute([$elderUserId]);
    $elderName = $nameStmt->fetchColumn() ?: 'An elder user';

    $riskLevel = $probability >= RISK_HIGH ? 'HIGH' : 'MEDIUM';
    $message   = "{$elderName} submitted a {$riskLevel} RISK scam report ({$probability}% probability). "
               . "Category: " . str_replace('_', ' ', $category) . ". Please review immediately.";

    $notifType = $probability >= RISK_HIGH ? 'high_risk' : 'medium_risk';

    foreach ($caregivers as $cg) {
        createNotification($incidentId, (int)$cg['caregiver_user_id'], $message, $notifType);
    }

    $adminStmt = $db->prepare('SELECT user_id FROM users WHERE role="admin" AND is_active=1');
    $adminStmt->execute();
    foreach ($adminStmt->fetchAll() as $admin) {
        createNotification($incidentId, (int)$admin['user_id'], $message, $notifType);
    }
}

function getNotificationsForUser(int $userId, bool $unreadOnly = false): array {
    $db    = getDB();
    $where = $unreadOnly ? 'AND n.is_read = 0' : '';
    $stmt  = $db->prepare(
        "SELECT n.*,
                i.content   AS incident_content,
                u.full_name AS elder_name
         FROM notifications n
         LEFT JOIN incidents i ON n.incident_id = i.incident_id
         LEFT JOIN users u     ON i.user_id = u.user_id
         WHERE n.recipient_user_id = ? {$where}
         ORDER BY n.created_at DESC
         LIMIT 50"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function markNotificationRead(int $notificationId, int $userId): void {
    getDB()->prepare(
        'UPDATE notifications SET is_read=1 WHERE notification_id=? AND recipient_user_id=?'
    )->execute([$notificationId, $userId]);
}

function countUnreadNotifications(int $userId): int {
    $stmt = getDB()->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_user_id=? AND is_read=0');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

// ════════════════════════════════════════════════════════════
// ACCOUNT LINKS (caregiver <-> elder)
// ════════════════════════════════════════════════════════════

function linkCaregiverToElder(int $elderUserId, int $caregiverUserId, string $relationshipType = 'caregiver'): array {
    $db = getDB();

    $check = $db->prepare(
        'SELECT link_id FROM account_links
         WHERE elder_user_id = ? AND caregiver_user_id = ? AND status IN ("active","pending")
         LIMIT 1'
    );
    $check->execute([$elderUserId, $caregiverUserId]);
    if ($check->fetch()) {
        return ['success' => false, 'message' => 'A link request already exists for this relationship.'];
    }

    try {
        $stmt = $db->prepare(
            'INSERT INTO account_links
                (elder_user_id, caregiver_user_id, relationship_type, status, linked_at)
             VALUES (?, ?, ?, "pending", NULL)'
        );
        $stmt->execute([$elderUserId, $caregiverUserId, $relationshipType]);
        return ['success' => true, 'link_id' => (int)$db->lastInsertId()];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Failed to create link.'];
    }
}

function approveLink(int $linkId): void {
    getDB()->prepare(
        'UPDATE account_links
         SET status = "active", linked_at = UTC_TIMESTAMP()
         WHERE link_id = ? AND status = "pending"'
    )->execute([$linkId]);
}

function revokeLink(int $linkId): void {
    getDB()->prepare(
        'UPDATE account_links
         SET status = "revoked", unlinked_at = UTC_TIMESTAMP()
         WHERE link_id = ? AND status = "active"'
    )->execute([$linkId]);
}

function deleteLink(int $linkId): bool {
    $stmt = getDB()->prepare('DELETE FROM account_links WHERE link_id = ?');
    $stmt->execute([$linkId]);
    return $stmt->rowCount() > 0;
}

function getLinksForElder(int $elderUserId): array {
    $stmt = getDB()->prepare(
        'SELECT al.*, u.full_name, u.email FROM account_links al
         JOIN users u ON al.caregiver_user_id = u.user_id
         WHERE al.elder_user_id = ?
         ORDER BY al.created_at DESC'
    );
    $stmt->execute([$elderUserId]);
    return $stmt->fetchAll();
}

function getLinksForCaregiver(int $caregiverId): array {
    $stmt = getDB()->prepare(
        'SELECT al.*, u.full_name, u.email FROM account_links al
         JOIN users u ON al.elder_user_id = u.user_id
         WHERE al.caregiver_user_id = ? AND al.status = "active"
         ORDER BY u.full_name'
    );
    $stmt->execute([$caregiverId]);
    return $stmt->fetchAll();
}

// ════════════════════════════════════════════════════════════
// ADMIN — SCAM TYPE OVERRIDE
// ════════════════════════════════════════════════════════════

function updateScamCategory(int $incidentId, string $category): bool {
    $category = trim(strip_tags($category));
    if (empty($category) || strlen($category) > 100) return false;
    $stmt = getDB()->prepare(
        'UPDATE analysis SET scam_category = ?, admin_override = 1 WHERE incident_id = ?'
    );
    return $stmt->execute([$category, $incidentId]);
}

// ════════════════════════════════════════════════════════════
// UTILITY
// ════════════════════════════════════════════════════════════

function e(string $val): string {
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

function riskBadge(float $probability): string {
    $level = $probability >= RISK_HIGH ? 'high' : ($probability >= RISK_MEDIUM ? 'medium' : 'low');
    $map   = ['high' => ['label'=>'High Risk','class'=>'badge-danger'],
              'medium'=>['label'=>'Medium Risk','class'=>'badge-warning'],
              'low'  => ['label'=>'Low Risk','class'=>'badge-success']];
    $b = $map[$level];
    return "<span class=\"badge {$b['class']}\">{$b['label']} (" . round($probability) . "%)</span>";
}

function timeAgo(string $datetime): string {
    $now  = new DateTime();
    $then = new DateTime($datetime);
    $diff = $now->diff($then);
    if ($diff->days > 30)  return $then->format('M j, Y');
    if ($diff->days >= 1)  return $diff->days  . 'd ago';
    if ($diff->h >= 1)     return $diff->h     . 'h ago';
    if ($diff->i >= 1)     return $diff->i     . 'm ago';
    return 'just now';
}

function formatCategory(string $category): string {
    return ucwords(str_replace('_', ' ', $category));
}
