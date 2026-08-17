<?php
session_start();

if (!isset($_SESSION['face_registration_user_id'])) {
    header("Location: register.php");
    exit();
}

$userId = $_SESSION['face_registration_user_id'];

// Generate CSRF token for form protection
if (empty($_SESSION['face_form_token'])) {
    $_SESSION['face_form_token'] = bin2hex(random_bytes(32));
}
$form_token = $_SESSION['face_form_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Face Registration</title>
    <link rel="stylesheet" href="face_register_style.css">
</head>
<body>

<div class="face-container">

    <h2>Face Registration</h2>

    <?php if (!empty($_SESSION['registration_success'])): ?>
        <div class="success-message" style="background-color: #d4edda; color: #155724; padding: 10px; border-radius: 5px; margin-bottom: 15px;">
            <?= htmlspecialchars($_SESSION['registration_success']) ?>
        </div>
    <?php unset($_SESSION['registration_success']); ?>
    <?php endif; ?>

    <p class="instruction">
        Position your face clearly in front of the camera.
    </p>

    <div class="camera-wrapper">
        <video id="video" autoplay muted playsinline></video>
        <canvas id="canvas"></canvas>
    </div>

    <div id="statusMessage" class="status-message">
        Loading face detection...
    </div>

    <button id="captureBtn" disabled type="button">
        Capture Face
    </button>

    <form id="faceForm" method="POST" action="save_face.php">
        <input type="hidden" name="user_id" value="<?= htmlspecialchars($userId) ?>">
        <input type="hidden" name="face_image" id="faceImage">
        <input type="hidden" name="face_encoding" id="faceEncoding">
        <input type="hidden" name="form_token" value="<?= htmlspecialchars($form_token) ?>">
    </form>

</div>

<script src="../face-api.js-master/dist/face-api.min.js"></script>
<script src="script_capture.js?v=20260819"></script>

</body>
</html>