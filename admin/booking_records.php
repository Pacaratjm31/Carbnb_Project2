<?php require_once 'booking_records_logic.php'; 

$ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
if ($ajax && ($_GET['section'] ?? '') === 'booking-history') {
  if (empty($records)) {
    echo '<tr><td colspan="8" class="empty-state">No booking records found.</td></tr>';
  } else {
    foreach ($records as $record) {
      echo '<tr>';
      echo '<td data-label="Booking ID">#' . (int) $record['id'] . '</td>';
      echo '<td data-label="Renter">' . clean($record['renter_name']) . '</td>';
      echo '<td data-label="Vehicle">' . clean($record['vehicle_name']) . '</td>';
      echo '<td data-label="Owner">' . clean($record['owner_name']) . '</td>';
      echo '<td data-label="Rental Date">' . formatDate($record['start_date']) . ' - ' . formatDate($record['end_date']) . '</td>';
      echo '<td data-label="Total Price">$' . number_format($record['total_price'], 2) . '</td>';
      echo '<td data-label="Status"><span class="status-badge ' . statusBadgeClass($record['status']) . '">' . statusLabel($record['status']) . '</span></td>';
      echo '<td class="cell-actions" data-label="Action"><div class="action-group">';
      if ($record['status'] === 'pending') {
        echo '<form method="POST" action="booking_records_logic.php"><input type="hidden" name="booking_id" value="' . $record['id'] . '"><input type="hidden" name="action" value="approve"><button type="submit" class="action-btn-small approve">Approve</button></form>';
        echo '<button class="action-btn-small reject" onclick="showRejectModal(' . $record['id'] . ')">Disapprove</button>';
      } elseif ($record['status'] === 'approved') {
        echo '<span class="text-success">Approved</span>';
      } elseif ($record['status'] === 'disapproved') {
        echo '<span class="text-danger">Disapproved</span>';
      } else {
        echo '<span class="text-muted">Completed</span>';
      }
      echo '</div></td></tr>';
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
  <title>Rental Records | Carbnb Admin</title>
  <link rel="stylesheet" href="css/admin_style.css?v=20260702">
  <link rel="stylesheet" href="css/admin_style_backup.css?v=20260702">
  <link rel="stylesheet" href="css/admin_responsive.css?v=20260801">
</head>
<body>
  <div class="overlay"></div>
  
  <aside class="sidebar">
    <div class="sidebar-header">
      <h2>Carbnb Admin</h2>
      <button class="sidebar-close" type="button" aria-label="Close sidebar"></button>
    </div>
    <nav class="sidebar-nav">
      <a href="dashboard.php">Dashboard</a>
      <a href="manage_users.php">Verify Users</a>
      <a href="verify_vehicles.php">Verify Vehicles</a>
      <a href="booking_records.php" class="active">Rental Records</a>
      <a href="account_control.php">Account Control</a>
      <a href="earnings.php">Earnings & Commission</a>
      <a href="contact_messages.php">Contact Messages</a>
      <a href="delete_user.php">Delete Users</a>
      <a href="trashbin.php">Trash Bin</a>
      <a href="location_tracker.php">Renter Tracker</a>
      <a href="../auth/logout.php" class="topbar-action" style="display:block; margin-top:1rem; text-align:center;">Logout</a>
    </nav>
  </aside>

  <div class="main-content">
    <header class="topbar">
      <button class="sidebar-toggle" type="button" aria-label="Open sidebar"></button>
      <h1>Rental Records</h1>
    </header>

    <main class="page">
      <section class="hero-card">
        <div>
          <h2>Rental Records</h2>
          <p>View all booking transactions made by renters and monitor their current rental status.</p>
        </div>
      </section>

      <?php if (!empty($success)): ?>
        <div class="alert success">
          <?= clean($success) ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($error)): ?>
        <div class="alert error">
          <?= clean($error) ?>
        </div>
      <?php endif; ?>

      <section class="card">
        <h3 class="section-title">Booking History</h3>
        <div class="table-wrapper">
          <table class="table">
            <thead>
              <tr>
                <th>Booking ID</th>
                <th>Renter</th>
                <th>Vehicle</th>
                <th>Owner</th>
                <th>Rental Date</th>
                <th>Total Price</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="admin-booking-history" data-live-refresh="booking_records.php?ajax=1&section=booking-history" data-live-target="#admin-booking-history">
              <?php if (empty($records)): ?>
                <tr>
                  <td colspan="8" class="empty-state">No booking records found.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($records as $record): ?>
                  <tr>
                    <td data-label="Booking ID">#<?= $record['id'] ?></td>
                    <td data-label="Renter"><?= clean($record['renter_name']) ?></td>
                    <td data-label="Vehicle"><?= clean($record['vehicle_name']) ?></td>
                    <td data-label="Owner"><?= clean($record['owner_name']) ?></td>
                    <td data-label="Rental Date"><?= formatDate($record['start_date']) ?> - <?= formatDate($record['end_date']) ?></td>
                    <td data-label="Total Price">$<?= number_format($record['total_price'], 2) ?></td>
                    <td data-label="Status">
                      <span class="status-badge <?= statusBadgeClass($record['status']) ?>">
                        <?= statusLabel($record['status']) ?>
                      </span>
                    </td>
                    <td class="cell-actions" data-label="Action">
                      <div class="action-group">
                        <?php if ($record['status'] === 'pending'): ?>
                          <form method="POST" action="booking_records_logic.php">
                            <input type="hidden" name="booking_id" value="<?= $record['id'] ?>">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="action-btn-small approve">Approve</button>
                          </form>
                          <button class="action-btn-small reject" onclick="showRejectModal(<?= $record['id'] ?>)">Disapprove</button>
                        <?php elseif ($record['status'] === 'approved'): ?>
                          <span class="text-success">Approved</span>
                        <?php elseif ($record['status'] === 'disapproved'): ?>
                          <span class="text-danger">Disapproved</span>
                        <?php else: ?>
                          <span class="text-muted">Completed</span>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </div>

  <!-- Reject Modal -->
  <div id="rejectModal" class="modal" style="display:none;">
    <div class="modal-content">
      <h3>Disapprove Booking</h3>
      <form method="POST" action="booking_records_logic.php">
        <input type="hidden" name="booking_id" id="rejectBookingId">
        <input type="hidden" name="action" value="reject">
        <textarea name="feedback" placeholder="Enter reason for disapproval (optional)..." rows="3"></textarea>
        <button type="submit" class="btn-primary">Submit</button>
        <button type="button" onclick="closeRejectModal()" class="action-btn-small">Cancel</button>
      </form>
    </div>
  </div>

  <script>
    // ============================================================
    // SIDEBAR TOGGLE - FIXED
    // ============================================================
    document.addEventListener('DOMContentLoaded', function () {
      const sidebar = document.querySelector('.sidebar');
      const overlay = document.querySelector('.overlay');
      const toggleBtn = document.querySelector('.sidebar-toggle');
      const closeBtn = document.querySelector('.sidebar-close');

      function openSidebar() {
        if (sidebar) sidebar.classList.add('open');
        if (overlay) overlay.classList.add('show');
        document.body.classList.add('sidebar-open');
      }

      function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('show');
        document.body.classList.remove('sidebar-open');
      }

      if (toggleBtn) {
        toggleBtn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          if (sidebar && sidebar.classList.contains('open')) {
            closeSidebar();
          } else {
            openSidebar();
          }
        });
      }

      if (closeBtn) {
        closeBtn.addEventListener('click', function (e) {
          e.preventDefault();
          closeSidebar();
        });
      }

      if (overlay) {
        overlay.addEventListener('click', function (e) {
          if (e.target === this) {
            closeSidebar();
          }
        });
      }

      document.querySelectorAll('.sidebar-nav a').forEach(function (link) {
        link.addEventListener('click', function () {
          if (window.innerWidth <= 992) {
            closeSidebar();
          }
        });
      });

      window.addEventListener('resize', function () {
        if (window.innerWidth > 992) {
          closeSidebar();
        }
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          closeSidebar();
        }
      });
    });

    function showRejectModal(bookingId) {
      document.getElementById('rejectBookingId').value = bookingId;
      document.getElementById('rejectModal').style.display = 'flex';
    }

    function closeRejectModal() {
      document.getElementById('rejectModal').style.display = 'none';
    }

    // ============================================================
    // LIVE REFRESH
    // ============================================================
    (function () {
      const liveTargets = document.querySelectorAll('[data-live-refresh]');
      let refreshIntervals = [];

      liveTargets.forEach(function (node) {
        const refreshUrl = node.dataset.liveRefresh;
        const targetSelector = node.dataset.liveTarget || '#' + node.id;

        function refreshSection() {
          fetch(refreshUrl, {
            headers: {
              'Cache-Control': 'no-cache',
              'Pragma': 'no-cache'
            }
          })
            .then(function (response) {
              if (!response.ok) {
                throw new Error('Network response was not ok');
              }
              return response.text();
            })
            .then(function (html) {
              const targetNode = document.querySelector(targetSelector);
              if (targetNode) {
                targetNode.innerHTML = html;
              }
            })
            .catch(function (error) {
              console.log('Booking records live refresh failed:', error);
            });
        }

        refreshSection();
        const intervalId = setInterval(refreshSection, 8000);
        refreshIntervals.push(intervalId);
      });

      window.addEventListener('beforeunload', function() {
        refreshIntervals.forEach(function(id) {
          clearInterval(id);
        });
      });
    })();
  </script>
</body>
</html>