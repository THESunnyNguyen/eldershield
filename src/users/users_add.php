<?php
session_start();

if (!isset($_SESSION["user"])) {
  header("Location: ../login.php");
  exit();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $full_name = trim($_POST["full_name"] ?? "");
  $email = trim($_POST["email"] ?? "");
  $role = trim($_POST["role"] ?? "");

  if ($full_name === "" || $email === "" || $role === "") {
    $error = "All fields are required.";
  } else {
    $users = $_SESSION["users_db"];

    // Generate new ID
    $new_id = 1;
    foreach ($users as $u) {
      if ($u["user_id"] >= $new_id) {
        $new_id = $u["user_id"] + 1;
      }
    }

    $users[] = [
      "user_id" => $new_id,
      "full_name" => $full_name,
      "email" => $email,
      "role" => $role
    ];

    $_SESSION["users_db"] = $users;

    header("Location: users_view.php");
    exit();
  }
}
?>

<!DOCTYPE html>
<html>
<head>
  <title>Add User</title>
</head>
<body>

  <h1>Add User</h1>
  <p>Create a new user account.</p>

  <hr>

  <a href="users_view.php">Back to View Users</a>

  <hr>

  <?php if ($error !== ""): ?>
    <p style="color:red;"><?php echo htmlspecialchars($error); ?></p>
  <?php endif; ?>

  <form method="POST" action="users_add.php">
    <label>Full Name:</label><br>
    <input type="text" name="full_name"><br><br>

    <label>Email:</label><br>
    <input type="text" name="email"><br><br>

    <label>Role:</label><br>
    <select name="role">
      <option value="">-- Select --</option>
      <option value="elder">elder</option>
      <option value="caregiver">caregiver</option>
      <option value="admin">admin</option>
    </select>
    <br><br>

    <button type="submit">Create User</button>
  </form>

</body>
</html>
