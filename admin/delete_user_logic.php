<?php
// Delete User Logic - Soft delete user accounts
require_once 'admin_auth.php';

$users = [];

// Fetch users for deletion
try {
    $stmt = $pdo->query("
        SELECT
            id,
            full_name,
            email,
            role,
            status,
            created_at
        FROM users
        WHERE role <> 'admin'
        AND is_deleted = 0
        ORDER BY created_at DESC
    ");
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = $e->getMessage();
}

// Handle delete user action
if (isset($_GET['delete_id'])) {
    $userId = (int)$_GET['delete_id'];

    try {
        $stmt = $pdo->prepare("
            UPDATE users
            SET
                is_deleted = 1,
                deleted_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        redirectSuccess(
            'delete_user.php',
            'User moved to trash bin successfully.'
        );
    } catch (PDOException $e) {
        redirectError(
            'delete_user.php',
            $e->getMessage()
        );
    }
}
?>