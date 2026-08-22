<?php
/**
 * location_helper.php
 * Shared helper functions for location tracking
 * 
 * FIXED: Removed start_date restriction to allow tracking from booking
 * creation until the booking end_date (or until status changes to completed/disapproved).
 */

// ============================================================
// LOCATION STATUS CONSTANTS
// ============================================================
define('LOCATION_ACTIVE_THRESHOLD_SECONDS', 30);

// ============================================================
// Get status for a single renter based on their last update
// ============================================================
function getRenterStatus($lastRecordedAt, $thresholdSeconds = LOCATION_ACTIVE_THRESHOLD_SECONDS) {
    if (empty($lastRecordedAt)) {
        return [
            'status' => 'inactive',
            'status_label' => 'Inactive',
            'icon' => '🔴',
            'reason' => 'no_location'
        ];
    }
    
    $lastUpdate = strtotime($lastRecordedAt);
    $now = time();
    $secondsAgo = $now - $lastUpdate;
    
    if ($secondsAgo <= $thresholdSeconds) {
        return [
            'status' => 'active',
            'status_label' => 'Active',
            'icon' => '🟢',
            'seconds_ago' => $secondsAgo,
            'reason' => 'recent_location'
        ];
    } else {
        return [
            'status' => 'inactive',
            'status_label' => 'Inactive',
            'icon' => '🔴',
            'seconds_ago' => $secondsAgo,
            'reason' => 'old_location'
        ];
    }
}

// ============================================================
// Get all renters with their latest location and status
// FIXED: Removed start_date restriction so renters with future start dates are included.
// ============================================================
function getAllRentersWithStatus($pdo, $thresholdSeconds = LOCATION_ACTIVE_THRESHOLD_SECONDS) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                u.id AS user_id,
                u.full_name,
                u.status AS account_status,
                u.is_deleted,
                lt.id AS location_id,
                lt.latitude,
                lt.longitude,
                lt.accuracy,
                lt.recorded_at,
                lt.booking_id,
                b.status AS booking_status,
                b.start_date,
                b.end_date
            FROM users u
            LEFT JOIN location_tracker lt
                ON lt.id = (
                    SELECT lt2.id
                    FROM location_tracker lt2
                    WHERE lt2.user_id = u.id
                    ORDER BY lt2.recorded_at DESC, lt2.id DESC
                    LIMIT 1
                )
            INNER JOIN bookings b ON b.renter_id = u.id
            WHERE u.role = 'renter'
            AND u.is_deleted = 0
            AND b.status IN ('pending_location', 'pending', 'approved')
            AND b.end_date >= CURDATE()          -- Only track bookings that haven't ended
            ORDER BY u.full_name ASC
        ");
        $stmt->execute();
        
        $renters = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $results = [];
        $activeCount = 0;
        $inactiveCount = 0;
        $noLocationCount = 0;
        
        foreach ($renters as $renter) {
            $status = getRenterStatus($renter['recorded_at'], $thresholdSeconds);
            $renter['status_info'] = $status;
            $results[] = $renter;
            
            if ($status['status'] === 'active') {
                $activeCount++;
            } else {
                $inactiveCount++;
                if ($status['reason'] === 'no_location') {
                    $noLocationCount++;
                } elseif ($status['reason'] === 'old_location') {
                    // Renter WAS actively tracking and has now gone stale
                    // (e.g. turned off location, lost signal, closed the app).
                    // This is the "accidentally turned off" case admins should
                    // be warned about — not the same as never having tracked at all.
                    createStatusChangeNotification(
                        $pdo,
                        $renter['user_id'],
                        $renter['full_name'],
                        'inactive',
                        $renter['booking_id'] ?? null
                    );
                }
            }
        }
        
        return [
            'renters' => $results,
            'active_count' => $activeCount,
            'inactive_count' => $inactiveCount,
            'no_location_count' => $noLocationCount,
            'total' => count($results)
        ];
        
    } catch (PDOException $e) {
        error_log('getAllRentersWithStatus error: ' . $e->getMessage());
        return [
            'renters' => [],
            'active_count' => 0,
            'inactive_count' => 0,
            'no_location_count' => 0,
            'total' => 0,
            'error' => $e->getMessage()
        ];
    }
}

// ============================================================
// Get active renters only (for map display)
// ============================================================
function getActiveRenters($pdo, $thresholdSeconds = LOCATION_ACTIVE_THRESHOLD_SECONDS) {
    $all = getAllRentersWithStatus($pdo, $thresholdSeconds);
    
    $active = array_filter($all['renters'], function($renter) {
        return $renter['status_info']['status'] === 'active';
    });
    
    return [
        'renters' => array_values($active),
        'active_count' => count($active)
    ];
}

// ============================================================
// Get renter by booking_id
// ============================================================
function getRenterByBookingId($pdo, $bookingId) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                u.id AS user_id,
                u.full_name,
                u.status AS account_status,
                u.is_deleted,
                b.id AS booking_id,
                b.status AS booking_status,
                b.start_date,
                b.end_date,
                lt.id AS location_id,
                lt.latitude,
                lt.longitude,
                lt.accuracy,
                lt.recorded_at
            FROM users u
            INNER JOIN bookings b ON b.renter_id = u.id
            LEFT JOIN location_tracker lt
                ON lt.id = (
                    SELECT lt2.id
                    FROM location_tracker lt2
                    WHERE lt2.user_id = u.id
                    AND lt2.booking_id = b.id
                    ORDER BY lt2.recorded_at DESC, lt2.id DESC
                    LIMIT 1
                )
            WHERE u.role = 'renter'
            AND u.is_deleted = 0
            AND b.id = ?
            LIMIT 1
        ");
        $stmt->execute([$bookingId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('getRenterByBookingId error: ' . $e->getMessage());
        return null;
    }
}

