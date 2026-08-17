<?php
/**
 * FIX: Face verification was only ever used as a one-time redirect
 * suggestion right after login (auth/login.php). None of the renter
 * pages (browse, book, paid, payment_api, record, renter_messages,
 * vehicle_details, view_profile, comment_rate) actually checked
 * face_verified, so a renter could skip face_verify.php entirely by
 * navigating straight to any of those URLs after logging in.
 *
 * Include this file (after session_start()/db.php, and after
 * confirming $_SESSION['user_id'] is set and $conn/$pdo is available)
 * on every renter-only page to enforce the real gate:
 * a renter must have $_SESSION['face_verified'] truthy, backed by
 * users.face_verified = 1 in the database, or they're bounced back
 * to auth/face_verify.php.
 *
 * Only enforced for role === 'renter' — owners/admins never go
 * through face registration by design (see auth/register.php).
 *
 * For JSON API endpoints, set $requireFaceVerifiedJson = true before
 * including this file so a failed check returns a JSON 403 instead
 * of an HTTP redirect.
 */

$requireFaceVerifiedJson = $requireFaceVerifiedJson ?? false;

if (($_SESSION['role'] ?? '') === 'renter') {
    $needsFaceCheck = empty($_SESSION['face_verified']);

    if ($needsFaceCheck) {
        // Session might be stale (e.g. verified in a previous session
        // on this device) — re-check the database before bouncing.
        $db = $conn ?? $GLOBALS['conn'] ?? $GLOBALS['pdo'] ?? null;

        if ($db) {
            $faceCheck = $db->prepare("SELECT face_verified FROM users WHERE id = ? AND is_deleted = 0");
            $faceCheck->execute([(int) $_SESSION['user_id']]);
            $faceRow = $faceCheck->fetch(PDO::FETCH_ASSOC);

            if ($faceRow && (int) $faceRow['face_verified'] === 1) {
                $_SESSION['face_verified'] = true;
                $needsFaceCheck = false;
            }
        }
    }

    if ($needsFaceCheck) {
        if ($requireFaceVerifiedJson) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Face verification required. Please verify your face to continue.'
            ]);
        } else {
            header('Location: ' . (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/renter/') !== false ? '../auth/face_verify.php' : 'face_verify.php'));
        }
        exit;
    }
}
