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

$dashboard = get_dashboard_data($pdo, $owner['id']);
$stats = $dashboard['stats'];
$recent_bookings = $dashboard['recent_bookings'];
$account_state = get_owner_account_state($owner);
$unread_count = get_unread_message_count($pdo, $owner['id']);
$recent_messages = get_owner_messages($pdo, $owner['id']);
// Get only the 3 most recent messages for dashboard display
$recent_messages = array_slice($recent_messages, 0, 3);

// Get maintenance count for dashboard
$maintenance_count = 0;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE owner_id = ? AND availability_status = 'maintenance' AND is_deleted = 0");
$stmt->execute([$owner['id']]);
$maintenance_count = (int) $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Owner Dashboard</title>
  <link rel="stylesheet" href="css/owner_style.css?v=20260702">
  <link rel="stylesheet" href="css/owner_responsive.css?v=20260803">
</head>
<body data-user-id="<?php echo (int) $owner['id']; ?>" data-current-status="<?php echo htmlspecialchars($owner['status'] ?? 'pending'); ?>">
  <div class="overlay"></div>
  <aside class="sidebar">
<div class="sidebar-header">
      <h2>Carbnb Owner</h2>
      <button class="sidebar-close" type="button" aria-label="Close sidebar"></button>
    </div>
    <nav class="sidebar-nav">
      <a class="active" href="owner_dashboard.php">Dashboard</a>
      <?php if ($account_state['restricted']) : ?>
        <a href="#" onclick="return false;" style="opacity:0.6; cursor:not-allowed;">Add Vehicle</a>
        <a href="#" onclick="return false;" style="opacity:0.6; cursor:not-allowed;">Manage Vehicles</a>
        <a href="#" onclick="return false;" style="opacity:0.6; cursor:not-allowed;">Booking Requests</a>
        <a href="#" onclick="return false;" style="opacity:0.6; cursor:not-allowed;">Vehicle Status</a>
        <a href="#" onclick="return false;" style="opacity:0.6; cursor:not-allowed;">Income</a>
        <a href="#" onclick="return false;" style="opacity:0.6; cursor:not-allowed;">Rental History</a>
        <a href="#" onclick="return false;" style="opacity:0.6; cursor:not-allowed;">Profile</a>
        <a href="#" onclick="return false;" style="opacity:0.6; cursor:not-allowed;">Messages</a>
        <a href="#" onclick="return false;" style="opacity:0.6; cursor:not-allowed;">Reviews</a>
      <?php else : ?>
        <a href="add_vehicle.php">Add Vehicle</a>
        <a href="manage_vehicles.php">Manage Vehicles</a>
        <a href="booking_requests.php">Booking Requests</a>
        <a href="vehicle_status.php">Vehicle Status</a>
        <a href="owner_income.php">Income</a>
        <a href="rental_history.php">Rental History</a>
        <a href="owner_profile.php">Profile</a>
        <a href="owner_message.php">Messages</a>
        <a href="owner_reviews.php">Reviews</a>
      <?php endif; ?>
      <a href="../auth/logout.php" class="topbar-action" style="display:block; margin-top:1rem; text-align:center;">Logout</a>
    </nav>
  </aside>

  <div class="main-content">
<header class="topbar">
      <button class="sidebar-toggle" type="button" aria-label="Open sidebar"></button>
      <h1>Owner Dashboard</h1>
      <a class="topbar-action" href="owner_profile.php">Profile</a>
    </header>

    <main class="page">
      <section class="hero-card">
        <div>
          <h2>Welcome back, <?php echo htmlspecialchars($owner['full_name'] ?: 'Owner'); ?></h2>
          <p>Manage your rental business and track bookings from one place.</p>
        </div>
        <?php if (!$account_state['restricted']) : ?>
          <a class="topbar-action" href="add_vehicle.php">Add New Vehicle</a>
        <?php endif; ?>
      </section>

      <section class="card" style="margin-bottom: 1.2rem;">
        <div class="alert <?php echo $account_state['restricted'] ? 'error' : 'success'; ?>">
          <strong><?php echo htmlspecialchars($account_state['title']); ?></strong>
          <div><?php echo htmlspecialchars($account_state['message']); ?></div>
        </div>
      </section>

      <section class="card" style="margin-bottom: 1.2rem;">
        <h3 class="section-title">Admin Approval Tracking</h3>
        <p><strong>Status:</strong> <span id="approval-status-badge" class="status-badge <?php echo htmlspecialchars(approval_status_badge_class($owner['status'] ?? 'pending')); ?>"><?php echo htmlspecialchars(approval_status_label($owner['status'] ?? 'pending')); ?></span></p>
        <p><strong>Note:</strong> <span id="approval-note"><?php echo htmlspecialchars(($owner['disapproval_reason'] ?? '') !== '' ? $owner['disapproval_reason'] : 'No admin note yet.'); ?></span></p>
      </section>

