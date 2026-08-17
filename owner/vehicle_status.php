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
$vehicles = get_owner_vehicles($pdo, $owner['id']);
$available = 0;
$rented = 0;
$maintenance = 0;
$maintenance_vehicles = [];

foreach ($vehicles as $vehicle) {
    // Only count approved vehicles in status summary
    if ($vehicle['approval_status'] === 'approved') {
        if ($vehicle['availability_status'] === 'available') $available++;
        elseif ($vehicle['availability_status'] === 'rented') $rented++;
        elseif ($vehicle['availability_status'] === 'maintenance') {
            $maintenance++;
            $maintenance_vehicles[] = $vehicle;
        }
    }
}

$ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
if ($ajax && ($_GET['section'] ?? '') === 'vehicle-status') {
    echo '<section class="content-grid" id="owner-vehicle-status-summary">';
    echo '<div class="card"><h3 class="section-title">Available Now</h3><p>' . (int) $available . ' vehicle(s)</p></div>';
    echo '<div class="card"><h3 class="section-title">In Use</h3><p>' . (int) $rented . ' vehicle(s)</p></div>';
    echo '<div class="card"><h3 class="section-title">Maintenance</h3><p>' . (int) $maintenance . ' vehicle(s)</p></div>';
    echo '</section>';
    if (!empty($maintenance_vehicles)) {
        echo '<section class="card" style="margin-top:20px;" id="owner-maintenance-list">';
        echo '<h3 class="section-title">Vehicles Under Maintenance</h3>';
        echo '<div class="table-wrapper"><table class="table"><thead><tr><th>Vehicle</th><th>Category</th><th>Price</th><th>Status</th></tr></thead><tbody>';
        foreach ($maintenance_vehicles as $vehicle) {
            echo '<tr>';
            echo '<td data-label="Vehicle">' . htmlspecialchars($vehicle['name']) . '</td>';
            echo '<td data-label="Category">' . htmlspecialchars(str_replace('_', ' ', $vehicle['category'])) . '</td>';
            echo '<td data-label="Price">' . format_currency($vehicle['price_per_day']) . '/day</td>';
            echo '<td data-label="Status"><span class="status-badge pending">' . htmlspecialchars(status_label($vehicle['availability_status'])) . '</span></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></section>';
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Vehicle Status</title>
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
      <a class="active" href="vehicle_status.php">Vehicle Status</a>
      <a href="owner_income.php">Income</a>
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
      <h1>Vehicle Status</h1>
      <a class="topbar-action" href="owner_profile.php">Profile</a>
    </header>

    <main class="page">
      <section class="content-grid" id="owner-vehicle-status-summary" data-live-refresh="vehicle_status.php?ajax=1&section=vehicle-status" data-live-target="#owner-vehicle-status-summary">
        <div class="card">
          <h3 class="section-title">Available Now</h3>
          <p><?php echo $available; ?> vehicle(s)</p>
        </div>
        <div class="card">
          <h3 class="section-title">In Use</h3>
          <p><?php echo $rented; ?> vehicle(s)</p>
        </div>
        <div class="card">
          <h3 class="section-title">Maintenance</h3>
          <p><?php echo $maintenance; ?> vehicle(s)</p>
        </div>
      </section>

      <?php if (!empty($maintenance_vehicles)): ?>
      <section class="card" style="margin-top:20px;" id="owner-maintenance-list">
        <h3 class="section-title">Vehicles Under Maintenance</h3>
        <div class="table-wrapper only-desktop">
          <table class="table">
            <thead>
              <tr>
                <th>Vehicle</th>
                <th>Category</th>
                <th>Price</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($maintenance_vehicles as $vehicle): ?>
                <tr>
                  <td data-label="Vehicle"><?php echo htmlspecialchars($vehicle['name']); ?></td>
                  <td data-label="Category"><?php echo htmlspecialchars(str_replace('_', ' ', $vehicle['category'])); ?></td>
                  <td data-label="Price"><?php echo format_currency($vehicle['price_per_day']); ?>/day</td>
                  <td data-label="Status"><span class="status-badge pending"><?php echo htmlspecialchars(status_label($vehicle['availability_status'])); ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- ============================================
             MOBILE MAINTENANCE CARDS
             Same $maintenance_vehicles data, card-per-vehicle
             for phones (same .only-desktop/.only-mobile
             pattern used across the owner panel). Read-only
             list, no actions to stack.
        ============================================ -->
        <div class="maintenance-cards only-mobile">
          <?php foreach ($maintenance_vehicles as $vehicle): ?>
            <div class="maintenance-card">
              <div class="maintenance-card-header"><?php echo htmlspecialchars($vehicle['name']); ?></div>
              <div class="maintenance-card-body">
                <div class="maintenance-card-row">
                  <span class="label">Category</span>
                  <span class="value"><?php echo htmlspecialchars(str_replace('_', ' ', $vehicle['category'])); ?></span>
                </div>
                <div class="maintenance-card-row">
                  <span class="label">Price</span>
                  <span class="value"><?php echo format_currency($vehicle['price_per_day']); ?>/day</span>
                </div>
                <div class="maintenance-card-row">
                  <span class="label">Status</span>
                  <span class="value"><span class="status-badge pending"><?php echo htmlspecialchars(status_label($vehicle['availability_status'])); ?></span></span>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>
    </main>
  </div>

  <script>
    (function () {
      const summaryNode = document.getElementById('owner-vehicle-status-summary');
      if (summaryNode && summaryNode.dataset.liveRefresh) {
        const refreshUrl = summaryNode.dataset.liveRefresh;
        const refreshSection = function () {
          fetch(refreshUrl)
            .then((response) => response.text())
            .then((html) => {
              const container = document.getElementById('owner-vehicle-status-summary');
              if (container) {
                container.innerHTML = html;
              }
            })
            .catch((error) => console.log('Vehicle status summary refresh failed:', error));
        };
        refreshSection();
        setInterval(refreshSection, 7000);
      }
    })();
  </script>
  <script src="js/owner_script.js"></script>
</body>
