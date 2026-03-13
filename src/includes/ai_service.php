<?php
// ============================================================
// includes/ai_service.php  —  Ollama llama3.2-vision (local)
// ============================================================

require_once __DIR__ . '/../config/config.php';

// ── Analyze incident — called by background worker only ──────
function analyzeIncident(string $text, ?string $imagePath = null): array {
    $systemPrompt = 'You are ElderShield, a scam detection assistant protecting elderly users. '
        . 'Analyze the submitted content and return ONLY a valid JSON object. '
        . 'No extra text, no markdown, no code fences, nothing before or after the JSON. '
        . 'Use exactly this structure: '
        . '{"scam_probability":<integer 0-100>,'
        . '"scam_category":"<phishing|impersonation|romance_scam|tech_support|lottery_prize|grandparent_scam|investment_fraud|other|not_a_scam>",'
        . '"manipulation_tactics":["<tactic>"],'
        . '"explanation_simple":"<2-3 plain sentences a senior would understand>",'
        . '"recommended_action":"<2-3 clear action steps>"}'
        . 'You must respond with a single JSON object only. '
        . 'Do not write any words, explanation, or markdown before or after the JSON. '
        . 'Do not use code fences. Your entire response must be parseable by json_decode(). '
        . 'Start your response with { and end with }.';

if ($imagePath && file_exists($imagePath)) {
    $imageData = base64_encode(file_get_contents($imagePath));
    $messages  = [
        ['role' => 'system', 'content' => $systemPrompt],
        [
            'role'    => 'user',
            'content' => "Analyze this content for scams:\n\n" . $text,
            'images'  => [$imageData],   // gemma3 format — raw base64 in images array
        ]
    ];
} else {
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => "Analyze this content for scams:\n\n" . $text]
    ];
}

    $payload = json_encode([
        'model'    => 'gemma3:4b',
        'messages' => $messages,
        'stream'   => false,
        'options'  => [
            'temperature' => 0.1,
            'num_ctx'     => 2048,
        ],
    ]);

    $ch = curl_init('http://localhost:11434/api/chat');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $result  = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($result === false || $curlErr) {
        error_log('[ElderShield] Ollama connection error: ' . $curlErr);
        return defaultAnalysis('Could not reach Ollama. Make sure it is running.');
    }

    $decoded = json_decode($result, true);
    if (!is_array($decoded)) {
        error_log('[ElderShield] Ollama bad response: ' . $result);
        return defaultAnalysis('Ollama returned an unexpected response.');
    }

    $rawText = $decoded['message']['content'] ?? '';
    if (empty($rawText)) {
        error_log('[ElderShield] Ollama empty content. Response: ' . $result);
        return defaultAnalysis('Ollama returned an empty response.');
    }

    return parseAIResponse($rawText);
}

// ── Fire analysis in background so Apache does not freeze ────
function analyzeIncidentAsync(int $incidentId, string $text, ?string $imagePath = null): void {
    $phpBinaries = glob('/Applications/MAMP/bin/php/php*/bin/php');
    $phpBin      = !empty($phpBinaries) ? end($phpBinaries) : '/usr/bin/php';

    $scriptPath = APP_ROOT . '/api/run_analysis.php';
    $imageArg   = ($imagePath && $imagePath !== '') ? escapeshellarg($imagePath) : '""';

    $cmd = sprintf(
        '%s %s %s %s %s > /dev/null 2>&1 &',
        escapeshellarg($phpBin),
        escapeshellarg($scriptPath),
        escapeshellarg((string)$incidentId),
        escapeshellarg($text),
        $imageArg
    );

    exec($cmd);
}

// ── Parse AI JSON response ────────────────────────────────────
function parseAIResponse(string $rawText): array {
    $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($rawText));

    $jsonStart = strpos($clean, '{');
    $jsonEnd   = strrpos($clean, '}');
    if ($jsonStart !== false && $jsonEnd !== false) {
        $clean = substr($clean, $jsonStart, $jsonEnd - $jsonStart + 1);
    }

    $data = json_decode($clean, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        error_log('[ElderShield] JSON parse failed. Raw: ' . $rawText);
        return defaultAnalysis('AI returned an unreadable response.');
    }

    $validCategories = [
        'phishing', 'impersonation', 'romance_scam', 'tech_support',
        'lottery_prize', 'grandparent_scam', 'investment_fraud', 'other', 'not_a_scam'
    ];

    return [
        'scam_probability'     => max(0, min(100, (int)($data['scam_probability'] ?? 0))),
        'scam_category'        => in_array($data['scam_category'] ?? '', $validCategories, true)
                                    ? $data['scam_category'] : 'other',
        'manipulation_tactics' => is_array($data['manipulation_tactics'] ?? null)
                                    ? $data['manipulation_tactics'] : [],
        'explanation_simple'   => $data['explanation_simple'] ?? 'Analysis unavailable.',
        'recommended_action'   => $data['recommended_action'] ?? 'Please contact a caregiver.',
        'ai_raw_response'      => $data,
        'error'                => null,
    ];
}

function defaultAnalysis(string $reason = 'Unknown error'): array {
    return [
        'scam_probability'     => 0,
        'scam_category'        => 'other',
        'manipulation_tactics' => [],
        'explanation_simple'   => 'We were unable to analyze this automatically. A caregiver has been notified.',
        'recommended_action'   => 'Please contact your caregiver or a trusted person about this message.',
        'ai_raw_response'      => ['error' => $reason],
        'error'                => $reason,
    ];
}

function getRiskLevel(int|float $probability): string {
    if ($probability >= RISK_HIGH)   return 'high';
    if ($probability >= RISK_MEDIUM) return 'medium';
    return 'low';
}

function getRiskLabel(string $level): string {
    return match($level) {
        'high'   => '⚠️ High Risk',
        'medium' => '⚡ Medium Risk',
        default  => '✅ Low Risk',
    };
}
