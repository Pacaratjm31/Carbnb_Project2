<?php require_once 'dashboard_logic.php'; 

$ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
if ($ajax && ($_GET['section'] ?? '') === 'stats-grid') {
  echo '<div class="stat-box"><h3>Pending Users</h3><p>' . (int) $totalPendingUsers . '</p></div>';
  echo '<div class="stat-box"><h3>Pending Vehicles</h3><p>' . (int) $totalPendingVehicles . '</p></div>';
  echo '<div class="stat-box"><h3>Pending Bookings</h3><p>' . (int) $totalPendingBookings . '</p></div>';
  echo '<div class="stat-box"><h3>Pending Payments</h3><p>' . (int) $totalPendingPayments . '</p></div>';
  echo '<div class="stat-box"><h3>Pending Messages</h3><p>' . (int) $totalPendingMessages . '</p></div>';
  exit;
}

if ($ajax && ($_GET['section'] ?? '') === 'overview-table') {
  echo '<tr><td data-label="Category">Total Users (Owners + Renters)</td><td data-label="Count">' . (int) $totalUsers . '</td></tr>';
  echo '<tr><td data-label="Category">Total Owners</td><td data-label="Count">' . (int) $totalOwners . '</td></tr>';
  echo '<tr><td data-label="Category">Total Renters</td><td data-label="Count">' . (int) $totalRenters . '</td></tr>';
  echo '<tr><td data-label="Category">Total Vehicles</td><td data-label="Count">' . (int) $totalVehicles . '</td></tr>';
  echo '<tr><td data-label="Category">Total Bookings</td><td data-label="Count">' . (int) $totalBookings . '</td></tr>';
  echo '<tr><td data-label="Category">Total Payments</td><td data-label="Count">' . (int) $totalPayments . '</td></tr>';
  echo '<tr><td data-label="Category">Deleted Users</td><td data-label="Count">' . (int) $totalDeletedUsers . '</td></tr>';
  echo '<tr><td data-label="Category">Total Contact Messages</td><td data-label="Count">' . (int) $totalContactMessages . '</td></tr>';
  exit;
}

// ============================================
// AJAX: Location Status Refresh
// ============================================
if ($ajax && ($_GET['section'] ?? '') === 'location-status') {
  header('Content-Type: application/json');
  
  // Ensure variables are defined
  $hasRecentLocation = isset($hasRecentLocation) ? $hasRecentLocation : false;
  $latestLocationTime = isset($latestLocationTime) ? $latestLocationTime : null;
  $recentLocationUser = isset($recentLocationUser) ? $recentLocationUser : '';
  $totalActiveRenters = isset($totalActiveRenters) ? $totalActiveRenters : 0;
  
  $status = 'inactive';
  $message = '🔴 Location Tracking Inactive — No recent location updates.';
  $user = '';
  $time = '';
  $activeCount = 0;
  
  if ($hasRecentLocation && $latestLocationTime) {
    $status = 'active';
    $user = $recentLocationUser ?? 'Unknown Renter';
    $time = date('h:i:s A', strtotime($latestLocationTime));
    $activeCount = $totalActiveRenters;
    $message = "🟢 Location Tracking Active — {$activeCount} renter(s) currently sharing location.";
  }
  
  echo json_encode([
    'success' => true,
    'status' => $status,
    'message' => $message,
    'user' => $user,
    'time' => $time,
    'active_count' => $activeCount
  ]);
  exit;
}

// ============================================
// AJAX: Notification count refresh
// ============================================
if ($ajax && ($_GET['section'] ?? '') === 'notification-count') {
  header('Content-Type: application/json');
  echo json_encode(['success' => true, 'count' => (int) $unreadNotifications]);
  exit;
}

