<?php
// ============================================================
// config/config.php  —  App-wide settings
// ============================================================

// ── App ───────────────────────────────────────────────────────
define('APP_NAME', 'ElderShield');
define('APP_URL',  'http://localhost:8888/eldershield'); // MAMP default
define('APP_ROOT', __DIR__ . '/..');

// ── Ollama (local AI) ─────────────────────────────────────────
define('OLLAMA_URL',   'http://localhost:11434');
define('OLLAMA_MODEL', 'gemma3:4b');

// ── Upload settings ───────────────────────────────────────────
define('UPLOAD_DIR',          APP_ROOT . '/uploads/');
define('UPLOAD_URL',          APP_URL  . '/uploads/');
define('UPLOAD_MAX_MB',       10);
define('ALLOWED_IMAGE_TYPES', ['image/jpeg','image/png','image/gif','image/webp']);

// ── Session ───────────────────────────────────────────────────
define('SESSION_LIFETIME', 3600);

// ── Risk thresholds ───────────────────────────────────────────
define('RISK_HIGH',   70);  // >= 70% → high risk, auto-notify caregivers
define('RISK_MEDIUM', 40);  // >= 40% → medium risk

ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
set_time_limit(0); // background worker needs unlimited time

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
