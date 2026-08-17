<?php
session_start();
require_once __DIR__ . '/../database/db.php';

$error = '';
$success = '';

// Generate CSRF token for form protection
if (empty($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$form_token = $_SESSION['form_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ============================================
    // CSRF VALIDATION
    // ============================================
    if (!isset($_POST['form_token']) || $_POST['form_token'] !== $_SESSION['form_token']) {
        $error = "Invalid form submission. Please try again.";
    } else {
        // Clear token after use
        unset($_SESSION['form_token']);

        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $role = $_POST['role'] ?? '';

        // ============================================
        // VALIDATION
        // ============================================
        if (empty($full_name) || empty($email) || empty($password) || empty($confirm_password) || empty($role)) {
            $error = "Please fill in all fields.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email address.";
        } elseif (strlen($password) < 8) {
            $error = "Password must be at least 8 characters.";
        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match.";
        } else {
            try {
                // ============================================
                // CHECK FOR DUPLICATE EMAIL
                // ============================================
                $checkUser = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $checkUser->execute([$email]);

                if ($checkUser->fetch()) {
                    $error = "Email already exists.";
                } else {
                    // ============================================
                    // START TRANSACTION
                    // ============================================
                    $pdo->beginTransaction();

                    // ============================================
                    // HASH PASSWORD
                    // ============================================
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                    // ============================================
                    // INSERT USER WITH EXPLICIT STATUS
                    // ============================================
                    $insertUser = $pdo->prepare("
                        INSERT INTO users (full_name, email, password, role, status)
                        VALUES (?, ?, ?, ?, 'pending')
                    ");
                    $insertUser->execute([$full_name, $email, $hashed_password, $role]);
                    $user_id = $pdo->lastInsertId();

                    // ============================================
                    // CREATE UPLOAD DIRECTORIES
                    // ============================================
                    $upload_paths = [
                        'id1' => __DIR__ . '/../uploads/renters/id1/',
                        'id2' => __DIR__ . '/../uploads/renters/id2/',
                        'proof_of_billing' => __DIR__ . '/../uploads/renters/proof_of_billing/',
                        'drivers_license' => __DIR__ . '/../uploads/owners/drivers_license/',
                        'nbi_clearance' => __DIR__ . '/../uploads/owners/nbi_clearance/',
                        'intro_video' => __DIR__ . '/../uploads/owners/intro_video/'
                    ];

                    foreach ($upload_paths as $folder) {
                        if (!is_dir($folder)) {
                            mkdir($folder, 0777, true);
                        }
                    }

                    // ============================================
                    // DETERMINE REQUIRED DOCUMENTS BY ROLE
                    // ============================================
                    if ($role === 'renter') {
                        $requiredDocs = ['id1', 'id2', 'proof_of_billing'];
                        $maxFileSize = 5 * 1024 * 1024; // 5MB
                    } elseif ($role === 'owner') {
                        $requiredDocs = ['drivers_license', 'nbi_clearance', 'intro_video'];
                        $maxFileSize = 50 * 1024 * 1024; // 50MB for video
                    } else {
                        throw new Exception("Invalid role selected.");
                    }

                    // ============================================
                    // VALIDATE AND UPLOAD DOCUMENTS
                    // ============================================
                    $uploadedFiles = [];

                    foreach ($requiredDocs as $docType) {
                        if (!isset($_FILES[$docType]) || $_FILES[$docType]['error'] !== UPLOAD_ERR_OK) {
                            throw new Exception(ucfirst(str_replace('_', ' ', $docType)) . " is required.");
                        }

                        $file = $_FILES[$docType];
                        
                        // ============================================
                        // FILE SIZE VALIDATION
                        // ============================================
                        if ($file['size'] > $maxFileSize) {
                            $maxDisplay = $role === 'renter' ? '5MB' : '50MB';
                            throw new Exception(ucfirst(str_replace('_', ' ', $docType)) . " exceeds the maximum size of " . $maxDisplay . ".");
                        }

                        // ============================================
                        // FILE TYPE VALIDATION
                        // ============================================
                        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                        $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
                        
                        if ($docType === 'intro_video') {
                            $allowedExtensions = ['mp4', 'webm', 'ogg', 'mov'];
                        }

                        if (!in_array($extension, $allowedExtensions)) {
                            $allowed = implode(', ', $allowedExtensions);
                            throw new Exception(ucfirst(str_replace('_', ' ', $docType)) . " must be: " . $allowed);
                        }

                        // ============================================
                        // MIME TYPE VALIDATION (Security)
                        // ============================================
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mimeType = finfo_file($finfo, $file['tmp_name']);
                        finfo_close($finfo);

                        $allowedMimeTypes = ['image/jpeg', 'image/png', 'application/pdf'];
                        if ($docType === 'intro_video') {
                            $allowedMimeTypes = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'];
                        }

                        if (!in_array($mimeType, $allowedMimeTypes)) {
                            throw new Exception(ucfirst(str_replace('_', ' ', $docType)) . " has an invalid file type.");
                        }

                        // ============================================
                        // SAVE FILE
                        // ============================================
                        $filename = $user_id . "_" . time() . "_" . $docType . "." . $extension;
                        $destination = $upload_paths[$docType] . $filename;

                        if (!move_uploaded_file($file['tmp_name'], $destination)) {
                            throw new Exception("Failed to upload " . $docType);
                        }

                        $relativePath = str_replace(__DIR__ . '/../', '', $destination);
                        $uploadedFiles[] = [
                            'docType' => $docType,
                            'path' => $relativePath
                        ];
                    }

                    // ============================================
                    // INSERT DOCUMENTS (After all uploads succeed)
                    // ============================================
                    $insertDocument = $pdo->prepare("
                        INSERT INTO user_documents (user_id, document_type, file_path)
                        VALUES (?, ?, ?)
                    ");

                    foreach ($uploadedFiles as $file) {
                        $insertDocument->execute([$user_id, $file['docType'], $file['path']]);
                    }

                    // ============================================
                    // COMMIT TRANSACTION
                    // ============================================
                    $pdo->commit();

                    // ============================================
                    // REDIRECT
                    // ============================================
                    if ($role === 'renter') {
                        $_SESSION['face_registration_user_id'] = $user_id;
                        $_SESSION['registration_success'] = "Registration successful! Your account is pending admin approval. Please complete face registration.";
                        header("Location: face_register.php");
                        exit;
                    } else {
                        $_SESSION['registration_success'] = "Registration successful! Your owner account is pending admin approval.";
                        header("Location: login.php?registered=1");
                        exit;
                    }
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Registration failed: " . $e->getMessage();
            }
        }
    }
}

// ============================================
// GENERATE NEW CSRF TOKEN FOR NEXT SUBMISSION
// ============================================
if (empty($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register</title>
    <link rel="stylesheet" href="register_style.css">
    <style>
        .navigate {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 50px;
            color: lightskyblue;
        }
        .back-home {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 40px;
            color: lightskyblue;
            text-decoration: none;
            font-size: 14px;
            transition: 0.3s;
        }
        .back-home:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

<div class="register-wrapper">

    <!-- LEFT SIDE -->
    <div class="register-left">
        <h2>Create Account</h2>

        <?php if (!empty($error)): ?>
            <div class="error-message">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="success-message">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="registerForm">
            <input type="hidden" name="form_token" value="<?= htmlspecialchars($form_token) ?>">

            <input type="text" name="full_name" placeholder="Full Name" required>
            <input type="email" name="email" placeholder="Email Address" required>
            <input type="password" name="password" placeholder="Password (min 8 characters)" required minlength="8">
            <input type="password" name="confirm_password" placeholder="Confirm Password" required minlength="8">

            <select name="role" id="role" required>
                <option value="">Select Role</option>
                <option value="renter">Renter</option>
                <option value="owner">Vehicle Owner</option>
            </select>
    </div>

    <!-- RIGHT SIDE -->
    <div class="register-right">
        <h2>Required Documents</h2>

        <!-- RENTER -->
        <div id="renterFields" class="document-group">
            <h3>Renter Requirements</h3>
            <label>Valid ID #1</label>
            <input type="file" name="id1" accept=".jpg,.jpeg,.png,.pdf">
            <label>Valid ID #2</label>
            <input type="file" name="id2" accept=".jpg,.jpeg,.png,.pdf">
            <label>Proof of Billing</label>
            <input type="file" name="proof_of_billing" accept=".jpg,.jpeg,.png,.pdf">
            <p>Face registration will be required after successful registration.</p>
        </div>

        <!-- OWNER -->
        <div id="ownerFields" class="document-group">
            <h3>Vehicle Owner Requirements</h3>
            <label>Driver's License</label>
            <input type="file" name="drivers_license" accept=".jpg,.jpeg,.png,.pdf">
            <label>NBI Clearance</label>
            <input type="file" name="nbi_clearance" accept=".jpg,.jpeg,.png,.pdf">
            <p><strong>Video Intro Guide:</strong> Please record a short introduction video where you introduce yourself, show your valid ID, and briefly describe the car or vehicle you will rent out.</p>
            <label>Introduction Video</label>
            <input type="file" name="intro_video" accept="video/*">
            <small>Video Guide: Show your face, present your valid ID, and show proof of vehicle ownership.</small>
        </div>

        <button type="submit" id="registerBtn">Create Account</button>
        </form>

        <a href="login.php" class="navigate">Already have an account? Login here!</a>

        <!-- ============================================ -->
        <!-- RETURN TO HOME BUTTON (ONLY ADDED THIS)      -->
        <!-- ============================================ -->
        <a href="../index.php" class="back-home">← Back to Home</a>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const roleSelect = document.getElementById('role');
    const renterFields = document.getElementById('renterFields');
    const ownerFields = document.getElementById('ownerFields');
    const registerBtn = document.getElementById('registerBtn');

    function updateFields() {
        renterFields.style.display = 'none';
        ownerFields.style.display = 'none';

        renterFields.querySelectorAll('input').forEach(input => {
            input.required = false;
        });

        ownerFields.querySelectorAll('input').forEach(input => {
            input.required = false;
        });

        if (roleSelect.value === 'renter') {
            renterFields.style.display = 'block';
            renterFields.querySelectorAll('input').forEach(input => {
                input.required = true;
            });
        }

        if (roleSelect.value === 'owner') {
            ownerFields.style.display = 'block';
            ownerFields.querySelectorAll('input').forEach(input => {
                input.required = true;
            });
        }
    }

    updateFields();

    roleSelect.addEventListener('change', updateFields);

    // Prevent double submission
    const form = document.getElementById('registerForm');
    if (form) {
        form.addEventListener('submit', function() {
            registerBtn.disabled = true;
            registerBtn.textContent = 'Creating Account...';
        });
    }
});
</script>

</body>
</html>