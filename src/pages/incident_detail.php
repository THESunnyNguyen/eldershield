<?php
// pages/incident_detail.php — Full AI analysis result view
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/ai_service.php';

requireLogin();
$user       = currentUser();
$incidentId = (int)($_GET['id'] ?? 0);

if (!$incidentId) {
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

$incident = getIncidentById($incidentId);
if (!$incident) {
    setFlash('danger', 'Incident not found.');
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

$db = getDB();

// Access control
if ($user['role'] === 'elder' && $incident['user_id'] != $user['user_id']) {
    header('Location: ' . APP_URL . '/pages/unauthorized.php');
    exit;
}
if ($user['role'] === 'caregiver') {
    $check = $db->prepare(
        'SELECT link_id FROM account_links
         WHERE elder_user_id=? AND caregiver_user_id=? AND status="active"'
    );
    $check->execute([$incident['user_id'], $user['user_id']]);
    if (!$check->fetch()) {
        header('Location: ' . APP_URL . '/pages/unauthorized.php');
        exit;
    }
}

// Handle POST actions (admin/caregiver)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($user['role'], ['admin','caregiver'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid form token.');
    } else {
        $action = $_POST['action'] ?? 'update_status';

        // Update incident status
        if ($action === 'update_status') {
            $newStatus = $_POST['status'] ?? '';
            if ($newStatus) {
                updateIncidentStatus($incidentId, $newStatus);
                setFlash('success', 'Status updated.');
            }
        }

        // Admin: override scam category
        if ($action === 'update_category' && $user['role'] === 'admin') {
            $category = trim($_POST['scam_category'] ?? '');
            if ($category) {
                updateScamCategory($incidentId, $category);
                setFlash('success', 'Scam category updated.');
            } else {
                setFlash('danger', 'Category cannot be empty.');
            }
        }

        // Admin: delete analysis
        if ($action === 'delete_analysis' && $user['role'] === 'admin') {
            deleteAnalysis($incidentId);
            setFlash('success', 'Analysis deleted. Incident reset to pending.');
        }
    }
    header('Location: ' . APP_URL . '/pages/incident_detail.php?id=' . $incidentId);
    exit;
}

$analysisReady = ($incident['scam_probability'] !== null);
$probability   = (float)($incident['scam_probability'] ?? 0);
$riskLevel     = getRiskLevel($probability);
$tactics       = json_decode($incident['manipulation_tactics'] ?? '[]', true) ?: [];

$ownerStmt = $db->prepare('SELECT full_name FROM users WHERE user_id=?');
$ownerStmt->execute([$incident['user_id']]);
$ownerName = $ownerStmt->fetchColumn();

$pageTitle = 'Incident #' . $incidentId;
include __DIR__ . '/../includes/header.php';
?>

