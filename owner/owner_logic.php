<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../database/db.php';

function column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        if (($col['Field'] ?? '') === $column) {
            return true;
        }
    }

    return false;
}

function get_owner_pdo(): PDO {
    if (!empty($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    require_once __DIR__ . '/../database/db.php';

    if (!empty($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    throw new RuntimeException('Unable to connect to the database.');
}

function is_owner_access_restricted(array $owner): bool {
    $status = strtolower((string) ($owner['status'] ?? 'pending'));
    return $status !== 'approved';
}

function enforce_owner_access(PDO $pdo, array $owner, string $current_page): void {
    if (is_owner_access_restricted($owner)) {
        if ($current_page === 'owner_dashboard.php') {
            return;
        }
        header('Location: owner_dashboard.php');
        exit();
    }
}

function get_current_owner(PDO $pdo): array {
    $owner_id = 0;

    // Check for user_id in session (set during login)
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['role']) && $_SESSION['role'] === 'owner') {
        $owner_id = (int) $_SESSION['user_id'];
    } elseif (!empty($_SESSION['owner_id'])) {
        $owner_id = (int) $_SESSION['owner_id'];
    }

    if ($owner_id > 0) {
        $stmt = $pdo->prepare("SELECT id, full_name, email, role, status, disapproval_reason FROM users WHERE id = ? AND role = 'owner' AND is_deleted = 0 LIMIT 1");
        $stmt->execute([$owner_id]);
        $owner = $stmt->fetch();
        if ($owner) {
            return $owner;
        }
    }

    // No valid owner found - return default
    return ['id' => 0, 'full_name' => 'Owner', 'email' => '', 'role' => 'owner', 'status' => 'pending', 'disapproval_reason' => ''];
}

function format_currency($value): string {
    return '₱' . number_format((float) $value, 2);
}

function format_date($value): string {
    return $value ? date('M d, Y', strtotime($value)) : '—';
}

function status_label(string $status): string {
    return match ($status) {
        'available' => 'Available',
        'rented' => 'Rented',
        'maintenance' => 'Maintenance',
        'pending' => 'Pending',
        'approved' => 'Approved',
        'completed' => 'Completed',
        'disapproved' => 'Disapproved',
        default => ucfirst($status),
    };
}

function approval_status_label(string $status): string {
    return match ($status) {
        'approved' => 'Approved',
        'pending' => 'Pending',
        'disapproved' => 'Disapproved',
        default => ucfirst($status),
    };
}

function approval_status_badge_class(string $status): string {
    return match ($status) {
        'approved' => 'available',
        'pending' => 'pending',
        'disapproved' => 'active',
        default => 'pending',
    };
}

function get_owner_account_state(array $owner): array {
    $status = $owner['status'] ?? 'pending';
    $reason = trim($owner['disapproval_reason'] ?? '');

    if ($status === 'approved') {
        return [
            'status' => 'approved',
            'title' => 'Account Approved',
            'message' => 'Your owner account has been approved by admin. Full access is enabled.',
            'restricted' => false,
        ];
    }

    if ($status === 'disapproved') {
        return [
            'status' => 'disapproved',
            'title' => 'Account Disapproved',
            'message' => $reason !== '' ? $reason : 'Your owner account was disapproved by admin.',
            'restricted' => true,
        ];
    }

    return [
        'status' => 'pending',
        'title' => 'Pending Admin Approval',
        'message' => 'Your owner account is waiting for admin approval. Access is limited until approved.',
        'restricted' => true,
    ];
}

function get_dashboard_data(PDO $pdo, int $owner_id): array {
    $stats = [
        'active_vehicles' => 0,
        'pending_requests' => 0,
        'monthly_income' => 0,
    ];

    if ($owner_id > 0) {
        // Count only approved vehicles
        $stmt = $pdo->prepare("SELECT COUNT(*) AS count FROM vehicles WHERE owner_id = ? AND is_deleted = 0 AND availability_status = 'available' AND approval_status = 'approved'");
        $stmt->execute([$owner_id]);
        $stats['active_vehicles'] = (int) $stmt->fetchColumn();

        // Count pending booking requests
        $stmt = $pdo->prepare("SELECT COUNT(*) AS count FROM bookings b JOIN vehicles v ON v.id = b.vehicle_id WHERE v.owner_id = ? AND b.status = 'pending'");
        $stmt->execute([$owner_id]);
        $stats['pending_requests'] = (int) $stmt->fetchColumn();

        // Monthly income from completed bookings
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.owner_income), 0) AS total FROM earnings e JOIN bookings b ON b.id = e.booking_id JOIN vehicles v ON v.id = b.vehicle_id WHERE v.owner_id = ? AND MONTH(e.created_at) = MONTH(CURRENT_DATE()) AND YEAR(e.created_at) = YEAR(CURRENT_DATE())");
        $stmt->execute([$owner_id]);
        $stats['monthly_income'] = (float) $stmt->fetchColumn();
    }

    $stmt = $pdo->prepare("SELECT b.id, b.status, b.total_price, b.start_date, b.end_date, v.name AS vehicle_name, u.full_name AS renter_name FROM bookings b JOIN vehicles v ON v.id = b.vehicle_id JOIN users u ON u.id = b.renter_id WHERE v.owner_id = ? ORDER BY b.created_at DESC LIMIT 5");
    $stmt->execute([$owner_id]);
    $recent_bookings = $stmt->fetchAll();

    return ['stats' => $stats, 'recent_bookings' => $recent_bookings];
}

