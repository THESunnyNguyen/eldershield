<?php
// database/seed.php — Run ONCE in browser to create demo users
// DELETE THIS FILE after running in production!
require_once __DIR__ . '/../config/db.php';

$db = getDB();

// ============================================================
// USERS
// Format: [full_name, email, password, role, plan]
// ============================================================
$users = [
    // ── Original demo accounts ──────────────────────────────
    ['Admin User',        'admin@eldershield.com',  'acrobat', 'admin',     'premium'],
    ['Dorothy Johnson',   'dorothy@example.com',    'acrobat', 'elder',     'free'],
    ['Sarah Johnson',     'sarah@example.com',      'acrobat', 'caregiver', 'free'],

    // ── Required test accounts ───────────────────────────────
    ['Bob Smith',         'bsmith@eldershield.com', 'mysecret',    'admin',     'premium'],
    ['Patricia Jones',    'pjones@example.com',     'acrobat',     'elder',     'free'],

    // ── Additional elders ────────────────────────────────────
    ['Harold Turner',     'hturner@example.com',    'acrobat', 'elder',     'free'],
    ['Evelyn Martinez',   'emartinez@example.com',  'acrobat', 'elder',     'free'],
    ['Walter Nguyen',     'wnguyen@example.com',    'acrobat', 'elder',     'free'],
    ['Betty Kowalski',    'bkowalski@example.com',  'acrobat', 'elder',     'free'],
    ['Raymond Chen',      'rchen@example.com',      'acrobat', 'elder',     'free'],
    ['Gloria Okafor',     'gokafor@example.com',    'acrobat', 'elder',     'free'],
    ['Franklin Patel',    'fpatel@example.com',     'acrobat', 'elder',     'free'],
    // Elders with NO caregiver links
    ['Mildred Haynes',    'mhaynes@example.com',    'acrobat', 'elder',     'free'],
    ['Chester Bloom',     'cbloom@example.com',     'acrobat', 'elder',     'free'],

    // ── Caregivers — premium, unlimited links ────────────────
    ['Michael Johnson',   'mjohnson@example.com',   'acrobat', 'caregiver', 'premium'],
    ['Angela Rivera',     'arivera@example.com',    'acrobat', 'caregiver', 'premium'],

    // ── Caregivers — free plan (max 2 links) ─────────────────
    ['Tom Bradley',       'tbradley@example.com',   'acrobat', 'caregiver', 'free'],
    ['Linda Park',        'lpark@example.com',      'acrobat', 'caregiver', 'free'],

    // ── Caregiver — free plan, only 1 link ───────────────────
    ['James Osei',        'josei@example.com',      'acrobat', 'caregiver', 'free'],
];

echo "<h2>👤 Creating Users</h2>";
foreach ($users as [$name, $email, $pass, $role, $plan]) {
    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare(
        'INSERT INTO users (full_name, email, password_hash, role, plan)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), plan = VALUES(plan)'
    )->execute([$name, $email, $hash, $role, $plan]);
    $planLabel = $plan === 'premium' ? '⭐ premium' : 'free';
    echo "✅ {$email} ({$role} / {$planLabel})<br>";
}

// ============================================================
// Helper: look up user_id by email
// ============================================================
function uid(PDO $db, string $email): ?int {
    $s = $db->prepare('SELECT user_id FROM users WHERE email = ?');
    $s->execute([$email]);
    $v = $s->fetchColumn();
    return $v ? (int)$v : null;
}

// ============================================================
// Helper: create a caregiver -> elder link
// ============================================================
function addLink(PDO $db, string $cgEmail, string $elderEmail, string $status = 'active'): void {
    $cgId    = uid($db, $cgEmail);
    $elderId = uid($db, $elderEmail);
    if (!$cgId || !$elderId) {
        echo "⚠️  Could not link {$cgEmail} → {$elderEmail} (user not found)<br>";
        return;
    }
    $db->prepare(
        'INSERT IGNORE INTO account_links (elder_user_id, caregiver_user_id, status)
         VALUES (?, ?, ?)'
    )->execute([$elderId, $cgId, $status]);
    echo "🔗 Linked {$cgEmail} → {$elderEmail} ({$status})<br>";
}

// ============================================================
// ACCOUNT LINKS
// ============================================================
echo "<h2>🔗 Creating Account Links</h2>";

// Original link
addLink($db, 'sarah@example.com',    'dorothy@example.com');

