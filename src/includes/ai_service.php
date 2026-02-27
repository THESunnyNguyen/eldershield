<?php
// ============================================================
// includes/ai_service.php  —  OpenAI GPT-4o-mini analysis
// ============================================================

require_once __DIR__ . '/../config/config.php';

// ══════════════════════════════════════════════════════════════
// MOCK MODE — set to true to skip real API calls during testing
// ══════════════════════════════════════════════════════════════
define('AI_MOCK_MODE', false);

// Which mock scenario to return (see $MOCK_SCENARIOS below):
//   'high_risk'   — 92% phishing with multiple tactics
//   'medium_risk' — 55% tech support scam
//   'low_risk'    — 8% legitimate message
//   'romance'     — 78% romance scam
//   'lottery'     — 88% lottery/prize scam
//   'not_a_scam'  — 5% confirmed legitimate
define('AI_MOCK_SCENARIO', 'not_a_scam');

// ── Mock scenario library ─────────────────────────────────────
$MOCK_SCENARIOS = [
    'high_risk' => [
        'scam_probability'    => 92,
        'scam_category'       => 'phishing',
        'manipulation_tactics'=> ['urgency', 'fear_based_language', 'authority_impersonation'],
        'explanation_simple'  => 'This message is almost certainly a scam. The sender is pretending to be your bank and trying to scare you into clicking a link by saying your account will be closed. Real banks never ask for your password or card number by text or email.',
        'recommended_action'  => "1. Do NOT click any links in the message.\n2. Call your bank directly using the number on the back of your card.\n3. Delete the message and tell a family member or caregiver.",
        'ai_raw_response'     => ['mock' => true, 'scenario' => 'high_risk'],
        'error'               => null,
    ],
    'medium_risk' => [
        'scam_probability'    => 55,
        'scam_category'       => 'tech_support',
        'manipulation_tactics'=> ['urgency', 'fear_based_language'],
        'explanation_simple'  => 'This could be a tech support scam. Someone is claiming your computer has a virus and wants you to call a phone number. Legitimate companies like Microsoft or Apple do not send unsolicited warnings asking you to call them.',
        'recommended_action'  => "1. Do not call the phone number in the message.\n2. Run your computer's built-in virus scan instead.\n3. Ask a trusted family member or caregiver to check your computer if you are worried.",
        'ai_raw_response'     => ['mock' => true, 'scenario' => 'medium_risk'],
        'error'               => null,
    ],
    'low_risk' => [
        'scam_probability'    => 8,
        'scam_category'       => 'not_a_scam',
        'manipulation_tactics'=> [],
        'explanation_simple'  => 'This message appears to be legitimate. It does not contain the typical warning signs of a scam such as urgent demands, requests for money, or suspicious links. It looks like a normal message.',
        'recommended_action'  => "This message looks safe. No action is needed.\nIf something still feels off, it is always okay to check with a family member before responding.",
        'ai_raw_response'     => ['mock' => true, 'scenario' => 'low_risk'],
        'error'               => null,
    ],
    'romance' => [
        'scam_probability'    => 78,
        'scam_category'       => 'romance_scam',
        'manipulation_tactics'=> ['emotional_manipulation', 'isolation_tactics', 'payment_pressure'],
        'explanation_simple'  => 'This has the signs of a romance scam. Someone you met online is building a close relationship with you and is now asking for money or gift cards. Scammers often pretend to be in the military or working overseas to explain why they cannot meet in person.',
        'recommended_action'  => "1. Do not send any money, gift cards, or wire transfers.\n2. Stop all contact with this person.\n3. Talk to a trusted family member or caregiver about this relationship right away.",
        'ai_raw_response'     => ['mock' => true, 'scenario' => 'romance'],
        'error'               => null,
    ],
    'lottery' => [
        'scam_probability'    => 88,
        'scam_category'       => 'lottery_prize',
        'manipulation_tactics'=> ['too_good_to_be_true', 'urgency', 'payment_pressure'],
        'explanation_simple'  => 'This is very likely a lottery or prize scam. You cannot win a contest you never entered. Scammers say you have won money but ask you to pay a fee first to collect it — you will never see any winnings, and you will lose the money you send.',
        'recommended_action'  => "1. Do not pay any fees or taxes to claim a prize.\n2. Do not give out your bank account or Social Security number.\n3. Delete the message. Notify a caregiver or family member.",
        'ai_raw_response'     => ['mock' => true, 'scenario' => 'lottery'],
        'error'               => null,
    ],
    'not_a_scam' => [
        'scam_probability'    => 5,
        'scam_category'       => 'not_a_scam',
        'manipulation_tactics'=> [],
        'explanation_simple'  => 'This message looks completely legitimate. It matches what you would expect from a real company or person. There are no red flags present.',
        'recommended_action'  => "No action needed — this message appears safe.\nYou can respond normally if you choose to.",
        'ai_raw_response'     => ['mock' => true, 'scenario' => 'not_a_scam'],
        'error'               => null,
    ],
];

