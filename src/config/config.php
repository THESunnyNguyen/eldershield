<?php
// ============================================================
// config/config.php  —  App-wide settings
// ============================================================

// ── AI Provider ──────────────────────────────────────────────
define('OPENAI_API_KEY',   'sk-YOUR-OPENAI-KEY-HERE');   // Replace with your key
define('OPENAI_MODEL',     'gpt-4o-mini');               // Cost-effective with vision
define('OPENAI_MAX_TOKENS', 1000);

// ── App ───────────────────────────────────────────────────────
define('APP_NAME',    'ElderShield');
define('APP_URL',     'http://localhost:8888/eldershield'); // MAMP default port
define('APP_ROOT',    __DIR__ . '/..');

// ── Upload settings ───────────────────────────────────────────
define('UPLOAD_DIR',       APP_ROOT . '/uploads/');
define('UPLOAD_URL',       APP_URL  . '/uploads/');
define('UPLOAD_MAX_MB',    10);
define('ALLOWED_IMAGE_TYPES', ['image/jpeg','image/png','image/gif','image/webp']);

// ── Session ───────────────────────────────────────────────────
define('SESSION_LIFETIME', 3600); // 1 hour

// ── Risk thresholds ───────────────────────────────────────────
define('RISK_HIGH',   70);   // >= 70% → high risk, auto-notify caregivers
define('RISK_MEDIUM', 40);   // >= 40% → medium risk

ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
