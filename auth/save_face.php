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
    // Also check if user is logged in directly
    if (!isset($_SESSION['user_id']) || (int) $_SESSION['user_id'] !== $userId) {
        die("Session mismatch. Please register again.");
    }
}

// ============================================
// FIX: Strict 128-value descriptor validation
// ============================================
$descriptor = json_decode($faceEncoding, true);

// Check JSON decode success
if (!is_array($descriptor)) {
    die("Invalid face descriptor: JSON decode failed.");
}

// Check exact length (face-api.js always returns 128 values)
if (count($descriptor) !== 128) {
    die("Invalid face descriptor: expected 128 values, got " . count($descriptor));
}

// Validate each value is numeric and finite
foreach ($descriptor as $value) {
    if (!is_numeric($value) || !is_finite((float)$value)) {
        die("Invalid face descriptor: non-numeric or non-finite value detected.");
    }
}

// ============================================
// SAVE FACE IMAGE - FIXED PATH
// ============================================
// IMPORTANT: Using "face_auth" as shown in your folder structure
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

// Generate unique filename
$fileName = 'face_' . $userId . '_' . time() . '.png';
$filePath = $uploadDir . $fileName;

if (!file_put_contents($filePath, $imageData)) {
    die("Failed to save face image.");
}

// ============================================
// ENCODE DESCRIPTOR WITH ERROR CHECK
// ============================================
$descriptorJson = json_encode($descriptor);

// FIX: Check json_encode success before storing
if ($descriptorJson === false) {
    // Clean up file if JSON encoding fails
    if (file_exists($filePath)) {
        unlink($filePath);
    }
    die("Failed to encode face descriptor. Please try again.");
}

// ============================================
// UPDATE DATABASE - FIXED PATH
// ============================================
// IMPORTANT: Store path relative to root (without leading slash)
// This matches the path structure in your database:
// "uploads/face_auth/filename.png"
$dbFacePath = "uploads/face_auth/" . $fileName;

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

    // Verify the update was successful
    $checkStmt = $pdo->prepare("SELECT face_image FROM users WHERE id = ?");
    $checkStmt->execute([$userId]);
    $result = $checkStmt->fetch();
    
    if (!$result || empty($result['face_image'])) {
        // If update failed, clean up file
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        die("Failed to update database with face data.");
    }

    // ============================================
    // CLEANUP SESSION
    // ============================================
    unset($_SESSION['face_registration_user_id']);
    unset($_SESSION['registration_success']);
    unset($_SESSION['face_form_token']);

    // ============================================
    // SET SUCCESS MESSAGE
    // ============================================
    $_SESSION['registration_success'] = "Face registered successfully! Please login again.";

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
    
    // Log error (but don't expose to user)
    error_log("Face registration database error: " . $e->getMessage());
    
    die("Database error occurred. Please try again.");
}
?>