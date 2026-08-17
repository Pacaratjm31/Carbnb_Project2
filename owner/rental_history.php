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
$history = get_owner_history($pdo, $owner['id']);

$ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
if ($ajax && ($_GET['section'] ?? '') === 'rental-history') {
    if (empty($history)) {
        echo '<tr><td colspan="5" class="empty-state">No booking history found.</td></tr>';
    } else {
        foreach ($history as $item) {
            echo '<tr>';
            echo '<td data-label="Vehicle">' . htmlspecialchars($item['vehicle_name']) . '</td>';
            echo '<td data-label="Renter">' . htmlspecialchars($item['renter_name']) . '</td>';
            echo '<td data-label="Dates">' . format_date($item['start_date']) . ' - ' . format_date($item['end_date']) . '</td>';
            echo '<td data-label="Price">' . format_currency($item['total_price']) . '</td>';
            echo '<td data-label="Status"><span class="status-badge ' . htmlspecialchars(status_badge_class($item['status'])) . '">' . htmlspecialchars(status_label($item['status'])) . '</span></td>';
            echo '</tr>';
        }
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Rental History</title>
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
      <a href="owner_income.php">Income</a>
      <a class="active" href="rental_history.php">Rental History</a>
      <a href="owner_profile.php">Profile</a>
      <a href="owner_message.php">Messages</a>
      <a href="owner_reviews.php">Reviews</a>
      <a href="../auth/logout.php" class="topbar-action" style="display:block; margin-top:1rem; text-align:center;">Logout</a>
    </nav>
  </aside>

  <div class="main-content">
<header class="topbar">
      <button class="sidebar-toggle" type="button" aria-label="Open sidebar"></button>
      <h1>Rental History</h1>
      <a class="topbar-action" href="owner_profile.php">Profile</a>
    </header>

    <main class="page">
      <section class="card">
        <h3 class="section-title">All Bookings</h3>
        <?php if (empty($history)) : ?>
          <p class="empty-state">No booking history found.</p>
        <?php else : ?>
          <div class="table-wrapper only-desktop">
            <table class="table">
              <thead>
                <tr>
                  <th>Vehicle</th>
                  <th>Renter</th>
                  <th>Dates</th>
                  <th>Price</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody id="owner-rental-history" data-live-refresh="rental_history.php?ajax=1&section=rental-history" data-live-target="tbody#owner-rental-history">
                <?php foreach ($history as $item) : ?>
                  <tr>
                    <td data-label="Vehicle"><?php echo htmlspecialchars($item['vehicle_name']); ?></td>
                    <td data-label="Renter"><?php echo htmlspecialchars($item['renter_name']); ?></td>
                    <td data-label="Dates"><?php echo format_date($item['start_date']); ?> - <?php echo format_date($item['end_date']); ?></td>
                    <td data-label="Price"><?php echo format_currency($item['total_price']); ?></td>
                    <td data-label="Status"><span class="status-badge <?php echo htmlspecialchars(status_badge_class($item['status'])); ?>"><?php echo htmlspecialchars(status_label($item['status'])); ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- ============================================
               MOBILE HISTORY CARDS
               Same $history data as the table above, card-
               per-booking for phones (same .only-desktop/
               .only-mobile pattern used across the owner
               panel). Read-only page - no actions to stack,
               so no live-refresh limitation here either way
               since desktop table already covers that.
          ============================================ -->
          <div class="history-cards only-mobile">
            <?php foreach ($history as $item) : ?>
              <div class="history-card">
                <div class="history-card-header"><?php echo htmlspecialchars($item['vehicle_name']); ?></div>
                <div class="history-card-body">
                  <div class="history-card-row">
                    <span class="label">Renter</span>
                    <span class="value"><?php echo htmlspecialchars($item['renter_name']); ?></span>
                  </div>
                  <div class="history-card-row">
                    <span class="label">Pickup</span>
                    <span class="value"><?php echo format_date($item['start_date']); ?></span>
                  </div>
                  <div class="history-card-row">
                    <span class="label">Return</span>
                    <span class="value"><?php echo format_date($item['end_date']); ?></span>
                  </div>
                  <div class="history-card-row">
                    <span class="label">Price</span>
                    <span class="value"><?php echo format_currency($item['total_price']); ?></span>
                  </div>
                  <div class="history-card-row">
                    <span class="label">Status</span>
                    <span class="value"><span class="status-badge <?php echo htmlspecialchars(status_badge_class($item['status'])); ?>"><?php echo htmlspecialchars(status_label($item['status'])); ?></span></span>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </main>
  </div>

  <script>
    (function () {
      const tableBody = document.getElementById('owner-rental-history');
      if (!tableBody || !tableBody.dataset.liveRefresh) return;

      const refreshUrl = tableBody.dataset.liveRefresh;
      const target = tableBody.dataset.liveTarget || '#owner-rental-history';
      const refreshSection = () => {
        fetch(refreshUrl)
          .then((response) => response.text())
          .then((html) => {
            const targetNode = document.querySelector(target);
            if (targetNode) {
              targetNode.innerHTML = html;
            }
          })
          .catch((error) => console.log('Rental history refresh failed:', error));
      };

      refreshSection();
      setInterval(refreshSection, 7000);
    })();
  </script>
  <script src="js/owner_script.js"></script>
</body>
</html>