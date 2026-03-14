<?php
// pages/profile.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
$user   = currentUser();
$db     = getDB();
$errors = [];

// Load full user record
$stmt = $db->prepare('SELECT * FROM users WHERE user_id=?');
$stmt->execute([$user['user_id']]);
$profile = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_profile') {
            $fullName = trim($_POST['full_name'] ?? '');
            $email    = trim($_POST['email']     ?? '');

            if (strlen($fullName) < 2) $errors[] = 'Name must be at least 2 characters.';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';

            if (!$errors) {
                // Check email not taken by another user
                $chk = $db->prepare('SELECT user_id FROM users WHERE email=? AND user_id!=?');
                $chk->execute([$email, $user['user_id']]);
                if ($chk->fetch()) {
                    $errors[] = 'That email is already in use.';
                } else {
                    $db->prepare('UPDATE users SET full_name=?, email=? WHERE user_id=?')
                       ->execute([$fullName, $email, $user['user_id']]);
                    $_SESSION['full_name'] = $fullName;
                    $_SESSION['email']     = $email;
                    setFlash('success', 'Profile updated.');
                    header('Location: ' . APP_URL . '/pages/profile.php');
                    exit;
                }
            }
        }

        if ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password']     ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if (!password_verify($current, $profile['password_hash'])) {
                $errors[] = 'Current password is incorrect.';
            } elseif (strlen($new) < 7) {
                $errors[] = 'New password must be at least 7 characters.';
            } elseif ($new !== $confirm) {
                $errors[] = 'New passwords do not match.';
            } else {
                $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
                $db->prepare('UPDATE users SET password_hash=? WHERE user_id=?')
                   ->execute([$hash, $user['user_id']]);
                setFlash('success', 'Password changed successfully.');
                header('Location: ' . APP_URL . '/pages/profile.php');
                exit;
            }
        }
    }
}

$pageTitle = 'My Profile';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-container page-narrow">
    <h1>My Profile</h1>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $e): ?><p><?= e($e) ?></p><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Account Info</h2>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_profile">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="full_name" value="<?= e($profile['full_name']) ?>" required>
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" value="<?= e($profile['email']) ?>" required>
            </div>
            <div class="form-group">
                <label>Account Type</label>
                <input type="text" value="<?= e(ucfirst($profile['role'])) ?>" disabled>
            </div>
            <div class="form-group">
                <label>Member Since</label>
                <input type="text" value="<?= date('F j, Y', strtotime($profile['created_at'])) ?>" disabled>
            </div>
            <button type="submit" class="btn btn-primary">Save Changes</button>
        </form>
    </div>

    <div class="card">
        <h2>Change Password</h2>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="change_password">
            <div class="form-group">
                <label>Current Password</label>
                <input type="password" name="current_password" required>
            </div>
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="new_password" required minlength="7">
            </div>
            <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" required>
            </div>
            <button type="submit" class="btn btn-secondary">Change Password</button>
        </form>
    </div>

    <?php if ($user['role'] === 'elder'): ?>
    <div class="card">
        <h2>My Caregivers</h2>
        <?php
        $myLinks = getLinksForElder((int)$user['user_id']);
        if (empty($myLinks)): ?>
            <p>No caregivers linked yet. Ask a caregiver to send you a link request.</p>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Caregiver</th><th>Email</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($myLinks as $l): ?>
                <tr>
                    <td><?= e($l['full_name']) ?></td>
                    <td><?= e($l['email']) ?></td>
                    <td><span class="status-badge status-<?= e($l['status']) ?>"><?= e(ucfirst($l['status'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>