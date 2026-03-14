<?php
// ============================================================
// includes/helpers.php  —  CRUD helpers
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// ════════════════════════════════════════════════════════════
// IMAGE UPLOAD
// ════════════════════════════════════════════════════════════

function handleImageUpload(array $file): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'File upload failed.'];
    }
    if (!in_array($file['type'], ALLOWED_IMAGE_TYPES, true)) {
        return ['success' => false, 'message' => 'Only JPG, PNG, GIF, WEBP images are allowed.'];
    }
    if ($file['size'] > UPLOAD_MAX_MB * 1024 * 1024) {
        return ['success' => false, 'message' => 'Image must be under ' . UPLOAD_MAX_MB . 'MB.'];
    }
    if (!@getimagesize($file['tmp_name'])) {
        return ['success' => false, 'message' => 'Uploaded file is not a valid image.'];
    }
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destPath = UPLOAD_DIR . $filename;
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return ['success' => false, 'message' => 'Could not save uploaded file.'];
    }
    return ['success' => true, 'path' => $destPath, 'filename' => $filename];
}

// ════════════════════════════════════════════════════════════
// INCIDENTS
// ════════════════════════════════════════════════════════════

function createIncident(int $userId, string $content, ?string $imagePath = null): int {
    $db = getDB();
    $db->prepare(
        'INSERT INTO incidents (user_id, content, image_path, status) VALUES (?, ?, ?, "pending")'
    )->execute([$userId, $content, $imagePath]);
    return (int)$db->lastInsertId();
}

// analysis.incident_id is now the PK — INSERT, not INSERT with separate analysis_id
function saveAnalysis(int $incidentId, array $result): void {
    $db = getDB();
    $db->prepare(
        'INSERT INTO analysis
            (incident_id, scam_probability, scam_category, manipulation_tactics,
             explanation_simple, recommended_action)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            scam_probability   = VALUES(scam_probability),
            scam_category      = VALUES(scam_category),
            manipulation_tactics = VALUES(manipulation_tactics),
            explanation_simple = VALUES(explanation_simple),
            recommended_action = VALUES(recommended_action)'
    )->execute([
        $incidentId,
        $result['scam_probability'],
        $result['scam_category'],
        json_encode($result['manipulation_tactics']),
        $result['explanation_simple'],
        $result['recommended_action'],
    ]);
    $db->prepare('UPDATE incidents SET status = "analyzed" WHERE incident_id = ?')
       ->execute([$incidentId]);
}

function getIncidentById(int $incidentId): ?array {
    $stmt = getDB()->prepare(
        'SELECT i.*, a.scam_probability, a.scam_category, a.manipulation_tactics,
                a.explanation_simple, a.recommended_action
         FROM incidents i
         LEFT JOIN analysis a ON i.incident_id = a.incident_id
         WHERE i.incident_id = ?'
    );
    $stmt->execute([$incidentId]);
    return $stmt->fetch() ?: null;
}

function getIncidentsByUser(int $userId, int $limit = 20): array {
    $stmt = getDB()->prepare(
        'SELECT i.*, a.scam_probability, a.scam_category
         FROM incidents i
         LEFT JOIN analysis a ON i.incident_id = a.incident_id
         WHERE i.user_id = ?
         ORDER BY i.submitted_at DESC LIMIT ?'
    );
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}

