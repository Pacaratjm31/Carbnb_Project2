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

// Handle make available request
// NOTE: This is the ONLY place a vehicle should be allowed to transition
// back to 'available'. make_vehicle_available() (in owner_logic.php)
// verifies vehicle ownership and blocks the change if there are active
// bookings. Renters must never be able to trigger this — record.php's
// return_car() only ever updates bookings.status, never vehicles.availability_status.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['make_available'], $_POST['vehicle_id'])) {
    $vehicle_id = (int) $_POST['vehicle_id'];
    $result = make_vehicle_available($pdo, $owner['id'], $vehicle_id);
    if ($result['success']) {
        header('Location: manage_vehicles.php?success=' . urlencode('Vehicle is now available.'));
        exit;
    } else {
        $error = $result['message'];
    }
}

// Handle set maintenance request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_maintenance'], $_POST['vehicle_id'])) {
    $vehicle_id = (int) $_POST['vehicle_id'];
    $stmt = $pdo->prepare("SELECT id, availability_status FROM vehicles WHERE id = ? AND owner_id = ? AND is_deleted = 0");
    $stmt->execute([$vehicle_id, $owner['id']]);
    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($vehicle) {
        // Check if vehicle has any active bookings (pending or approved)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE vehicle_id = ? AND status IN ('pending', 'approved')");
        $stmt->execute([$vehicle_id]);
        $active_bookings = (int) $stmt->fetchColumn();
        
        if ($active_bookings > 0) {
            $error = 'Cannot set to maintenance. Vehicle has active bookings.';
        } else {
            $stmt = $pdo->prepare("UPDATE vehicles SET availability_status = 'maintenance' WHERE id = ?");
            $stmt->execute([$vehicle_id]);
            header('Location: manage_vehicles.php?success=' . urlencode('Vehicle set to maintenance.'));
            exit;
        }
    } else {
        $error = 'Vehicle not found.';
    }
}

// Handle remove maintenance request (make vehicle available again)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_maintenance'], $_POST['vehicle_id'])) {
    $vehicle_id = (int) $_POST['vehicle_id'];
    $stmt = $pdo->prepare("SELECT id, availability_status FROM vehicles WHERE id = ? AND owner_id = ? AND is_deleted = 0");
    $stmt->execute([$vehicle_id, $owner['id']]);
    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($vehicle && $vehicle['availability_status'] === 'maintenance') {
        $stmt = $pdo->prepare("UPDATE vehicles SET availability_status = 'available' WHERE id = ?");
        $stmt->execute([$vehicle_id]);
        header('Location: manage_vehicles.php?success=' . urlencode('Vehicle is now available in the market.'));
        exit;
    } else {
        $error = 'Vehicle not found or not in maintenance.';
    }
}

$vehicles = get_owner_vehicles($pdo, $owner['id']);

$ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
if ($ajax && ($_GET['section'] ?? '') === 'vehicle-list') {
    if (empty($vehicles)) {
        echo '<tr><td colspan="6" class="empty-state">No vehicles registered yet.</td></tr>';
    } else {
        foreach ($vehicles as $vehicle) {
            echo '<tr>';
            echo '<td data-label="Vehicle">' . htmlspecialchars($vehicle['name']) . '</td>';
            echo '<td data-label="Category">' . htmlspecialchars(str_replace('_', ' ', $vehicle['category'])) . '</td>';
            echo '<td data-label="Price">' . format_currency($vehicle['price_per_day']) . '/day</td>';
            echo '<td data-label="Availability"><span class="status-badge ' . htmlspecialchars(status_badge_class($vehicle['availability_status'])) . '">' . htmlspecialchars(status_label($vehicle['availability_status'])) . '</span></td>';
            echo '<td data-label="Approval"><span class="status-badge ' . htmlspecialchars(approval_status_badge_class($vehicle['approval_status'])) . '">' . htmlspecialchars(approval_status_label($vehicle['approval_status'])) . '</span></td>';
            echo '<td data-label="Actions" class="cell-actions"><div class="action-group">';
            if ($vehicle['availability_status'] === 'available') {
                echo '<form method="POST" onsubmit="return confirm(\'Set this vehicle to maintenance?\');">';
                echo '<input type="hidden" name="vehicle_id" value="' . (int) $vehicle['id'] . '">';
                echo '<button type="submit" name="set_maintenance" class="action-btn-small" style="background:#ffc107; color:#111;">Set Maintenance</button>';
                echo '</form>';
            }
            if ($vehicle['availability_status'] === 'rented') {
                echo '<form method="POST" onsubmit="return confirm(\'Make this vehicle available again?\');">';
                echo '<input type="hidden" name="vehicle_id" value="' . (int) $vehicle['id'] . '">';
                echo '<button type="submit" name="make_available" class="action-btn-small approve">Make Available</button>';
                echo '</form>';
            }
            if ($vehicle['availability_status'] === 'maintenance') {
                echo '<form method="POST" onsubmit="return confirm(\'Remove maintenance and make this vehicle available in the market?\');">';
                echo '<input type="hidden" name="vehicle_id" value="' . (int) $vehicle['id'] . '">';
                echo '<button type="submit" name="remove_maintenance" class="action-btn-small approve">Remove Maintenance</button>';
                echo '</form>';
            }
            echo '</div></td>';
            echo '</tr>';
        }
    }
    exit;
}

