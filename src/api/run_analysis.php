<?php
// api/run_analysis.php — Background CLI worker for Ollama analysis
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ai_service.php';
require_once __DIR__ . '/../includes/helpers.php';

$incidentId = (int)($argv[1] ?? 0);
$tmpFile    = $argv[2] ?? '';
$text       = ($tmpFile && file_exists($tmpFile)) ? file_get_contents($tmpFile) : '';
$imagePath  = (isset($argv[3]) && $argv[3] !== '""' && $argv[3] !== '') ? $argv[3] : null;

if ($tmpFile && file_exists($tmpFile)) @unlink($tmpFile);

if (!$incidentId || !$text) {
    error_log('[ElderShield] run_analysis.php: missing args. incidentId=' . $incidentId);
    exit(1);
}

error_log('[ElderShield] Background analysis starting for incident #' . $incidentId);
$result = analyzeIncident($text, $imagePath);
saveAnalysis($incidentId, $result);

if ($result['scam_probability'] >= RISK_MEDIUM) {
    $db = getDB();
    $stmt = $db->prepare('SELECT user_id FROM incidents WHERE incident_id = ?');
    $stmt->execute([$incidentId]);
    $userId = $stmt->fetchColumn();
    if ($userId) {
        notifyCaregivers($incidentId, (int)$userId, (int)$result['scam_probability'], $result['scam_category']);
    }
}

error_log('[ElderShield] Background analysis complete for incident #' . $incidentId);
exit(0);