// Michael Johnson (premium) — 4 elders
addLink($db, 'mjohnson@example.com', 'dorothy@example.com');
addLink($db, 'mjohnson@example.com', 'hturner@example.com');
addLink($db, 'mjohnson@example.com', 'emartinez@example.com');
addLink($db, 'mjohnson@example.com', 'wnguyen@example.com');

// Angela Rivera (premium) — 3 elders
addLink($db, 'arivera@example.com',  'bkowalski@example.com');
addLink($db, 'arivera@example.com',  'rchen@example.com');
addLink($db, 'arivera@example.com',  'gokafor@example.com');

// Sarah Johnson (free) — 2 elders (at limit, dorothy already linked above)
addLink($db, 'sarah@example.com',    'fpatel@example.com');

// Tom Bradley (free) — 2 elders (at limit)
addLink($db, 'tbradley@example.com', 'hturner@example.com');
addLink($db, 'tbradley@example.com', 'rchen@example.com');

// Linda Park (free) — 2 elders (at limit)
addLink($db, 'lpark@example.com',    'emartinez@example.com');
addLink($db, 'lpark@example.com',    'pjones@example.com');

// James Osei (free) — only 1 elder linked
addLink($db, 'josei@example.com',    'bkowalski@example.com');

// Mildred Haynes and Chester Bloom intentionally have NO links
echo "ℹ️  mhaynes@example.com — no caregiver links (intentional)<br>";
echo "ℹ️  cbloom@example.com  — no caregiver links (intentional)<br>";

// ============================================================
// SUMMARY TABLE
// ============================================================
echo "<br><h2>📊 Summary</h2>";
echo "<table border='1' cellpadding='6' cellspacing='0' style='border-collapse:collapse;font-family:monospace;font-size:.9em'>";
echo "<tr style='background:#ddd'><th>Email</th><th>Role</th><th>Plan</th><th>Password</th><th>Notes</th></tr>";

$rows = [
    ['admin@eldershield.com',  'admin',     'premium', 'acrobat', 'Original admin'],
    ['bsmith@eldershield.com', 'admin',     'premium', 'mysecret',    'Test admin — Bob Smith'],
    ['dorothy@example.com',    'elder',     'free',    'acrobat', 'Linked to sarah, mjohnson'],
    ['pjones@example.com',     'elder',     'free',    'acrobat',     'Test elder — linked to lpark'],
    ['hturner@example.com',    'elder',     'free',    'acrobat', 'Linked to mjohnson, tbradley'],
    ['emartinez@example.com',  'elder',     'free',    'acrobat', 'Linked to mjohnson, lpark'],
    ['wnguyen@example.com',    'elder',     'free',    'acrobat', 'Linked to mjohnson'],
    ['bkowalski@example.com',  'elder',     'free',    'acrobat', 'Linked to arivera, josei'],
    ['rchen@example.com',      'elder',     'free',    'acrobat', 'Linked to arivera, tbradley'],
    ['gokafor@example.com',    'elder',     'free',    'acrobat', 'Linked to arivera'],
    ['fpatel@example.com',     'elder',     'free',    'acrobat', 'Linked to sarah'],
    ['mhaynes@example.com',    'elder',     'free',    'acrobat', 'NO caregiver links'],
    ['cbloom@example.com',     'elder',     'free',    'acrobat', 'NO caregiver links'],
    ['sarah@example.com',      'caregiver', 'free',    'acrobat', '2 links — at free limit'],
    ['mjohnson@example.com',   'caregiver', 'premium', 'acrobat', '4 links — premium unlimited'],
    ['arivera@example.com',    'caregiver', 'premium', 'acrobat', '3 links — premium unlimited'],
    ['tbradley@example.com',   'caregiver', 'free',    'acrobat', '2 links — at free limit'],
    ['lpark@example.com',      'caregiver', 'free',    'acrobat', '2 links — at free limit'],
    ['josei@example.com',      'caregiver', 'free',    'acrobat', '1 link only'],
];

foreach ($rows as [$email, $role, $plan, $pass, $notes]) {
    $bg = match($role) { 'admin' => '#fff3cd', 'caregiver' => '#d1ecf1', default => '#f8f9fa' };
    echo "<tr style='background:{$bg}'>"
       . "<td>{$email}</td><td>{$role}</td><td>{$plan}</td>"
       . "<td><code>{$pass}</code></td><td>{$notes}</td></tr>";
}
echo "</table>";

echo "<br><strong style='color:red;font-size:1.1em'>⚠️ Delete this file now! (database/seed.php)</strong>";