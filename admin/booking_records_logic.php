<?php
// Booking Records Logic - Booking records display logic
require_once 'admin_auth.php';

$records = [];

// Fetch booking records
try {
    $stmt = $pdo->query("
        SELECT
            b.id,
            b.start_date,
            b.end_date,
            b.total_days,
            b.total_price,
            b.status,
            b.admin_id,
            r.full_name AS renter_name,
            r.email AS renter_email,
            o.full_name AS owner_name,
            v.name AS vehicle_name,
            v.category,
            v.transmission
        FROM bookings b
        INNER JOIN users r
            ON b.renter_id = r.id
        INNER JOIN vehicles v
            ON b.vehicle_id = v.id
        INNER JOIN users o
            ON v.owner_id = o.id
        ORDER BY b.created_at DESC
    ");
    $records = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = $e->getMessage();
}

// Handle approve/reject actions
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['booking_id']) &&
    isset($_POST['action'])
) {
    $bookingId = (int) $_POST['booking_id'];
    $action = $_POST['action'];
    $feedback = trim($_POST['feedback'] ?? '');
    $adminId = $_SESSION['user_id'];

    try {
        if ($action === 'approve') {
            $stmt = $pdo->prepare("
                UPDATE bookings
                SET
                    status = 'approved',
                    admin_id = ?
                WHERE id = ?
            ");
            $stmt->execute([$adminId, $bookingId]);
            redirectSuccess(
                'booking_records.php',
                'Booking approved successfully.'
            );
        }

        if ($action === 'reject') {
            $stmt = $pdo->prepare("
                UPDATE bookings
                SET
                    status = 'disapproved',
                    admin_id = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $adminId,
                $bookingId
            ]);
            redirectSuccess(
                'booking_records.php',
                'Booking disapproved successfully.'
            );
        }
    } catch (PDOException $e) {
        redirectError(
            'booking_records.php',
            $e->getMessage()
        );
    }
}
?>