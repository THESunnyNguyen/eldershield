<?php
// pages/logout.php
require_once __DIR__ . '/../includes/auth.php';
logoutUser();
header('Location: ' . APP_URL . '/pages/login.php');
exit;
