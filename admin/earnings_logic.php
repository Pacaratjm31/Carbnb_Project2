<?php
// Earnings Logic - Payment approval and earnings distribution
require_once 'admin_auth.php';

// Handle payment approval/disapproval
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $payment_id = (int) ($_POST['payment_id'] ?? 0);
    $admin_id = (int) $_SESSION['user_id'];

    if ($payment_id > 0) {
        try {
            // Get payment and booking details
            $stmt = $pdo->prepare("
                SELECT p.id, p.booking_id, p.amount, p.status,
                       b.renter_id, b.vehicle_id,
                       v.owner_id
                FROM payments p
                JOIN bookings b ON p.booking_id = b.id
                JOIN vehicles v ON b.vehicle_id = v.id
                WHERE p.id = ?
            ");
            $stmt->execute([$payment_id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                redirectError('earnings.php', 'Payment not found.');
            }

            // FIXED: this was a duplicate of what was already verified as
            // pending, but a resubmitted/double-clicked request could
            // still process a payment twice and insert duplicate earnings
            // rows. Bail out early if it's already been handled.
            if ($payment['status'] !== 'pending') {
                redirectError('earnings.php', 'This payment was already processed.');
            }

            if ($action === 'approve') {
                // Calculate 20% platform commission and 80% owner income
                $amount = (float) $payment['amount'];
                $platform_commission = round($amount * 0.20, 2);
                $owner_income = round($amount * 0.80, 2);

                $pdo->beginTransaction();

                // Update payment status to verified
                $stmt = $pdo->prepare("UPDATE payments SET status = 'verified' WHERE id = ?");
                $stmt->execute([$payment_id]);

                // FIXED: booking stayed 'pending' forever even after its
                // payment was fully verified, because nothing ever moved
                // it to 'approved'. Renter/owner pages that check booking
                // status never showed the rental as confirmed.
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'approved' WHERE id = ?");
                $stmt->execute([$payment['booking_id']]);

                // Insert earnings record (20% to platform, 80% to owner)
                $stmt = $pdo->prepare("
                    INSERT INTO earnings (booking_id, owner_income, platform_commission)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$payment['booking_id'], $owner_income, $platform_commission]);

                $pdo->commit();

                redirectSuccess('earnings.php', 'Payment approved successfully. Earnings distributed.');
            } elseif ($action === 'disapprove') {
                $reason = trim($_POST['reason'] ?? 'Payment disapproved by admin');

                $pdo->beginTransaction();

                // Update payment status to disapproved
                $stmt = $pdo->prepare("UPDATE payments SET status = 'disapproved' WHERE id = ?");
                $stmt->execute([$payment_id]);

                // FIXED: same gap as approve - a disapproved payment left
                // its booking stuck on 'pending' instead of reflecting
                // that the booking was disapproved too.
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'disapproved' WHERE id = ?");
                $stmt->execute([$payment['booking_id']]);

                $pdo->commit();

                redirectSuccess('earnings.php', 'Payment disapproved.');
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            redirectError('earnings.php', 'Error processing payment: ' . $e->getMessage());
        }
    }
}

// Fetch pending payments for approval
$pending_payments = [];
try {
    $stmt = $pdo->query("
        SELECT 
            p.id AS payment_id,
            p.amount,
            p.proof_image,
            p.payment_method,
            p.payment_url,
            p.status AS payment_status,
            p.created_at AS payment_date,
            b.id AS booking_id,
            b.start_date,
            b.end_date,
            b.total_price,
            r.full_name AS renter_name,
            r.email AS renter_email,
            o.full_name AS owner_name,
            o.email AS owner_email,
            v.name AS vehicle_name,
            v.image AS vehicle_image
        FROM payments p
        JOIN bookings b ON p.booking_id = b.id
        JOIN users r ON b.renter_id = r.id
        JOIN vehicles v ON b.vehicle_id = v.id
        JOIN users o ON v.owner_id = o.id
        WHERE p.status = 'pending'
        ORDER BY p.created_at DESC
    ");
    $pending_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = $e->getMessage();
}

// Fetch all payments for history
$all_payments = [];
try {
    $stmt = $pdo->query("
        SELECT 
            p.id AS payment_id,
            p.amount,
            p.proof_image,
            p.payment_method,
            p.payment_url,
            p.status AS payment_status,
            p.created_at AS payment_date,
            b.id AS booking_id,
            b.start_date,
            b.end_date,
            r.full_name AS renter_name,
            o.full_name AS owner_name,
            v.name AS vehicle_name
        FROM payments p
        JOIN bookings b ON p.booking_id = b.id
        JOIN users r ON b.renter_id = r.id
        JOIN vehicles v ON b.vehicle_id = v.id
        JOIN users o ON v.owner_id = o.id
        ORDER BY p.created_at DESC
    ");
    $all_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = $e->getMessage();
}
?>
