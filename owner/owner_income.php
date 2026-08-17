<?php
require_once __DIR__ . '/owner_logic.php';
$pdo = get_owner_pdo();
$owner = get_current_owner($pdo);

// Update session status from database to reflect any admin changes
if (isset($_SESSION['user_id']) && $owner['id'] > 0) {
    $_SESSION['status'] = $owner['status'];
    $_SESSION['approval_status'] = $owner['status'];
    $_SESSION['approval_reason'] = $owner['disapproval_reason'] ?? '';
}

$current_page = basename($_SERVER['PHP_SELF'] ?? 'owner_dashboard.php');
if (function_exists('enforce_owner_access')) {
    enforce_owner_access($pdo, $owner, $current_page);
} elseif (($owner['status'] ?? 'pending') !== 'approved' && $current_page !== 'owner_dashboard.php') {
    header('Location: owner_dashboard.php');
    exit();
}
$income = get_owner_income($pdo, $owner['id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Owner Income</title>
  <link rel="stylesheet" href="css/owner_style.css?v=20260702">
  <link rel="stylesheet" href="css/owner_responsive.css?v=20260803">
</head>
<body>
  <div class="overlay"></div>
  <aside class="sidebar">
<div class="sidebar-header">
      <h2>Carbnb Owner</h2>
      <button class="sidebar-close" type="button" aria-label="Close sidebar"></button>
    </div>
    <nav class="sidebar-nav">
      <a href="owner_dashboard.php">Dashboard</a>
      <a href="add_vehicle.php">Add Vehicle</a>
      <a href="manage_vehicles.php">Manage Vehicles</a>
      <a href="booking_requests.php">Booking Requests</a>
      <a href="vehicle_status.php">Vehicle Status</a>
      <a class="active" href="owner_income.php">Income</a>
      <a href="rental_history.php">Rental History</a>
      <a href="owner_profile.php">Profile</a>
      <a href="owner_message.php">Messages</a>
      <a href="owner_reviews.php">Reviews</a>
      <a href="../auth/logout.php" class="topbar-action" style="display:block; margin-top:1rem; text-align:center;">Logout</a>
    </nav>
  </aside>

  <div class="main-content">
<header class="topbar">
      <button class="sidebar-toggle" type="button" aria-label="Open sidebar"></button>
      <h1>Income Overview</h1>
      <a class="topbar-action" href="owner_profile.php">Profile</a>
    </header>

    <main class="page">
      <!-- ============================================
           NO MOBILE-SPECIFIC MARKUP NEEDED HERE
           .stats-grid already uses
           grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)),
           which collapses to a single column on narrow
           screens on its own - no dual markup, wrapper
           classes, or JS required for this page.
      ============================================ -->
      <section class="stats-grid">
        <div class="stat-box">
          <h3>This Month</h3>
          <p><?php echo format_currency($income['monthly']); ?></p>
        </div>
        <div class="stat-box">
          <h3>Pending Payout</h3>
          <p><?php echo format_currency($income['pending']); ?></p>
        </div>
        <div class="stat-box">
          <h3>Total Earnings</h3>
          <p><?php echo format_currency($income['total']); ?></p>
        </div>
      </section>
    </main>
  </div>

  <script src="js/owner_script.js"></script>
</body>
</html>