<div class="detail-container">

    <div class="detail-header">
        <a href="<?= APP_URL ?>/pages/<?= $user['role'] === 'elder' ? 'my_incidents' : 'incidents' ?>.php"
           class="back-link">&larr; Back to Reports</a>
        <h1>Scam Report #<?= $incidentId ?></h1>
        <div class="detail-meta">
            <span>Submitted by <strong><?= e($ownerName) ?></strong></span>
            <span><?= date('F j, Y g:i A', strtotime($incident['submitted_at'])) ?></span>
            <span class="status-badge status-<?= e($incident['status']) ?>"><?= e(ucfirst($incident['status'])) ?></span>
        </div>
    </div>

    <?php if ($analysisReady): ?>
    <!-- ── AI RESULT ─────────────────────────────────────────── -->
    <div class="result-card result-<?= $riskLevel ?>">
        <div class="result-score-area">
            <div class="risk-gauge">
                <div class="gauge-fill" style="width: <?= round($probability) ?>%"></div>
            </div>
            <div class="risk-score"><?= round($probability) ?>%</div>
            <div class="risk-label"><?= getRiskLabel($riskLevel) ?></div>
        </div>

        <div class="result-details">
            <div class="result-section">
                <h3>&#x1F4CB; Scam Type</h3>
                <p class="scam-category">
                    <?= e(formatCategory($incident['scam_category'] ?? 'Unknown')) ?>
                    <?php if (!empty($incident['admin_override'])): ?>
                        <span class="badge badge-secondary" style="font-size:.75rem;">Admin Override</span>
                    <?php endif; ?>
                </p>
            </div>

            <?php if (!empty($tactics)): ?>
            <div class="result-section">
                <h3>&#x1F3AF; Tactics Detected</h3>
                <ul class="tactics-list">
                    <?php foreach ($tactics as $tactic): ?>
                        <li><?= e(formatCategory($tactic)) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="result-section">
                <h3>&#x1F4AC; What This Means</h3>
                <p class="explanation-text"><?= e($incident['explanation_simple'] ?? '') ?></p>
            </div>

            <div class="result-section">
                <h3>&#x2705; What You Should Do</h3>
                <div class="action-box">
                    <?= nl2br(e($incident['recommended_action'] ?? '')) ?>
                </div>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- ── PENDING — auto-refresh every 5 seconds ────────────── -->
    <div class="alert alert-info analyzing-banner">
        <span class="analyzing-spinner">&#9203;</span>
        <strong>Analysis in progress...</strong>
        Your report has been received and our AI is analyzing it now.
        This page will update automatically — no need to do anything.
    </div>
    <?php endif; ?>

    <!-- ── ORIGINAL SUBMISSION ───────────────────────────────── -->
    <div class="submission-card">
        <h2>Original Submission</h2>
        <div class="submission-content">
            <?= nl2br(e($incident['content'])) ?>
        </div>

        <?php if ($incident['image_path'] && file_exists($incident['image_path'])): ?>
        <div class="submission-image">
            <h3>Attached Screenshot</h3>
            <img src="<?= APP_URL ?>/uploads/<?= e(basename($incident['image_path'])) ?>"
                 alt="Submitted screenshot" class="screenshot-img">
        </div>
        <?php endif; ?>
    </div>

    <!-- ── ADMIN/CAREGIVER CONTROLS ──────────────────────────── -->
    <?php if (in_array($user['role'], ['admin','caregiver'])): ?>
    <div class="admin-controls">
        <h2>Update Status</h2>
        <form method="POST" action="">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_status">
            <div class="form-inline">
                <select name="status">
                    <?php foreach (['pending','analyzed','reviewed','dismissed'] as $s): ?>
                        <option value="<?= $s ?>" <?= $incident['status']==$s ? 'selected':'' ?>>
                            <?= ucfirst($s) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-secondary">Update Status</button>
            </div>
        </form>

        <?php if ($user['role'] === 'admin' && $analysisReady): ?>
        <!-- ── Admin: Override Scam Category ────────────────────── -->
        <div style="margin-top:1.5rem;">
            <h2>Override Scam Category</h2>
            <form method="POST" action="">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_category">
                <div class="form-inline">
                    <select name="scam_category">
                        <?php
                        $categories = ['phishing','impersonation','romance_scam','tech_support',
                                       'lottery_prize','grandparent_scam','investment_fraud','other','not_a_scam'];
                        foreach ($categories as $cat): ?>
                            <option value="<?= $cat ?>" <?= ($incident['scam_category'] ?? '') === $cat ? 'selected' : '' ?>>
                                <?= e(formatCategory($cat)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-secondary">Update Category</button>
                </div>
            </form>
        </div>

        <!-- ── Admin: Delete Analysis ───────────────────────────── -->
        <div style="margin-top:1.5rem;">
            <h2>Delete Analysis</h2>
            <p style="color:var(--color-muted); font-size:.9rem; margin-bottom:.75rem;">
                This will remove the AI analysis and reset the incident to "pending" status.
            </p>
            <form method="POST" action=""
                  onsubmit="return confirm('Delete the analysis for this incident? The incident will be reset to pending.')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_analysis">
                <button type="submit" class="btn btn-danger">Delete Analysis</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($user['role'] === 'admin'): ?>
        <div class="danger-zone" style="margin-top:1.5rem;">
            <a href="<?= APP_URL ?>/api/delete_incident.php?id=<?= $incidentId ?>&csrf=<?= urlencode(csrfToken()) ?>"
               class="btn btn-danger"
               onclick="return confirm('Permanently delete this incident and all associated data?')">
                &#x1F5D1;&#xFE0F; Delete Entire Incident
            </a>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── ELDER DELETE ───────────────────────────────────────── -->
    <?php if ($user['role'] === 'elder' && $incident['user_id'] == $user['user_id']): ?>
    <div class="elder-controls">
        <a href="<?= APP_URL ?>/api/delete_incident.php?id=<?= $incidentId ?>&csrf=<?= urlencode(csrfToken()) ?>"
           class="btn btn-outline-danger btn-sm"
           onclick="return confirm('Remove this report from your history?')">
            Remove This Report
        </a>
    </div>
    <?php endif; ?>

</div>

<?php if (!$analysisReady): ?>
<script>
// Auto-refresh every 5 seconds until analysis is ready
setTimeout(function() { window.location.reload(); }, 5000);
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
