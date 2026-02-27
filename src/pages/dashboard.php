<?php
// pages/dashboard.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
$user = currentUser();
$db   = getDB();

// ── Stats ─────────────────────────────────────────────────────
if ($user['role'] === 'elder') {
    $incidents = getIncidentsByUser((int)$user['user_id'], 5);
    $totalStmt = $db->prepare('SELECT COUNT(*) FROM incidents WHERE user_id=?');
    $totalStmt->execute([$user['user_id']]);
    $totalIncidents = $totalStmt->fetchColumn();

    $highRiskStmt = $db->prepare(
        'SELECT COUNT(*) FROM incidents i JOIN analysis a ON i.incident_id=a.incident_id
         WHERE i.user_id=? AND a.scam_probability >= ?'
    );
    $highRiskStmt->execute([$user['user_id'], RISK_HIGH]);
    $highRiskCount = $highRiskStmt->fetchColumn();

} elseif ($user['role'] === 'caregiver') {
    $incidents    = getIncidentsForCaregiver((int)$user['user_id'], 10);
    $linkedElders = getLinksForCaregiver((int)$user['user_id']);
    $totalIncidents = count($incidents);

} elseif ($user['role'] === 'admin') {
    $incidents = getAllIncidents(10);

    $totalStmt = $db->query('SELECT COUNT(*) FROM incidents');
    $totalIncidents = $totalStmt->fetchColumn();

    $highRiskStmt = $db->prepare(
        'SELECT COUNT(*) FROM analysis WHERE scam_probability >= ?'
    );
    $highRiskStmt->execute([RISK_HIGH]);
    $highRiskCount = $highRiskStmt->fetchColumn();

    $userCountStmt = $db->query('SELECT COUNT(*) FROM users WHERE is_active=1');
    $totalUsers = $userCountStmt->fetchColumn();
}

$pageTitle = 'Dashboard';
include __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-wrap">
    <h1>Welcome back, <?= e($user['full_name']) ?>!</h1>

    <?php if ($user['role'] === 'elder'): ?>
    <!-- ═══════════════════════ ELDER VIEW ═══════════════════════ -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-number"><?= $totalIncidents ?></div>
            <div class="stat-label">Reports Submitted</div>
        </div>
        <div class="stat-card stat-danger">
            <div class="stat-number"><?= $highRiskCount ?></div>
            <div class="stat-label">High Risk Detected</div>
        </div>
    </div>

    <div class="action-banner">
        <p>Received a suspicious message, call, or email?</p>
        <a href="<?= APP_URL ?>/pages/submit.php" class="btn btn-primary btn-large">
            🚨 Report a Suspicious Message
        </a>
    </div>

    <h2>Your Recent Reports</h2>
    <?php if (empty($incidents)): ?>
        <div class="empty-state">
            <p>You haven't submitted any reports yet. That's great! If you ever receive a suspicious message, report it here.</p>
        </div>
    <?php else: ?>
    <div class="incident-list">
        <?php foreach ($incidents as $inc): ?>
        <div class="incident-card">
            <div class="incident-meta">
                <span><?= timeAgo($inc['submitted_at']) ?></span>
                <?php if ($inc['scam_probability'] !== null): ?>
                    <?= riskBadge((float)$inc['scam_probability']) ?>
                <?php else: ?>
                    <span class="badge badge-secondary">Analyzing...</span>
                <?php endif; ?>
            </div>
            <p class="incident-excerpt"><?= e(mb_substr($inc['content'], 0, 120)) ?>...</p>
            <a href="<?= APP_URL ?>/pages/incident_detail.php?id=<?= $inc['incident_id'] ?>" class="btn btn-sm">
                View Details
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <p><a href="<?= APP_URL ?>/pages/my_incidents.php">View all my reports →</a></p>
    <?php endif; ?>


    <?php elseif ($user['role'] === 'caregiver'): ?>
    <!-- ═══════════════════════ CAREGIVER VIEW ═══════════════════════ -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-number"><?= count($linkedElders) ?></div>
            <div class="stat-label">Linked Elders</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $totalIncidents ?></div>
            <div class="stat-label">Reported Incidents</div>
        </div>
    </div>

    <h2>Recent Incidents from Your Elders</h2>
    <?php if (empty($incidents)): ?>
        <div class="empty-state">
            <p>No incidents yet. <a href="<?= APP_URL ?>/pages/admin_users.php">Link an elder account</a> to start monitoring.</p>
        </div>
    <?php else: ?>
    <div class="incident-list">
        <?php foreach ($incidents as $inc): ?>
        <div class="incident-card <?= ($inc['scam_probability'] >= RISK_HIGH) ? 'incident-high-risk' : '' ?>">
            <div class="incident-meta">
                <strong><?= e($inc['full_name']) ?></strong>
                <span><?= timeAgo($inc['submitted_at']) ?></span>
                <?php if ($inc['scam_probability'] !== null): ?>
                    <?= riskBadge((float)$inc['scam_probability']) ?>
                <?php endif; ?>
            </div>
            <p class="incident-excerpt"><?= e(mb_substr($inc['content'], 0, 120)) ?>...</p>
            <a href="<?= APP_URL ?>/pages/incident_detail.php?id=<?= $inc['incident_id'] ?>" class="btn btn-sm">View</a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>


    <?php elseif ($user['role'] === 'admin'): ?>
    <!-- ═══════════════════════ ADMIN VIEW ═══════════════════════ -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-number"><?= $totalUsers ?></div>
            <div class="stat-label">Total Users</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $totalIncidents ?></div>
            <div class="stat-label">Total Incidents</div>
        </div>
        <div class="stat-card stat-danger">
            <div class="stat-number"><?= $highRiskCount ?></div>
            <div class="stat-label">High Risk Reports</div>
        </div>
    </div>

    <div class="admin-actions">
        <a href="<?= APP_URL ?>/pages/incidents.php" class="btn btn-primary">All Incidents</a>
        <a href="<?= APP_URL ?>/pages/admin_users.php" class="btn btn-secondary">Manage Users</a>
    </div>

    <h2>Latest Incidents</h2>
    <table class="data-table">
        <thead>
            <tr>
                <th>User</th><th>Submitted</th><th>Risk</th><th>Category</th><th>Status</th><th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($incidents as $inc): ?>
            <tr class="<?= ($inc['scam_probability'] >= RISK_HIGH) ? 'row-danger' : '' ?>">
                <td><?= e($inc['full_name']) ?></td>
                <td><?= timeAgo($inc['submitted_at']) ?></td>
                <td><?= $inc['scam_probability'] !== null ? riskBadge((float)$inc['scam_probability']) : '—' ?></td>
                <td><?= $inc['scam_category'] ? e(formatCategory($inc['scam_category'])) : '—' ?></td>
                <td><span class="status-badge status-<?= e($inc['status']) ?>"><?= e(ucfirst($inc['status'])) ?></span></td>
                <td><a href="<?= APP_URL ?>/pages/incident_detail.php?id=<?= $inc['incident_id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
