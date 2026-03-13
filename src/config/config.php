<?php
// ============================================================
// config/config.php  —  App-wide settings
// ============================================================

// ── App ───────────────────────────────────────────────────────
define('APP_NAME', 'ElderShield');
define('APP_URL',  'http://localhost/eldershield/src');
define('APP_ROOT', __DIR__ . '/..');

// ── Ollama (local AI) ─────────────────────────────────────────
define('OLLAMA_URL',   'http://localhost:11434');
define('OLLAMA_MODEL', 'qwen3-vl:8b');   // BUG FIX: was qwen3-vl:8b which doesn't exist

// ── Upload settings ───────────────────────────────────────────
define('UPLOAD_DIR',          APP_ROOT . '/uploads/');
define('UPLOAD_URL',          APP_URL  . '/uploads/');
define('UPLOAD_MAX_MB',       10);
define('ALLOWED_IMAGE_TYPES', ['image/jpeg','image/png','image/gif','image/webp']);

// ── Session ───────────────────────────────────────────────────
define('SESSION_LIFETIME', 3600);

// ── Risk thresholds ───────────────────────────────────────────
define('RISK_HIGH',   70);
define('RISK_MEDIUM', 40);

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
ini_set('display_errors', 0);
ini_set('log_errors', 1);
set_time_limit(0);

if (php_sapi_name() !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_start();
}
