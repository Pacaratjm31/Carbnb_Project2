<?php
/**
 * location_helper.php
 * Shared helper functions for location tracking
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
// ============================================================
function getAllRentersWithStatus($pdo, $thresholdSeconds = LOCATION_ACTIVE_THRESHOLD_SECONDS) {
    try {
        // Start from users table - get ALL non-deleted renters
        // For each renter, get their latest location record (any age)
        // Use a subquery to get the latest location per user
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
                lt.booking_id
            FROM users u
            LEFT JOIN location_tracker lt
                ON lt.id = (
                    SELECT lt2.id
                    FROM location_tracker lt2
                    WHERE lt2.user_id = u.id
                    ORDER BY lt2.recorded_at DESC, lt2.id DESC
                    LIMIT 1
                )
            WHERE u.role = 'renter'
            AND u.is_deleted = 0
            ORDER BY u.full_name ASC
        ");
        $stmt->execute();
        
        $renters = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $results = [];
        $activeCount = 0;
        $inactiveCount = 0;
        $noLocationCount = 0;
        
        foreach ($renters as $renter) {
            // Get status based on their latest recorded_at
            $status = getRenterStatus($renter['recorded_at'], $thresholdSeconds);
            $renter['status_info'] = $status;
            $results[] = $renter;
            
            if ($status['status'] === 'active') {
                $activeCount++;
            } else {
                $inactiveCount++;
                if ($status['reason'] === 'no_location') {
                    $noLocationCount++;
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
// Get status change notifications (for admin notifications)
// ============================================================
function getStatusChangeNotifications($pdo, $userId, $currentStatus) {
    // Check if we already have a recent notification for this status change
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count, MAX(created_at) as last_time
        FROM admin_notifications
        WHERE user_id = ?
        AND notification_type IN ('location_active', 'location_inactive')
        AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ");
    $stmt->execute([$userId]);
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
        $message = "{$userName} is now sharing their location.";
    } else {
        $type = 'location_inactive';
        $title = '🔴 Location Tracking Inactive';
        $message = "{$userName} has stopped sharing their location.";
    }
    
    // Check for recent notification to prevent spam
    $recent = getStatusChangeNotifications($pdo, $userId, $newStatus);
    if ($recent['has_recent']) {
        return false; // Skip duplicate notification
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
// Get specific renter's latest location
// ============================================================
function getRenterLatestLocation($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("
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
            ORDER BY recorded_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('getRenterLatestLocation error: ' . $e->getMessage());
        return null;
    }
}

// ============================================================
// Get renter's location history (for map timeline)
// ============================================================
function getRenterLocationHistory($pdo, $userId, $limit = 50) {
    try {
        $stmt = $pdo->prepare("
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
            ORDER BY recorded_at DESC, id DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('getRenterLocationHistory error: ' . $e->getMessage());
        return [];
    }
}
?>