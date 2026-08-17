<?php require_once 'account_control_logic.php'; 

$ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
if ($ajax && ($_GET['section'] ?? '') === 'locked-accounts') {
  if (empty($lockedUsers)) {
    echo '<tr><td colspan="6" class="empty-state">No locked accounts found.</td></tr>';
  } else {
    foreach ($lockedUsers as $user) {
      echo '<tr>';
      echo '<td class="cell-name" data-label="Name">' . clean($user['full_name']) . '</td>';
      echo '<td class="cell-email" data-label="Email">' . clean($user['email']) . '</td>';
      echo '<td data-label="Role">' . clean(ucfirst($user['role'])) . '</td>';
      echo '<td data-label="Login Attempts">' . (int) $user['login_attempts'] . '</td>';
      echo '<td data-label="Locked Until">' . formatDate($user['locked_until']) . '</td>';
      echo '<td class="cell-actions" data-label="Action"><div class="action-group"><a href="account_control.php?unlock_id=' . (int) $user['id'] . '" class="action-btn-small approve" onclick="return confirm(\'Are you sure you want to unlock this account?\')">Unlock</a></div></td>';
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
  <title>Account Control | Carbnb Admin</title>
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
      <a href="account_control.php" class="active">Account Control</a>
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
      <h1>Account Control</h1>
    </header>

    <main class="page">
      <section class="hero-card">
        <div>
          <h2>Account Security</h2>
          <p>Monitor locked user accounts and restore access when necessary to maintain platform security.</p>
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
        <h3 class="section-title">Locked User Accounts</h3>
        <div class="table-wrapper">
          <table class="table">
            <thead>
              <tr>
                <th>Full Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Login Attempts</th>
                <th>Locked Until</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="admin-locked-accounts" data-live-refresh="account_control.php?ajax=1&section=locked-accounts" data-live-target="#admin-locked-accounts">
              <?php if (empty($lockedUsers)): ?>
                <tr>
                  <td colspan="6" class="empty-state">No locked accounts found.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($lockedUsers as $user): ?>
                  <tr>
                    <td class="cell-name" data-label="Name"><?= clean($user['full_name']) ?></td>
                    <td class="cell-email" data-label="Email"><?= clean($user['email']) ?></td>
                    <td data-label="Role"><?= clean(ucfirst($user['role'])) ?></td>
                    <td data-label="Login Attempts"><?= $user['login_attempts'] ?></td>
                    <td data-label="Locked Until"><?= formatDate($user['locked_until']) ?></td>
                    <td class="cell-actions" data-label="Action">
                      <div class="action-group">
                        <a href="account_control.php?unlock_id=<?= $user['id'] ?>" class="action-btn-small approve" onclick="return confirm('Are you sure you want to unlock this account?')">Unlock</a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="card">
        <h3 class="section-title">Security Information</h3>
        <p class="empty-state">
          Carbnb automatically limits failed login attempts and temporarily locks user accounts to protect the platform from unauthorized access.
        </p>
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
              console.log('Account control live refresh failed:', error);
            });
        };

        refreshSection();
        setInterval(refreshSection, 8000);
      });
    })();
  </script>
</body>
</html>