<?php
// index.php — Entry point, redirect based on auth state
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/pages/dashboard.php');
} else {
    header('Location: ' . APP_URL . '/pages/login.php');
}
exit;
