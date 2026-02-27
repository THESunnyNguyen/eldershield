<?php
// api/reanalyze.php — Admin re-runs AI on an existing incident
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/ai_service.php';

requireLogin();
requireRole('admin');

$user       = currentUser();
$incidentId = (int)($_POST['incident_id'] ?? 0);
$csrfToken  = $_POST['csrf_token'] ?? '';

if (!$incidentId || !verifyCsrf($csrfToken)) {
    setFlash('danger', 'Invalid request.');
    header('Location: ' . APP_URL . '/pages/incidents.php');
    exit;
}

$incident = getIncidentById($incidentId);
if (!$incident) {
    setFlash('danger', 'Incident not found.');
    header('Location: ' . APP_URL . '/pages/incidents.php');
    exit;
}

// Delete old analysis
$db = getDB();
$db->prepare('DELETE FROM analysis WHERE incident_id=?')->execute([$incidentId]);

// Re-run AI
$aiResult = analyzeIncident($incident['content'], $incident['image_path'] ?: null);
saveAnalysis($incidentId, $aiResult);

setFlash('success', 'AI analysis re-run successfully.');
header('Location: ' . APP_URL . '/pages/incident_detail.php?id=' . $incidentId);
exit;
