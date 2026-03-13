<?php
// api/run_analysis.php — Background CLI worker for Ollama analysis
// Launched by analyzeIncidentAsync() — never called directly by browser

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ai_service.php';
require_once __DIR__ . '/../includes/helpers.php';

$incidentId = (int)($argv[1] ?? 0);
$text       = $argv[2] ?? '';
$imagePath  = (isset($argv[3]) && $argv[3] !== '""' && $argv[3] !== '') ? $argv[3] : null;

if (!$incidentId || !$text) {
    error_log('[ElderShield] run_analysis.php called with missing args');
    exit(1);
}

error_log('[ElderShield] Background analysis starting for incident #' . $incidentId);

$result = analyzeIncident($text, $imagePath);
saveAnalysis($incidentId, $result);

if ($result['scam_probability'] >= RISK_MEDIUM) {
    $db       = getDB();
    $userStmt = $db->prepare('SELECT user_id FROM incidents WHERE incident_id = ?');
    $userStmt->execute([$incidentId]);
    $userId = $userStmt->fetchColumn();
    if ($userId) {
        notifyCaregivers(
            $incidentId,
            (int)$userId,
            (int)$result['scam_probability'],
            $result['scam_category']
        );
    }
}

error_log('[ElderShield] Background analysis complete for incident #' . $incidentId);
exit(0);
