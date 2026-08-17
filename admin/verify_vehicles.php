<?php require_once 'verify_vehicles_logic.php';

$ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
if ($ajax && ($_GET['section'] ?? '') === 'pending-vehicles') {
  if (empty($vehicles)) {
    echo '<p class="empty-state">No vehicles found.</p>';
  } else {
    foreach ($vehicles as $vehicle) {
      echo '<div class="vehicle-card">';
      echo '<div class="vehicle-image">';
      if (!empty($vehicle['image'])) {
        echo '<img src="../' . clean($vehicle['image']) . '" alt="' . clean($vehicle['name']) . '" loading="lazy" decoding="async" onerror="this.src=\'../uploads/vehicles/default-car.svg\'">';
      } else {
        echo '<div class="no-image">No Image</div>';
      }
      echo '</div>';
      echo '<div class="vehicle-details"><h3>' . clean($vehicle['name']) . '</h3><div class="table-wrapper"><table class="table info-table">';
      echo '<tr><th>Owner</th><td data-label="Owner">' . clean($vehicle['owner_name']) . '</td></tr>';
      echo '<tr><th>Model Year</th><td data-label="Model Year">' . clean($vehicle['model_year']) . '</td></tr>';
      echo '<tr><th>Category</th><td data-label="Category">' . clean(ucfirst(str_replace('_', ' ', $vehicle['category']))) . '</td></tr>';
      echo '<tr><th>Transmission</th><td data-label="Transmission">' . clean(ucfirst($vehicle['transmission'])) . '</td></tr>';
      echo '<tr><th>Price / Day</th><td data-label="Price / Day">$' . number_format($vehicle['price_per_day'], 2) . '</td></tr>';
      echo '<tr><th>Status</th><td data-label="Status"><span class="status-badge ' . statusBadgeClass($vehicle['approval_status']) . '">' . statusLabel($vehicle['approval_status']) . '</span></td></tr>';
      echo '<tr><th>Description</th><td class="cell-message" data-label="Description">' . clean($vehicle['description'] ?? 'No description') . '</td></tr>';
      echo '</table></div></div>';
      echo '<div class="vehicle-actions">';
      if ($vehicle['approval_status'] === 'pending') {
        echo '<form method="POST" action="verify_vehicles_logic.php"><input type="hidden" name="vehicle_id" value="' . $vehicle['id'] . '"><input type="hidden" name="action" value="approve"><button type="submit" class="action-btn-small approve">Approve</button></form>';
        echo '<button class="action-btn-small reject" onclick="showRejectModal(' . $vehicle['id'] . ')">Disapprove</button>';
      } elseif ($vehicle['approval_status'] === 'approved') {
        echo '<span class="text-success">Approved</span>';
      } else {
        echo '<span class="text-danger">Disapproved</span>';
        if (!empty($vehicle['approval_feedback'])) {
          echo '<br><small>Feedback: ' . clean($vehicle['approval_feedback']) . '</small>';
        }
      }
      echo '</div></div>';
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
  <title>Verify Vehicles | Carbnb Admin</title>
  <link rel="stylesheet" href="css/admin_style.css?v=20260702">
  <link rel="stylesheet" href="css/admin_style_backup.css?v=20260702">
  <link rel="stylesheet" href="css/admin_responsive.css?v=20260801">
  <style>
    .vehicle-image img {
      background: #f0f0f0;
      transition: opacity 0.3s ease;
      object-fit: cover;
      width: 100%;
      height: 100%;
    }
    .vehicle-image img.loaded {
      opacity: 1;
    }
    .vehicle-image img:not(.loaded) {
      opacity: 0;
    }
    .vehicle-image img.loading {
      background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
      background-size: 200% 100%;
      animation: shimmer 1.5s infinite;
    }
    @keyframes shimmer {
      0% { background-position: -200% 0; }
      100% { background-position: 200% 0; }
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
      <a href="dashboard.php">Dashboard</a>
      <a href="manage_users.php">Verify Users</a>
      <a href="verify_vehicles.php" class="active">Verify Vehicles</a>
      <a href="booking_records.php">Rental Records</a>
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
      <h1>Verify Vehicles</h1>
    </header>

    <main class="page">
      <section class="hero-card">
        <div>
          <h2>Vehicle Verification</h2>
          <p>Review vehicle listings submitted by owners. Approve qualified vehicles or disapprove listings that do not meet Carbnb requirements.</p>
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

      <section class="card" id="admin-pending-vehicles" data-live-refresh="verify_vehicles.php?ajax=1&section=pending-vehicles" data-live-target="#admin-pending-vehicles">
        <h3 class="section-title">Pending Vehicle Listings</h3>
        
        <?php if (empty($vehicles)): ?>
          <p class="empty-state">No vehicles found.</p>
        <?php else: ?>
          <?php foreach ($vehicles as $vehicle): ?>
            <div class="vehicle-card">
              <div class="vehicle-image">
                <?php if (!empty($vehicle['image'])): ?>
                  <img src="../<?= clean($vehicle['image']) ?>" 
                       alt="<?= clean($vehicle['name']) ?>"
                       loading="lazy"
                       decoding="async"
                       onerror="this.src='../uploads/vehicles/default-car.svg'">
                <?php else: ?>
                  <div class="no-image">No Image</div>
                <?php endif; ?>
              </div>

              <div class="vehicle-details">
                <h3><?= clean($vehicle['name']) ?></h3>
                <div class="table-wrapper">
                  <table class="table info-table">
                    <tr>
                      <th>Owner</th>
                      <td data-label="Owner"><?= clean($vehicle['owner_name']) ?></td>
                    </tr>
                    <tr>
                      <th>Model Year</th>
                      <td data-label="Model Year"><?= clean($vehicle['model_year']) ?></td>
                    </tr>
                    <tr>
                      <th>Category</th>
                      <td data-label="Category"><?= clean(ucfirst(str_replace('_', ' ', $vehicle['category']))) ?></td>
                    </tr>
                    <tr>
                      <th>Transmission</th>
                      <td data-label="Transmission"><?= clean(ucfirst($vehicle['transmission'])) ?></td>
                    </tr>
                    <tr>
                      <th>Price / Day</th>
                      <td data-label="Price / Day">$<?= number_format($vehicle['price_per_day'], 2) ?></td>
                    </tr>
                    <tr>
                      <th>Status</th>
                      <td data-label="Status">
                        <span class="status-badge <?= statusBadgeClass($vehicle['approval_status']) ?>">
                          <?= statusLabel($vehicle['approval_status']) ?>
                        </span>
                      </td>
                    </tr>
                    <tr>
                      <th>Description</th>
                      <td class="cell-message" data-label="Description"><?= clean($vehicle['description'] ?? 'No description') ?></td>
                    </tr>
                  </table>
                </div>
              </div>

              <div class="vehicle-actions">
                <?php if ($vehicle['approval_status'] === 'pending'): ?>
                  <form method="POST" action="verify_vehicles_logic.php">
                    <input type="hidden" name="vehicle_id" value="<?= $vehicle['id'] ?>">
                    <input type="hidden" name="action" value="approve">
                    <button type="submit" class="action-btn-small approve">Approve</button>
                  </form>
                  <button class="action-btn-small reject" onclick="showRejectModal(<?= $vehicle['id'] ?>)">Disapprove</button>
                <?php elseif ($vehicle['approval_status'] === 'approved'): ?>
                  <span class="text-success">Approved</span>
                <?php else: ?>
                  <span class="text-danger">Disapproved</span>
                  <?php if (!empty($vehicle['approval_feedback'])): ?>
                    <br><small>Feedback: <?= clean($vehicle['approval_feedback']) ?></small>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>
    </main>
  </div>

  <!-- Reject Modal -->
  <div id="rejectModal" class="modal" style="display:none;">
    <div class="modal-content">
      <h3>Disapprove Vehicle</h3>
      <form method="POST" action="verify_vehicles_logic.php">
        <input type="hidden" name="vehicle_id" id="rejectVehicleId">
        <input type="hidden" name="action" value="reject">
        <textarea name="feedback" placeholder="Enter reason for disapproval..." required></textarea>
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

      // Image loading
      document.querySelectorAll('.vehicle-image img').forEach(function(img) {
        img.classList.add('loading');
        if (img.complete) {
          img.classList.remove('loading');
          img.classList.add('loaded');
        } else {
          img.addEventListener('load', function() {
            this.classList.remove('loading');
            this.classList.add('loaded');
          });
          img.addEventListener('error', function() {
            this.classList.remove('loading');
            this.classList.add('loaded');
          });
        }
      });
    });

    function showRejectModal(vehicleId) {
      document.getElementById('rejectVehicleId').value = vehicleId;
      document.getElementById('rejectModal').style.display = 'flex';
    }

    function closeRejectModal() {
      document.getElementById('rejectModal').style.display = 'none';
    }

    // ============================================================
    // LIVE REFRESH
    // ============================================================
    (function () {
      const liveTarget = document.getElementById('admin-pending-vehicles');
      if (!liveTarget || !liveTarget.dataset.liveRefresh) return;

      const refreshUrl = liveTarget.dataset.liveRefresh;
      let refreshInterval;

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
            liveTarget.innerHTML = '<h3 class="section-title">Pending Vehicle Listings</h3>' + html;
            document.querySelectorAll('.vehicle-image img').forEach(function(img) {
              img.classList.add('loading');
              if (img.complete) {
                img.classList.remove('loading');
                img.classList.add('loaded');
              } else {
                img.addEventListener('load', function() {
                  this.classList.remove('loading');
                  this.classList.add('loaded');
                });
                img.addEventListener('error', function() {
                  this.classList.remove('loading');
                  this.classList.add('loaded');
                });
              }
            });
          })
          .catch(function (error) {
            console.log('Vehicle verification live refresh failed:', error);
          });
      }

      refreshSection();
      refreshInterval = setInterval(refreshSection, 8000);

      window.addEventListener('beforeunload', function() {
        clearInterval(refreshInterval);
      });
    })();
  </script>
</body>
</html>