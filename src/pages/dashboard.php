<?php
// pages/dashboard.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
$user = currentUser();
$db   = getDB();

// ── Stats + analytics data ────────────────────────────────────
if ($user['role'] === 'elder') {
    $incidents = getIncidentsByUser((int)$user['user_id'], 5);

    $totalStmt = $db->prepare('SELECT COUNT(*) FROM incidents WHERE user_id=?');
    $totalStmt->execute([$user['user_id']]);
    $totalIncidents = (int)$totalStmt->fetchColumn();

    $highRiskStmt = $db->prepare(
        'SELECT COUNT(*) FROM incidents i JOIN analysis a ON i.incident_id=a.incident_id
         WHERE i.user_id=? AND a.scam_probability >= ?'
    );
    $highRiskStmt->execute([$user['user_id'], RISK_HIGH]);
    $highRiskCount = (int)$highRiskStmt->fetchColumn();

} elseif ($user['role'] === 'caregiver') {
    $incidents    = getIncidentsForCaregiver((int)$user['user_id'], 10);
    $linkedElders = getLinksForCaregiver((int)$user['user_id']);
    $totalIncidents = count($incidents);

    // 7-day daily counts for caregiver's elders
    $cgChart = $db->prepare(
        'SELECT DATE(i.submitted_at) AS day, COUNT(*) AS total,
                SUM(CASE WHEN a.scam_probability >= ? THEN 1 ELSE 0 END) AS high_risk,
                SUM(CASE WHEN a.scam_probability >= ? AND a.scam_probability < ? THEN 1 ELSE 0 END) AS medium_risk
         FROM incidents i
         LEFT JOIN analysis a ON i.incident_id = a.incident_id
         JOIN account_links al ON al.elder_user_id = i.user_id
         WHERE al.caregiver_user_id = ? AND al.status = "active"
           AND i.submitted_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
         GROUP BY DATE(i.submitted_at)
         ORDER BY day ASC'
    );
    $cgChart->execute([RISK_HIGH, RISK_MEDIUM, RISK_HIGH, $user['user_id']]);
    $cgChartRaw = $cgChart->fetchAll();

    // Fill all 7 days (including zeros)
    $cgDays = [];
    for ($i = 6; $i >= 0; $i--) {
        $cgDays[date('Y-m-d', strtotime("-{$i} days"))] = ['total' => 0, 'high_risk' => 0, 'medium_risk' => 0];
    }
    foreach ($cgChartRaw as $row) {
        if (isset($cgDays[$row['day']])) {
            $cgDays[$row['day']] = [
                'total'       => (int)$row['total'],
                'high_risk'   => (int)$row['high_risk'],
                'medium_risk' => (int)$row['medium_risk'],
            ];
        }
    }

    // Category breakdown (last 7 days)
    $cgCatStmt = $db->prepare(
        'SELECT a.scam_category, COUNT(*) AS cnt
         FROM incidents i
         JOIN analysis a ON i.incident_id = a.incident_id
         JOIN account_links al ON al.elder_user_id = i.user_id
         WHERE al.caregiver_user_id = ? AND al.status = "active"
           AND i.submitted_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
           AND a.scam_category IS NOT NULL
         GROUP BY a.scam_category
         ORDER BY cnt DESC LIMIT 6'
    );
    $cgCatStmt->execute([$user['user_id']]);
    $cgCategories = $cgCatStmt->fetchAll();

    // High-risk count this week
    $cgHighWeekStmt = $db->prepare(
        'SELECT COUNT(*) FROM incidents i
         JOIN analysis a ON i.incident_id = a.incident_id
         JOIN account_links al ON al.elder_user_id = i.user_id
         WHERE al.caregiver_user_id = ? AND al.status = "active"
           AND a.scam_probability >= ?
           AND i.submitted_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)'
    );
    $cgHighWeekStmt->execute([$user['user_id'], RISK_HIGH]);
    $cgHighWeek = (int)$cgHighWeekStmt->fetchColumn();

} elseif ($user['role'] === 'admin') {
    $incidents = getAllIncidents(10);

    $totalStmt = $db->query('SELECT COUNT(*) FROM incidents');
    $totalIncidents = (int)$totalStmt->fetchColumn();

    $highRiskStmt = $db->prepare('SELECT COUNT(*) FROM analysis WHERE scam_probability >= ?');
    $highRiskStmt->execute([RISK_HIGH]);
    $highRiskCount = (int)$highRiskStmt->fetchColumn();

    $userCountStmt = $db->query('SELECT COUNT(*) FROM users WHERE is_active=1');
    $totalUsers = (int)$userCountStmt->fetchColumn();

    // 30-day daily counts
    $adminDailyStmt = $db->prepare(
        'SELECT DATE(i.submitted_at) AS day, COUNT(*) AS total,
                SUM(CASE WHEN a.scam_probability >= ? THEN 1 ELSE 0 END) AS high_risk,
                SUM(CASE WHEN a.scam_probability >= ? AND a.scam_probability < ? THEN 1 ELSE 0 END) AS medium_risk
         FROM incidents i
         LEFT JOIN analysis a ON i.incident_id = a.incident_id
         WHERE i.submitted_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
         GROUP BY DATE(i.submitted_at)
         ORDER BY day ASC'
    );
    $adminDailyStmt->execute([RISK_HIGH, RISK_MEDIUM, RISK_HIGH]);
    $adminDailyRaw = $adminDailyStmt->fetchAll();

    // Fill all 30 days
    $adminDays = [];
    for ($i = 29; $i >= 0; $i--) {
        $adminDays[date('Y-m-d', strtotime("-{$i} days"))] = ['total' => 0, 'high_risk' => 0, 'medium_risk' => 0];
    }
    foreach ($adminDailyRaw as $row) {
        if (isset($adminDays[$row['day']])) {
            $adminDays[$row['day']] = [
                'total'       => (int)$row['total'],
                'high_risk'   => (int)$row['high_risk'],
                'medium_risk' => (int)$row['medium_risk'],
            ];
        }
    }

    // Category breakdown (last 30 days)
    $adminCatStmt = $db->prepare(
        'SELECT a.scam_category, COUNT(*) AS cnt
         FROM incidents i
         JOIN analysis a ON i.incident_id = a.incident_id
         WHERE i.submitted_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
           AND a.scam_category IS NOT NULL
         GROUP BY a.scam_category ORDER BY cnt DESC LIMIT 8'
    );
    $adminCatStmt->execute();
    $adminCategories = $adminCatStmt->fetchAll();

    // This month vs last month
    $monthStmt = $db->query(
        'SELECT
           SUM(CASE WHEN submitted_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01") THEN 1 ELSE 0 END) AS this_month,
           SUM(CASE WHEN submitted_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), "%Y-%m-01")
                     AND submitted_at <  DATE_FORMAT(CURDATE(), "%Y-%m-01") THEN 1 ELSE 0 END) AS last_month
         FROM incidents'
    );
    $monthData   = $monthStmt->fetch();
    $thisMonth   = (int)$monthData['this_month'];
    $lastMonth   = (int)$monthData['last_month'];
    $monthChange = $lastMonth > 0 ? round((($thisMonth - $lastMonth) / $lastMonth) * 100) : null;

    // Risk distribution (all-time analyzed)
    $riskDistStmt = $db->prepare(
        'SELECT
           SUM(CASE WHEN scam_probability >= ? THEN 1 ELSE 0 END) AS high,
           SUM(CASE WHEN scam_probability >= ? AND scam_probability < ? THEN 1 ELSE 0 END) AS medium,
           SUM(CASE WHEN scam_probability < ? THEN 1 ELSE 0 END) AS low
         FROM analysis'
    );
    $riskDistStmt->execute([RISK_HIGH, RISK_MEDIUM, RISK_HIGH, RISK_MEDIUM]);
    $riskDist = $riskDistStmt->fetch();
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
            <div class="stat-label">Total Incidents</div>
        </div>
        <div class="stat-card stat-danger">
            <div class="stat-number"><?= $cgHighWeek ?></div>
            <div class="stat-label">High Risk This Week</div>
        </div>
    </div>

    <!-- 7-Day Analytics -->
    <div class="analytics-section">
        <h2 class="analytics-heading">📊 Activity — Last 7 Days</h2>
        <div class="analytics-grid">

            <!-- Stacked bar: daily incidents -->
            <div class="chart-card chart-wide">
                <div class="chart-title">Daily Incidents</div>
                <?php $cgMax = max(1, max(array_column($cgDays, 'total'))); ?>
                <div class="bar-chart">
                    <?php foreach ($cgDays as $date => $d): ?>
                    <div class="bar-group">
                        <div class="bar-stack" style="height:120px;">
                            <?php if ($d['total'] > 0):
                                $low = $d['total'] - $d['high_risk'] - $d['medium_risk'];
                            ?>
                                <?php if ($d['high_risk'] > 0): ?>
                                <div class="bar-seg bar-high" style="height:<?= round(($d['high_risk']/$cgMax)*110) ?>px" title="<?= $d['high_risk'] ?> high risk"></div>
                                <?php endif; ?>
                                <?php if ($d['medium_risk'] > 0): ?>
                                <div class="bar-seg bar-medium" style="height:<?= round(($d['medium_risk']/$cgMax)*110) ?>px" title="<?= $d['medium_risk'] ?> medium risk"></div>
                                <?php endif; ?>
                                <?php if ($low > 0): ?>
                                <div class="bar-seg bar-low" style="height:<?= round(($low/$cgMax)*110) ?>px" title="<?= $low ?> low risk"></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="bar-empty"></div>
                            <?php endif; ?>
                        </div>
                        <div class="bar-value"><?= $d['total'] ?: '' ?></div>
                        <div class="bar-label"><?= date('D', strtotime($date)) ?><br><small><?= date('M j', strtotime($date)) ?></small></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="chart-legend">
                    <span class="legend-dot legend-high"></span> High Risk &nbsp;
                    <span class="legend-dot legend-medium"></span> Medium &nbsp;
                    <span class="legend-dot legend-low"></span> Low
                </div>
            </div>

            <!-- Horizontal bars: categories -->
            <div class="chart-card">
                <div class="chart-title">Top Scam Types This Week</div>
                <?php if (empty($cgCategories)): ?>
                    <p class="chart-empty">No analyzed incidents this week.</p>
                <?php else: ?>
                <?php $cgCatMax = max(array_column($cgCategories, 'cnt')); ?>
                <div class="hbar-chart">
                    <?php foreach ($cgCategories as $cat): ?>
                    <div class="hbar-row">
                        <div class="hbar-label"><?= e(formatCategory($cat['scam_category'])) ?></div>
                        <div class="hbar-track">
                            <div class="hbar-fill hbar-fill-cg" style="width:<?= round(($cat['cnt']/$cgCatMax)*100) ?>%"></div>
                        </div>
                        <div class="hbar-val"><?= $cat['cnt'] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

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
        <div class="stat-card <?= ($monthChange !== null && $monthChange > 0) ? 'stat-warning' : 'stat-success' ?>">
            <div class="stat-number">
                <?= $thisMonth ?>
                <?php if ($monthChange !== null): ?>
                    <span class="stat-delta <?= $monthChange > 0 ? 'delta-up' : 'delta-down' ?>">
                        <?= $monthChange > 0 ? '↑' : '↓' ?><?= abs($monthChange) ?>%
                    </span>
                <?php endif; ?>
            </div>
            <div class="stat-label">This Month
                <?php if ($lastMonth > 0): ?>
                    <span style="font-size:.75rem;color:var(--color-muted);display:block;">vs <?= $lastMonth ?> last month</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="admin-actions">
        <a href="<?= APP_URL ?>/pages/incidents.php" class="btn btn-primary">All Incidents</a>
        <a href="<?= APP_URL ?>/pages/admin_users.php" class="btn btn-secondary">Manage Users</a>
    </div>

    <!-- 30-Day Analytics -->
    <div class="analytics-section">
        <h2 class="analytics-heading">📊 Incident Analytics — Last 30 Days</h2>
        <div class="analytics-grid">

            <!-- Full-width 30-day bar chart -->
            <div class="chart-card chart-full">
                <div class="chart-title">Daily Volume</div>
                <?php
                $adminMax  = max(1, max(array_column($adminDays, 'total')));
                $adminVals = array_values($adminDays);
                $adminKeys = array_keys($adminDays);
                ?>
                <div class="area-chart-wrap">
                    <div class="area-y-axis">
                        <span><?= $adminMax ?></span>
                        <span><?= round($adminMax / 2) ?></span>
                        <span>0</span>
                    </div>
                    <div class="area-chart">
                        <?php foreach ($adminVals as $i => $d):
                            $hPx = $d['total'] > 0 ? round(($d['high_risk']   / $adminMax) * 130) : 0;
                            $mPx = $d['total'] > 0 ? round(($d['medium_risk'] / $adminMax) * 130) : 0;
                            $lPx = $d['total'] > 0 ? max(0, round((($d['total'] - $d['high_risk'] - $d['medium_risk']) / $adminMax) * 130)) : 0;
                        ?>
                        <div class="area-col" title="<?= date('M j', strtotime($adminKeys[$i])) ?>: <?= $d['total'] ?> incidents">
                            <div class="area-bars" style="height:140px;">
                                <?php if ($hPx > 0): ?><div class="bar-seg bar-high"   style="height:<?= $hPx ?>px"></div><?php endif; ?>
                                <?php if ($mPx > 0): ?><div class="bar-seg bar-medium" style="height:<?= $mPx ?>px"></div><?php endif; ?>
                                <?php if ($lPx > 0): ?><div class="bar-seg bar-low"    style="height:<?= $lPx ?>px"></div><?php endif; ?>
                                <?php if ($d['total'] === 0): ?><div class="area-zero-bar"></div><?php endif; ?>
                            </div>
                            <div class="area-x-label">
                                <?php if ($i % 5 === 0 || $i === count($adminVals) - 1): ?>
                                    <?= date('M j', strtotime($adminKeys[$i])) ?>
                                <?php else: ?>&nbsp;<?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="chart-legend" style="margin-top:.75rem;">
                    <span class="legend-dot legend-high"></span> High Risk &nbsp;
                    <span class="legend-dot legend-medium"></span> Medium Risk &nbsp;
                    <span class="legend-dot legend-low"></span> Low / Pending
                </div>
            </div>

            <!-- Risk distribution donut -->
            <div class="chart-card">
                <div class="chart-title">Risk Distribution (All Time)</div>
                <?php
                $riskTotal = max(1, (int)$riskDist['high'] + (int)$riskDist['medium'] + (int)$riskDist['low']);
                $highPct   = round(($riskDist['high']   / $riskTotal) * 100);
                $medPct    = round(($riskDist['medium'] / $riskTotal) * 100);
                $lowPct    = 100 - $highPct - $medPct;
                $segments  = [
                    ['pct' => $highPct, 'color' => '#ef4444'],
                    ['pct' => $medPct,  'color' => '#f59e0b'],
                    ['pct' => $lowPct,  'color' => '#60a5fa'],
                ];
                $offset = 25;
                ?>
                <div class="risk-donut-wrap">
                    <div class="risk-donut">
                        <svg viewBox="0 0 36 36" class="donut-svg">
                            <?php foreach ($segments as $seg):
                                if ($seg['pct'] <= 0) continue;
                                $dash = $seg['pct']; $gap = 100 - $dash;
                            ?>
                            <circle r="15.9155" cx="18" cy="18"
                                    fill="transparent"
                                    stroke="<?= $seg['color'] ?>"
                                    stroke-width="3.8"
                                    stroke-dasharray="<?= $dash ?> <?= $gap ?>"
                                    stroke-dashoffset="<?= $offset ?>"/>
                            <?php $offset -= $dash; endforeach; ?>
                        </svg>
                        <div class="donut-center">
                            <div class="donut-total"><?= $riskTotal ?></div>
                            <div class="donut-label">analyzed</div>
                        </div>
                    </div>
                </div>
                <div class="risk-dist-legend">
                    <div class="risk-dist-row"><span class="legend-dot legend-high"></span><span>High</span><strong><?= $highPct ?>%</strong><span class="muted-sm">(<?= $riskDist['high'] ?>)</span></div>
                    <div class="risk-dist-row"><span class="legend-dot legend-medium"></span><span>Medium</span><strong><?= $medPct ?>%</strong><span class="muted-sm">(<?= $riskDist['medium'] ?>)</span></div>
                    <div class="risk-dist-row"><span class="legend-dot legend-low"></span><span>Low</span><strong><?= $lowPct ?>%</strong><span class="muted-sm">(<?= $riskDist['low'] ?>)</span></div>
                </div>
            </div>

            <!-- Category breakdown -->
            <div class="chart-card">
                <div class="chart-title">Top Scam Categories <span class="muted-sm">(30 days)</span></div>
                <?php if (empty($adminCategories)): ?>
                    <p class="chart-empty">No categorized incidents yet.</p>
                <?php else:
                    $catMax = max(array_column($adminCategories, 'cnt')); ?>
                <div class="hbar-chart">
                    <?php foreach ($adminCategories as $cat): ?>
                    <div class="hbar-row">
                        <div class="hbar-label"><?= e(formatCategory($cat['scam_category'])) ?></div>
                        <div class="hbar-track">
                            <div class="hbar-fill" style="width:<?= round(($cat['cnt']/$catMax)*100) ?>%"></div>
                        </div>
                        <div class="hbar-val"><?= $cat['cnt'] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <h2>Latest Incidents</h2>
    <table class="data-table">
        <thead>
            <tr><th>User</th><th>Submitted</th><th>Risk</th><th>Category</th><th>Status</th><th></th></tr>
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

