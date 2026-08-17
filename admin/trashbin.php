<?php require_once 'trashbin_logic.php'; 

$ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
if ($ajax && ($_GET['section'] ?? '') === 'deleted-users') {
  if (empty($deletedUsers)) {
    echo '<tr><td colspan="5" class="empty-state">No deleted users found.</td></tr>';
  } else {
    foreach ($deletedUsers as $user) {
      echo '<tr>';
      echo '<td class="cell-name" data-label="Name">' . clean($user['full_name']) . '</td>';
      echo '<td class="cell-email" data-label="Email">' . clean($user['email']) . '</td>';
      echo '<td data-label="Role">' . clean(ucfirst($user['role'])) . '</td>';
      echo '<td data-label="Deleted Date">' . formatDate($user['deleted_at']) . '</td>';
      echo '<td class="cell-actions" data-label="Action"><div class="action-group"><a href="trashbin.php?restore_id=' . (int) $user['id'] . '" class="action-btn-small approve" onclick="return confirm(\'Are you sure you want to restore this user?\')">Restore</a><a href="trashbin.php?permanent_delete_id=' . (int) $user['id'] . '" class="action-btn-small reject" onclick="return confirm(\'Are you sure you want to permanently delete this user? This action cannot be undone.\')">Delete Permanently</a></div></td>';
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
  <title>Trash Bin | Carbnb Admin</title>
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
      <a href="booking_records.php">Rental Records</a>
      <a href="account_control.php">Account Control</a>
      <a href="earnings.php">Earnings & Commission</a>
      <a href="contact_messages.php">Contact Messages</a>
      <a href="delete_user.php">Delete Users</a>
      <a href="trashbin.php" class="active">Trash Bin</a>
      <a href="location_tracker.php">Renter Tracker</a>
      <a href="../auth/logout.php" class="topbar-action" style="display:block; margin-top:1rem; text-align:center;">Logout</a>
    </nav>
  </aside>

  <div class="main-content">
<header class="topbar">
      <button class="sidebar-toggle" type="button" aria-label="Open sidebar"></button>
      <h1>Trash Bin</h1>
    </header>

    <main class="page">
      <section class="hero-card">
        <div>
          <h2>Deleted User Accounts</h2>
          <p>Review user accounts that have been moved to the Trash Bin. You can restore an account or permanently remove it from the system.</p>
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
        <h3 class="section-title">Deleted Users</h3>
        <div class="table-wrapper">
          <table class="table">
            <thead>
              <tr>
                <th>Full Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Deleted Date</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="admin-deleted-users-table" data-live-refresh="trashbin.php?ajax=1&section=deleted-users" data-live-target="#admin-deleted-users-table">
              <?php if (empty($deletedUsers)): ?>
                <tr>
                  <td colspan="5" class="empty-state">No deleted users found.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($deletedUsers as $user): ?>
                  <tr>
                    <td class="cell-name" data-label="Name"><?= clean($user['full_name']) ?></td>
                    <td class="cell-email" data-label="Email"><?= clean($user['email']) ?></td>
                    <td data-label="Role"><?= clean(ucfirst($user['role'])) ?></td>
                    <td data-label="Deleted Date"><?= formatDate($user['deleted_at']) ?></td>
                    <td class="cell-actions" data-label="Action">
                      <div class="action-group">
                        <a href="trashbin.php?restore_id=<?= $user['id'] ?>" class="action-btn-small approve" onclick="return confirm('Are you sure you want to restore this user?')">Restore</a>
                        <a href="trashbin.php?permanent_delete_id=<?= $user['id'] ?>" class="action-btn-small reject" onclick="return confirm('Are you sure you want to permanently delete this user? This action cannot be undone.')">Delete Permanently</a>
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
    // LIVE REFRESH
    // ============================================================
    (function () {
      const liveTargets = document.querySelectorAll('[data-live-refresh]');
      liveTargets.forEach(function (node) {
        const refreshUrl = node.dataset.liveRefresh;
        const targetSelector = node.dataset.liveTarget || '#' + node.id;
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
              console.log('Trash bin live refresh failed:', error);
            });
        };

        refreshSection();
        setInterval(refreshSection, 8000);
      });
    })();
  </script>
</body>
</html>