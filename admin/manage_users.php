<?php require_once 'manage_users_logic.php';

$ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
if ($ajax && ($_GET['section'] ?? '') === 'registered-users') {
  if (empty($users)) {
    echo '<tr><td colspan="7" class="empty-state">No users found.</td></tr>';
  } else {
    foreach ($users as $user) {
      echo '<tr>';
      echo '<td class="cell-name" data-label="Name">' . clean($user['full_name']) . '</td>';
      echo '<td class="cell-email" data-label="Email">' . clean($user['email']) . '</td>';
      echo '<td data-label="Role">' . clean(ucfirst($user['role'])) . '</td>';
      echo '<td data-label="Status"><span class="status-badge ' . statusBadgeClass($user['status']) . '">' . statusLabel($user['status']) . '</span></td>';
      echo '<td data-label="Registered">' . formatDate($user['created_at']) . '</td>';
      echo '<td class="cell-actions" data-label="Documents"><div class="action-group"><button class="view-docs-btn" type="button" onclick="openDocModal(' . $user['id'] . ', \'' . clean($user['full_name']) . '\', \'' . clean($user['role']) . '\')">View Documents</button></div></td>';
      echo '<td class="cell-actions" data-label="Action"><div class="action-group">';
      if ($user['status'] === 'pending') {
        echo '<a href="manage_users.php?action=approve&id=' . $user['id'] . '" class="action-btn-small approve">Approve</a>';
        echo '<a href="manage_users.php?action=reject&id=' . $user['id'] . '" class="action-btn-small reject" onclick="return disapproveUser(' . $user['id'] . ')">Disapprove</a>';
      } elseif ($user['status'] === 'approved') {
        echo '<span class="text-success">Approved</span>';
      } else {
        echo '<span class="text-danger">Disapproved</span>';
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
  <title>Verify Users | Carbnb Admin</title>
  <link rel="stylesheet" href="css/admin_style.css?v=20260702">
  <link rel="stylesheet" href="css/admin_style_backup.css?v=20260702">
  <link rel="stylesheet" href="css/admin_responsive.css?v=20260801">
  <style>
    .view-docs-btn {
      background: var(--accent-2);
      color: #fff;
      padding: 6px 12px;
      border-radius: 6px;
      font-size: 12px;
      cursor: pointer;
      border: none;
    }
    .view-docs-btn:hover {
      background: #3da8d1;
    }
    .doc-modal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0,0,0,0.85);
      z-index: 3000;
      overflow-y: auto;
      padding: 20px;
    }
    .doc-modal.active {
      display: block;
    }
    .doc-modal-content {
      background: var(--panel);
      max-width: 900px;
      margin: 40px auto;
      border-radius: 14px;
      padding: 25px;
      border: 1px solid var(--border);
    }
    .doc-modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      padding-bottom: 15px;
      border-bottom: 1px solid var(--border);
    }
    .doc-modal-header h3 {
      margin: 0;
      color: var(--accent);
    }
    .doc-modal-close {
      background: transparent;
      border: none;
      color: var(--text);
      font-size: 24px;
      cursor: pointer;
    }
    .doc-section {
      margin-bottom: 25px;
    }
    .doc-section h4 {
      color: var(--accent-2);
      margin-bottom: 12px;
      font-size: 16px;
    }
    .doc-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
      gap: 15px;
    }
    .doc-item {
      background: var(--panel-2);
      border-radius: 10px;
      padding: 12px;
      text-align: center;
      border: 1px solid var(--border);
    }
    .doc-item img, .doc-item video {
      max-width: 100%;
      max-height: 150px;
      object-fit: contain;
      border-radius: 6px;
      margin-bottom: 8px;
      background: #1a1a1a;
    }
    .doc-item a {
      color: var(--accent-2);
      font-size: 13px;
      word-break: break-all;
    }
    .face-preview {
      text-align: center;
    }
    .face-preview img {
      max-width: 200px;
      max-height: 200px;
      object-fit: cover;
      border-radius: 50%;
      border: 3px solid var(--accent);
      margin: 0 auto;
      background: #1a1a1a;
    }
    .face-status {
      margin-top: 10px;
      font-size: 14px;
    }
    .face-status.verified {
      color: var(--success);
    }
    .face-status.not-verified {
      color: var(--danger);
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
      <a href="manage_users.php" class="active">Verify Users</a>
      <a href="verify_vehicles.php">Verify Vehicles</a>
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
      <h1>Verify Users</h1>
    </header>

    <main class="page">
      <section class="hero-card">
        <div>
          <h2>User Verification</h2>
          <p>Review submitted renter and owner accounts before granting access to the Carbnb platform.</p>
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
                <th>Status</th>
                <th>Registered</th>
                <th>Documents</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="admin-users-table" data-live-refresh="manage_users.php?ajax=1&section=registered-users" data-live-target="#admin-users-table">
              <?php if (empty($users)): ?>
                <tr>
                  <td colspan="7" class="empty-state">No users found.</td>
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
                    <td class="cell-actions" data-label="Documents">
                      <div class="action-group">
                        <button class="view-docs-btn" type="button" 
                                onclick="openDocModal(<?= $user['id'] ?>, '<?= clean($user['full_name']) ?>', '<?= clean($user['role']) ?>')">
                          View Documents
                        </button>
                      </div>
                    </td>
                    <td class="cell-actions" data-label="Action">
                      <div class="action-group">
                        <?php if ($user['status'] === 'pending'): ?>
                          <a href="manage_users.php?action=approve&id=<?= $user['id'] ?>" class="action-btn-small approve">Approve</a>
                          <a href="manage_users.php?action=reject&id=<?= $user['id'] ?>" class="action-btn-small reject" onclick="return disapproveUser(<?= $user['id'] ?>)">Disapprove</a>
                        <?php elseif ($user['status'] === 'approved'): ?>
                          <span class="text-success">Approved</span>
                        <?php else: ?>
                          <span class="text-danger">Disapproved</span>
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

  <!-- Document Modal -->
  <div id="docModal" class="doc-modal">
    <div class="doc-modal-content">
      <div class="doc-modal-header">
        <h3 id="modalUserName">User Documents</h3>
        <button class="doc-modal-close" type="button" onclick="closeDocModal()">×</button>
      </div>
      <div id="modalContent">
        <!-- Content will be loaded dynamically -->
      </div>
    </div>
  </div>

<script>
    let usersData = [];

    function openDocModal(userId, userName, userRole) {
      const user = usersData.find(u => u.id == userId);
      
      if (!user) {
        fetch('manage_users.php?get_user_data=' + userId)
          .then(response => response.json())
          .then(data => {
            usersData = [data];
            renderDocModal(data, userName, userRole);
          })
          .catch(error => {
            console.error('Error fetching user data:', error);
          });
        return;
      }
      
      renderDocModal(user, userName, userRole);
    }

    function renderDocModal(user, userName, userRole) {
      document.getElementById('modalUserName').textContent = userName + "'s Documents";
      
      let content = '';
      
      if (user.documents && user.documents.length > 0) {
        content += '<div class="doc-section"><h4>Uploaded Documents</h4><div class="doc-grid">';
        
        user.documents.forEach(doc => {
          const docLabel = getDocumentLabel(doc.document_type);
          const isVideo = doc.file_path.includes('.mp4') || doc.file_path.includes('.webm') || doc.file_path.includes('.ogg');
          
          content += '<div class="doc-item">';
          content += '<div>' + docLabel + '</div>';
          
          if (isVideo) {
            content += '<video controls preload="metadata"><source src="../' + doc.file_path + '" type="video/webm">Your browser does not support the video tag.</video>';
          } else {
            content += '<img src="../' + doc.file_path + '" alt="' + docLabel + '" loading="lazy" decoding="async" onerror="this.src=\'../assets/placeholder.png\'">';
          }
          
          content += '<a href="../' + doc.file_path + '" target="_blank">View Full</a>';
          content += '</div>';
        });
        
        content += '</div></div>';
      } else {
        content += '<div class="doc-section"><h4>Uploaded Documents</h4><p style="color: var(--muted);">No documents uploaded.</p></div>';
      }

      if (userRole === 'renter') {
        content += '<div class="doc-section"><h4>Face Verification</h4>';
        
        if (user.face_image_path) {
          content += '<div class="face-preview">';
          content += '<img src="../' + user.face_image_path + '" alt="Face Image" loading="lazy" decoding="async" onerror="this.style.display=\'none\'">';
          content += '<div class="face-status ' + (user.face_verified == 1 ? 'verified' : 'not-verified') + '">';
          content += user.face_verified == 1 ? '✓ Face Verified' : '✗ Face Not Verified';
          content += '</div></div>';
        } else {
          content += '<p style="color: var(--danger);">No face image registered.</p>';
        }
        
        content += '</div>';
      }

      document.getElementById('modalContent').innerHTML = content;
      document.getElementById('docModal').classList.add('active');
    }

    function closeDocModal() {
      document.getElementById('docModal').classList.remove('active');
    }

    function getDocumentLabel(docType) {
      const labels = {
        'id1': 'Valid ID #1',
        'id2': 'Valid ID #2',
        'proof_of_billing': 'Proof of Billing',
        'drivers_license': "Driver's License",
        'nbi_clearance': 'NBI Clearance',
        'intro_video': 'Introduction Video'
      };
      return labels[docType] || docType;
    }

    function disapproveUser(userId) {
      var reason = prompt("Please enter the reason for disapproval (or leave empty for default):", "");
      if (reason === null) {
        return false;
      }
      var url = "manage_users.php?action=reject&id=" + userId + "&reason=" + encodeURIComponent(reason);
      window.location.href = url;
      return false;
    }

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

      // Close modal on overlay click
      document.getElementById('docModal').addEventListener('click', function(e) {
        if (e.target === this) {
          closeDocModal();
        }
      });
    });

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
              console.log('Manage users live refresh failed:', error);
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