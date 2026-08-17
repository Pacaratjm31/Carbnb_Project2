<?php
session_start();

// ============================================================
// FIXED: Allow both logged-in users AND registration flow
// ============================================================
$userId = null;

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
} 
// Or check if in registration flow
else if (isset($_SESSION['face_registration_user_id'])) {
    $userId = $_SESSION['face_registration_user_id'];
} 
// Neither - redirect to register
else {
    header("Location: register.php");
    exit();
}

// Verify user exists in database
require_once __DIR__ . '/../database/db.php';

$checkStmt = $pdo->prepare("SELECT id, role, status FROM users WHERE id = ? AND is_deleted = 0");
$checkStmt->execute([$userId]);
$userCheck = $checkStmt->fetch();

if (!$userCheck) {
    session_destroy();
    header("Location: register.php");
    exit();
}

// Store user ID in session for consistency
$_SESSION['face_registration_user_id'] = $userId;

// Generate CSRF token for form protection
if (empty($_SESSION['face_form_token'])) {
    $_SESSION['face_form_token'] = bin2hex(random_bytes(32));
}
$form_token = $_SESSION['face_form_token'];

// Check if user already has face registered
$faceCheck = $pdo->prepare("SELECT face_image, face_descriptor FROM users WHERE id = ?");
$faceCheck->execute([$userId]);
$faceData = $faceCheck->fetch();

$hasFace = !empty($faceData['face_image']) && !empty($faceData['face_descriptor']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Face Registration - Carbnb</title>
    <link rel="stylesheet" href="face_register_style.css">
</head>
<body>

<div class="face-container">

    <h2>📸 Face Registration</h2>
    <p class="subtitle">Secure your account with facial recognition</p>

    <?php if (!empty($_SESSION['registration_success'])): ?>
        <div class="success-message">
            ✅ <?= htmlspecialchars($_SESSION['registration_success']) ?>
        </div>
        <?php unset($_SESSION['registration_success']); ?>
    <?php endif; ?>

    <?php if ($hasFace): ?>
        <div class="already-registered">
            <p>✅ You already have a registered face template.</p>
            <p style="font-size: 13px; color: #999; margin-top: 5px;">
                If you want to update your face, you can re-register below.
            </p>
        </div>
    <?php endif; ?>

    <p class="instruction">
        👤 Position your face clearly in front of the camera.<br>
        <small style="color: #888;">Make sure your face is well-lit and visible</small>
    </p>

    <div class="camera-wrapper">
        <video id="video" autoplay muted playsinline></video>
        <canvas id="canvas"></canvas>
        
        <div id="loadingOverlay">
            <div class="spinner"></div>
            <p>Loading face detection models...</p>
        </div>
        
        <div id="faceIndicator" class="face-detected-indicator">
            ✅ Face Detected
        </div>
    </div>

    <div id="statusMessage" class="status-message">
        ⏳ Initializing camera...
    </div>

    <button id="captureBtn" disabled type="button">
        📷 Capture Face
    </button>

    <div class="info-box">
        <strong>💡 Tips for best results:</strong><br>
        • Ensure good lighting on your face<br>
        • Remove glasses, hats, or masks<br>
        • Look directly at the camera<br>
        • Keep your face centered in frame
    </div>

    <form id="faceForm" method="POST" action="save_face.php">
        <input type="hidden" name="user_id" value="<?= htmlspecialchars($userId) ?>">
        <input type="hidden" name="face_image" id="faceImage">
        <input type="hidden" name="face_encoding" id="faceEncoding">
        <input type="hidden" name="form_token" value="<?= htmlspecialchars($form_token) ?>">
    </form>

</div>

<script src="../face-api.js-master/dist/face-api.min.js"></script>
<script src="script_capture.js?v=<?= time() ?>"></script>

</body>
</html>