<?php
// pages/login.php
require_once __DIR__ . '/../includes/auth.php';

// Already logged in → redirect
if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission. Please try again.';
    } else {
        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');

        if (!$email || !$password) {
            $errors[] = 'Please enter your email and password.';
        } else {
            $result = loginUser($email, $password);
            if ($result['success']) {
                // Role-based redirect
                $redirect = match($result['role']) {
                    'elder'     => '/pages/dashboard.php',
                    'caregiver' => '/pages/dashboard.php',
                    'admin'     => '/pages/dashboard.php',
                    default     => '/pages/dashboard.php'
                };
                header('Location: ' . APP_URL . $redirect);
                exit;
            } else {
                $errors[] = $result['message'];
            }
        }
    }
}

$pageTitle = 'Login';
include __DIR__ . '/../includes/header.php';
?>

<div class="auth-container">
    <div class="auth-card">
        <h1>🛡️ Welcome to ElderShield</h1>
        <p class="auth-subtitle">Sign in to protect yourself from scams</p>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $err): ?>
                    <p><?= e($err) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <?= csrfField() ?>

            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email"
                       value="<?= e($_POST['email'] ?? '') ?>"
                       placeholder="you@example.com"
                       required autocomplete="email">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       placeholder="Your password"
                       required autocomplete="current-password">
            </div>

            <button type="submit" class="btn btn-primary btn-large btn-full">
                Sign In
            </button>
        </form>

        <p class="auth-link">
            Don't have an account? <a href="<?= APP_URL ?>/pages/register.php">Register here</a>
        </p>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