// ============================================
// AJAX: Notification list refresh
// ============================================
if ($ajax && ($_GET['section'] ?? '') === 'notifications') {
  header('Content-Type: application/json');
  $notifications = [];
  if (isset($recentNotifications) && is_array($recentNotifications)) {
    $notifications = $recentNotifications;
  }
  echo json_encode(['success' => true, 'notifications' => $notifications]);
  exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard | Carbnb</title>
  <link rel="stylesheet" href="css/admin_style.css?v=20260702">
  <link rel="stylesheet" href="css/admin_style_backup.css?v=20260702">
  <link rel="stylesheet" href="css/admin_responsive.css?v=20260801">
  <style>
    /* ============================================
       LOCATION STATUS CARD STYLES
       ============================================ */
    .location-status-card {
      padding: 20px 24px;
      margin-bottom: 24px;
      border-radius: 12px;
      border: 1px solid #e5e7eb;
      background: #fff;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
    }
    .location-status-card .status-left {
      display: flex;
      align-items: center;
      gap: 16px;
    }
    .location-status-card .status-icon {
      font-size: 28px;
      line-height: 1;
    }
    .location-status-card .status-text {
      font-size: 16px;
      font-weight: 600;
      color: #1a1a2e;
    }
    .location-status-card .status-detail {
      font-size: 14px;
      color: #6b7280;
      margin-top: 2px;
    }
    .location-status-card .status-badge {
      padding: 6px 16px;
      border-radius: 999px;
      font-size: 13px;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .location-status-card .status-badge.active {
      background: #dcfce7;
      color: #16a34a;
    }
    .location-status-card .status-badge.inactive {
      background: #fee2e2;
      color: #dc2626;
    }
    .location-status-card .status-badge .dot {
      display: inline-block;
      width: 8px;
      height: 8px;
      border-radius: 50%;
      animation: pulse-dot 1.5s ease-in-out infinite;
    }
    .location-status-card .status-badge.active .dot {
      background: #22c55e;
    }
    .location-status-card .status-badge.inactive .dot {
      background: #ef4444;
      animation: none;
    }
    @keyframes pulse-dot {
      0%, 100% { opacity: 1; transform: scale(1); }
      50% { opacity: 0.5; transform: scale(0.8); }
    }
    .location-status-card .status-right {
      display: flex;
      align-items: center;
      gap: 16px;
      flex-wrap: wrap;
    }
    .location-status-card .view-tracker-link {
      font-size: 13px;
      color: #0d6efd;
      text-decoration: none;
      font-weight: 500;
    }
    .location-status-card .view-tracker-link:hover {
      text-decoration: underline;
    }

    @media (max-width: 576px) {
      .location-status-card {
        flex-direction: column;
        align-items: flex-start;
      }
      .location-status-card .status-right {
        width: 100%;
        justify-content: space-between;
      }
    }

    /* ============================================
       NOTIFICATION BELL STYLES
       ============================================ */
    .notification-bell {
      position: relative;
      display: inline-block;
      margin-left: 15px;
      cursor: pointer;
    }
    .notification-bell .bell-icon {
      font-size: 24px;
      color: #4b5563;
      text-decoration: none;
    }
    .notification-bell .badge {
      position: absolute;
      top: -8px;
      right: -8px;
      background: #dc2626;
      color: #fff;
      border-radius: 50%;
      padding: 2px 6px;
      font-size: 11px;
      font-weight: 700;
      min-width: 18px;
      text-align: center;
    }
    .notification-bell .badge.hidden {
      display: none;
    }
    .notification-dropdown {
      display: none;
      position: absolute;
      right: 0;
      top: 40px;
      width: 360px;
      max-height: 400px;
      overflow-y: auto;
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 10px 40px rgba(0,0,0,0.15);
      border: 1px solid #e5e7eb;
      z-index: 1000;
    }
    .notification-dropdown.show {
      display: block;
    }
    .notification-dropdown .dropdown-header {
      padding: 12px 16px;
      border-bottom: 1px solid #f3f4f6;
      font-weight: 600;
      color: #1a1a2e;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .notification-dropdown .dropdown-header .mark-all {
      font-size: 12px;
      color: #0d6efd;
      cursor: pointer;
      text-decoration: none;
    }
    .notification-dropdown .notification-item {
      padding: 12px 16px;
      border-bottom: 1px solid #f9fafb;
      transition: background 0.2s;
    }
    .notification-dropdown .notification-item:hover {
      background: #f9fafb;
    }
    .notification-dropdown .notification-item.unread {
      background: #eff6ff;
      border-left: 3px solid #0d6efd;
    }
    .notification-dropdown .notification-item .notif-title {
      font-weight: 600;
      font-size: 13px;
      color: #1a1a2e;
    }
    .notification-dropdown .notification-item .notif-message {
      font-size: 12px;
      color: #6b7280;
      margin: 4px 0 6px 0;
      line-height: 1.4;
    }
    .notification-dropdown .notification-item .notif-time {
      font-size: 10px;
      color: #9ca3af;
    }
    .notification-dropdown .empty-notifications {
      padding: 30px 16px;
      text-align: center;
      color: #9ca3af;
      font-size: 14px;
    }
    .notification-dropdown .notification-item .notif-link {
      color: #0d6efd;
      text-decoration: none;
      font-size: 12px;
      font-weight: 500;
    }
    .notification-dropdown .notification-item .notif-link:hover {
      text-decoration: underline;
    }

    @media (max-width: 576px) {
      .notification-dropdown {
        width: 300px;
        right: -20px;
      }
    }
  </style>
</head>
<body>
  <div class="overlay"></div>
  
  <aside class="sidebar">
    <div class="sidebar-header">
      <h2>Carbnb Admin</h2>
      <button class="sidebar-close" type="button" aria-label="Close sidebar"></button>
    </div>
    <nav class="sidebar-nav">
      <a class="active" href="dashboard.php">Dashboard</a>
      <a href="manage_users.php">Verify Users</a>
      <a href="verify_vehicles.php">Verify Vehicles</a>
      <a href="booking_records.php">Rental Records</a>
      <a href="account_control.php">Account Control</a>
      <a href="earnings.php">Earnings & Commission</a>
      <a href="contact_messages.php">Contact Messages</a>
      <a href="delete_user.php">Delete Users</a>
      <a href="trashbin.php">Trash Bin</a>
      <a href="location_tracker.php">Location Tracker</a>
      <a href="../auth/logout.php" class="topbar-action" style="display:block; margin-top:1rem; text-align:center;">Logout</a>
    </nav>
  </aside>

  <div class="main-content">
    <header class="topbar">
      <button class="sidebar-toggle" type="button" aria-label="Open sidebar"></button>
      <h1>Admin Dashboard</h1>
      <!-- Notification Bell -->
      <div class="notification-bell" id="notificationBell">
        <span class="bell-icon" id="bellIcon">🔔</span>
        <span class="badge <?= $unreadNotifications > 0 ? '' : 'hidden' ?>" id="notificationBadge">
          <?= $unreadNotifications ?>
        </span>
        <div class="notification-dropdown" id="notificationDropdown">
          <div class="dropdown-header">
            <span>Notifications</span>
            <?php if ($unreadNotifications > 0): ?>
              <a href="#" class="mark-all" id="markAllRead">Mark all read</a>
            <?php endif; ?>
          </div>
          <div id="notificationList">
            <?php if (empty($recentNotifications)): ?>
              <div class="empty-notifications">No notifications yet</div>
            <?php else: ?>
              <?php foreach ($recentNotifications as $notif): ?>
                <div class="notification-item <?= $notif['is_read'] ? '' : 'unread' ?>" data-id="<?= $notif['id'] ?>">
                  <div class="notif-title"><?= htmlspecialchars($notif['title']) ?></div>
                  <div class="notif-message"><?= htmlspecialchars($notif['message']) ?></div>
                  <div class="notif-time"><?= date('M d, Y h:i A', strtotime($notif['created_at'])) ?></div>
                  <?php if ($notif['user_id']): ?>
                    <a href="manage_users.php?view=<?= $notif['user_id'] ?>" class="notif-link">View user</a>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </header>

    <main class="page">
      <section class="hero-card">
        <div>
          <h2>Welcome, Administrator</h2>
          <p>Manage users, vehicles, bookings, payments, and system records.</p>
        </div>
      </section>

      <!-- ============================================ -->
      <!-- Location Tracking Status Section              -->
      <!-- ============================================ -->
      <section class="location-status-card" id="locationStatusCard">
        <div class="status-left">
          <span class="status-icon" id="statusIcon"><?= $hasRecentLocation ? '🟢' : '🔴' ?></span>
          <div>
            <div class="status-text" id="statusText">
              <?php if ($hasRecentLocation && $latestLocationTime): ?>
                🟢 Location Tracking Active — <?= $totalActiveRenters ?> renter(s) currently sharing location.
              <?php else: ?>
                🔴 Location Tracking Inactive — No recent location updates.
              <?php endif; ?>
            </div>
            <div class="status-detail" id="statusDetail">
              <?php if ($hasRecentLocation && $latestLocationTime): ?>
                <?php if ($recentLocationUser): ?>
                  Last: <?= htmlspecialchars($recentLocationUser) ?> at <?= date('h:i:s A', strtotime($latestLocationTime)) ?>
                <?php endif; ?>
              <?php else: ?>
                No location updates received in the last <?= isset($locationThresholdSeconds) ? $locationThresholdSeconds : 30 ?> seconds
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="status-right">
          <span class="status-badge <?= $hasRecentLocation ? 'active' : 'inactive' ?>" id="statusBadge">
            <span class="dot"></span>
            <?= $hasRecentLocation ? 'Active' : 'Inactive' ?>
          </span>
          <a href="location_tracker.php" class="view-tracker-link">View Map →</a>
        </div>
      </section>

      <?php if (!empty($error)): ?>
        <div class="alert error">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <section class="stats-grid" id="admin-dashboard-stats" data-live-refresh="dashboard.php?ajax=1&section=stats-grid" data-live-target="#admin-dashboard-stats">
        <div class="stat-box">
          <h3>Pending Users</h3>
          <p><?= $totalPendingUsers ?></p>
        </div>

        <div class="stat-box">
          <h3>Pending Vehicles</h3>
          <p><?= $totalPendingVehicles ?></p>
        </div>

        <div class="stat-box">
          <h3>Pending Bookings</h3>
          <p><?= $totalPendingBookings ?></p>
        </div>

        <div class="stat-box">
          <h3>Pending Payments</h3>
          <p><?= $totalPendingPayments ?></p>
        </div>

        <div class="stat-box">
          <h3>Pending Messages</h3>
          <p><?= $totalPendingMessages ?></p>
        </div>
      </section>

      <section class="card">
        <h3 class="section-title">Quick Actions</h3>
        <div class="quick-actions">
          <a href="manage_users.php" class="action-btn">Verify Users</a>
          <a href="verify_vehicles.php" class="action-btn">Verify Vehicles</a>
          <a href="booking_records.php" class="action-btn">View Bookings</a>
          <a href="contact_messages.php" class="action-btn">Contact Messages</a>
          <a href="location_tracker.php" class="action-btn">Location Tracker</a>
        </div>
      </section>

      <section class="card">
        <h3 class="section-title">System Overview</h3>
        <div class="table-wrapper">
          <table class="table">
            <thead>
              <tr>
                <th>Category</th>
                <th>Count</th>
              </tr>
            </thead>
            <tbody id="admin-overview-table" data-live-refresh="dashboard.php?ajax=1&section=overview-table" data-live-target="#admin-overview-table">
              <tr>
                <td data-label="Category">Total Users (Owners + Renters)</td>
                <td data-label="Count"><?= $totalUsers ?></td>
              </tr>
              <tr>
                <td data-label="Category">Total Owners</td>
                <td data-label="Count"><?= $totalOwners ?></td>
              </tr>
              <tr>
                <td data-label="Category">Total Renters</td>
                <td data-label="Count"><?= $totalRenters ?></td>
              </tr>
              <tr>
                <td data-label="Category">Total Vehicles</td>
                <td data-label="Count"><?= $totalVehicles ?></td>
              </tr>
              <tr>
                <td data-label="Category">Total Bookings</td>
                <td data-label="Count"><?= $totalBookings ?></td>
              </tr>
              <tr>
                <td data-label="Category">Total Payments</td>
                <td data-label="Count"><?= $totalPayments ?></td>
              </tr>
              <tr>
                <td data-label="Category">Deleted Users</td>
                <td data-label="Count"><?= $totalDeletedUsers ?></td>
              </tr>
              <tr>
                <td data-label="Category">Total Contact Messages</td>
                <td data-label="Count"><?= $totalContactMessages ?></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </main>
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

    // ============================================================
    // LIVE REFRESH - Stats and Overview (8 seconds)
    // ============================================================
    (function () {
      const refreshTargets = document.querySelectorAll('[data-live-refresh]');
      refreshTargets.forEach(function (element) {
        const refreshUrl = element.dataset.liveRefresh;
        const targetSelector = element.dataset.liveTarget || '#' + element.id;
        const refreshSection = function () {
          fetch(refreshUrl)
            .then(function (response) { return response.text(); })
            .then(function (html) {
              const targetNode = document.querySelector(targetSelector);
              if (targetNode) {
                targetNode.innerHTML = html;
              }
            })
            .catch(function (error) {
              console.log('Dashboard live refresh failed:', error);
            });
        };

        refreshSection();
        setInterval(refreshSection, 8000);
      });
    })();

    // ============================================================
    // LOCATION STATUS - Live refresh every 10 seconds (FIXED)
    // ============================================================
    (function () {
      function refreshLocationStatus() {
        const url = 'dashboard.php?ajax=1&section=location-status';
        
        fetch(url, {
          headers: { 'Cache-Control': 'no-cache', 'Pragma': 'no-cache' }
        })
          .then(function (response) { return response.json(); })
          .then(function (data) {
            if (data.success) {
              // Update status text
              const statusText = document.getElementById('statusText');
              const statusDetail = document.getElementById('statusDetail');
              const statusBadge = document.getElementById('statusBadge');
              const statusIcon = document.getElementById('statusIcon');
              
              if (statusText) statusText.textContent = data.message;
              
              if (statusDetail) {
                if (data.status === 'active') {
                  statusDetail.textContent = 'Last: ' + data.user + ' at ' + data.time;
                } else {
                  statusDetail.textContent = 'No location updates received in the last 30 seconds';
                }
              }
              
              if (statusBadge) {
                statusBadge.className = 'status-badge ' + data.status;
                statusBadge.innerHTML = '<span class="dot"></span> ' + (data.status === 'active' ? 'Active' : 'Inactive');
              }
              
              if (statusIcon) {
                statusIcon.textContent = data.status === 'active' ? '🟢' : '🔴';
              }
            }
          })
          .catch(function (error) {
            console.log('Location status refresh failed:', error);
          });
      }

      // FIXED: Call immediately on page load, then every 10 seconds
      refreshLocationStatus();
      setInterval(refreshLocationStatus, 10000);
    })();

    // ============================================================
    // NOTIFICATIONS - Live refresh every 10 seconds
    // ============================================================
    (function () {
      const bellIcon = document.getElementById('bellIcon');
      const badge = document.getElementById('notificationBadge');
      const dropdown = document.getElementById('notificationDropdown');
      const notificationList = document.getElementById('notificationList');
      const markAllBtn = document.getElementById('markAllRead');

      let isDropdownOpen = false;

      // Helper function to escape HTML
      function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
      }

      // Toggle dropdown
      if (bellIcon) {
        bellIcon.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          isDropdownOpen = !isDropdownOpen;
          if (isDropdownOpen) {
            dropdown.classList.add('show');
            refreshNotifications();
          } else {
            dropdown.classList.remove('show');
          }
        });
      }

      // Close dropdown on outside click
      document.addEventListener('click', function (e) {
        const bell = document.getElementById('notificationBell');
        if (bell && !bell.contains(e.target)) {
          dropdown.classList.remove('show');
          isDropdownOpen = false;
        }
      });

      // Render notifications in dropdown
      function renderNotifications(notifications) {
        if (!notificationList) return;

        if (!notifications || notifications.length === 0) {
          notificationList.innerHTML = '<div class="empty-notifications">No notifications yet</div>';
          return;
        }

        let html = '';
        notifications.forEach(function (notif) {
          const unreadClass = notif.is_read ? '' : 'unread';
          const time = new Date(notif.created_at).toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
          });
          html += `
            <div class="notification-item ${unreadClass}" data-id="${notif.id}">
              <div class="notif-title">${escapeHtml(notif.title)}</div>
              <div class="notif-message">${escapeHtml(notif.message)}</div>
              <div class="notif-time">${time}</div>
              ${notif.user_id ? `<a href="manage_users.php?view=${notif.user_id}" class="notif-link">View user</a>` : ''}
            </div>
          `;
        });
        notificationList.innerHTML = html;

        // Mark unread items as read when clicked
        document.querySelectorAll('.notification-item.unread').forEach(function (item) {
          item.addEventListener('click', function () {
            const id = this.dataset.id;
            if (id) {
              fetch('location_tracker.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_read&notification_id=' + id
              })
              .then(function (response) { return response.json(); })
              .then(function (data) {
                if (data.success) {
                  item.classList.remove('unread');
                  // Update badge count
                  refreshNotifications();
                }
              })
              .catch(function (error) {
                console.log('Mark read failed:', error);
              });
            }
          });
        });
      }

      // Refresh notification count and list
      function refreshNotifications() {
        // Update count
        fetch('dashboard.php?ajax=1&section=notification-count')
          .then(function (response) { return response.json(); })
          .then(function (data) {
            if (data.success) {
              const count = data.count;
              if (count > 0) {
                badge.textContent = count;
                badge.classList.remove('hidden');
              } else {
                badge.classList.add('hidden');
              }
            }
          })
          .catch(function (error) {
            console.log('Notification count refresh failed:', error);
          });

        // Update list if dropdown is open
        if (isDropdownOpen) {
          fetch('dashboard.php?ajax=1&section=notifications')
            .then(function (response) { return response.json(); })
            .then(function (data) {
              if (data.success && data.notifications) {
                renderNotifications(data.notifications);
              }
            })
            .catch(function (error) {
              console.log('Notification list refresh failed:', error);
            });
        }
      }

      // Mark all as read
      if (markAllBtn) {
        markAllBtn.addEventListener('click', function (e) {
          e.preventDefault();
          document.querySelectorAll('.notification-item.unread').forEach(function (item) {
            const id = item.dataset.id;
            if (id) {
              fetch('location_tracker.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_read&notification_id=' + id
              })
              .then(function (response) { return response.json(); })
              .then(function (data) {
                if (data.success) {
                  item.classList.remove('unread');
                }
              })
              .catch(function (error) {
                console.log('Mark read failed:', error);
              });
            }
          });
          setTimeout(refreshNotifications, 500);
        });
      }

      // Initial load and periodic refresh
      setTimeout(refreshNotifications, 1000);
      setInterval(refreshNotifications, 10000);
    })();
  </script>
</body>
</html>