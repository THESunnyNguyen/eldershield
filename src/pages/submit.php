<?php
// ============================================================
// pages/submit.php — Elder submits suspicious content for AI analysis
// ============================================================
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/ai_service.php';
require_once __DIR__ . '/../includes/subscription_helper.php';

requireLogin();
requireRole('elder');

$user = currentUser();
$errors = [];

// ── Subscription gate (server-side enforcement) ───────────────
$sub          = getUserSubscription($user['user_id']);
$monthlyCount = getMonthlyIncidentCount($user['user_id']);
$limitReached = !canSubmitIncident($user['user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF failure → 403 immediately (OWASP best practice)
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        $errors[] = 'Invalid form submission. Please try again.';
    } elseif ($limitReached) {
        // Re-check server-side on POST — cannot be bypassed via JS
        $errors[] = 'You have reached your monthly limit. Please upgrade to Premium.';
    } else {
        $content = trim($_POST['content'] ?? '');
        if (strlen($content) < 10) {
            $errors[] = 'Please describe the suspicious message in at least 10 characters.';
        }

        $imagePath = null;
        if (!empty($_FILES['screenshot']['name'])) {
            $upload = handleImageUpload($_FILES['screenshot']);
            if (!$upload['success']) {
                $errors[] = $upload['message'];
            } else {
                $imagePath = $upload['path'];
            }
        }

        if (!$errors) {
            $incidentId = createIncident((int)$user['user_id'], $content, $imagePath);
            $aiResult   = analyzeIncident($content, $imagePath);
            saveAnalysis($incidentId, $aiResult);

            // Only notify caregivers if plan supports it
            if ($aiResult['scam_probability'] >= RISK_MEDIUM && $sub['notifications_enabled']) {
                notifyCaregivers(
                    $incidentId,
                    (int)$user['user_id'],
                    (int)$aiResult['scam_probability'],
                    $aiResult['scam_category']
                );
            }

            setFlash('success', 'Your report has been analyzed. See the results below.');
            // 1. Save incident to DB immediately
            $incidentId = createIncident((int)$user['user_id'], $content, $imagePath);

            // 2. Fire Ollama in background — user is NOT kept waiting
            analyzeIncidentAsync($incidentId, $content, $imagePath);

            // 3. Redirect immediately — detail page will poll for results
            setFlash('success', 'Your report has been submitted! Analysis is running and will appear shortly.');
            header('Location: ' . APP_URL . '/pages/incident_detail.php?id=' . $incidentId);
            exit;
        }
    }
}

$pageTitle = 'Report a Suspicious Message';
include __DIR__ . '/../includes/header.php';
?>

<div class="submit-container">
    <div class="submit-card">
        <h1>🚨 Report a Suspicious Message</h1>
        <p class="submit-intro">
            Did someone contact you asking for money, passwords, or personal information?
            Tell us what happened and our AI will check if it's a scam — instantly.
        </p>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $err): ?><p><?= e($err) ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($limitReached): ?>
            <!-- Hard block with upgrade prompt — integers are safe but cast explicitly -->
            <div class="alert alert-warning">
                <strong>Monthly limit reached.</strong>
                You've used <?= (int)$monthlyCount ?> of <?= (int)FREE_INCIDENT_LIMIT ?> free submissions this month.
                <a href="<?= e(APP_URL) ?>/pages/subscription.php" class="btn btn-sm btn-primary ms-2">
                    Upgrade to Premium
                </a>
            </div>
        <?php else: ?>
            <?php if ($sub['plan_name'] === 'free'): ?>
                <div class="alert alert-info">
                    <?= (int)$monthlyCount ?> of <?= (int)FREE_INCIDENT_LIMIT ?> free submissions used this month.
                    <a href="<?= e(APP_URL) ?>/pages/subscription.php">Upgrade for unlimited access.</a>
                </div>
            <?php endif; ?>

            <form method="POST" action="" enctype="multipart/form-data">
                <?= csrfField() ?>

                <div class="form-group">
                    <label for="content">What happened? Describe the message, call, or email:</label>
                    <textarea id="content" name="content" rows="7"
                        placeholder="Example: I received a call from someone saying they were from Microsoft..."
                        required><?= e($_POST['content'] ?? '') ?></textarea>
                    <small>Include as much detail as you can — the more info, the better the analysis.</small>
                </div>

                <div class="form-group">
                    <label for="screenshot">📸 Upload a Screenshot (optional)</label>
                    <div class="upload-zone" id="uploadZone">
                        <input type="file" id="screenshot" name="screenshot"
                               accept="image/*" class="upload-input">
                        <div class="upload-label">
                            <span>Click to upload or drag &amp; drop an image</span>
                            <small>JPG, PNG, GIF, WEBP · Max <?= (int)UPLOAD_MAX_MB ?>MB</small>
                        </div>
                        <div id="imagePreview" class="image-preview hidden"></div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-large btn-full" id="submitBtn">
                    🔍 Analyze This Message
                </button>
                <p class="submit-note">
                    Your report is private. It will only be seen by you and your caregivers.
                </p>
            </form>
        <?php endif; ?>
    </div>

    <div class="tips-card">
        <h3>⚠️ Common Scam Warning Signs</h3>
        <ul>
            <li>🕐 They're rushing you to decide NOW</li>
            <li>💰 They want gift cards, wire transfers, or crypto</li>
            <li>🔑 They ask for passwords or account numbers</li>
            <li>😨 They threaten bad things if you don't act</li>
            <li>🏛️ They claim to be the IRS, Social Security, or police</li>
            <li>🎁 You "won" something you never entered</li>
            <li>💻 They want to control your computer remotely</li>
        </ul>
        <p><strong>When in doubt — don't respond! Report it here first.</strong></p>
    </div>
</div>

<script>
document.getElementById('screenshot').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(ev) {
        const preview = document.getElementById('imagePreview');
        preview.innerHTML = '<img src="' + ev.target.result + '" alt="Preview">'
            + '<button type="button" onclick="clearImage()" class="btn btn-sm btn-secondary">Remove</button>';
        preview.classList.remove('hidden');
        document.querySelector('.upload-label').classList.add('hidden');
    };
    reader.readAsDataURL(file);
});

function clearImage() {
    document.getElementById('screenshot').value = '';
    document.getElementById('imagePreview').innerHTML = '';
    document.getElementById('imagePreview').classList.add('hidden');
    document.querySelector('.upload-label').classList.remove('hidden');
}

document.querySelector('form').addEventListener('submit', function() {
    const btn = document.getElementById('submitBtn');
    btn.textContent = '⏳ Submitting...';
    btn.disabled = true;
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
