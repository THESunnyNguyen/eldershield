<?php
session_start();

if (!isset($_SESSION["user"])) {
  header("Location: ../login.php");
  exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  header("Location: users_view.php");
  exit();
}

$id = intval($_POST["id"] ?? 0);

$users = $_SESSION["users_db"];
$new_users = [];

foreach ($users as $u) {
  if ($u["user_id"] !== $id) {
    $new_users[] = $u;
  }
}

$_SESSION["users_db"] = $new_users;

header("Location: users_view.php");
exit();
