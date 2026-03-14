<?php
// ============================================================
// pages/submit.php — Submit suspicious content for AI analysis
// Accessible by: elder (own report), caregiver (on behalf of linked elder)
// ============================================================
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/ai_service.php';
require_once __DIR__ . '/../includes/subscription_helper.php';

requireLogin();
requireRole(['elder', 'caregiver']);

$user   = currentUser();
$errors = [];

// ── Determine who the report is being submitted for ───────────
$isCaregiver  = ($user['role'] === 'caregiver');
$linkedElders = [];
if ($isCaregiver) {
    $linkedElders = getLinksForCaregiver((int)$user['user_id']);
    if (empty($linkedElders)) {
        setFlash('danger', 'You have no linked elders. Link an elder account first.');
        header('Location: ' . APP_URL . '/pages/admin_users.php');
        exit;
    }
}

// Subscription gate (only applies when submitting as elder)
$sub          = null;
$monthlyCount = 0;
$limitReached = false;
if (!$isCaregiver) {
    $sub          = getUserSubscription($user['user_id']);
    $monthlyCount = getMonthlyIncidentCount($user['user_id']);
    $limitReached = !canSubmitIncident($user['user_id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        $errors[] = 'Invalid form submission. Please try again.';
    } elseif (!$isCaregiver && $limitReached) {
        $errors[] = 'You have reached your monthly limit. Please upgrade to Premium.';
    } else {
        $content = trim($_POST['content'] ?? '');
        if (strlen($content) < 10) {
            $errors[] = 'Please describe the suspicious message in at least 10 characters.';
        }

        // Determine the submitting user ID
        if ($isCaregiver) {
            $elderUserId = (int)($_POST['elder_user_id'] ?? 0);
            // Verify the caregiver actually has an active link to this elder
            $validElder = false;
            foreach ($linkedElders as $le) {
                if ((int)$le['elder_user_id'] === $elderUserId) {
                    $validElder = true;
                    break;
                }
            }
            if (!$validElder) {
                $errors[] = 'Invalid elder selection. You can only submit on behalf of your linked elders.';
            }
            $submitUserId = $elderUserId;
        } else {
            $submitUserId = (int)$user['user_id'];
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
            // 1. Save incident to DB immediately
            $incidentId = createIncident($submitUserId, $content, $imagePath);

            // 2. Fire Ollama in background — user is NOT kept waiting
            analyzeIncidentAsync($incidentId, $content, $imagePath);

            // 3. Redirect immediately — detail page will poll for results
            setFlash('success', 'Report submitted! Analysis is running and will appear shortly.');
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
        <h1>&#x1F6A8; Report a Suspicious Message</h1>
        <p class="submit-intro">
            <?php if ($isCaregiver): ?>
                Submit a scam report on behalf of one of your linked elders.
                Our AI will analyze it and flag any risks.
            <?php else: ?>
                Did someone contact you asking for money, passwords, or personal information?
                Tell us what happened and our AI will check if it's a scam — instantly.
            <?php endif; ?>
        </p>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $err): ?><p><?= e($err) ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!$isCaregiver && $limitReached): ?>
            <div class="alert alert-warning">
                <strong>Monthly limit reached.</strong>
                You've used <?= (int)$monthlyCount ?> of <?= (int)FREE_INCIDENT_LIMIT ?> free submissions this month.
                <a href="<?= e(APP_URL) ?>/pages/subscription.php" class="btn btn-sm btn-primary ms-2">
                    Upgrade to Premium
                </a>
            </div>
        <?php else: ?>
            <?php if (!$isCaregiver && $sub && $sub['plan_name'] === 'free'): ?>
                <div class="alert alert-info">
                    <?= (int)$monthlyCount ?> of <?= (int)FREE_INCIDENT_LIMIT ?> free submissions used this month.
                    <a href="<?= e(APP_URL) ?>/pages/subscription.php">Upgrade for unlimited access.</a>
                </div>
            <?php endif; ?>

            <form method="POST" action="" enctype="multipart/form-data">
                <?= csrfField() ?>

                <?php if ($isCaregiver): ?>
                <!-- Caregiver selects which elder to submit for -->
                <div class="form-group">
                    <label for="elder_user_id">Submit on behalf of:</label>
                    <select id="elder_user_id" name="elder_user_id" required>
                        <option value="">— Select an elder —</option>
                        <?php foreach ($linkedElders as $le): ?>
                            <option value="<?= (int)$le['elder_user_id'] ?>"
                                <?= ((int)($_POST['elder_user_id'] ?? 0) === (int)$le['elder_user_id']) ? 'selected' : '' ?>>
                                <?= e($le['full_name']) ?> (<?= e($le['email']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="content">
                        <?= $isCaregiver ? 'Describe the suspicious message the elder received:' : 'What happened? Describe the message, call, or email:' ?>
                    </label>
                    <textarea id="content" name="content" rows="7"
                        placeholder="Example: I received a call from someone saying they were from Microsoft..."
                        required><?= e($_POST['content'] ?? '') ?></textarea>
                    <small>Include as much detail as you can — the more info, the better the analysis.</small>
                </div>

                <div class="form-group">
                    <label for="screenshot">&#x1F4F8; Upload a Screenshot (optional)</label>
                    <div class="upload-zone" id="uploadZone">
                        <input type="file" id="screenshot" name="screenshot"
                               accept="image/*" class="upload-input">
                        <div class="upload-label">
                            <span>Click to upload or drag &amp; drop an image</span>
                            <small>JPG, PNG, GIF, WEBP &middot; Max <?= (int)UPLOAD_MAX_MB ?>MB</small>
                        </div>
                        <div id="imagePreview" class="image-preview hidden"></div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-large btn-full" id="submitBtn">
                    &#x1F50D; Analyze This Message
                </button>
                <p class="submit-note">
                    Reports are private and only visible to the elder, their caregivers, and admins.
                </p>
            </form>
        <?php endif; ?>
    </div>

    <div class="tips-card">
        <h3>&#x26A0;&#xFE0F; Common Scam Warning Signs</h3>
        <ul>
            <li>&#x1F550; They're rushing you to decide NOW</li>
            <li>&#x1F4B0; They want gift cards, wire transfers, or crypto</li>
            <li>&#x1F511; They ask for passwords or account numbers</li>
            <li>&#x1F628; They threaten bad things if you don't act</li>
            <li>&#x1F3DB;&#xFE0F; They claim to be the IRS, Social Security, or police</li>
            <li>&#x1F381; You "won" something you never entered</li>
            <li>&#x1F4BB; They want to control your computer remotely</li>
        </ul>
        <p><strong>When in doubt — don't respond! Report it here first.</strong></p>
    </div>
</div>

<script>
document.getElementById('screenshot')?.addEventListener('change', function(e) {
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

document.querySelector('form')?.addEventListener('submit', function() {
    const btn = document.getElementById('submitBtn');
    btn.textContent = '\u23F3 Submitting...';
    btn.disabled = true;
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
