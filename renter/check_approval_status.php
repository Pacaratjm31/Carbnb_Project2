<?php
/**
 * check_approval_status.php
 * AJAX endpoint that returns JSON response for renter approval status
 * Location: /renter/check_approval_status.php
 */

// ============================================
// 1. DISABLE ERROR DISPLAY (prevents HTML errors)
// ============================================
ini_set('display_errors', 0);
ini_set('html_errors', 0);
error_reporting(0);

// ============================================
// 2. START SESSION
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// 3. SET JSON HEADER (MUST BE BEFORE ANY OUTPUT)
// ============================================
header('Content-Type: application/json; charset=utf-8');

// ============================================
// 4. HELPER FUNCTION - Send JSON and exit
// ============================================
function sendJsonResponse($data, $exit = true) {
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($exit) {
        exit;
    }
}

// ============================================
// 5. GET USER ID FROM REQUEST
// ============================================
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

// ============================================
// 6. VALIDATE USER ID
// ============================================
if ($userId <= 0) {
    sendJsonResponse([
        'success' => false,
        'error' => 'Invalid user ID',
        'status' => 'error'
    ]);
}

// ============================================
// 7. VALIDATE SESSION
// ============================================
if (!isset($_SESSION['user_id'])) {
    sendJsonResponse([
        'success' => false,
        'error' => 'Not logged in',
        'status' => 'error'
    ]);
}

// ============================================
// 8. VALIDATE USER MATCHES SESSION
// ============================================
if ((int)$_SESSION['user_id'] !== $userId) {
    sendJsonResponse([
        'success' => false,
        'error' => 'Unauthorized access',
        'status' => 'error'
    ]);
}

// ============================================
// 9. INCLUDE DATABASE CONNECTION
// ============================================
require_once __DIR__ . '/../database/db.php';

// Get database connection
$conn = $GLOBALS['conn'] ?? $GLOBALS['pdo'] ?? null;

if (!$conn) {
    sendJsonResponse([
        'success' => false,
        'error' => 'Database connection not available',
        'status' => 'error'
    ]);
}

// ============================================
// 10. QUERY DATABASE
// ============================================
try {
    // Prepare and execute query
    $stmt = $conn->prepare("SELECT status, disapproval_reason FROM users WHERE id = ? AND is_deleted = 0 LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if user exists
    if (!$user) {
        sendJsonResponse([
            'success' => false,
            'error' => 'User not found in database',
            'status' => 'error'
        ]);
    }
    
    // ============================================
    // 11. SUCCESS - RETURN USER STATUS
    // ============================================
    $status = $user['status'] ?? 'pending';
    $disapprovalReason = $user['disapproval_reason'] ?? '';
    
    // Map status to display label
    $statusLabels = [
        'approved' => 'Approved',
        'pending' => 'Pending',
        'disapproved' => 'Disapproved',
    ];
    $statusLabel = $statusLabels[$status] ?? ucfirst($status);
    
    // Success response
    sendJsonResponse([
        'success' => true,
        'user_id' => $userId,
        'status' => $status,
        'disapproval_reason' => $disapprovalReason,
        'status_label' => $statusLabel,
        'is_approved' => ($status === 'approved'),
        'is_pending' => ($status === 'pending'),
        'is_disapproved' => ($status === 'disapproved'),
        'message' => $status === 'approved' ? 'Account is approved' : 
                    ($status === 'disapproved' ? 'Account is disapproved' : 'Account is pending approval')
    ]);
    
} catch (PDOException $e) {
    // Log error for debugging (server-side)
    error_log('check_approval_status.php PDO Error: ' . $e->getMessage() . ' at ' . $e->getFile() . ' line ' . $e->getLine());
    
    // Return user-friendly error
    sendJsonResponse([
        'success' => false,
        'error' => 'Database error occurred',
        'status' => 'error'
    ]);
} catch (Exception $e) {
    // Catch any other errors
    error_log('check_approval_status.php Error: ' . $e->getMessage());
    
    sendJsonResponse([
        'success' => false,
        'error' => 'An unexpected error occurred',
        'status' => 'error'
    ]);
}
?>