<style>
/* ── Analytics ─────────────────────────────────────────────── */
.analytics-section  { margin: 2rem 0; }
.analytics-heading  { margin: 0 0 1.25rem; }
.analytics-grid     { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }
@media (max-width: 720px) { .analytics-grid { grid-template-columns: 1fr; } }

.chart-card  { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius); padding: 1.25rem 1.5rem; }
.chart-wide  { grid-column: span 1; }
.chart-full  { grid-column: 1 / -1; }
.chart-title { font-size: .92rem; font-weight: 700; margin: 0 0 1rem; color: var(--color-text); }
.chart-empty { color: var(--color-muted); font-size: .875rem; padding: .75rem 0; }
.muted-sm    { color: var(--color-muted); font-size: .78rem; font-weight: 400; }

/* Stat extras */
.stat-delta  { font-size: .68rem; font-weight: 700; padding: .1rem .35rem; border-radius: 999px; vertical-align: middle; margin-left: .3rem; }
.delta-up    { background: #fee2e2; color: #b91c1c; }
.delta-down  { background: #dcfce7; color: #15803d; }
.stat-warning { border-top: 3px solid #f59e0b; }
.stat-success { border-top: 3px solid #22c55e; }

/* Stacked bar chart */
.bar-chart   { display: flex; align-items: flex-end; gap: 5px; padding: .25rem 0; }
.bar-group   { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 2px; min-width: 0; }
.bar-stack   { display: flex; flex-direction: column; justify-content: flex-end; align-items: center; width: 100%; gap: 1px; }
.bar-seg     { width: 100%; min-height: 3px; border-radius: 2px 2px 0 0; transition: opacity .15s; cursor: default; }
.bar-seg:hover { opacity: .75; }
.bar-high    { background: #ef4444; }
.bar-medium  { background: #f59e0b; }
.bar-low     { background: #60a5fa; }
.bar-empty   { width: 100%; height: 3px; background: var(--color-border); border-radius: 2px; margin-top: auto; }
.bar-value   { font-size: .68rem; font-weight: 600; color: var(--color-muted); min-height: .9rem; line-height: 1; }
.bar-label   { font-size: .63rem; color: var(--color-muted); text-align: center; line-height: 1.3; }
.bar-label small { font-size: .58rem; }

/* 30-day volume chart */
.area-chart-wrap  { display: flex; gap: .4rem; align-items: flex-start; }
.area-y-axis      { display: flex; flex-direction: column; justify-content: space-between; font-size: .63rem; color: var(--color-muted); padding-bottom: 1.25rem; height: 155px; text-align: right; min-width: 22px; }
.area-chart       { flex: 1; display: flex; align-items: flex-end; gap: 2px; overflow: hidden; }
.area-col         { flex: 1; display: flex; flex-direction: column; align-items: center; min-width: 0; cursor: default; }
.area-col:hover .bar-seg { opacity: .75; }
.area-bars        { display: flex; flex-direction: column; justify-content: flex-end; align-items: center; width: 100%; gap: 1px; }
.area-zero-bar    { width: 100%; height: 2px; background: var(--color-border); border-radius: 1px; }
.area-x-label     { font-size: .58rem; color: var(--color-muted); text-align: center; margin-top: 3px; white-space: nowrap; overflow: hidden; max-width: 100%; }

/* Horizontal bars */
.hbar-chart    { display: flex; flex-direction: column; gap: .65rem; }
.hbar-row      { display: flex; align-items: center; gap: .5rem; }
.hbar-label    { font-size: .78rem; min-width: 120px; max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--color-text); }
.hbar-track    { flex: 1; background: var(--color-border); border-radius: 999px; height: 10px; overflow: hidden; }
.hbar-fill     { height: 100%; border-radius: 999px; background: #6366f1; transition: width .5s ease; }
.hbar-fill-cg  { background: #0891b2; }
.hbar-val      { font-size: .78rem; font-weight: 700; min-width: 22px; text-align: right; }

/* Donut */
.risk-donut-wrap { display: flex; justify-content: center; margin: .5rem 0 1rem; }
.risk-donut      { position: relative; width: 140px; height: 140px; }
.donut-svg       { width: 100%; height: 100%; transform: rotate(-90deg); }
.donut-center    { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.donut-total     { font-size: 1.5rem; font-weight: 800; line-height: 1; }
.donut-label     { font-size: .7rem; color: var(--color-muted); }
.risk-dist-legend { display: flex; flex-direction: column; gap: .55rem; }
.risk-dist-row   { display: flex; align-items: center; gap: .5rem; font-size: .85rem; }
.risk-dist-row strong { margin-left: auto; }

/* Legend */
.chart-legend  { font-size: .77rem; color: var(--color-muted); display: flex; align-items: center; flex-wrap: wrap; gap: .3rem; }
.legend-dot    { display: inline-block; width: 10px; height: 10px; border-radius: 2px; flex-shrink: 0; }
.legend-high   { background: #ef4444; }
.legend-medium { background: #f59e0b; }
.legend-low    { background: #60a5fa; }
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>