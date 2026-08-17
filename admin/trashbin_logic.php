<?php
// Trash Bin Logic - Deleted user management
require_once 'admin_auth.php';

$deletedUsers = [];

// Fetch deleted users
try {
    $stmt = $pdo->query("
        SELECT
            id,
            full_name,
            email,
            role,
            deleted_at
        FROM users
        WHERE is_deleted = 1
        ORDER BY deleted_at DESC
    ");
    $deletedUsers = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = $e->getMessage();
}

// Handle restore user action
if (isset($_GET['restore_id'])) {
    $userId = (int)$_GET['restore_id'];

    try {
        $stmt = $pdo->prepare("
            UPDATE users
            SET
                is_deleted = 0,
                deleted_at = NULL
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        redirectSuccess(
            'trashbin.php',
            'User account restored successfully.'
        );
    } catch (PDOException $e) {
        redirectError(
            'trashbin.php',
            $e->getMessage()
        );
    }
}

// Handle permanent delete user action
if (isset($_GET['permanent_delete_id'])) {
    $userId = (int)$_GET['permanent_delete_id'];

    try {
        $stmt = $pdo->prepare("
            DELETE FROM users
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        redirectSuccess(
            'trashbin.php',
            'User account permanently deleted.'
        );
    } catch (PDOException $e) {
        redirectError(
            'trashbin.php',
            $e->getMessage()
        );
    }
}
?>