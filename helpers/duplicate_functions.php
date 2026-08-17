<?php
// Duplicate Prevention Functions
// This file contains core functions to prevent duplicate form submissions
// and check for existing records in the database.

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate a unique form token for CSRF protection and duplicate prevention
function generate_form_token(string $formName): string
{
    $token = bin2hex(random_bytes(32));
    $_SESSION['form_tokens'][$formName] = [
        'token' => $token,
        'created_at' => time()
    ];
    return $token;
}

// Validate a form token to prevent duplicate submissions
function validate_form_token(string $formName, ?string $token): bool
{
    if (empty($token) || empty($_SESSION['form_tokens'][$formName])) {
        return false;
    }
    
    $stored = $_SESSION['form_tokens'][$formName];
    
    // Check if token matches
    if (!hash_equals($stored['token'], $token)) {
        return false;
    }
    
    // Token is valid - remove it to prevent reuse (one-time token)
    unset($_SESSION['form_tokens'][$formName]);
    
    return true;
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

// Get the current PDO connection from global scope
function get_pdo_connection(): ?PDO
{
    return $GLOBALS['pdo'] ?? $GLOBALS['conn'] ?? null;
}

// Generate a hidden token input field for forms
function form_token_input(string $formName): string
{
    $token = generate_form_token($formName);
    return '<input type="hidden" name="form_token" value="' . htmlspecialchars($token) . '">';
}

// Validate form token and return error message if invalid
function validate_form_token_or_error(string $formName): ?string
{
    $token = $_POST['form_token'] ?? null;
    
    if (!validate_form_token($formName, $token)) {
        return 'Invalid or expired form submission. Please try again.';
    }
    
    return null;
}

// Return a car - mark booking as return_requested and notify owner
function return_car(PDO $pdo, int $renter_id, int $booking_id): array
{
    if ($renter_id <= 0 || $booking_id <= 0) {
        return ['success' => false, 'message' => 'Invalid parameters.'];
    }

    // Verify the booking belongs to this renter and is approved
    $stmt = $pdo->prepare("SELECT b.id, b.vehicle_id, b.status, v.owner_id, v.name AS vehicle_name, u.full_name AS renter_name 
                           FROM bookings b 
                           JOIN vehicles v ON v.id = b.vehicle_id 
                           JOIN users u ON u.id = b.renter_id 
                           WHERE b.id = ? AND b.renter_id = ?");
    $stmt->execute([$booking_id, $renter_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        return ['success' => false, 'message' => 'Booking not found.'];
    }

    if ($booking['status'] !== 'approved') {
        return ['success' => false, 'message' => 'Only approved bookings can be returned.'];
    }

    try {
        $pdo->beginTransaction();

        // Update booking status to 'return_requested' (waiting for owner to mark as available)
        $stmt = $pdo->prepare("UPDATE bookings SET status = 'return_requested' WHERE id = ?");
        $stmt->execute([$booking_id]);

        // ============================================
        // REMOVED: Auto-message to owner
        // The owner can see the return request in booking_requests.php
        // This was removed because it was causing duplicate/annoying messages
        // ============================================

        $pdo->commit();

        return ['success' => true, 'message' => 'Return request sent! The owner has been notified.'];
    } catch (PDOException $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Error sending return request. Please try again.'];
    }
}
?>