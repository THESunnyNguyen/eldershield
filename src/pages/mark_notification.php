<?php
// pages/mark_notification.php — Toggle notification read/unread state
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
$user = currentUser();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$notifId = (int)($body['notification_id'] ?? 0);
$isRead  = isset($body['is_read']) ? (int)(bool)$body['is_read'] : null;

if (!$notifId || $isRead === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing notification_id or is_read']);
    exit;
}

$db = getDB();

// Ensure the notification belongs to the current user before updating
$stmt = $db->prepare(
    'UPDATE notifications SET is_read = ?
     WHERE notification_id = ? AND recipient_user_id = ?'
);
$stmt->execute([$isRead, $notifId, $user['user_id']]);

if ($stmt->rowCount() === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Notification not found or access denied']);
    exit;
}

echo json_encode(['success' => true, 'is_read' => $isRead]);