function get_owner_vehicles(PDO $pdo, int $owner_id): array {
    if ($owner_id <= 0) {
        return [];
    }

    // Only show vehicles that belong to this owner (including pending/disapproved for management)
    $stmt = $pdo->prepare("SELECT id, name, description, price_per_day, availability_status, approval_status, model_year, category, transmission, image FROM vehicles WHERE owner_id = ? AND is_deleted = 0 ORDER BY created_at DESC");
    $stmt->execute([$owner_id]);
    return $stmt->fetchAll();
}

function get_owner_bookings(PDO $pdo, int $owner_id): array {
    if ($owner_id <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("SELECT b.id, b.start_date, b.end_date, b.total_days, b.total_price, b.status, v.name AS vehicle_name, u.full_name AS renter_name FROM bookings b JOIN vehicles v ON v.id = b.vehicle_id JOIN users u ON u.id = b.renter_id WHERE v.owner_id = ? ORDER BY b.created_at DESC");
    $stmt->execute([$owner_id]);
    return $stmt->fetchAll();
}

function get_owner_income(PDO $pdo, int $owner_id): array {
    if ($owner_id <= 0) {
        return ['monthly' => 0, 'pending' => 0, 'total' => 0];
    }

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.owner_income), 0) AS monthly FROM earnings e JOIN bookings b ON b.id = e.booking_id JOIN vehicles v ON v.id = b.vehicle_id WHERE v.owner_id = ? AND MONTH(e.created_at) = MONTH(CURRENT_DATE()) AND YEAR(e.created_at) = YEAR(CURRENT_DATE())");
    $stmt->execute([$owner_id]);
    $monthly = (float) $stmt->fetchColumn();

    // Pending payout: renter has paid via Xendit (payments.status = 'pending'),
    // but the admin hasn't approved it yet, so it hasn't reached the earnings table.
    // Shown as the owner's projected 80% share.
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(p.amount) * 0.80, 0) AS pending
        FROM payments p
        JOIN bookings b ON b.id = p.booking_id
        JOIN vehicles v ON v.id = b.vehicle_id
        WHERE v.owner_id = ? AND p.status = 'pending'
    ");
    $stmt->execute([$owner_id]);
    $pending = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.owner_income), 0) AS total FROM earnings e JOIN bookings b ON b.id = e.booking_id JOIN vehicles v ON v.id = b.vehicle_id WHERE v.owner_id = ?");
    $stmt->execute([$owner_id]);
    $total = (float) $stmt->fetchColumn();

    return ['monthly' => $monthly, 'pending' => $pending, 'total' => $total];
}

