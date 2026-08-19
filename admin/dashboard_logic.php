<?php
// Dashboard Logic - Statistics and data for admin dashboard
require_once 'admin_auth.php';
require_once __DIR__ . '/helpers/location_helper.php';

// ============================================
// DECLARE VARIABLES FOR VS CODE / IDE
// These are defined in admin_auth.php but declared here for IDE support
// ============================================
/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? null;

// Initialize statistics variables
$totalUsers = 0;
$totalOwners = 0;
$totalRenters = 0;
$totalVehicles = 0;
$totalBookings = 0;
$totalPayments = 0;
$totalPendingUsers = 0;
$totalPendingVehicles = 0;
$totalPendingBookings = 0;
$totalPendingPayments = 0;
$totalDeletedUsers = 0;
$totalContactMessages = 0;
$totalPendingMessages = 0;

// ============================================
// NOTIFICATION VARIABLES
// ============================================
$unreadNotifications = 0;
$recentNotifications = [];

// ============================================
// LOCATION TRACKING STATUS VARIABLES (PER RENTER)
// ============================================
$locationThresholdSeconds = LOCATION_ACTIVE_THRESHOLD_SECONDS;
$locationRenters = [];
$activeLocationRenters = [];
$inactiveLocationRenters = [];
$totalActiveRenters = 0;
$totalInactiveRenters = 0;
$totalNoLocationRenters = 0;
$totalRentersWithLocation = 0;
$hasActiveRenters = false;

// ============================================
// ERROR VARIABLE
// ============================================
$error = null;

try {
    // Check if $pdo is available
    if (!$pdo) {
        throw new Exception('Database connection not available');
    }

    // Total Users (excluding admin)
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE role <> 'admin'
        AND is_deleted = 0
    ");
    $totalUsers = (int)$stmt->fetchColumn();

    // Total Owners
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE role = 'owner'
        AND is_deleted = 0
    ");
    $totalOwners = (int)$stmt->fetchColumn();

    // Total Renters
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE role = 'renter'
        AND is_deleted = 0
    ");
    $totalRenters = (int)$stmt->fetchColumn();

    // Total Vehicles
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM vehicles
        WHERE is_deleted = 0
    ");
    $totalVehicles = (int)$stmt->fetchColumn();

    // Total Bookings
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM bookings
    ");
    $totalBookings = (int)$stmt->fetchColumn();

    // Total Payments
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM payments
    ");
    $totalPayments = (int)$stmt->fetchColumn();

    // Total verified revenue for dashboard summary
    $stmt = $pdo->query("
        SELECT COALESCE(SUM(amount), 0)
        FROM payments
        WHERE status = 'verified'
    ");
    $verifiedRevenueTotal = (float)$stmt->fetchColumn();

    // Pending Users
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE status = 'pending'
        AND is_deleted = 0
    ");
    $totalPendingUsers = (int)$stmt->fetchColumn();

    // Pending Vehicles
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM vehicles
        WHERE approval_status = 'pending'
        AND is_deleted = 0
    ");
    $totalPendingVehicles = (int)$stmt->fetchColumn();

    // Pending Bookings
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM bookings
        WHERE status = 'pending'
    ");
    $totalPendingBookings = (int)$stmt->fetchColumn();

    // Pending Payments
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM payments
        WHERE status = 'pending'
    ");
    $totalPendingPayments = (int)$stmt->fetchColumn();

    // Deleted Users
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE is_deleted = 1
    ");
    $totalDeletedUsers = (int)$stmt->fetchColumn();

    // Total Contact Messages
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM contact_messages
    ");
    $totalContactMessages = (int)$stmt->fetchColumn();

    // Pending Contact Messages
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM contact_messages
        WHERE is_replied = 0
    ");
    $totalPendingMessages = (int)$stmt->fetchColumn();

    // ============================================
    // FETCH NOTIFICATIONS
    // ============================================
    // Check if notification table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'admin_notifications'");
    if ($stmt->rowCount() > 0) {
        // Get unread count
        $stmt = $pdo->query("SELECT COUNT(*) FROM admin_notifications WHERE is_read = 0");
        $unreadNotifications = (int)$stmt->fetchColumn();
        
        // Get recent notifications (last 10)
        $stmt = $pdo->query("
            SELECT n.*, u.full_name as user_name
            FROM admin_notifications n
            LEFT JOIN users u ON n.user_id = u.id
            ORDER BY n.created_at DESC
            LIMIT 10
        ");
        $recentNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ============================================
    // FETCH LOCATION TRACKING STATUS (PER RENTER)
    // ============================================
    // Check if location_tracker table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'location_tracker'");
    if ($stmt->rowCount() > 0) {
        $locationData = getAllRentersWithStatus($pdo, $locationThresholdSeconds);
        
        // Populate location variables
        $locationRenters = $locationData['renters'];
        $totalActiveRenters = $locationData['active_count'];
        $totalInactiveRenters = $locationData['inactive_count'];
        $totalNoLocationRenters = $locationData['no_location_count'] ?? 0;
        $totalRentersWithLocation = $totalActiveRenters + ($totalInactiveRenters - $totalNoLocationRenters);
        $hasActiveRenters = $totalActiveRenters > 0;
        
        // Split into active and inactive arrays for easier display
        $activeLocationRenters = array_filter($locationRenters, function($renter) {
            return $renter['status_info']['status'] === 'active';
        });
        
        $inactiveLocationRenters = array_filter($locationRenters, function($renter) {
            return $renter['status_info']['status'] === 'inactive';
        });
    }

} catch (PDOException $e) {
    $error = $e->getMessage();
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>