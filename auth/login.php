<?php
session_start();
require_once __DIR__ . '/../database/db.php';

$error = '';
$success = '';
$is_locked = false;

// Check for registration success message
if (isset($_GET['registered']) && $_GET['registered'] == 1) {
    $success = "Registration successful! Your account is pending admin approval. Please log in.";
}

// Check for face registration success message
if (isset($_GET['face_registered']) && $_GET['face_registered'] == 1) {
    $success = "Face registration complete! Your account is pending admin approval. Please log in.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Please enter your email and password.";
    } else {
        $stmt = $pdo->prepare("
            SELECT *
            FROM users
            WHERE email = ?
            AND is_deleted = 0
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = "Invalid email or password.";
        } else {
            // ============================================
            // ACCOUNT LOCK CHECK (PERMANENT LOCK)
            // ============================================
            $is_locked = false;

            if (!empty($user['locked_until'])) {
                $lock_time = strtotime($user['locked_until']);
                $current_time = time();
                
                if ($lock_time > $current_time) {
                    $is_locked = true;
                    $error = "Account is locked. Please contact admin to unlock your account.";
                } else {
                    // Lock expired, reset attempts
                    $reset = $pdo->prepare("
                        UPDATE users
                        SET login_attempts = 0, locked_until = NULL
                        WHERE id = ?
                    ");
                    $reset->execute([$user['id']]);
                }
            }

            // ============================================
            // ONLY PROCESS LOGIN IF NOT LOCKED
            // ============================================
            if (!$is_locked) {
                // ============================================
                // PASSWORD CHECK
                // ============================================
                if (password_verify($password, $user['password'])) {
                    // ============================================
                    // RESET LOGIN ATTEMPTS ON SUCCESS
                    // ============================================
                    $reset = $pdo->prepare("
                        UPDATE users
                        SET login_attempts = 0, locked_until = NULL
                        WHERE id = ?
                    ");
                    $reset->execute([$user['id']]);

                    // ============================================
                    // DISAPPROVED ACCOUNT - BLOCK ACCESS
                    // ============================================
                    if ($user['status'] === 'disapproved') {
                        $reason = !empty($user['disapproval_reason']) ? $user['disapproval_reason'] : 'No reason provided.';
                        $error = "Account disapproved. " . $reason;
                        session_destroy();
                    } else {
                        // ============================================
                        // SET SESSION VARIABLES
                        // ============================================
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['full_name'] = $user['full_name'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['status'] = $user['status'];
                        $_SESSION['approval_status'] = $user['status'];
                        $_SESSION['approval_reason'] = $user['disapproval_reason'] ?? '';
                        $_SESSION['face_verified'] = $user['face_verified'] ?? 0;
                        $_SESSION['face_image'] = $user['face_image'] ?? '';

                        // ============================================
                        // REDIRECT BASED ON ROLE
                        // ============================================
                        if ($user['role'] === 'renter') {
                            if (!empty($user['face_image']) && $user['face_verified'] == 1) {
                                header("Location: ../renter/browse.php");
                            } else {
                                header("Location: face_verify.php");
                            }
                            exit();
                        } elseif ($user['role'] === 'owner') {
                            header("Location: ../owner/owner_dashboard.php");
                            exit();
                        } elseif ($user['role'] === 'admin') {
                            header("Location: ../admin/admin_dashboard.php");
                            exit();
                        } else {
                            session_destroy();
                            $error = "Invalid account role.";
                        }
                    }
                } else {
                    // ============================================
                    // WRONG PASSWORD - INCREMENT ATTEMPTS
                    // ============================================
                    $attempts = $user['login_attempts'] + 1;
                    $max_attempts = 3;

                    if ($attempts >= $max_attempts) {
                        // Lock the account PERMANENTLY (until admin unlocks)
                        $locked_until = date('Y-m-d H:i:s', strtotime('+1 year'));
                        $lock = $pdo->prepare("
                            UPDATE users
                            SET login_attempts = ?, locked_until = ?
                            WHERE id = ?
                        ");
                        $lock->execute([$attempts, $locked_until, $user['id']]);
                        
                        $error = "Too many failed login attempts. Your account is locked. Please contact admin to unlock.";
                        $is_locked = true;
                    } else {
                        $update = $pdo->prepare("
                            UPDATE users
                            SET login_attempts = ?
                            WHERE id = ?
                        ");
                        $update->execute([$attempts, $user['id']]);
                        
                        $left = $max_attempts - $attempts;
                        $error = "Invalid password. " . $left . " attempt(s) remaining.";
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link rel="stylesheet" href="login_style.css">
    <style>
        body {
            background: #1e1e1e;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .login-container {
            max-width: 450px;
            width: 100%;
            margin: 0 auto;
            padding: 35px;
            background: #2a2a2a;
            border-radius: 15px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.4);
        }
        .login-container h2 {
            text-align: center;
            color: #ffd700;
            margin-bottom: 25px;
            font-size: 2rem;
            font-weight: 700;
        }
        .login-container h2::after {
            content: "";
            display: block;
            width: 60px;
            height: 3px;
            background: #00bfff;
            margin: 10px auto 0;
            border-radius: 10px;
        }
        .login-container input {
            width: 100%;
            padding: 14px;
            margin-bottom: 15px;
            border: 1px solid #444;
            border-radius: 10px;
            background: #1e1e1e;
            color: #cfcfcf;
            font-size: 15px;
            transition: .3s;
            box-sizing: border-box;
        }
        .login-container input:focus {
            outline: none;
            border-color: #00bfff;
            box-shadow: 0 0 10px rgba(0, 191, 255, .3);
        }
        .login-container input::placeholder {
            color: #888;
        }
        .login-container button {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 10px;
            background: #00bfff;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: .3s;
        }
        .login-container button:hover {
            background: #0099cc;
            transform: translateY(-2px);
        }
        .login-container button:disabled {
            background: #555;
            color: #999;
            cursor: not-allowed;
            transform: none;
        }
        .navigate {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 50px;
            color: lightskyblue;
            text-decoration: none;
            transition: 0.3s;
        }
        .navigate:hover {
            text-decoration: underline;
        }
        .back-home {
            display: block;
            text-align: center;
            margin-top: 10px;
            color: lightskyblue;
            text-decoration: none;
            font-size: 14px;
            transition: 0.3s;
        }
        .back-home:hover {
            text-decoration: underline;
            color: #ffd700;
        }
        .success-message {
            background-color: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            border: 1px solid #f5c6cb;
        }
        @media (max-width: 768px) {
            .login-container {
                padding: 25px;
            }
            .login-container h2 {
                font-size: 1.7rem;
            }
            .login-container input {
                padding: 12px;
            }
            .login-container button {
                padding: 12px;
            }
        }
        @media (max-width: 480px) {
            .login-container {
                padding: 20px;
                border-radius: 12px;
            }
            .login-container h2 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>

<div class="login-container">
    <h2>Login</h2>

    <?php if (!empty($success)): ?>
        <div class="success-message">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="error-message">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="loginForm">
        <input
            type="email"
            name="email"
            placeholder="Email Address"
            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
            required
            <?= $is_locked ? 'disabled' : '' ?>
        >
        <input
            type="password"
            name="password"
            placeholder="Password"
            required
            <?= $is_locked ? 'disabled' : '' ?>
        >
        <button type="submit" id="loginBtn" <?= $is_locked ? 'disabled' : '' ?>>
            <?= $is_locked ? 'Account Locked' : 'Login' ?>
        </button>
    </form>

    <a href="register.php" class="navigate">Don't have an account? Register here!</a>

    <!-- ============================================ -->
    <!-- RETURN TO HOME BUTTON                        -->
    <!-- ============================================ -->
    <a href="../index.php" class="back-home">← Back to Home</a>
</div>

<script>
// ============================================
// PREVENT DOUBLE SUBMISSION
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('loginForm');
    const btn = document.getElementById('loginBtn');
    
    if (form && btn) {
        form.addEventListener('submit', function() {
            btn.disabled = true;
            btn.textContent = 'Logging in...';
        });
    }
});
</script>

</body>
</html>