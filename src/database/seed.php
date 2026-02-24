<?php
// database/seed.php
// Run this ONCE in the browser to generate proper password hashes and insert demo users.
// DELETE THIS FILE after running it in production!

require_once __DIR__ . '/../config/db.php';

$demoUsers = [
    ['Admin User',      'admin@eldershield.com',   'password123', 'admin'],
    ['Dorothy Johnson', 'dorothy@example.com',      'password123', 'elder'],
    ['Sarah Johnson',   'sarah@example.com',        'password123', 'caregiver'],
];

$db = getDB();

foreach ($demoUsers as [$name, $email, $pass, $role]) {
    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $db->prepare(
        'INSERT INTO users (full_name, email, password_hash, role)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)'
    );
    $stmt->execute([$name, $email, $hash, $role]);
    echo "✅ Created/updated: $email ($role)<br>";
}

// Link Dorothy (elder) to Sarah (caregiver)
$elderStmt = $db->prepare('SELECT user_id FROM users WHERE email=?');
$elderStmt->execute(['dorothy@example.com']);
$elderId = $elderStmt->fetchColumn();

$cgStmt = $db->prepare('SELECT user_id FROM users WHERE email=?');
$cgStmt->execute(['sarah@example.com']);
$cgId = $cgStmt->fetchColumn();

if ($elderId && $cgId) {
    $db->prepare(
        'INSERT IGNORE INTO account_links (elder_user_id, caregiver_user_id, relationship_type, status)
         VALUES (?, ?, "family", "active")'
    )->execute([$elderId, $cgId]);
    echo "✅ Linked Dorothy → Sarah<br>";
}

echo "<br><strong>⚠️ Delete this file now! (database/seed.php)</strong>";
