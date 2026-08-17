<?php
session_start();
require_once '../database/db.php';

// If admin is already logged in, redirect to dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    header("Location: dashboard.php");
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST["email"]);
    $password = $_POST["password"];

    if (empty($email) || empty($password)) {
        $error = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT *
                FROM users
                WHERE email = ?
                AND role = 'admin'
                LIMIT 1
            ");

            $stmt->execute([$email]);

            $admin = $stmt->fetch();

            if ($admin) {
                if ($admin["status"] !== "approved") {
                    $error = "Admin account is not approved.";
                } elseif (password_verify($password, $admin["password"])) {
                    $_SESSION["user_id"] = $admin["id"];
                    $_SESSION["full_name"] = $admin["full_name"];
                    $_SESSION["email"] = $admin["email"];
                    $_SESSION["role"] = $admin["role"];

                    header("Location: dashboard.php");
                    exit;
                } else {
                    $error = "Invalid email or password.";
                }
            } else {
                $error = "Invalid email or password.";
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
  <title>Carbnb Admin Login</title>
  <link rel="stylesheet" href="css/admin_style.css?v=20260702">
  <link rel="stylesheet" href="css/admin_style_backup.css?v=20260702">
</head>
<body>
  <div class="login-container">
    <div class="login-card">
      <div class="logo">
        <h1><span style="color:var(--accent-2);">Car</span><span style="color:var(--accent);">bnb</span></h1>
        <p>Administrator Login</p>
      </div>

      <?php if (!empty($error)) : ?>
        <div class="alert error">
          <?= htmlspecialchars($error); ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="">
        <div class="form-group">
          <label>Email Address</label>
          <input type="email" name="email" class="form-control" placeholder="Enter your email address" required>
        </div>

        <div class="form-group">
          <label>Password</label>
          <input type="password" name="password" class="form-control" placeholder="Enter your password" required>
        </div>

        <button type="submit" class="login-btn">Login</button>
      </form>

      <a href="admin_register.php" class="back-link">Create Administrator Account</a>
      <a href="../home.php" class="back-link">← Back to Home</a>

      <div class="footer">
        <strong><span style="color:var(--accent-2);">Car</span><span style="color:var(--accent);">bnb</span></strong>
        <br><br>
        Self-Drive Car Rental Management System
        <br>
        Administrator Panel
        <br><br>
        &copy; 2026 Carbnb. All Rights Reserved.
      </div>
    </div>
  </div>
</body>
</html>