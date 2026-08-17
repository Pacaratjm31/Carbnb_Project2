<?php
// Check Duplicate Functions
// This file contains functions to check for existing records in the database
// to prevent duplicate submissions.

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if a booking already exists for a vehicle with overlapping dates
function booking_exists(PDO $pdo, int $vehicleId, string $startDate, string $endDate): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM bookings 
        WHERE vehicle_id = ? 
        AND status IN ('pending', 'approved') 
        AND (start_date <= ? AND end_date >= ?)
    ");
    $stmt->execute([$vehicleId, $endDate, $startDate]);
    return $stmt->fetchColumn() > 0;
}

// Check if a review already exists for a renter-vehicle pair
function review_exists(PDO $pdo, int $renterId, int $vehicleId): bool
{
    $stmt = $pdo->prepare("
        SELECT id 
        FROM reviews 
        WHERE renter_id = ? AND vehicle_id = ?
    ");
    $stmt->execute([$renterId, $vehicleId]);
    return (bool) $stmt->fetch();
}

// Check if general feedback already exists for a renter-owner pair
function general_feedback_exists(PDO $pdo, int $renterId, int $ownerId): bool
{
    $stmt = $pdo->prepare("
        SELECT id 
        FROM reviews 
        WHERE renter_id = ? AND owner_id = ? AND vehicle_id IS NULL
    ");
    $stmt->execute([$renterId, $ownerId]);
    return (bool) $stmt->fetch();
}

// Check if a payment already exists for a booking
function payment_exists(PDO $pdo, int $bookingId): bool
{
    $stmt = $pdo->prepare("SELECT id FROM payments WHERE booking_id = ?");
    $stmt->execute([$bookingId]);
    return (bool) $stmt->fetch();
}

// Check if a vehicle with the same name already exists for an owner
function vehicle_name_exists(PDO $pdo, int $ownerId, string $vehicleName): bool
{
    $stmt = $pdo->prepare("
        SELECT id 
        FROM vehicles 
        WHERE owner_id = ? AND name = ? AND is_deleted = 0
    ");
    $stmt->execute([$ownerId, $vehicleName]);
    return (bool) $stmt->fetch();
}

// Check if a user is already approved
function user_is_approved(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    return $user && $user['status'] === 'approved';
}

// Check if a user is already disapproved
function user_is_disapproved(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    return $user && $user['status'] === 'disapproved';
}
?>