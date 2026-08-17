<?php
require_once __DIR__ . '/../database/db.php';
session_start();

header('Content-Type: application/json');

$userId = (int) ($_GET['user_id'] ?? 0);

if ($userId <= 0) {
    echo json_encode(['error' => 'Invalid user ID']);
    exit;
}

// Verify the user is logged in and matches the requested user
if (empty($_SESSION['user_id']) || $_SESSION['user_id'] != $userId) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get PDO from global scope
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    echo json_encode(['error' => 'Database connection not available']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT status, disapproval_reason FROM users WHERE id = ? AND is_deleted = 0 LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo json_encode([
            'status' => $user['status'],
            'disapproval_reason' => $user['disapproval_reason'] ?? ''
        ]);
    } else {
        echo json_encode(['error' => 'User not found']);
    }
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error']);
}
?>
