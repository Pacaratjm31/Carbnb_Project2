<?php

// ============================================================
// LOCAL XAMPP DATABASE CONNECTION
// ============================================================

$host = 'localhost';
$port = 3306;
$dbname = 'carbnb';
$username = 'root';
$password = '';

// ============================================================
// DATABASE HELPER FUNCTIONS
// ============================================================

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

// ============================================================
// CONNECT TO LOCAL MYSQL
// ============================================================

try {

    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Make PDO available globally
    $GLOBALS['pdo'] = $pdo;
    $GLOBALS['conn'] = $pdo;
    $conn = $pdo;

    // ========================================================
    // ENSURE REQUIRED COLUMNS EXIST
    // ========================================================

    ensure_column($pdo, 'users', 'face_descriptor', 'TEXT NULL');
    ensure_column($pdo, 'users', 'disapproval_reason', 'TEXT NULL');
    ensure_column($pdo, 'users', 'login_attempts', 'INT NOT NULL DEFAULT 0');
    ensure_column($pdo, 'users', 'locked_until', 'DATETIME NULL');

    ensure_column(
        $pdo,
        'vehicles',
        'availability_status',
        "ENUM('available','rented','maintenance') DEFAULT 'available'"
    );

    ensure_column(
        $pdo,
        'vehicles',
        'approval_status',
        "ENUM('pending','approved','disapproved') DEFAULT 'pending'"
    );

    ensure_column($pdo, 'vehicles', 'model_year', 'INT NULL');
    ensure_column($pdo, 'vehicles', 'approval_feedback', 'TEXT NULL');

    ensure_column($pdo, 'reviews', 'feedback', 'TEXT NULL');
    ensure_column($pdo, 'reviews', 'reply', 'TEXT NULL');

    // Allow rating to be NULL
    $checkTable = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
        AND table_name = 'reviews'
    ");

    $checkTable->execute();

    if ($checkTable->fetchColumn()) {
        $pdo->exec("
            ALTER TABLE `reviews`
            MODIFY COLUMN `rating` TINYINT UNSIGNED NULL
        ");
    }

    ensure_column($pdo, 'bookings', 'total_days', 'INT NOT NULL DEFAULT 1');
    ensure_column($pdo, 'bookings', 'total_price', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
    ensure_column($pdo, 'bookings', 'admin_id', 'INT NULL');

    ensure_column(
        $pdo,
        'payments',
        'payment_method',
        "ENUM('gcash','paymaya','cash','bank_transfer') NULL"
    );

    ensure_column($pdo, 'payments', 'transaction_reference', 'VARCHAR(100) NULL');
    ensure_column($pdo, 'payments', 'gateway_response', 'TEXT NULL');
    ensure_column($pdo, 'payments', 'paid_at', 'DATETIME NULL');

    // Allow Xendit if still used locally
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

    // ========================================================
    // USER DOCUMENTS
    // ========================================================

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
            ENUM(
                'id1',
                'id2',
                'proof_of_billing',
                'drivers_license',
                'nbi_clearance',
                'intro_video'
            ) NOT NULL
        ");
    }

    // ========================================================
    // LOCATION TRACKER TABLE
    // ========================================================

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