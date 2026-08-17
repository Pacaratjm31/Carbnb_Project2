<?php
session_start();
require_once '../database/db.php';

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $full_name = trim($_POST["full_name"]);
    $email = trim($_POST["email"]);
    $password = $_POST["password"];
    $confirm_password = $_POST["confirm_password"];

    if (
        empty($full_name) ||
        empty($email) ||
        empty($password) ||
        empty($confirm_password)
    ) {
        $error = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        try {
            $check = $pdo->prepare("
                SELECT id
                FROM users
                WHERE email = ?
                LIMIT 1
            ");

            $check->execute([$email]);

            if ($check->fetch()) {
                $error = "Email already exists.";
            } else {
                $hashedPassword = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $insert = $pdo->prepare("
                    INSERT INTO users
                    (
                        full_name,
                        email,
                        password,
                        role,
                        status
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        'admin',
                        'approved'
                    )
                ");

                $insert->execute([
                    $full_name,
                    $email,
                    $hashedPassword
                ]);

                $_SESSION["success"] = "Administrator account created successfully.";

                header("Location: admin_login.php");
                exit;
            }
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Carbnb Admin Register</title>
  <link rel="stylesheet" href="css/admin_style.css?v=20260702">
  <link rel="stylesheet" href="css/admin_style_backup.css?v=20260702">
</head>
<body>
  <div class="login-container">
    <div class="login-card">
      <div class="logo">
        <h1><span style="color:var(--accent-2);">Car</span><span style="color:var(--accent);">bnb</span></h1>
        <p>Administrator Registration</p>
      </div>

      <?php if (!empty($error)) : ?>
        <div class="alert error">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-group">
          <label>Full Name</label>
          <input type="text" name="full_name" class="form-control" placeholder="Enter full name" required>
        </div>

        <div class="form-group">
          <label>Email Address</label>
          <input type="email" name="email" class="form-control" placeholder="Enter email address" required>
        </div>

        <div class="form-group">
          <label>Password</label>
          <input type="password" name="password" class="form-control" placeholder="Enter password" required>
        </div>

        <div class="form-group">
          <label>Confirm Password</label>
          <input type="password" name="confirm_password" class="form-control" placeholder="Confirm password" required>
        </div>

        <button type="submit" class="login-btn">Register Admin</button>
      </form>

      <a href="admin_login.php" class="back-link">← Back to Admin Login</a>

      <div class="footer">
        Carbnb Administration Panel
      </div>
    </div>
  </div>
</body>
</html>