// Check for success message from redirect
$success = $_GET['success'] ?? '';
$error = $error ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Vehicles</title>
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
      <a class="active" href="manage_vehicles.php">Manage Vehicles</a>
      <a href="booking_requests.php">Booking Requests</a>
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
      <h1>Manage Vehicles</h1>
      <a class="topbar-action" href="add_vehicle.php">Add Vehicle</a>
    </header>

    <main class="page">
      <?php if ($success) : ?>
        <div class="alert success"><?= htmlspecialchars($success) ?></div>
      <?php endif; ?>
      <?php if ($error) : ?>
        <div class="alert" style="color: #dc3545;"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <section class="card">
        <h3 class="section-title">Your Vehicles</h3>
        <?php if (empty($vehicles)) : ?>
          <p class="empty-state">No vehicles registered yet.</p>
        <?php else : ?>
          <div class="table-wrapper only-desktop">
            <table class="table">
              <thead>
                <tr>
                  <th>Vehicle</th>
                  <th>Category</th>
                  <th>Price</th>
                  <th>Availability</th>
                  <th>Approval</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="owner-vehicle-list" data-live-refresh="manage_vehicles.php?ajax=1&section=vehicle-list" data-live-target="tbody#owner-vehicle-list">
                <?php foreach ($vehicles as $vehicle) : ?>
                  <tr>
                    <td data-label="Vehicle"><?php echo htmlspecialchars($vehicle['name']); ?></td>
                    <td data-label="Category"><?php echo htmlspecialchars(str_replace('_', ' ', $vehicle['category'])); ?></td>
                    <td data-label="Price"><?php echo format_currency($vehicle['price_per_day']); ?>/day</td>
                    <td data-label="Availability"><span class="status-badge <?php echo htmlspecialchars(status_badge_class($vehicle['availability_status'])); ?>"><?php echo htmlspecialchars(status_label($vehicle['availability_status'])); ?></span></td>
                    <td data-label="Approval"><span class="status-badge <?php echo htmlspecialchars(approval_status_badge_class($vehicle['approval_status'])); ?>"><?php echo htmlspecialchars(approval_status_label($vehicle['approval_status'])); ?></span></td>
                    <td data-label="Actions" class="cell-actions">
                      <div class="action-group">
                        <?php if ($vehicle['availability_status'] === 'available') : ?>
                          <form method="POST" onsubmit="return confirm('Set this vehicle to maintenance?');">
                            <input type="hidden" name="vehicle_id" value="<?php echo (int) $vehicle['id']; ?>">
                            <button type="submit" name="set_maintenance" class="action-btn-small" style="background:#ffc107; color:#111;">Set Maintenance</button>
                          </form>
                        <?php endif; ?>
                        <?php if ($vehicle['availability_status'] === 'rented') : ?>
                          <form method="POST" onsubmit="return confirm('Make this vehicle available again?');">
                            <input type="hidden" name="vehicle_id" value="<?php echo (int) $vehicle['id']; ?>">
                            <button type="submit" name="make_available" class="action-btn-small approve">Make Available</button>
                          </form>
                        <?php endif; ?>
                        <?php if ($vehicle['availability_status'] === 'maintenance') : ?>
                          <form method="POST" onsubmit="return confirm('Remove maintenance and make this vehicle available in the market?');">
                            <input type="hidden" name="vehicle_id" value="<?php echo (int) $vehicle['id']; ?>">
                            <button type="submit" name="remove_maintenance" class="action-btn-small approve">Remove Maintenance</button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- ============================================
               MOBILE VEHICLE CARDS
               Same $vehicles data as the table above, card-
               per-vehicle for phones (same .only-desktop/
               .only-mobile pattern as booking_requests.php).
               NOTE: like that page, this block renders once
               on load and is not part of the 7s live-refresh
               - only the desktop table auto-refreshes.
          ============================================ -->
          <div class="vehicle-list-cards only-mobile">
            <?php foreach ($vehicles as $vehicle) : ?>
              <div class="vehicle-list-card">
                <div class="vehicle-list-card-header"><?php echo htmlspecialchars($vehicle['name']); ?></div>
                <div class="vehicle-list-card-body">
                  <div class="vehicle-list-card-row">
                    <span class="label">Category</span>
                    <span class="value"><?php echo htmlspecialchars(str_replace('_', ' ', $vehicle['category'])); ?></span>
                  </div>
                  <div class="vehicle-list-card-row">
                    <span class="label">Price</span>
                    <span class="value"><?php echo format_currency($vehicle['price_per_day']); ?>/day</span>
                  </div>
                  <div class="vehicle-list-card-row">
                    <span class="label">Availability</span>
                    <span class="value"><span class="status-badge <?php echo htmlspecialchars(status_badge_class($vehicle['availability_status'])); ?>"><?php echo htmlspecialchars(status_label($vehicle['availability_status'])); ?></span></span>
                  </div>
                  <div class="vehicle-list-card-row">
                    <span class="label">Approval</span>
                    <span class="value"><span class="status-badge <?php echo htmlspecialchars(approval_status_badge_class($vehicle['approval_status'])); ?>"><?php echo htmlspecialchars(approval_status_label($vehicle['approval_status'])); ?></span></span>
                  </div>
                </div>
                <div class="vehicle-list-card-actions">
                  <?php if ($vehicle['availability_status'] === 'available') : ?>
                    <form method="POST" onsubmit="return confirm('Set this vehicle to maintenance?');">
                      <input type="hidden" name="vehicle_id" value="<?php echo (int) $vehicle['id']; ?>">
                      <button type="submit" name="set_maintenance" class="action-btn-small" style="background:#ffc107; color:#111;">Set Maintenance</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($vehicle['availability_status'] === 'rented') : ?>
                    <form method="POST" onsubmit="return confirm('Make this vehicle available again?');">
                      <input type="hidden" name="vehicle_id" value="<?php echo (int) $vehicle['id']; ?>">
                      <button type="submit" name="make_available" class="action-btn-small approve">Make Available</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($vehicle['availability_status'] === 'maintenance') : ?>
                    <form method="POST" onsubmit="return confirm('Remove maintenance and make this vehicle available in the market?');">
                      <input type="hidden" name="vehicle_id" value="<?php echo (int) $vehicle['id']; ?>">
                      <button type="submit" name="remove_maintenance" class="action-btn-small approve">Remove Maintenance</button>
                    </form>
                  <?php endif; ?>
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
      const tableBody = document.getElementById('owner-vehicle-list');
      if (!tableBody || !tableBody.dataset.liveRefresh) return;

      const refreshUrl = tableBody.dataset.liveRefresh;
      const target = tableBody.dataset.liveTarget || '#owner-vehicle-list';
      const refreshSection = () => {
        fetch(refreshUrl)
          .then((response) => response.text())
          .then((html) => {
            const targetNode = document.querySelector(target);
            if (targetNode) {
              targetNode.innerHTML = html;
            }
          })
          .catch((error) => console.log('Vehicle list refresh failed:', error));
      };

      refreshSection();
      setInterval(refreshSection, 7000);
    })();
  </script>
</body>
</html>