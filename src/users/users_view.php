<?php
session_start();

if (!isset($_SESSION["user"])) {
  header("Location: ../login.php");
  exit();
}

// Initialize fake database
if (!isset($_SESSION["users_db"])) {
  $_SESSION["users_db"] = [
    [
      "user_id" => 1,
      "full_name" => "Mary Johnson",
      "email" => "mary@example.com",
      "role" => "elder"
    ],
    [
      "user_id" => 2,
      "full_name" => "Chris Johnson",
      "email" => "chris@example.com",
      "role" => "caregiver"
    ]
  ];
}

$users = $_SESSION["users_db"];
?>

<!DOCTYPE html>
<html>
<head>
  <title>User Management - View Users</title>
</head>
<body>

  <h1>User Management</h1>
  <p>View all users (CRUD checkpoint)</p>

  <hr>

  <a href="../index.php">Home</a> |
  <a href="users_add.php">Add User</a>

  <hr>

  <table border="1" cellpadding="6">
    <tr>
      <th>User ID</th>
      <th>Full Name</th>
      <th>Email</th>
      <th>Role</th>
      <th>Actions</th>
    </tr>

    <?php foreach ($users as $u): ?>
      <tr>
        <td><?php echo htmlspecialchars($u["user_id"]); ?></td>
        <td><?php echo htmlspecialchars($u["full_name"]); ?></td>
        <td><?php echo htmlspecialchars($u["email"]); ?></td>
        <td><?php echo htmlspecialchars($u["role"]); ?></td>
        <td>
          <a href="users_edit.php?id=<?php echo $u["user_id"]; ?>">Edit</a>

          <form method="POST" action="users_delete.php" style="display:inline;">
            <input type="hidden" name="id" value="<?php echo $u["user_id"]; ?>">
            <button type="submit">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

</body>
</html>
