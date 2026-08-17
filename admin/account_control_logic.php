<?php
// Account Control Logic - Locked account management

require_once 'admin_auth.php';


// ==========================================================
// Initialize Variables
// ==========================================================

$lockedUsers = [];


// ==========================================================
// Handle Unlock User Action
// ==========================================================

if (isset($_GET['unlock_id'])) {

    $userId = (int) $_GET['unlock_id'];

    if ($userId > 0) {

        try {

            $stmt = $pdo->prepare("
                UPDATE users
                SET
                    login_attempts = 0,
                    locked_until = NULL
                WHERE id = ?
            ");

            $stmt->execute([$userId]);


            redirectSuccess(
                'account_control.php',
                'User account unlocked successfully.'
            );


        } catch (PDOException $e) {

            redirectError(
                'account_control.php',
                'Unable to unlock user: ' . $e->getMessage()
            );

        }

    }

}


// ==========================================================
// Fetch Locked Users
// ==========================================================

try {

    $stmt = $pdo->query("
        SELECT
            id,
            full_name,
            email,
            role,
            login_attempts,
            locked_until

        FROM users

        WHERE locked_until IS NOT NULL

        ORDER BY full_name ASC
    ");


    $lockedUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);


} catch (PDOException $e) {

    $error = $e->getMessage();

}

?>