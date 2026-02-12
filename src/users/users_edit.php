<?php
session_start();

if (!isset($_SESSION["user"])) {
  header("Location: ../login.php");
  exit();
}

$users = $_SESSION["users_db"];
$id = intval($_GET["id"] ?? 0);

$user_to_edit = null;

foreach ($users as $u) {
  if ($u["user_id"] === $id) {
    $user_to_edit = $u;
    break;
  }
}

if (!$user_to_edit) {
  die("User not found.");
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $full_name = trim($_POST["full_name"] ?? "");
  $email = trim($_POST["email"] ?? "");
  $role = trim($_POST["role"] ?? "");

  if ($full_name === "" || $email === "" || $role === "") {
    $error = "All fields are required.";
  } else {
    // Update user in session DB
    foreach ($users as &$u) {
      if ($u["user_id"] === $id) {
        $u["full_name"] = $full_name;
        $u["email"] = $email;
        $u["role"] = $role;
        break;
      }
    }

    $_SESSION["users_db"] = $users;

    header("Location: users_view.php");
    exit();
  }
}
?>

<!DOCTYPE html>
<html>
<head>
  <title>Edit User</title>
</head>
<body>

  <h1>Edit User</h1>
  <p>Update user information.</p>

  <hr>

  <a href="users_view.php">Back to View Users</a>

  <hr>

  <?php if ($error !== ""): ?>
    <p style="color:red;"><?php echo htmlspecialchars($error); ?></p>
  <?php endif; ?>

  <form method="POST" action="users_edit.php?id=<?php echo $id; ?>">
    <label>Full Name:</label><br>
    <input type="text" name="full_name" value="<?php echo htmlspecialchars($user_to_edit["full_name"]); ?>"><br><br>

    <label>Email:</label><br>
    <input type="text" name="email" value="<?php echo htmlspecialchars($user_to_edit["email"]); ?>"><br><br>

    <label>Role:</label><br>
    <select name="role">
      <option value="">-- Select --</option>
      <option value="elder" <?php if ($user_to_edit["role"] === "elder") echo "selected"; ?>>elder</option>
      <option value="caregiver" <?php if ($user_to_edit["role"] === "caregiver") echo "selected"; ?>>caregiver</option>
      <option value="admin" <?php if ($user_to_edit["role"] === "admin") echo "selected"; ?>>admin</option>
    </select>
    <br><br>

    <button type="submit">Update User</button>
  </form>

</body>
</html>
