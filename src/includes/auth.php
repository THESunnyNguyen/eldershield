<?php
// ============================================================
// includes/auth.php  —  Session & authentication helpers
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// ── Check if logged in ────────────────────────────────────────
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// ── Require login, redirect if not ───────────────────────────
function requireLogin(string $redirect = '/pages/login.php'): void {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . $redirect);
        exit;
    }
}

// ── Require specific role ─────────────────────────────────────
// Redirects to unauthorized.php (403 page) if role does not match
function requireRole(string|array $roles): void {
    requireLogin();
    $roles = (array)$roles;
    if (!in_array($_SESSION['user_role'], $roles, true)) {
        header('Location: ' . APP_URL . '/pages/unauthorized.php');
        exit;
    }
}

// ── Get current user from session ────────────────────────────
function currentUser(): array {
    return [
        'user_id'   => $_SESSION['user_id']   ?? null,
        'full_name' => $_SESSION['full_name']  ?? '',
        'email'     => $_SESSION['email']      ?? '',
        'role'      => $_SESSION['user_role']  ?? '',
    ];
}

// ── Login user (validate credentials, set session) ───────────
function loginUser(string $email, string $password): array {
    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([trim($email)]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    // Regenerate session ID on login (session fixation prevention)
    session_regenerate_id(true);

    $_SESSION['user_id']   = $user['user_id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email']     = $user['email'];
    $_SESSION['user_role'] = $user['role'];

    return ['success' => true, 'role' => $user['role']];
}

// ── Register new user ─────────────────────────────────────────
function registerUser(string $fullName, string $email, string $password, string $role = 'elder'): array {
    $db = getDB();

    // Check duplicate email
    $stmt = $db->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([trim($email)]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'That email is already registered.'];
    }

    $allowedRoles = ['elder', 'caregiver', 'admin'];
    if (!in_array($role, $allowedRoles, true)) {
        $role = 'elder';
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $db->prepare('INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, ?)');
    $stmt->execute([trim($fullName), trim($email), $hash, $role]);

    return ['success' => true, 'user_id' => $db->lastInsertId()];
}

// ── Logout ────────────────────────────────────────────────────
function logoutUser(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

// ── CSRF helpers ──────────────────────────────────────────────
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

// ── Flash messages ────────────────────────────────────────────
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}
