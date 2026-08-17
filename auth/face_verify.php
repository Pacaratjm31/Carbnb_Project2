<?php
session_start();
require_once __DIR__ . '/../database/db.php';

function computeDescriptorDistance(array $left, array $right): float
{
    $length = min(count($left), count($right));

    if ($length < 2) {
        return INF;
    }

    $sum = 0.0;

    for ($i = 0; $i < $length; $i++) {
        $delta = (float) $left[$i] - (float) $right[$i];
        $sum += $delta * $delta;
    }

    return sqrt($sum);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Please log in again.'
        ]);
        exit();
    }

    $userId = (int) $_SESSION['user_id'];

    $stmt = $pdo->prepare("
        SELECT
            face_descriptor,
            face_verified,
            status,
            role
        FROM users
        WHERE id = ?
        AND is_deleted = 0
    ");

    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'User not found.'
        ]);
        exit();
    }

    if (empty($user['face_descriptor'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'No registered face template available.'
        ]);
        exit();
    }

    if (($user['status'] ?? '') === 'disapproved') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Your account has been disapproved by admin.'
        ]);
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $liveDescriptor = $input['descriptor'] ?? null;

    if (!is_array($liveDescriptor) || count($liveDescriptor) < 2) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid face descriptor.'
        ]);
        exit();
    }

    $registeredDescriptor = json_decode($user['face_descriptor'], true);

    if (!is_array($registeredDescriptor) || count($registeredDescriptor) < 2) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Registered face template is missing.'
        ]);
        exit();
    }

    $distance = computeDescriptorDistance($liveDescriptor, $registeredDescriptor);
    $threshold = 0.55;

    if ($distance <= $threshold) {
        $_SESSION['face_verified'] = true;
        $_SESSION['face_verified_at'] = time();

        $update = $pdo->prepare("
            UPDATE users
            SET face_verified = 1
            WHERE id = ?
        ");
        $update->execute([$userId]);

        $redirect = '../renter/browse.php';
        if (($user['role'] ?? '') === 'owner') {
            $redirect = '../owner/owner_dashboard.php';
        }

        echo json_encode([
            'success' => true,
            'distance' => round($distance, 4),
            'redirect' => $redirect
        ]);
    } else {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Face does not match the registered profile.',
            'distance' => round($distance, 4)
        ]);
    }

    exit();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT
        id,
        full_name,
        email,
        role,
        status,
        face_image,
        face_verified,
        face_descriptor
    FROM users
    WHERE id = ?
    AND is_deleted = 0
");

$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

if ($user['status'] === 'disapproved') {
    session_destroy();
    die("Your account has been disapproved.");
}

if ($user['role'] === 'owner') {
    header("Location: ../owner/owner_dashboard.php");
    exit();
}

if ($user['role'] !== 'renter') {
    session_destroy();
    header("Location: login.php");
    exit();
}

if (empty($user['face_descriptor'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Face Registration Required</title>
        <link rel="stylesheet" href="face_verify_style.css">
    </head>
    <body>
        <div class="face-container">
            <h2>Face Registration Required</h2>
            <p class="instruction">You need to register your face first before verifying.</p>
            <br>
            <div style="text-align: center;">
                <a href="face_register.php" style="display: inline-block; padding: 14px 40px; background: #00bfff; color: #fff; text-decoration: none; border-radius: 10px; font-weight: 600;">Register Face</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

$registeredFaceImage = "../" . $user['face_image'];
$registeredFaceDescriptor = json_decode($user['face_descriptor'], true);

// DEBUG: Log the descriptor to check if it's valid
error_log("Face Descriptor for user " . $userId . ": " . (is_array($registeredFaceDescriptor) ? "Array with " . count($registeredFaceDescriptor) . " values" : "NOT an array"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Face Verification</title>
    <link rel="stylesheet" href="face_verify_style.css">
</head>
<body>

<div class="face-container">

    <h2>Face Verification</h2>

    <?php if ($user['status'] === 'pending'): ?>
        <div class="status-message" style="background: rgba(255, 193, 7, 0.15); color: #ffd700; padding: 12px; border-radius: 8px; margin-bottom: 15px;">
            ⚠️ Your account is pending admin approval. Limited access until verified.
        </div>
    <?php endif; ?>

    <?php if ($user['face_verified'] == 1): ?>
        <div class="success-message">
            ✅ Your face is already verified. You can proceed.
        </div>
    <?php endif; ?>

    <p class="instruction">
        Position your face clearly in front of the camera and verify your identity.
    </p>

    <div class="camera-wrapper">
        <video id="video" autoplay muted playsinline></video>
        <canvas id="canvas"></canvas>
    </div>

    <div id="statusMessage" class="status-message">
        Loading face verification...
    </div>

    <button id="verifyBtn" type="button" disabled>
        Verify Face
    </button>

</div>

<script src="../face-api.js-master/dist/face-api.min.js"></script>
<script src="script_verify.js?v=<?= time() ?>"></script>

<script>
// FIX: Pass the face descriptor properly
var descriptor = <?php echo json_encode($registeredFaceDescriptor); ?>;

console.log("=== FACE DATA DEBUG ===");
console.log("Descriptor type:", typeof descriptor);
console.log("Is array:", Array.isArray(descriptor));
console.log("Descriptor length:", descriptor ? descriptor.length : 0);
console.log("First 5 values:", descriptor ? descriptor.slice(0, 5) : "None");

if (descriptor && Array.isArray(descriptor) && descriptor.length > 0) {
    window.registeredFaceDescriptor = descriptor;
    console.log("✅ Face descriptor loaded successfully!");
} else {
    window.registeredFaceDescriptor = [];
    console.error("❌ No face descriptor found!");
}

window.registeredFaceImage = <?php echo json_encode($registeredFaceImage); ?>;
</script>

</body>
</html>