<?php if (!$account_state['restricted']) : ?>
      <!-- ============================================
           MOBILE QUICK ACTIONS
           Shortcut buttons to the most-used owner pages.
           Lets phone users jump around without opening
           the sidebar drawer every time. Uses .quick-actions
           / .action-btn (same classes as the admin panel) -
           full-width stacked buttons on mobile once
           owner_style.css picks up the matching rules.
      ============================================ -->
      <section class="card" style="margin-bottom: 1.2rem;">
        <h3 class="section-title">Quick Actions</h3>
        <div class="quick-actions">
          <a href="add_vehicle.php" class="action-btn">Add Vehicle</a>
          <a href="manage_vehicles.php" class="action-btn">Manage Vehicles</a>
          <a href="booking_requests.php" class="action-btn">Booking Requests</a>
          <a href="owner_message.php" class="action-btn">Messages</a>
        </div>
      </section>
      <?php endif; ?>

      <section class="stats-grid">
        <div class="stat-box">
          <h3>Active Vehicles</h3>
          <p><?php echo (int) $stats['active_vehicles']; ?></p>
        </div>
        <div class="stat-box">
          <h3>Pending Requests</h3>
          <p><?php echo (int) $stats['pending_requests']; ?></p>
        </div>
        <div class="stat-box">
          <h3>Monthly Income</h3>
          <p><?php echo format_currency($stats['monthly_income']); ?></p>
        </div>
        <div class="stat-box">
          <h3>Maintenance</h3>
          <p><?php echo (int) $maintenance_count; ?></p>
        </div>
      </section>

      <section class="content-grid spaced-grid">
        <div class="card">
          <h3 class="section-title">Recent Bookings</h3>
          <?php if (empty($recent_bookings)) : ?>
            <p class="empty-state">No bookings yet.</p>
          <?php else : ?>
            <ul>
              <?php foreach ($recent_bookings as $booking) : ?>
                <li><?php echo htmlspecialchars($booking['vehicle_name']); ?> — <?php echo htmlspecialchars($booking['renter_name']); ?> (<?php echo htmlspecialchars(status_label($booking['status'])); ?>)</li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
        <div class="card">
          <h3 class="section-title">Vehicle Status</h3>
          <p>Your fleet is synced from the database.</p>
        </div>
        <div class="card">
          <h3 class="section-title">Messages <?php if ($unread_count > 0): ?><span class="status-badge pending" style="margin-left:8px;"><?= $unread_count ?> new</span><?php endif; ?></h3>
          <?php if (empty($recent_messages)) : ?>
            <p class="empty-state">No messages yet. <a href="owner_message.php" style="color:var(--accent);">View all messages</a></p>
          <?php else : ?>
            <ul style="margin:0; padding-left:18px;">
              <?php foreach ($recent_messages as $msg): ?>
                <li style="margin-bottom:8px;">
                  <strong><?= $msg['sender_id'] == $owner['id'] ? 'To: ' . clean($msg['receiver_name']) : 'From: ' . clean($msg['sender_name']) ?></strong>
                  <br><span style="color:var(--muted);"><?= clean(substr($msg['message'], 0, 80)) ?><?= strlen($msg['message']) > 80 ? '...' : '' ?></span>
                  <br><small style="color:var(--muted);"><?= format_date($msg['created_at']) ?></small>
                </li>
              <?php endforeach; ?>
            </ul>
            <a href="owner_message.php" class="action-btn" style="margin-top:10px; display:inline-block;">View All Messages</a>
          <?php endif; ?>
        </div>
      </section>
    </main>
  </div>

  <script>
    // Auto-refresh approval status for pending accounts
    (function() {
      const userId = document.body.getAttribute('data-user-id');
      const currentStatus = document.body.getAttribute('data-current-status');
      const statusBadge = document.getElementById('approval-status-badge');
      const approvalNote = document.getElementById('approval-note');
      const alertBox = document.querySelector('.alert');
      
      if (!userId || !statusBadge) return;
      
      // Only poll if account is pending
      if (currentStatus !== 'pending') return;
      
      // Poll for status updates every 5 seconds
      const pollInterval = setInterval(function() {
        fetch('check_approval_status.php?user_id=' + userId)
          .then(response => response.json())
          .then(data => {
            if (data.status === 'approved') {
              clearInterval(pollInterval);
              location.reload();
            } else if (data.status === 'disapproved') {
              statusBadge.textContent = 'Disapproved';
              statusBadge.className = 'status-badge active';
              approvalNote.textContent = data.disapproval_reason || 'Your account was disapproved.';
            }
          })
          .catch(err => console.log('Status check failed:', err));
      }, 5000);
    })();
  </script>
  <script src="js/owner_script.js"></script>
</body>
</html>