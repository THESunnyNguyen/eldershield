<?php
session_start();
?>

<!DOCTYPE html>
<html>
<head>
  <title>ElderShield - Home</title>
</head>
<body>

  <h1>ElderShield</h1>
  <p>AI-Powered Scam Detection & Awareness Platform</p>

  <hr>

  <?php if (isset($_SESSION["user"])): ?>
    <p>Welcome, <strong><?php echo htmlspecialchars($_SESSION["user"]["full_name"]); ?></strong></p>
    <p>Role: <?php echo htmlspecialchars($_SESSION["user"]["role"]); ?></p>

    <ul>
      <li><a href="users/users_view.php">User Management (CRUD)</a></li>
      <li><a href="logout.php">Logout</a></li>
    </ul>
  <?php else: ?>
    <p>You are not logged in.</p>
    <a href="login.php">Login</a>
  <?php endif; ?>

</body>
</html>
