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

$bookings = get_owner_bookings($pdo, $owner['id']);

$ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
if ($ajax && ($_GET['section'] ?? '') === 'booking-requests') {
    if (empty($bookings)) {
        echo '<tr><td colspan="5" class="empty-state">No booking requests found.</td></tr>';
    } else {
        foreach ($bookings as $booking) {
            echo '<tr>';
            echo '<td data-label="Vehicle">' . htmlspecialchars($booking['vehicle_name']) . '</td>';
            echo '<td data-label="Renter">' . htmlspecialchars($booking['renter_name']) . '</td>';
            echo '<td data-label="Dates">' . format_date($booking['start_date']) . ' - ' . format_date($booking['end_date']) . '</td>';
            echo '<td data-label="Price">' . format_currency($booking['total_price']) . '</td>';
            echo '<td data-label="Status"><span class="status-badge ' . htmlspecialchars(status_badge_class($booking['status'])) . '">' . htmlspecialchars(status_label($booking['status'])) . '</span></td>';
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
  <title>Booking Requests</title>
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
      <a class="active" href="booking_requests.php">Booking Requests</a>
      <a href="vehicle_status.php">Vehicle Status</a>
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
      <h1>Booking Requests</h1>
      <a class="topbar-action" href="owner_profile.php">Profile</a>
    </header>

    <main class="page">
      <section class="card">
        <h3 class="section-title">All Requests</h3>
        <?php if (empty($bookings)) : ?>
          <p class="empty-state">No booking requests found.</p>
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
              <tbody id="owner-booking-list" data-live-refresh="booking_requests.php?ajax=1&section=booking-requests" data-live-target="tbody#owner-booking-list">
                <?php foreach ($bookings as $booking) : ?>
                  <tr>
                    <td data-label="Vehicle"><?php echo htmlspecialchars($booking['vehicle_name']); ?></td>
                    <td data-label="Renter"><?php echo htmlspecialchars($booking['renter_name']); ?></td>
                    <td data-label="Dates"><?php echo format_date($booking['start_date']); ?> - <?php echo format_date($booking['end_date']); ?></td>
                    <td data-label="Price"><?php echo format_currency($booking['total_price']); ?></td>
                    <td data-label="Status"><span class="status-badge <?php echo htmlspecialchars(status_badge_class($booking['status'])); ?>"><?php echo htmlspecialchars(status_label($booking['status'])); ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- ============================================
               MOBILE BOOKING CARDS
               Same $bookings data as the table above, just a
               card-per-booking layout for phones (matches the
               .only-desktop/.only-mobile pattern used on the
               admin Rental Records page). NOTE: like the admin
               version, this block is rendered once on page
               load and is not wired into the 7s live-refresh
               above - only the desktop table auto-refreshes.
          ============================================ -->
          <div class="booking-cards only-mobile">
            <?php foreach ($bookings as $booking) : ?>
              <div class="booking-card">
                <div class="booking-card-header"><?php echo htmlspecialchars($booking['vehicle_name']); ?></div>
                <div class="booking-card-body">
                  <div class="booking-card-row">
                    <span class="label">Renter</span>
                    <span class="value"><?php echo htmlspecialchars($booking['renter_name']); ?></span>
                  </div>
                  <div class="booking-card-row">
                    <span class="label">Pickup</span>
                    <span class="value"><?php echo format_date($booking['start_date']); ?></span>
                  </div>
                  <div class="booking-card-row">
                    <span class="label">Return</span>
                    <span class="value"><?php echo format_date($booking['end_date']); ?></span>
                  </div>
                  <div class="booking-card-row">
                    <span class="label">Price</span>
                    <span class="value"><?php echo format_currency($booking['total_price']); ?></span>
                  </div>
                  <div class="booking-card-row">
                    <span class="label">Status</span>
                    <span class="value">
                      <span class="status-badge <?php echo htmlspecialchars(status_badge_class($booking['status'])); ?>"><?php echo htmlspecialchars(status_label($booking['status'])); ?></span>
                    </span>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </main>
  </div>

  <script src="js/owner_script.js"></script>
  <script>
    (function () {
      const tableBody = document.getElementById('owner-booking-list');
      if (!tableBody || !tableBody.dataset.liveRefresh) return;

      const refreshUrl = tableBody.dataset.liveRefresh;
      const target = tableBody.dataset.liveTarget || '#owner-booking-list';
      const refreshSection = () => {
        fetch(refreshUrl)
          .then((response) => response.text())
          .then((html) => {
            const targetNode = document.querySelector(target);
            if (targetNode) {
              targetNode.innerHTML = html;
            }
          })
          .catch((error) => console.log('Booking list refresh failed:', error));
      };

      refreshSection();
      setInterval(refreshSection, 7000);
    })();
  </script>
</body>
</html>