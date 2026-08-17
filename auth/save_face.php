<?php
session_start();
require_once __DIR__ . '/../database/db.php';

// ============================================
// CSRF VALIDATION
// ============================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: register.php");
    exit();
}

// Check if form token exists and is valid
if (!isset($_POST['form_token']) || !isset($_SESSION['face_form_token']) || $_POST['form_token'] !== $_SESSION['face_form_token']) {
    die("Invalid form submission. Please try again.");
}

// Clear token after use
unset($_SESSION['face_form_token']);

$userId = (int) ($_POST['user_id'] ?? 0);
$faceImage = $_POST['face_image'] ?? null;
$faceEncoding = $_POST['face_encoding'] ?? null;

if ($userId <= 0 || empty($faceImage) || empty($faceEncoding)) {
    die("Invalid face registration data.");
}

// Verify session matches user ID
if (!isset($_SESSION['face_registration_user_id']) || (int) $_SESSION['face_registration_user_id'] !== $userId) {
    die("Session mismatch. Please register again.");
}

// ============================================
// VALIDATE FACE ENCODING
// ============================================
$descriptor = json_decode($faceEncoding, true);
if (!is_array($descriptor) || count($descriptor) < 2) {
    die("Invalid face descriptor.");
}

// ============================================
// SAVE FACE IMAGE
// ============================================
$uploadDir = "../uploads/face_auth/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Decode base64 image
$faceImage = str_replace('data:image/png;base64,', '', $faceImage);
$faceImage = str_replace(' ', '+', $faceImage);
$imageData = base64_decode($faceImage, true);

if ($imageData === false) {
    die("Failed to decode image.");
}

$fileName = 'face_' . $userId . '_' . time() . '.png';
$filePath = $uploadDir . $fileName;

if (!file_put_contents($filePath, $imageData)) {
    die("Failed to save face image.");
}

// ============================================
// UPDATE DATABASE
// ============================================
$dbFacePath = "uploads/face_auth/" . $fileName;
$descriptorJson = json_encode($descriptor);

try {
    $stmt = $pdo->prepare("
        UPDATE users
        SET
            face_image = ?,
            face_descriptor = ?,
            face_verified = 0
        WHERE id = ?
    ");
    $stmt->execute([$dbFacePath, $descriptorJson, $userId]);

    // ============================================
    // CLEANUP SESSION
    // ============================================
    unset($_SESSION['face_registration_user_id']);
    unset($_SESSION['registration_success']);
    unset($_SESSION['face_form_token']);

    // ============================================
    // REDIRECT TO LOGIN
    // ============================================
    header("Location: login.php?face_registered=1");
    exit();

} catch (PDOException $e) {
    // Clean up file if database fails
    if (file_exists($filePath)) {
        unlink($filePath);
    }
    die("Database error: " . $e->getMessage());
}
?>