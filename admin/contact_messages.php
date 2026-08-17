<?php
require_once 'admin_auth.php';
global $pdo;

// Handle sending message to user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'], $_POST['user_id'], $_POST['message_text'])) {
    $userId = (int)$_POST['user_id'];
    $messageText = trim($_POST['message_text']);
    $adminId = (int)$_SESSION['user_id'];
    
    if (!empty($messageText) && $userId > 0) {
        $stmt = $pdo->prepare("
            INSERT INTO messages (sender_id, receiver_id, message)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$adminId, $userId, $messageText]);
        redirectSuccess('contact_messages.php', 'Message sent to user successfully!');
    }
}

// Handle marking message as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'], $_POST['message_id'])) {
    $messageId = (int)$_POST['message_id'];
    
    $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE id = ?");
    $stmt->execute([$messageId]);
    redirectSuccess('contact_messages.php', 'Message marked as read!');
}

// Get all contact messages
$contactMessages = [];
try {
    $stmt = $pdo->query("
        SELECT * FROM contact_messages 
        ORDER BY created_at DESC
    ");
    $contactMessages = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = $e->getMessage();
}

// Get all users (owners and renters) for messaging
$users = [];
try {
    $stmt = $pdo->query("
        SELECT id, full_name, email, role 
        FROM users 
        WHERE role IN ('owner', 'renter') AND is_deleted = 0
        ORDER BY full_name
    ");
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = $e->getMessage();
}

// Get all user-to-user messages
$allMessages = [];
try {
    $stmt = $pdo->query("
        SELECT 
            m.id,
            m.message,
            m.is_read,
            m.created_at,
            s.full_name AS sender_name,
            s.role AS sender_role,
            r.full_name AS receiver_name,
            r.role AS receiver_role
        FROM messages m
        JOIN users s ON m.sender_id = s.id
        JOIN users r ON m.receiver_id = r.id
        ORDER BY m.created_at DESC
        LIMIT 100
    ");
    $allMessages = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = $e->getMessage();
}

$ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
if ($ajax && ($_GET['section'] ?? '') === 'all-user-messages') {
    if (empty($allMessages)) {
        echo '<tr><td colspan="6" class="empty-state">No messages found.</td></tr>';
    } else {
        foreach ($allMessages as $msg) {
            echo '<tr>';
            echo '<td data-label="From">' . clean($msg['sender_name']) . ' <small>(' . ucfirst($msg['sender_role']) . ')</small></td>';
            echo '<td data-label="To">' . clean($msg['receiver_name']) . ' <small>(' . ucfirst($msg['receiver_role']) . ')</small></td>';
            echo '<td class="cell-message" data-label="Message">' . clean(substr($msg['message'], 0, 80)) . (strlen($msg['message']) > 80 ? '...' : '') . '</td>';
            echo '<td data-label="Status"><span class="status-badge ' . ($msg['is_read'] ? 'available' : 'pending') . '">' . ($msg['is_read'] ? 'Read' : 'Unread') . '</span></td>';
            echo '<td data-label="Date">' . formatDate($msg['created_at']) . '</td>';
            echo '<td class="cell-actions" data-label="Action"><div class="action-group">';
            if (!$msg['is_read']) {
                echo '<form method="POST"><input type="hidden" name="mark_read" value="1"><input type="hidden" name="message_id" value="' . $msg['id'] . '"><button type="submit" class="action-btn-small approve" onclick="return confirm(\'Mark this message as read?\')">Read</button></form>';
            }
            echo '</div></td></tr>';
        }
    }
    exit;
}

if ($ajax && ($_GET['section'] ?? '') === 'contact-form-messages') {
    if (empty($contactMessages)) {
        echo '<tr><td colspan="5" class="empty-state">No contact messages found.</td></tr>';
    } else {
        foreach ($contactMessages as $msg) {
            echo '<tr>';
            echo '<td data-label="Name">' . clean($msg['name']) . '</td>';
            echo '<td class="cell-email" data-label="Email">' . clean($msg['email']) . '</td>';
            echo '<td class="cell-message" data-label="Message">' . clean(substr($msg['message'], 0, 100)) . (strlen($msg['message']) > 100 ? '...' : '') . '</td>';
            echo '<td data-label="Status"><span class="status-badge ' . ($msg['is_replied'] ? 'available' : 'pending') . '">' . ($msg['is_replied'] ? 'Replied' : 'Pending') . '</span></td>';
            echo '<td data-label="Date">' . formatDate($msg['created_at']) . '</td>';
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
  <title>Contact Messages | Carbnb Admin</title>
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
      <a class="active" href="contact_messages.php">Contact Messages</a>
      <a href="delete_user.php">Delete Users</a>
      <a href="trashbin.php">Trash Bin</a>
      <a href="location_tracker.php">Renter Tracker</a>
      <a href="../auth/logout.php" class="topbar-action" style="display:block; margin-top:1rem; text-align:center;">Logout</a>
    </nav>
  </aside>

  <div class="main-content">
<header class="topbar">
      <button class="sidebar-toggle" type="button" aria-label="Open sidebar"></button>
      <h1>Contact Messages</h1>
    </header>

    <main class="page">
      <?php if (!empty($success)): ?>
        <div class="alert success"><?= clean($success) ?></div>
      <?php endif; ?>

      <?php if (!empty($error)): ?>
        <div class="alert error"><?= clean($error) ?></div>
      <?php endif; ?>

      <!-- Send Message to User Section -->
      <section class="card" style="margin-bottom:20px;">
        <h3 class="section-title">Send Message to User</h3>
        <form method="POST" id="sendMessageForm">
          <input type="hidden" name="send_message" value="1">
          <div class="form-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
            <div class="form-group">
              <label>Select User</label>
              <select name="user_id" class="form-control" required>
                <option value="">-- Select User --</option>
                <?php foreach ($users as $user): ?>
                  <option value="<?= $user['id'] ?>">
                    <?= clean($user['full_name']) ?> (<?= ucfirst($user['role']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Message</label>
              <textarea name="message_text" rows="3" class="form-control" placeholder="Type your message..." required></textarea>
            </div>
          </div>
          <button type="submit" class="action-btn-small approve" style="margin-top:10px;">Send Message</button>
        </form>
      </section>

      <!-- All Messages Section -->
      <section class="card" style="margin-bottom:20px;">
        <h3 class="section-title">All User Messages</h3>
        <?php if (empty($allMessages)): ?>
          <p class="empty-state">No messages found.</p>
        <?php else: ?>
          <div class="table-wrapper">
            <table class="table">
              <thead>
                <tr>
                  <th>From</th>
                  <th>To</th>
                  <th>Message</th>
                  <th>Status</th>
                  <th>Date</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody id="admin-all-user-messages" data-live-refresh="contact_messages.php?ajax=1&section=all-user-messages" data-live-target="#admin-all-user-messages">
                <?php foreach ($allMessages as $msg): ?>
                  <tr>
                    <td data-label="From"><?= clean($msg['sender_name']) ?> <small>(<?= ucfirst($msg['sender_role']) ?>)</small></td>
                    <td data-label="To"><?= clean($msg['receiver_name']) ?> <small>(<?= ucfirst($msg['receiver_role']) ?>)</small></td>
                    <td class="cell-message" data-label="Message"><?= clean(substr($msg['message'], 0, 80)) ?><?= strlen($msg['message']) > 80 ? '...' : '' ?></td>
                    <td data-label="Status">
                      <span class="status-badge <?= $msg['is_read'] ? 'available' : 'pending' ?>">
                        <?= $msg['is_read'] ? 'Read' : 'Unread' ?>
                      </span>
                    </td>
                    <td data-label="Date"><?= formatDate($msg['created_at']) ?></td>
                    <td class="cell-actions" data-label="Action">
                      <div class="action-group">
                        <?php if (!$msg['is_read']): ?>
                          <form method="POST">
                            <input type="hidden" name="mark_read" value="1">
                            <input type="hidden" name="message_id" value="<?= $msg['id'] ?>">
                            <button type="submit" class="action-btn-small approve" onclick="return confirm('Mark this message as read?')">Read</button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <!-- Contact Messages Section -->
      <section class="card">
        <h3 class="section-title">Contact Form Messages</h3>
        
        <?php if (empty($contactMessages)): ?>
          <p class="empty-state">No contact messages found.</p>
        <?php else: ?>
          <div class="table-wrapper">
            <table class="table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Message</th>
                  <th>Status</th>
                  <th>Date</th>
                </tr>
              </thead>
              <tbody id="admin-contact-form-messages" data-live-refresh="contact_messages.php?ajax=1&section=contact-form-messages" data-live-target="#admin-contact-form-messages">
                <?php foreach ($contactMessages as $msg): ?>
                  <tr>
                    <td data-label="Name"><?= clean($msg['name']) ?></td>
                    <td class="cell-email" data-label="Email"><?= clean($msg['email']) ?></td>
                    <td class="cell-message" data-label="Message"><?= clean(substr($msg['message'], 0, 100)) ?><?= strlen($msg['message']) > 100 ? '...' : '' ?></td>
                    <td data-label="Status">
                      <span class="status-badge <?= $msg['is_replied'] ? 'available' : 'pending' ?>">
                        <?= $msg['is_replied'] ? 'Replied' : 'Pending' ?>
                      </span>
                    </td>
                    <td data-label="Date"><?= formatDate($msg['created_at']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
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
              console.log('Messages live refresh failed:', error);
            });
        };

        refreshSection();
        setInterval(refreshSection, 8000);
      });
    })();
  </script>
</body>
</html>