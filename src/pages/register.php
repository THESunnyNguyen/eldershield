<?php
// pages/register.php
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

$errors = [];
$data   = ['full_name'=>'','email'=>'','role'=>'elder'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission.';
    } else {
        $data['full_name'] = trim($_POST['full_name'] ?? '');
        $data['email']     = trim($_POST['email']     ?? '');
        $data['role']      = trim($_POST['role']      ?? 'elder');
        $password          = $_POST['password']       ?? '';
        $confirm           = $_POST['confirm_password'] ?? '';

        if (strlen($data['full_name']) < 2)   $errors[] = 'Please enter your full name.';
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
        if (strlen($password) < 8)             $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirm)            $errors[] = 'Passwords do not match.';

        if (!$errors) {
            $result = registerUser($data['full_name'], $data['email'], $password, $data['role']);
            if ($result['success']) {
                setFlash('success', 'Account created! Please log in.');
                header('Location: ' . APP_URL . '/pages/login.php');
                exit;
            } else {
                $errors[] = $result['message'];
            }
        }
    }
}

$pageTitle = 'Register';
include __DIR__ . '/../includes/header.php';
?>

<div class="auth-container">
    <div class="auth-card">
        <h1>Create Your Account</h1>
        <p class="auth-subtitle">Join ElderShield to stay safe from scams</p>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $err): ?><p><?= e($err) ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <?= csrfField() ?>

            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name"
                       value="<?= e($data['full_name']) ?>"
                       placeholder="Dorothy Johnson" required>
            </div>

            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email"
                       value="<?= e($data['email']) ?>"
                       placeholder="you@example.com" required>
            </div>

            <div class="form-group">
                <label for="role">I am a...</label>
                <select id="role" name="role">
                    <option value="elder"     <?= $data['role']==='elder'     ? 'selected':'' ?>>Senior / Elder User</option>
                    <option value="caregiver" <?= $data['role']==='caregiver' ? 'selected':'' ?>>Caregiver / Family Member</option>
                </select>
                <small>Admin accounts are created by existing admins only.</small>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       placeholder="At least 8 characters" required minlength="8">
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password"
                       placeholder="Repeat your password" required>
            </div>

            <button type="submit" class="btn btn-primary btn-large btn-full">
                Create Account
            </button>
        </form>

        <p class="auth-link">
            Already have an account? <a href="<?= APP_URL ?>/pages/login.php">Sign in</a>
        </p>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