// ============================================================
// Get status change notifications
// ============================================================
function getStatusChangeNotifications($pdo, $userId, $currentStatus, $bookingId = null) {
    $params = [$userId];
    $sql = "
        SELECT COUNT(*) as count, MAX(created_at) as last_time
        FROM admin_notifications
        WHERE user_id = ?
        AND notification_type IN ('location_active', 'location_inactive')
        AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ";
    
    if ($bookingId) {
        $sql .= " AND booking_id = ?";
        $params[] = $bookingId;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return [
        'has_recent' => $result['count'] > 0,
        'count' => (int)$result['count'],
        'last_time' => $result['last_time']
    ];
}

// ============================================================
// Create status change notification
// ============================================================
function createStatusChangeNotification($pdo, $userId, $userName, $newStatus, $bookingId = null) {
    if ($newStatus === 'active') {
        $type = 'location_active';
        $title = '🟢 Location Tracking Active';
        $message = $bookingId 
            ? "{$userName} is now sharing their location for booking #{$bookingId}."
            : "{$userName} is now sharing their location.";
    } else {
        $type = 'location_inactive';
        $title = '🔴 Location Tracking Inactive';
        $message = $bookingId 
            ? "{$userName} has stopped sharing their location for booking #{$bookingId}."
            : "{$userName} has stopped sharing their location.";
    }
    
    $recent = getStatusChangeNotifications($pdo, $userId, $newStatus, $bookingId);
    if ($recent['has_recent']) {
        return false;
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO admin_notifications 
        (notification_type, title, message, user_id, booking_id, is_read, created_at)
        VALUES (?, ?, ?, ?, ?, 0, NOW())
    ");
    $stmt->execute([$type, $title, $message, $userId, $bookingId]);
    
    return $pdo->lastInsertId();
}

// ============================================================
// Get specific renter's latest location by booking_id
// ============================================================
function getRenterLatestLocation($pdo, $userId, $bookingId = null) {
    try {
        $sql = "
            SELECT 
                id,
                user_id,
                booking_id,
                latitude,
                longitude,
                accuracy,
                recorded_at,
                created_at
            FROM location_tracker
            WHERE user_id = ?
        ";
        $params = [$userId];
        
        if ($bookingId) {
            $sql .= " AND booking_id = ?";
            $params[] = $bookingId;
        }
        
        $sql .= " ORDER BY recorded_at DESC, id DESC LIMIT 1";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('getRenterLatestLocation error: ' . $e->getMessage());
        return null;
    }
}

// ============================================================
// Get renter's location history by booking_id
// ============================================================
function getRenterLocationHistory($pdo, $userId, $bookingId = null, $limit = 50) {
    try {
        $sql = "
            SELECT 
                id,
                user_id,
                booking_id,
                latitude,
                longitude,
                accuracy,
                recorded_at,
                created_at
            FROM location_tracker
            WHERE user_id = ?
        ";
        $params = [$userId];
        
        if ($bookingId) {
            $sql .= " AND booking_id = ?";
            $params[] = $bookingId;
        }
        
        $sql .= " ORDER BY recorded_at DESC, id DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('getRenterLocationHistory error: ' . $e->getMessage());
        return [];
    }
}

// ============================================================
// Verify if a booking is active for tracking
// FIXED: Removed start_date condition so tracking continues
// even if the rental period hasn't started yet.
// ============================================================
function verifyActiveBooking($pdo, $userId, $bookingId) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, status, start_date, end_date
            FROM bookings
            WHERE id = ?
            AND renter_id = ?
            AND status IN ('pending_location', 'pending', 'approved')
            AND end_date >= CURDATE()   -- Booking must not have ended
            LIMIT 1
        ");
        $stmt->execute([$bookingId, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('verifyActiveBooking error: ' . $e->getMessage());
        return null;
    }
}

// ============================================================
// Get all active bookings for a user
// FIXED: Removed start_date condition.
// ============================================================
function getUserActiveBookings($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                b.id,
                b.status,
                b.start_date,
                b.end_date,
                v.name AS vehicle_name
            FROM bookings b
            INNER JOIN vehicles v ON b.vehicle_id = v.id
            WHERE b.renter_id = ?
            AND b.status IN ('pending_location', 'pending', 'approved')
            AND b.end_date >= CURDATE()
            ORDER BY b.start_date ASC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('getUserActiveBookings error: ' . $e->getMessage());
        return [];
    }
}

// ============================================================
// Clean up old location records
// ============================================================
function cleanupOldLocations($pdo, $daysToKeep = 30) {
    try {
        $stmt = $pdo->prepare("
            DELETE FROM location_tracker
            WHERE recorded_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$daysToKeep]);
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log('cleanupOldLocations error: ' . $e->getMessage());
        return 0;
    }
}
?>