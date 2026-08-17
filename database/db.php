<?php

$host = "localhost";
$dbname = "carbnb";
$username = "root";
$password = "";

function ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    // Check if the table exists first
    $checkTable = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
        AND table_name = ?
    ");

    $checkTable->execute([$table]);

    if (!$checkTable->fetchColumn()) {
        return;
    }

    // Check if the column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {

        if (($col['Field'] ?? '') === $column) {
            return;
        }

    }

    // Add the column if it doesn't exist
    $pdo->exec("
        ALTER TABLE `$table`
        ADD COLUMN `$column` $definition
    ");
}

function ensure_table(PDO $pdo, string $table, string $definition): void
{
    $checkTable = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
        AND table_name = ?
    ");

    $checkTable->execute([$table]);

    if ($checkTable->fetchColumn()) {
        return;
    }

    $pdo->exec("CREATE TABLE `$table` ($definition)");
}

try {

    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8",
        $username,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $GLOBALS['pdo'] = $pdo;
    $GLOBALS['conn'] = $pdo;
    $conn = $pdo;

    // Ensure columns exist
    ensure_column($pdo, 'users', 'face_descriptor', 'TEXT NULL');
    // FIXED: 'disapproval_reason' is read/written by login, browse, book,
    // owner and renter dashboards, and admin approve/reject actions, but
    // the column never existed. Every one of those pages was throwing an
    // uncaught PDOException as soon as it touched this column.
    ensure_column($pdo, 'users', 'disapproval_reason', 'TEXT NULL');
    // FIXED: login.php's lockout logic and admin/account_control.php both
    // read/write these two columns, but neither existed — every login
    // attempt (correct or wrong password) threw an uncaught PDOException.
    ensure_column($pdo, 'users', 'login_attempts', 'INT NOT NULL DEFAULT 0');
    ensure_column($pdo, 'users', 'locked_until', 'DATETIME NULL');
    ensure_column($pdo, 'vehicles', 'availability_status', "ENUM('available','rented','maintenance') DEFAULT 'available'");
    ensure_column($pdo, 'vehicles', 'approval_status', "ENUM('pending','approved','disapproved') DEFAULT 'pending'");
    // FIXED: owner/add_vehicle.php requires model_year and admin
    // verify_vehicles.php reads/writes approval_feedback (rejection
    // reason shown to owners/renters), but neither column existed —
    // adding a vehicle and rejecting a vehicle both threw fatal errors.
    ensure_column($pdo, 'vehicles', 'model_year', 'INT NULL');
    ensure_column($pdo, 'vehicles', 'approval_feedback', 'TEXT NULL');

    // FIXED: owner_reviews.php / owner_logic.php / renter/record.php all
    // read and write reviews.feedback and reviews.reply, and record.php
    // inserts a general-feedback review with rating = NULL, but neither
    // column existed and rating was NOT NULL. Owner "Reviews" page and
    // renter feedback submission both threw fatal errors.
    ensure_column($pdo, 'reviews', 'feedback', 'TEXT NULL');
    ensure_column($pdo, 'reviews', 'reply', 'TEXT NULL');
    $checkTable = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
        AND table_name = 'reviews'
    ");
    $checkTable->execute();
    if ($checkTable->fetchColumn()) {
        $pdo->exec("ALTER TABLE `reviews` MODIFY COLUMN `rating` TINYINT UNSIGNED NULL");
    }
    ensure_column($pdo, 'bookings', 'total_days', 'INT NOT NULL DEFAULT 1');
    ensure_column($pdo, 'bookings', 'total_price', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
    // FIXED: admin/booking_records_logic.php stamps who approved/rejected
    // a booking into bookings.admin_id, but the column never existed —
    // every admin booking approve/reject action threw a fatal error.
    ensure_column($pdo, 'bookings', 'admin_id', 'INT NULL');
    ensure_column($pdo, 'payments', 'payment_method', "ENUM('gcash','paymaya','cash','bank_transfer') NULL");
    ensure_column($pdo, 'payments', 'transaction_reference', 'VARCHAR(100) NULL');
    ensure_column($pdo, 'payments', 'gateway_response', 'TEXT NULL');
    ensure_column($pdo, 'payments', 'paid_at', 'DATETIME NULL');
    // FIXED: admin/earnings.php shows a "View Xendit Invoice" link from
    // payments.payment_url, but nothing ever wrote that column (or even
    // had it) — payment_gateway.php only saved the raw gateway_response
    // JSON, so the link was always blank.
    // FIXED: payment_gateway.php saves payment_method = 'xendit' for
    // online payments, but the ENUM only allowed gcash/paymaya/cash/
    // bank_transfer. Every Xendit payment attempt was throwing a
    // "Data truncated for column 'payment_method'" fatal error.
    $checkTable = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
        AND table_name = 'payments'
    ");
    $checkTable->execute();
    if ($checkTable->fetchColumn()) {
        $pdo->exec("
            ALTER TABLE `payments`
            MODIFY COLUMN `payment_method`
            ENUM('gcash','paymaya','cash','bank_transfer','xendit') NULL
        ");
    }
    ensure_column($pdo, 'payments', 'payment_url', 'VARCHAR(500) NULL');

    // ============================================================
    // FIXED: user_documents.document_type ENUM was missing
    // 'proof_of_billing', 'nbi_clearance', 'intro_video'.
    // register.php requires these for renter/owner signup, so every
    // registration was failing with "Data truncated for column
    // 'document_type'". Widen the enum on existing databases too.
    // ============================================================
    $checkTable = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
        AND table_name = 'user_documents'
    ");
    $checkTable->execute();
    if ($checkTable->fetchColumn()) {
        $pdo->exec("
            ALTER TABLE `user_documents`
            MODIFY COLUMN `document_type`
            ENUM('id1','id2','proof_of_billing','drivers_license','nbi_clearance','intro_video') NOT NULL
        ");
    }
    
    // ============================================================
    // FIXED: Changed 'renter_locations' to 'location_tracker'
    // This matches what location_tracker.php expects
    // ============================================================
    ensure_table(
        $pdo,
        'location_tracker',
        "id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        booking_id INT NULL,
        latitude DECIMAL(10,8) NOT NULL,
        longitude DECIMAL(11,8) NOT NULL,
        accuracy DECIMAL(10,2) NULL,
        recorded_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_location_user_id (user_id),
        INDEX idx_location_booking_id (booking_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL"
    );

} catch (PDOException $e) {

    die("Database connection failed: " . $e->getMessage());

}

?>