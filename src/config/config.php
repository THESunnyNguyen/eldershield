<?php
// ============================================================
// config/config.php  —  App-wide settings
// ============================================================

// ── AI Provider ──────────────────────────────────────────────
define('OPENAI_API_KEY',    'sk-YOUR-OPENAI-KEY-HERE');   // Replace with your key
define('OPENAI_MODEL',      'gpt-4o-mini');               // Cost-effective with vision
define('OPENAI_MAX_TOKENS', 1000);

// ── App ───────────────────────────────────────────────────────
define('APP_NAME', 'ElderShield');
define('APP_URL',  'http://localhost:8888/eldershield');   // MAMP default port
define('APP_ROOT', __DIR__ . '/..');

// ── Upload settings ───────────────────────────────────────────
define('UPLOAD_DIR',          APP_ROOT . '/uploads/');
define('UPLOAD_URL',          APP_URL  . '/uploads/');
define('UPLOAD_MAX_MB',       10);
define('ALLOWED_IMAGE_TYPES', ['image/jpeg','image/png','image/gif','image/webp']);

// ── Session ───────────────────────────────────────────────────
define('SESSION_LIFETIME', 3600); // 1 hour

// ── Risk thresholds ───────────────────────────────────────────
define('RISK_HIGH',   70);   // >= 70% → high risk, auto-notify caregivers
define('RISK_MEDIUM', 40);   // >= 40% → medium risk

// ── PHP error handling ────────────────────────────────────────
ini_set('display_errors', 0);   // Never expose errors to the browser
ini_set('log_errors',     1);   // Always log server-side
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

// ── Security headers (OWASP recommended) ─────────────────────
// Content-Security-Policy: restrict resource origins to prevent XSS
// Adjust 'script-src' if you add CDN scripts (e.g., Bootstrap JS)
if (!headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;");
    header("X-Content-Type-Options: nosniff");      // Prevent MIME-type sniffing
    header("X-Frame-Options: SAMEORIGIN");           // Prevent clickjacking
    header("Referrer-Policy: strict-origin-when-cross-origin");
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