function get_owner_history(PDO $pdo, int $owner_id): array {
    if ($owner_id <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("SELECT b.id, b.start_date, b.end_date, b.total_price, b.status, v.name AS vehicle_name, u.full_name AS renter_name FROM bookings b JOIN vehicles v ON v.id = b.vehicle_id JOIN users u ON u.id = b.renter_id WHERE v.owner_id = ? ORDER BY b.created_at DESC");
    $stmt->execute([$owner_id]);
    return $stmt->fetchAll();
}

function get_owner_messages(PDO $pdo, int $owner_id): array {
    if ($owner_id <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("SELECT m.id, m.message, m.is_read, m.created_at, m.sender_id, m.receiver_id, s.full_name AS sender_name, s.role AS sender_role, r.full_name AS receiver_name, r.role AS receiver_role FROM messages m LEFT JOIN users s ON s.id = m.sender_id LEFT JOIN users r ON r.id = m.receiver_id WHERE (m.sender_id = ? OR m.receiver_id = ?) ORDER BY m.created_at DESC");
    $stmt->execute([$owner_id, $owner_id]);
    return $stmt->fetchAll();
}

function get_unread_message_count(PDO $pdo, int $owner_id): int {
    if ($owner_id <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
    $stmt->execute([$owner_id]);
    return (int) $stmt->fetchColumn();
}

function get_owner_reviews(PDO $pdo, int $owner_id): array {
    if ($owner_id <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("SELECT r.id, r.rating, r.feedback, r.comment, r.reply, r.created_at, u.full_name AS renter_name, v.name AS vehicle_name FROM reviews r JOIN users u ON u.id = r.renter_id LEFT JOIN vehicles v ON v.id = r.vehicle_id WHERE r.owner_id = ? ORDER BY r.created_at DESC");
    $stmt->execute([$owner_id]);
    return $stmt->fetchAll();
}

function create_vehicle(PDO $pdo, int $owner_id, array $data, array $files = []): array {
    $errors = [];
    $name = trim($data['name'] ?? '');
    $price = trim($data['price_per_day'] ?? '');
    $model_year = trim($data['model_year'] ?? '');

    if ($name === '') {
        $errors[] = 'Vehicle name is required.';
    }
    if ($price === '' || !is_numeric($price)) {
        $errors[] = 'A valid price per day is required.';
    }
    if ($model_year === '' || !is_numeric($model_year) || (int) $model_year < 1900) {
        $errors[] = 'A valid model year is required.';
    }

    // Check for duplicate vehicle name for this owner
    if ($name !== '' && $owner_id > 0) {
        $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE owner_id = ? AND name = ? AND is_deleted = 0");
        $stmt->execute([$owner_id, $name]);
        if ($stmt->fetch()) {
            $errors[] = 'A vehicle with this name already exists.';
        }
    }

    if ($errors) {
        return ['success' => false, 'errors' => $errors];
    }

    $imagePath = null;

    if (isset($files['image']) && $files['image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/vehicles/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $fileName = 'vehicle_' . $owner_id . '_' . time() . '_' . basename($files['image']['name']);
        $uploadPath = $uploadDir . $fileName;

        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        $fileType = mime_content_type($files['image']['tmp_name']);

        if (!in_array($fileType, $allowedTypes)) {
            $errors[] = 'Only JPEG, PNG, and WebP images are allowed.';
        }

        if ($files['image']['size'] > 5 * 1024 * 1024) {
            $errors[] = 'Image must be less than 5MB.';
        }

        if ($errors) {
            return ['success' => false, 'errors' => $errors];
        }

        if (!move_uploaded_file($files['image']['tmp_name'], $uploadPath)) {
            $errors[] = 'Failed to upload image. Please try again.';
            return ['success' => false, 'errors' => $errors];
        }

        $imagePath = 'uploads/vehicles/' . $fileName;
    }

    $columns = ['owner_id', 'name', 'description', 'model_year', 'category', 'transmission', 'price_per_day', 'image'];
    $placeholders = ['?', '?', '?', '?', '?', '?', '?', '?'];
    $values = [
        $owner_id,
        $name,
        trim($data['description'] ?? ''),
        (int) $model_year,
        $data['category'] ?? '4-5_seater',
        $data['transmission'] ?? 'automatic',
        (float) $price,
        $imagePath,
    ];

    // Add availability_status and approval_status - both should be set
    $columns[] = 'availability_status';
    $placeholders[] = '?';
    $values[] = 'available';

    $columns[] = 'approval_status';
    $placeholders[] = '?';
    $values[] = 'pending'; // Vehicles need admin approval

    $stmt = $pdo->prepare('INSERT INTO vehicles (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
    $stmt->execute($values);

    return ['success' => true, 'errors' => []];
}

function update_owner_profile(PDO $pdo, int $owner_id, array $data): array {
    $errors = [];
    $full_name = trim($data['full_name'] ?? '');
    $email = trim($data['email'] ?? '');
    $password = trim($data['password'] ?? '');

    if ($full_name === '') {
        $errors[] = 'Full name is required.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email is required.';
    }

    if ($errors) {
        return ['success' => false, 'errors' => $errors];
    }

    if ($password !== '') {
        $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, password = ? WHERE id = ?");
        $stmt->execute([$full_name, $email, password_hash($password, PASSWORD_DEFAULT), $owner_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?");
        $stmt->execute([$full_name, $email, $owner_id]);
    }

    return ['success' => true, 'errors' => []];
}

function clean($value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function status_badge_class(string $status): string {
    return match ($status) {
        'available' => 'available',
        'approved' => 'available',
        'pending' => 'pending',
        'rented' => 'active',
        'maintenance' => 'pending',
        'return_requested' => 'pending',
        default => 'pending',
    };
}

function make_vehicle_available(PDO $pdo, int $owner_id, int $vehicle_id): array {
    if ($owner_id <= 0 || $vehicle_id <= 0) {
        return ['success' => false, 'message' => 'Invalid parameters.'];
    }

    // Verify the vehicle belongs to this owner
    $stmt = $pdo->prepare("SELECT id, availability_status FROM vehicles WHERE id = ? AND owner_id = ? AND is_deleted = 0");
    $stmt->execute([$vehicle_id, $owner_id]);
    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vehicle) {
        return ['success' => false, 'message' => 'Vehicle not found or does not belong to you.'];
    }

    // Check if vehicle has any active bookings (pending or approved)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE vehicle_id = ? AND status IN ('pending', 'approved')");
    $stmt->execute([$vehicle_id]);
    $active_bookings = (int) $stmt->fetchColumn();

    if ($active_bookings > 0) {
        return ['success' => false, 'message' => 'Cannot make available. Vehicle has active bookings.'];
    }

    // Update vehicle status to available
    $stmt = $pdo->prepare("UPDATE vehicles SET availability_status = 'available' WHERE id = ?");
    $stmt->execute([$vehicle_id]);

    return ['success' => true, 'message' => 'Vehicle is now available.'];
}