/**
 * Analyze an incident (text + optional image) via OpenAI.
 * Set AI_MOCK_MODE = true above to return fake data without API calls.
 *
 * @param string      $text      The suspicious message/description
 * @param string|null $imagePath Absolute server path to uploaded image (optional)
 * @return array Structured analysis result
 */
function analyzeIncident(string $text, ?string $imagePath = null): array {
    // ── Mock mode: return preset scenario instantly, no API call ──
    if (AI_MOCK_MODE) {
        global $MOCK_SCENARIOS;
        $scenario = $MOCK_SCENARIOS[AI_MOCK_SCENARIO] ?? $MOCK_SCENARIOS['high_risk'];
        error_log('[ElderShield] AI_MOCK_MODE is ON — using scenario: ' . AI_MOCK_SCENARIO);
        return $scenario;
    }
    $systemPrompt = <<<PROMPT
You are ElderShield, an AI scam detection assistant specializing in protecting elderly users.
Analyze the submitted content for scam indicators.

Return ONLY valid JSON in this exact format:
{
  "scam_probability": <integer 0-100>,
  "scam_category": "<one of: phishing|impersonation|romance_scam|tech_support|lottery_prize|grandparent_scam|investment_fraud|other|not_a_scam>",
  "manipulation_tactics": ["<tactic1>", "<tactic2>"],
  "explanation_simple": "<2-3 sentences in plain English a senior citizen would understand>",
  "recommended_action": "<2-3 clear action steps the user should take>"
}

Manipulation tactics to detect: urgency/time_pressure, fear_based_language, authority_impersonation,
too_good_to_be_true, isolation_tactics, personal_information_requests, payment_pressure, emotional_manipulation.

Be accurate. Not everything is a scam. If it seems legitimate, set scam_probability low (0-20).
PROMPT;

    $userContent = [];

    // Add image if provided
    if ($imagePath && file_exists($imagePath)) {
        $imageData   = base64_encode(file_get_contents($imagePath));
        $mimeType    = mime_content_type($imagePath);
        $userContent[] = [
            'type'      => 'image_url',
            'image_url' => [
                'url'    => "data:{$mimeType};base64,{$imageData}",
                'detail' => 'high'
            ]
        ];
    }

    $userContent[] = [
        'type' => 'text',
        'text' => "Please analyze this suspicious content:\n\n" . $text
    ];

    $payload = [
        'model'       => OPENAI_MODEL,
        'max_tokens'  => OPENAI_MAX_TOKENS,
        'messages'    => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userContent]
        ],
        'temperature' => 0.2,  // Low temp for consistent structured output
    ];

    $response = callOpenAI($payload);

    if (isset($response['error'])) {
        return defaultAnalysis($response['error']);
    }

    $rawText = $response['choices'][0]['message']['content'] ?? '';
    return parseAIResponse($rawText);
}

// ── HTTP call to OpenAI ───────────────────────────────────────
function callOpenAI(array $payload): array {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $result   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log("OpenAI cURL error: $curlErr");
        return ['error' => 'Network error contacting AI service.'];
    }

    $decoded = json_decode($result, true);
    if ($httpCode !== 200) {
        $errMsg = $decoded['error']['message'] ?? 'Unknown API error';
        error_log("OpenAI API error ($httpCode): $errMsg");
        return ['error' => "AI service error: $errMsg"];
    }

    return $decoded;
}

// ── Parse and sanitize AI JSON response ───────────────────────
function parseAIResponse(string $rawText): array {
    // Strip markdown code fences if present
    $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($rawText));

    $data = json_decode($clean, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        error_log("AI JSON parse failed. Raw: $rawText");
        return defaultAnalysis('AI returned an unreadable response.');
    }

    $validCategories = ['phishing','impersonation','romance_scam','tech_support',
                        'lottery_prize','grandparent_scam','investment_fraud','other','not_a_scam'];

    return [
        'scam_probability'    => max(0, min(100, (int)($data['scam_probability'] ?? 0))),
        'scam_category'       => in_array($data['scam_category'] ?? '', $validCategories, true)
                                    ? $data['scam_category']
                                    : 'other',
        'manipulation_tactics'=> is_array($data['manipulation_tactics'] ?? null)
                                    ? $data['manipulation_tactics']
                                    : [],
        'explanation_simple'  => htmlspecialchars($data['explanation_simple'] ?? 'Analysis unavailable.'),
        'recommended_action'  => htmlspecialchars($data['recommended_action'] ?? 'Please contact a caregiver.'),
        'ai_raw_response'     => $data,
        'error'               => null,
    ];
}

// ── Fallback when AI fails ────────────────────────────────────
function defaultAnalysis(string $reason = 'Unknown error'): array {
    return [
        'scam_probability'    => 50,
        'scam_category'       => 'other',
        'manipulation_tactics'=> [],
        'explanation_simple'  => 'We were unable to analyze this submission automatically. A caregiver has been notified.',
        'recommended_action'  => 'Please contact your caregiver or a trusted person about this message.',
        'ai_raw_response'     => ['error' => $reason],
        'error'               => $reason,
    ];
}

// ── Risk level helper ─────────────────────────────────────────
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