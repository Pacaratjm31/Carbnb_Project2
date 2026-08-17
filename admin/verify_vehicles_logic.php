<?php
// Verify Vehicles Logic - Vehicle verification and approval logic
require_once 'admin_auth.php';

$vehicles = [];

// Fetch vehicles for verification
try {
    $stmt = $pdo->query("
        SELECT
            v.*,
            u.full_name AS owner_name,
            u.email AS owner_email
        FROM vehicles v
        INNER JOIN users u
            ON v.owner_id = u.id
        WHERE v.is_deleted = 0
        ORDER BY
            FIELD(v.approval_status,
                'pending',
                'approved',
                'disapproved'
            ),
            v.created_at DESC
    ");
    $vehicles = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = $e->getMessage();
}

// Handle approve/reject actions
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['vehicle_id']) &&
    isset($_POST['action'])
) {
    $vehicleId = (int) $_POST['vehicle_id'];
    $action = $_POST['action'];
    $feedback = trim($_POST['feedback'] ?? '');

    try {
        if ($action === 'approve') {
            $stmt = $pdo->prepare("
                UPDATE vehicles
                SET
                    approval_status = 'approved',
                    approval_feedback = NULL
                WHERE id = ?
            ");
            $stmt->execute([$vehicleId]);
            redirectSuccess(
                'verify_vehicles.php',
                'Vehicle approved successfully.'
            );
        }

        if ($action === 'reject') {
            $stmt = $pdo->prepare("
                UPDATE vehicles
                SET
                    approval_status = 'disapproved',
                    approval_feedback = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $feedback,
                $vehicleId
            ]);
            redirectSuccess(
                'verify_vehicles.php',
                'Vehicle disapproved successfully.'
            );
        }
    } catch (PDOException $e) {
        redirectError(
            'verify_vehicles.php',
            $e->getMessage()
        );
    }
}
?>