<?php
// pages/my_incidents.php — Elder views their own report history
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
requireRole('elder');

$user      = currentUser();
$incidents = getIncidentsByUser((int)$user['user_id'], 50);

$pageTitle = 'My Reports';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-container">
    <div class="page-header">
        <h1>My Scam Reports</h1>
        <a href="<?= APP_URL ?>/pages/submit.php" class="btn btn-primary">+ New Report</a>
    </div>

    <?php if (empty($incidents)): ?>
        <div class="empty-state">
            <h2>No reports yet</h2>
            <p>If you receive a suspicious call, text, or email, report it here and we'll check it for you.</p>
            <a href="<?= APP_URL ?>/pages/submit.php" class="btn btn-primary btn-large">Report Something Suspicious</a>
        </div>
    <?php else: ?>
        <div class="incident-list">
        <?php foreach ($incidents as $inc): ?>
            <div class="incident-card <?= ($inc['scam_probability'] >= RISK_HIGH) ? 'incident-high-risk' : '' ?>">
                <div class="incident-meta">
                    <span class="incident-date"><?= date('M j, Y', strtotime($inc['submitted_at'])) ?></span>
                    <?php if ($inc['scam_probability'] !== null): ?>
                        <?= riskBadge((float)$inc['scam_probability']) ?>
                        <span class="incident-category"><?= e(formatCategory($inc['scam_category'] ?? '')) ?></span>
                    <?php else: ?>
                        <span class="badge badge-secondary">Pending Analysis</span>
                    <?php endif; ?>
                    <span class="status-badge status-<?= e($inc['status']) ?>"><?= e(ucfirst($inc['status'])) ?></span>
                </div>
                <p class="incident-excerpt"><?= e(mb_substr($inc['content'], 0, 150)) ?>…</p>
                <a href="<?= APP_URL ?>/pages/incident_detail.php?id=<?= $inc['incident_id'] ?>"
                   class="btn btn-sm">See Full Analysis</a>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
