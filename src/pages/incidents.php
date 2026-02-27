<?php
// pages/incidents.php — Admin/caregiver view of all incidents
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
requireRole(['admin','caregiver']);

$user = currentUser();

// Filters
$filterRisk   = $_GET['risk']   ?? '';
$filterStatus = $_GET['status'] ?? '';
$search       = trim($_GET['search'] ?? '');

$db = getDB();

// Build query dynamically
$where  = [];
$params = [];

if ($user['role'] === 'caregiver') {
    $where[]  = 'al.caregiver_user_id = ? AND al.status = "active"';
    $params[] = $user['user_id'];
    $joinAL   = 'JOIN account_links al ON al.elder_user_id = i.user_id';
} else {
    $joinAL = '';
}

if ($filterStatus) {
    $where[]  = 'i.status = ?';
    $params[] = $filterStatus;
}
if ($filterRisk === 'high') {
    $where[]  = 'a.scam_probability >= ?';
    $params[] = RISK_HIGH;
} elseif ($filterRisk === 'medium') {
    $where[]  = 'a.scam_probability >= ? AND a.scam_probability < ?';
    $params[] = RISK_MEDIUM;
    $params[] = RISK_HIGH;
} elseif ($filterRisk === 'low') {
    $where[]  = '(a.scam_probability < ? OR a.scam_probability IS NULL)';
    $params[] = RISK_MEDIUM;
}
if ($search) {
    $where[]  = '(u.full_name LIKE ? OR u.email LIKE ? OR i.content LIKE ?)';
    $s = "%$search%";
    $params[] = $s; $params[] = $s; $params[] = $s;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT i.*, u.full_name, u.email, a.scam_probability, a.scam_category
        FROM incidents i
        JOIN users u ON i.user_id = u.user_id
        LEFT JOIN analysis a ON i.incident_id = a.incident_id
        $joinAL
        $whereSql
        ORDER BY i.submitted_at DESC
        LIMIT 100";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$incidents = $stmt->fetchAll();

$pageTitle = 'All Incidents';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-container">
    <div class="page-header">
        <h1>Incident Reports</h1>
        <span class="count-badge"><?= count($incidents) ?> results</span>
    </div>

    <!-- Filters -->
    <form method="GET" class="filter-bar">
        <input type="text" name="search" placeholder="Search by name, email, content..."
               value="<?= e($search) ?>">
        <select name="risk">
            <option value="">All Risk Levels</option>
            <option value="high"   <?= $filterRisk==='high'   ? 'selected':'' ?>>High Risk</option>
            <option value="medium" <?= $filterRisk==='medium' ? 'selected':'' ?>>Medium Risk</option>
            <option value="low"    <?= $filterRisk==='low'    ? 'selected':'' ?>>Low Risk</option>
        </select>
        <select name="status">
            <option value="">All Statuses</option>
            <?php foreach (['pending','analyzed','reviewed','dismissed'] as $s): ?>
                <option value="<?= $s ?>" <?= $filterStatus===$s ? 'selected':'' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary">Filter</button>
        <a href="<?= APP_URL ?>/pages/incidents.php" class="btn btn-outline">Reset</a>
    </form>

    <?php if (empty($incidents)): ?>
        <div class="empty-state"><p>No incidents match your filters.</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>User</th>
                    <th>Submitted</th>
                    <th>Risk Score</th>
                    <th>Category</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($incidents as $inc): ?>
                <tr class="<?= $inc['scam_probability'] >= RISK_HIGH ? 'row-danger' : '' ?>">
                    <td><?= $inc['incident_id'] ?></td>
                    <td>
                        <strong><?= e($inc['full_name']) ?></strong><br>
                        <small><?= e($inc['email']) ?></small>
                    </td>
                    <td><?= date('M j, Y', strtotime($inc['submitted_at'])) ?><br>
                        <small><?= date('g:i A', strtotime($inc['submitted_at'])) ?></small>
                    </td>
                    <td><?= $inc['scam_probability'] !== null ? riskBadge((float)$inc['scam_probability']) : '<span class="badge">Pending</span>' ?></td>
                    <td><?= $inc['scam_category'] ? e(formatCategory($inc['scam_category'])) : '—' ?></td>
                    <td><span class="status-badge status-<?= e($inc['status']) ?>"><?= e(ucfirst($inc['status'])) ?></span></td>
                    <td>
                        <a href="<?= APP_URL ?>/pages/incident_detail.php?id=<?= $inc['incident_id'] ?>"
                           class="btn btn-sm">View</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
