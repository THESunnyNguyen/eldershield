<?php
// database/seed.php — Run ONCE in browser to create demo users
// DELETE THIS FILE after running in production!
require_once __DIR__ . '/../config/db.php';

$db = getDB();
$demos = [
    ['Admin User',      'admin@eldershield.com', 'password123', 'admin',     'premium'],
    ['Dorothy Johnson', 'dorothy@example.com',   'password123', 'elder',     'free'],
    ['Sarah Johnson',   'sarah@example.com',     'password123', 'caregiver', 'free'],
];

foreach ($demos as [$name, $email, $pass, $role, $plan]) {
    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare(
        'INSERT INTO users (full_name, email, password_hash, role, plan)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), plan = VALUES(plan)'
    )->execute([$name, $email, $hash, $role, $plan]);
    echo "✅ $email ($role / $plan)<br>";
}

// Link Dorothy → Sarah
$elderStmt = $db->prepare('SELECT user_id FROM users WHERE email = ?');
$elderStmt->execute(['dorothy@example.com']);
$elderId = $elderStmt->fetchColumn();

$cgStmt = $db->prepare('SELECT user_id FROM users WHERE email = ?');
$cgStmt->execute(['sarah@example.com']);
$cgId = $cgStmt->fetchColumn();

if ($elderId && $cgId) {
    $db->prepare(
        'INSERT IGNORE INTO account_links (elder_user_id, caregiver_user_id, status)
         VALUES (?, ?, "active")'
    )->execute([$elderId, $cgId]);
    echo "✅ Linked Dorothy → Sarah<br>";
}

echo "<br><strong style='color:red'>⚠️ Delete this file now! (database/seed.php)</strong>";
