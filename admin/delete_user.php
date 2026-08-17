<?php require_once 'delete_user_logic.php'; 

$ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
if ($ajax && ($_GET['section'] ?? '') === 'registered-users') {
  if (empty($users)) {
    echo '<tr><td colspan="6" class="empty-state">No users found.</td></tr>';
  } else {
    foreach ($users as $user) {
      echo '<tr>';
      echo '<td class="cell-name" data-label="Name">' . clean($user['full_name']) . '</td>';
      echo '<td class="cell-email" data-label="Email">' . clean($user['email']) . '</td>';
      echo '<td data-label="Role">' . clean(ucfirst($user['role'])) . '</td>';
      echo '<td data-label="Status"><span class="status-badge ' . statusBadgeClass($user['status']) . '">' . statusLabel($user['status']) . '</span></td>';
      echo '<td data-label="Registered">' . formatDate($user['created_at']) . '</td>';
      echo '<td class="cell-actions" data-label="Action"><div class="action-group"><a href="delete_user.php?delete_id=' . (int) $user['id'] . '" class="action-btn-small reject" onclick="return confirm(\'Are you sure you want to delete this user? They will be moved to the trash bin.\')">Delete</a></div></td>';
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
  <title>Delete Users | Carbnb Admin</title>
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
      <a href="delete_user.php" class="active">Delete Users</a>
      <a href="trashbin.php">Trash Bin</a>
      <a href="location_tracker.php">Renter Tracker</a>
      <a href="../auth/logout.php" class="topbar-action" style="display:block; margin-top:1rem; text-align:center;">Logout</a>
    </nav>
  </aside>

  <div class="main-content">
<header class="topbar">
      <button class="sidebar-toggle" type="button" aria-label="Open sidebar"></button>
      <h1>Delete Users</h1>
    </header>

    <main class="page">
      <section class="hero-card">
        <div>
          <h2>Delete User Accounts</h2>
          <p>Move renter and owner accounts to the Trash Bin using a secure soft delete process. Deleted accounts can be restored later if needed.</p>
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
        <h3 class="section-title">Registered Users</h3>
        <div class="table-wrapper">
          <table class="table">
            <thead>
              <tr>
                <th>Full Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Account Status</th>
                <th>Registered</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="admin-delete-users-table" data-live-refresh="delete_user.php?ajax=1&section=registered-users" data-live-target="#admin-delete-users-table">
              <?php if (empty($users)): ?>
                <tr>
                  <td colspan="6" class="empty-state">No users found.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($users as $user): ?>
                  <tr>
                    <td class="cell-name" data-label="Name"><?= clean($user['full_name']) ?></td>
                    <td class="cell-email" data-label="Email"><?= clean($user['email']) ?></td>
                    <td data-label="Role"><?= clean(ucfirst($user['role'])) ?></td>
                    <td data-label="Status">
                      <span class="status-badge <?= statusBadgeClass($user['status']) ?>">
                        <?= statusLabel($user['status']) ?>
                      </span>
                    </td>
                    <td data-label="Registered"><?= formatDate($user['created_at']) ?></td>
                    <td class="cell-actions" data-label="Action">
                      <div class="action-group">
                        <a href="delete_user.php?delete_id=<?= $user['id'] ?>" class="action-btn-small reject" onclick="return confirm('Are you sure you want to delete this user? They will be moved to the trash bin.')">Delete</a>
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
              console.log('Delete user live refresh failed:', error);
            });
        };

        refreshSection();
        setInterval(refreshSection, 8000);
      });
    })();
  </script>
</body>
</html>