<?php
session_start();

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $email = trim($_POST["email"] ?? "");
  $password = trim($_POST["password"] ?? "");

  if ($email === "" || $password === "") {
    $error = "Please enter email and password.";
  } else {
    // MOCK LOGIN (Checkpoint version)
    $_SESSION["user"] = [
      "full_name" => "Admin User",
      "email" => $email,
      "role" => "admin"
    ];

    header("Location: index.php");
    exit();
  }
}
?>

<!DOCTYPE html>
<html>
<head>
  <title>ElderShield - Login</title>
</head>
<body>

  <h1>Login</h1>
  <p>Enter credentials to access ElderShield.</p>

  <hr>

  <?php if ($error !== ""): ?>
    <p style="color:red;"><?php echo htmlspecialchars($error); ?></p>
  <?php endif; ?>

  <form method="POST" action="login.php">
    <label>Email:</label><br>
    <input type="text" name="email"><br><br>

    <label>Password:</label><br>
    <input type="password" name="password"><br><br>

    <button type="submit">Login</button>
  </form>

  <br>
  <a href="index.php">Back to Home</a>

</body>
</html>
