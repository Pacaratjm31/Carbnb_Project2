<?php

// Small helper to convert PHP ini shorthand values (e.g. "8M", "2G")
// into a plain byte count, so we can compare against actual file sizes.
if (!function_exists('ini_parse_quantity')) {
    function ini_parse_quantity(string $val): int
    {
        $val = trim($val);
        if ($val === '' || $val === '0') {
            return 0;
        }
        $last = strtolower($val[strlen($val) - 1]);
        $num = (int) $val;
        switch ($last) {
            case 'g': $num *= 1024 * 1024 * 1024; break;
            case 'm': $num *= 1024 * 1024; break;
            case 'k': $num *= 1024; break;
        }
        return $num;
    }
}

require_once '../database/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

$conn = $GLOBALS['conn'] ?? $GLOBALS['pdo'] ?? null;

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'You must be logged in.'
    ]);
    exit;
}

if (!$conn) {
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed.'
    ]);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$requireFaceVerifiedJson = true;
require_once __DIR__ . '/../auth/require_face_verified.php';

$booking_id = (int) ($_POST['booking_id'] ?? 0);

if ($booking_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid booking ID.'
    ]);
    exit;
}

// Check booking belongs to renter
$stmt = $conn->prepare("
    SELECT id, total_price 
    FROM bookings
    WHERE id = ? AND renter_id = ?
");
$stmt->execute([$booking_id, $user_id]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Booking not found.'
    ]);
    exit;
}

$amount = (float) $booking['total_price'];

// Validate uploaded receipt/proof image
if (!isset($_FILES['proof_image'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Please choose a receipt image to upload.'
    ]);
    exit;
}

// Give a SPECIFIC reason instead of a generic "invalid file" message.
// This matters a lot on free hosting, where the server's own upload
// limits (upload_max_filesize / post_max_size) are often smaller than
// what this app assumes - a file that looks fine here can still get
// rejected by PHP itself before this script ever sees it properly.
if ($_FILES['proof_image']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'This file is larger than the server allows (server limit: ' . ini_get('upload_max_filesize') . '). Please upload a smaller image.',
        UPLOAD_ERR_FORM_SIZE  => 'This file is larger than the server allows. Please upload a smaller image.',
        UPLOAD_ERR_PARTIAL    => 'The upload was interrupted partway through. Please try again.',
        UPLOAD_ERR_NO_FILE    => 'No file was received. Please choose an image and try again.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server storage issue (no temp folder). Please contact support.',
        UPLOAD_ERR_CANT_WRITE => 'Server storage issue (couldn\'t write file). Please contact support.',
        UPLOAD_ERR_EXTENSION  => 'The upload was blocked by a server setting. Please contact support.',
    ];

    echo json_encode([
        'success' => false,
        'message' => $uploadErrors[$_FILES['proof_image']['error']]
            ?? 'Upload failed (error code ' . $_FILES['proof_image']['error'] . '). Please try again.'
    ]);
    exit;
}

$allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
$fileType = mime_content_type($_FILES['proof_image']['tmp_name']);

if (!in_array($fileType, $allowedTypes, true)) {
    echo json_encode([
        'success' => false,
        'message' => 'Only JPEG, PNG, and WebP images are allowed for the receipt.'
    ]);
    exit;
}

// Use the SMALLER of our own 5MB cap and whatever the server actually
// allows, so the limit we enforce always matches reality.
$serverLimitBytes = min(
    (int) ini_parse_quantity(ini_get('upload_max_filesize')),
    (int) ini_parse_quantity(ini_get('post_max_size'))
);
$appLimitBytes = 5 * 1024 * 1024;
$effectiveLimitBytes = ($serverLimitBytes > 0)
    ? min($serverLimitBytes, $appLimitBytes)
    : $appLimitBytes;

if ($_FILES['proof_image']['size'] > $effectiveLimitBytes) {
    $limitMb = round($effectiveLimitBytes / 1024 / 1024, 1);
    echo json_encode([
        'success' => false,
        'message' => "Receipt image must be less than {$limitMb}MB."
    ]);
    exit;
}

$uploadDir = __DIR__ . '/../uploads/payments/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$fileName = 'payment_' . $booking_id . '_' . time() . '_' . basename($_FILES['proof_image']['name']);
$uploadPath = $uploadDir . $fileName;

if (!move_uploaded_file($_FILES['proof_image']['tmp_name'], $uploadPath)) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to upload the receipt image. Please try again.'
    ]);
    exit;
}

$proofImagePath = $fileName;

// Check existing payment
$check = $conn->prepare("SELECT id FROM payments WHERE booking_id = ?");
$check->execute([$booking_id]);

try {

    if ($check->rowCount() > 0) {

        // Update existing payment
        $update = $conn->prepare("
            UPDATE payments SET
                amount = ?,
                proof_image = ?,
                status = 'pending',
                paid_at = NOW()
            WHERE booking_id = ?
        ");

        $update->execute([
            $amount,
            $proofImagePath,
            $booking_id
        ]);

    } else {

        // Create new payment
        $insert = $conn->prepare("
            INSERT INTO payments
            (
                booking_id,
                amount,
                proof_image,
                status,
                paid_at
            )
            VALUES
            (?, ?, ?, 'pending', NOW())
        ");

        $insert->execute([
            $booking_id,
            $amount,
            $proofImagePath
        ]);

    }

    echo json_encode([
        'success' => true,
        'message' => 'Payment submitted. Waiting for admin verification.'
    ]);

} catch (PDOException $e) {

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);

}
