<?php
// Admin Authentication and Shared Functions

// ==========================================================
// SAFE SESSION START
// Prevent "session_start(): Ignoring session_start() because
// a session is already active" when this file is included by
// another PHP file that already started the session.
// ==========================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database/db.php';


// ==========================================================
// VERIFY DATABASE CONNECTION
// ==========================================================

if (!isset($pdo)) {
    die("Database connection was not loaded.");
}


// ==========================================================
// ADMIN SECURITY - Check if user is logged in as admin
// ==========================================================

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header("Location: admin_login.php");
    exit;
}


// ==========================================================
// CURRENT PAGE
// ==========================================================

$currentPage = basename($_SERVER['PHP_SELF']);


// ==========================================================
// GLOBAL MESSAGES
// ==========================================================

$success = '';
$error = '';

if (isset($_GET['success'])) {
    $success = trim($_GET['success']);
}

if (isset($_GET['error'])) {
    $error = trim($_GET['error']);
}


// ==========================================================
// SHARED FUNCTIONS
// ==========================================================

function redirectSuccess($page, $message)
{
    header(
        "Location: {$page}?success=" . urlencode($message)
    );
    exit;
}


function redirectError($page, $message)
{
    header(
        "Location: {$page}?error=" . urlencode($message)
    );
    exit;
}


function clean($value)
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}


function formatDate($date)
{
    return $date
        ? date('M d, Y', strtotime($date))
        : '—';
}


function statusBadgeClass($status)
{
    return match ($status) {

        'approved' => 'available',

        'pending' => 'pending',

        'disapproved' => 'active',

        'available' => 'available',

        'rented' => 'active',

        'maintenance' => 'pending',

        'completed' => 'available',

        default => 'pending',
    };
}


function statusLabel($status)
{
    return match ($status) {

        'approved' => 'Approved',

        'pending' => 'Pending',

        'disapproved' => 'Disapproved',

        'available' => 'Available',

        'rented' => 'Rented',

        'maintenance' => 'Maintenance',

        'completed' => 'Completed',

        default => ucfirst($status),
    };
}

?>