function getAllIncidents(int $limit = 50): array {
    $stmt = getDB()->prepare(
        'SELECT i.*, u.full_name, u.email, a.scam_probability, a.scam_category
         FROM incidents i
         JOIN users u ON i.user_id = u.user_id
         LEFT JOIN analysis a ON i.incident_id = a.incident_id
         ORDER BY i.submitted_at DESC LIMIT ?'
    );
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function getIncidentsForCaregiver(int $caregiverId, int $limit = 50): array {
    $stmt = getDB()->prepare(
        'SELECT i.*, u.full_name, u.email, a.scam_probability, a.scam_category
         FROM incidents i
         JOIN users u ON i.user_id = u.user_id
         LEFT JOIN analysis a ON i.incident_id = a.incident_id
         JOIN account_links al ON al.elder_user_id = i.user_id
         WHERE al.caregiver_user_id = ? AND al.status = "active"
         ORDER BY i.submitted_at DESC LIMIT ?'
    );
    $stmt->execute([$caregiverId, $limit]);
    return $stmt->fetchAll();
}

function updateIncidentStatus(int $incidentId, string $status): void {
    $allowed = ['pending','analyzed','reviewed','dismissed'];
    if (!in_array($status, $allowed, true)) return;
    getDB()->prepare('UPDATE incidents SET status = ? WHERE incident_id = ?')
           ->execute([$status, $incidentId]);
}

function deleteIncident(int $incidentId, int $userId, string $role): bool {
    $db = getDB();
    if ($role === 'admin') {
        $stmt = $db->prepare('DELETE FROM incidents WHERE incident_id = ?');
        $stmt->execute([$incidentId]);
    } else {
        $stmt = $db->prepare('DELETE FROM incidents WHERE incident_id = ? AND user_id = ?');
        $stmt->execute([$incidentId, $userId]);
    }
    return $stmt->rowCount() > 0;
}

// ════════════════════════════════════════════════════════════
// NOTIFICATIONS
// ════════════════════════════════════════════════════════════

function createNotification(int $recipientId, string $message, string $type = 'info', ?int $incidentId = null): void {
    getDB()->prepare(
        'INSERT INTO notifications (incident_id, recipient_user_id, message_text, notification_type)
         VALUES (?, ?, ?, ?)'
    )->execute([$incidentId, $recipientId, $message, $type]);
}

function notifyCaregivers(int $incidentId, int $elderUserId, int $probability, string $category): void {
    $db = getDB();

    $stmt = $db->prepare(
        'SELECT al.caregiver_user_id FROM account_links al
         WHERE al.elder_user_id = ? AND al.status = "active"'
    );
    $stmt->execute([$elderUserId]);
    $caregivers = $stmt->fetchAll();

    $nameStmt = $db->prepare('SELECT full_name FROM users WHERE user_id = ?');
    $nameStmt->execute([$elderUserId]);
    $elderName = $nameStmt->fetchColumn() ?: 'An elder user';

    $level   = $probability >= RISK_HIGH ? 'HIGH' : 'MEDIUM';
    $cat     = ucwords(str_replace('_', ' ', $category));
    $message = "{$elderName} submitted a {$level} RISK report ({$probability}%). Category: {$cat}. Please review.";
    $type    = $probability >= RISK_HIGH ? 'high_risk' : 'medium_risk';

    foreach ($caregivers as $cg) {
        createNotification((int)$cg['caregiver_user_id'], $message, $type, $incidentId);
    }

    // Notify admins too
    $admins = $db->query('SELECT user_id FROM users WHERE role = "admin" AND is_active = 1')->fetchAll();
    foreach ($admins as $admin) {
        createNotification((int)$admin['user_id'], $message, $type, $incidentId);
    }
}

function getNotificationsForUser(int $userId): array {
    $stmt = getDB()->prepare(
        'SELECT n.*, i.content AS incident_content, u.full_name AS elder_name
         FROM notifications n
         LEFT JOIN incidents i ON n.incident_id = i.incident_id
         LEFT JOIN users u     ON i.user_id = u.user_id
         WHERE n.recipient_user_id = ?
         ORDER BY n.created_at DESC LIMIT 50'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function markNotificationRead(int $notificationId, int $userId): void {
    getDB()->prepare(
        'UPDATE notifications SET is_read = 1
         WHERE notification_id = ? AND recipient_user_id = ?'
    )->execute([$notificationId, $userId]);
}

function countUnreadNotifications(int $userId): int {
    $stmt = getDB()->prepare(
        'SELECT COUNT(*) FROM notifications WHERE recipient_user_id = ? AND is_read = 0'
    );
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

// ════════════════════════════════════════════════════════════
// ACCOUNT LINKS
// ════════════════════════════════════════════════════════════

function linkCaregiverToElder(int $elderUserId, int $caregiverUserId, string $relationshipType = 'caregiver'): array {
    try {
        getDB()->prepare(
            'INSERT INTO account_links (elder_user_id, caregiver_user_id, status) VALUES (?, ?, "pending")'
        )->execute([$elderUserId, $caregiverUserId]);
        return ['success' => true];
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['success' => false, 'message' => 'This relationship already exists.'];
        }
        return ['success' => false, 'message' => 'Failed to create link.'];
    }
}

function approveLink(int $linkId): void {
    getDB()->prepare('UPDATE account_links SET status = "active" WHERE link_id = ?')
           ->execute([$linkId]);
}

function revokeLink(int $linkId): void {
    // Hard delete — soft delete (status=revoked) blocks re-linking due to UNIQUE KEY.
    // Revoke history is no longer needed since billing proration was removed.
    getDB()->prepare('DELETE FROM account_links WHERE link_id = ?')
           ->execute([$linkId]);
}

function getLinksForElder(int $elderUserId): array {
    $stmt = getDB()->prepare(
        'SELECT al.*, u.full_name, u.email FROM account_links al
         JOIN users u ON al.caregiver_user_id = u.user_id
         WHERE al.elder_user_id = ?'
    );
    $stmt->execute([$elderUserId]);
    return $stmt->fetchAll();
}

function getLinksForCaregiver(int $caregiverId): array {
    $stmt = getDB()->prepare(
        'SELECT al.*, u.full_name, u.email FROM account_links al
         JOIN users u ON al.elder_user_id = u.user_id
         WHERE al.caregiver_user_id = ? AND al.status = "active"'
    );
    $stmt->execute([$caregiverId]);
    return $stmt->fetchAll();
}

// ════════════════════════════════════════════════════════════
// UTILITY
// ════════════════════════════════════════════════════════════

function e(string $val): string {
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

function riskBadge(float $probability): string {
    $level = $probability >= RISK_HIGH ? 'high' : ($probability >= RISK_MEDIUM ? 'medium' : 'low');
    $map   = [
        'high'   => ['High Risk',   'badge-danger'],
        'medium' => ['Medium Risk', 'badge-warning'],
        'low'    => ['Low Risk',    'badge-success'],
    ];
    [$label, $class] = $map[$level];
    return "<span class=\"badge {$class}\">{$label} (" . round($probability) . "%)</span>";
}

function timeAgo(string $datetime): string {
    $diff = (new DateTime())->diff(new DateTime($datetime));
    if ($diff->days > 30)  return (new DateTime($datetime))->format('M j, Y');
    if ($diff->days >= 1)  return $diff->days . 'd ago';
    if ($diff->h >= 1)     return $diff->h    . 'h ago';
    if ($diff->i >= 1)     return $diff->i    . 'm ago';
    return 'just now';
}

function formatCategory(string $category): string {
    return ucwords(str_replace('_', ' ', $category));
}