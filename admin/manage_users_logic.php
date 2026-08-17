<?php
// Manage Users Logic - User management and approval logic
require_once 'admin_auth.php';
include __DIR__ . '/../helpers/duplicate_functions.php';

$users = [];

// Fetch users for the list
try {
    $stmt = $pdo->query("
        SELECT *
        FROM users
        WHERE role <> 'admin'
        AND is_deleted = 0
        ORDER BY status ASC, created_at DESC
    ");
    $users = $stmt->fetchAll();
    
    // Fetch documents and face data for each user
    foreach ($users as $key => $user) {
        // Fetch user documents
        $docStmt = $pdo->prepare("
            SELECT document_type, file_path
            FROM user_documents
            WHERE user_id = ?
        ");
        $docStmt->execute([$user['id']]);
        $users[$key]['documents'] = $docStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add face image path for renters
        $users[$key]['face_image_path'] = $user['face_image'] ?? null;
    }
} catch (PDOException $e) {
    $error = $e->getMessage();
}

// Handle AJAX request for user data
if (isset($_GET['get_user_data'])) {
    $userId = (int)$_GET['get_user_data'];
    
    $stmt = $pdo->prepare("
        SELECT *
        FROM users
        WHERE id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // Fetch user documents
        $docStmt = $pdo->prepare("
            SELECT document_type, file_path
            FROM user_documents
            WHERE user_id = ?
        ");
        $docStmt->execute([$userId]);
        $user['documents'] = $docStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add face image path for renters
        $user['face_image_path'] = $user['face_image'] ?? null;
        
        header('Content-Type: application/json');
        echo json_encode($user);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
    }
    exit;
}

// Handle approve/reject actions
if (
    isset($_GET['action']) &&
    isset($_GET['id'])
) {
    $userId = (int)$_GET['id'];
    $action = $_GET['action'];
    $reason = trim($_GET['reason'] ?? '');

    if ($action === 'approve') {
        // Only update if user is currently pending (idempotency check)
        $stmt = $pdo->prepare("
            UPDATE users
            SET status = 'approved', disapproval_reason = NULL
            WHERE id = ? AND status = 'pending'
        ");
        $stmt->execute([$userId]);
        
        if ($stmt->rowCount() > 0) {
            redirectSuccess(
                'manage_users.php',
                'User approved successfully. Account is now verified and has full system access.'
            );
        } else {
            redirectError(
                'manage_users.php',
                'User was already processed or not found.'
            );
        }
    }

    if ($action === 'reject') {
        $disapprovalReason = $reason !== '' ? $reason : 'No reason provided.';
        // Only update if user is currently pending (idempotency check)
        $stmt = $pdo->prepare("
            UPDATE users
            SET status = 'disapproved', disapproval_reason = ?
            WHERE id = ? AND status = 'pending'
        ");
        $stmt->execute([$disapprovalReason, $userId]);
        
        if ($stmt->rowCount() > 0) {
            redirectSuccess(
                'manage_users.php',
                'User disapproved successfully. Access has been blocked.'
            );
        } else {
            redirectError(
                'manage_users.php',
                'User was already processed or not found.'
            );
        }
    }
}
?>
