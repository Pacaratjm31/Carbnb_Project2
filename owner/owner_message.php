<?php
require_once __DIR__ . '/owner_logic.php';
include __DIR__ . '/../helpers/duplicate_functions.php';
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

// Handle reply submission
$success = '';
$error = '';

// Get success message from redirect
if (isset($_GET['success'])) {
    $success = trim($_GET['success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply'], $_POST['message_id'])) {
    // Validate form token to prevent duplicate submissions
    $tokenError = validate_form_token_or_error('reply_message');
    if ($tokenError) {
        $error = $tokenError;
    } else {
        $messageId = (int)$_POST['message_id'];
        $reply = trim($_POST['reply']);
        
        if (!empty($reply)) {
            // Get the original message to find the sender (renter)
            $stmt = $pdo->prepare("SELECT sender_id, receiver_id FROM messages WHERE id = ? AND receiver_id = ?");
            $stmt->execute([$messageId, $owner['id']]);
            $originalMsg = $stmt->fetch();
            
            if ($originalMsg) {
                // Insert reply as a new message from owner to renter
                $stmt = $pdo->prepare("
                    INSERT INTO messages (sender_id, receiver_id, message) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$owner['id'], $originalMsg['sender_id'], $reply]);
                
                // Mark original message as read
                $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE id = ?");
                $stmt->execute([$messageId]);
                
                header('Location: owner_message.php?success=' . urlencode('Reply sent successfully!'));
                exit;
            }
        }
    }
}

// Handle sending new message to renter
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_new_message'], $_POST['renter_id'], $_POST['new_message'])) {
    // Validate form token to prevent duplicate submissions
    $tokenError = validate_form_token_or_error('send_new_message');
    if ($tokenError) {
        $error = $tokenError;
    } else {
        $renterId = (int)$_POST['renter_id'];
        $newMessage = trim($_POST['new_message']);
        
        if (!empty($newMessage) && $renterId > 0) {
            $stmt = $pdo->prepare("
                INSERT INTO messages (sender_id, receiver_id, message) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$owner['id'], $renterId, $newMessage]);
            header('Location: owner_message.php?success=' . urlencode('Message sent successfully!'));
            exit;
        }
    }
}

// Handle marking message as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'], $_POST['message_id'])) {
    $messageId = (int)$_POST['message_id'];
    
    $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE id = ? AND receiver_id = ?");
    $stmt->execute([$messageId, $owner['id']]);
    header('Location: owner_message.php?success=' . urlencode('Message marked as read!'));
    exit;
}

$messages = get_owner_messages($pdo, $owner['id']);

// Get all approved renters for the dropdown (not just those who have messaged)
$renters = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, full_name, email
        FROM users
        WHERE role = 'renter' AND status = 'approved' AND is_deleted = 0
        ORDER BY full_name
    ");
    $stmt->execute();
    $renters = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = $e->getMessage();
}

// Get all admins for the dropdown
$admins = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, full_name, email
        FROM users
        WHERE role = 'admin' AND is_deleted = 0
        ORDER BY full_name
    ");
    $stmt->execute();
    $admins = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = $e->getMessage();
}

// Get inspections for this owner's vehicles
$inspections = [];
try {
    $stmt = $pdo->prepare("
        SELECT i.*, v.name AS vehicle_name, u.full_name AS renter_name
        FROM inspect i
        JOIN vehicles v ON v.id = i.vehicle_id
        JOIN users u ON u.id = i.renter_id
        WHERE i.owner_id = ?
        ORDER BY i.created_at DESC
    ");
    $stmt->execute([$owner['id']]);
    $inspections = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Owner Messages</title>
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
      <a href="manage_vehicles.php">Manage Vehicles</a>
      <a href="booking_requests.php">Booking Requests</a>
      <a href="vehicle_status.php">Vehicle Status</a>
      <a href="owner_income.php">Income</a>
      <a href="rental_history.php">Rental History</a>
      <a href="owner_profile.php">Profile</a>
      <a class="active" href="owner_message.php">Messages</a>
      <a href="owner_reviews.php">Reviews</a>
      <a href="../auth/logout.php" class="topbar-action" style="display:block; margin-top:1rem; text-align:center;">Logout</a>
    </nav>
  </aside>

  <div class="main-content">
<header class="topbar">
      <button class="sidebar-toggle" type="button" aria-label="Open sidebar"></button>
      <h1>Messages</h1>
      <a class="topbar-action" href="owner_dashboard.php">Home</a>
    </header>

    <main class="page">
      <?php if ($success): ?>
        <div class="alert success"><?= clean($success) ?></div>
      <?php endif; ?>
      
      <?php if ($error): ?>
        <div class="alert error"><?= clean($error) ?></div>
      <?php endif; ?>

<!-- Car Inspections Section -->
      <section class="card" style="margin-bottom:20px;">
        <h3 class="section-title">Car Inspections</h3>
        
        <?php if (empty($inspections)): ?>
          <p class="empty-state">No inspection images submitted yet.</p>
        <?php else: ?>
          <div class="table-wrapper">
            <table class="table">
              <thead>
                <tr>
                  <th>Renter</th>
                  <th>Vehicle</th>
                  <th>Front Car</th>
                  <th>Back Car</th>
                  <th>Left Side</th>
                  <th>Right Side</th>
                  <th>Reason for Inspection</th>
                  <th>Date</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($inspections as $inspection): ?>
                  <tr>
                    <td data-label="Renter"><?= clean($inspection['renter_name']) ?></td>
                    <td data-label="Vehicle"><?= clean($inspection['vehicle_name']) ?></td>
                    <td data-label="Front Car">
                      <?php if (!empty($inspection['front_image'])): ?>
                        <a href="../<?= $inspection['front_image'] ?>" target="_blank">
                          <img src="../<?= $inspection['front_image'] ?>" style="width:50px; height:50px; object-fit:cover; border-radius:4px;">
                        </a>
                      <?php else: ?> — <?php endif; ?>
                    </td>
                    <td data-label="Back Car">
                      <?php if (!empty($inspection['back_image'])): ?>
                        <a href="../<?= $inspection['back_image'] ?>" target="_blank">
                          <img src="../<?= $inspection['back_image'] ?>" style="width:50px; height:50px; object-fit:cover; border-radius:4px;">
                        </a>
                      <?php else: ?> — <?php endif; ?>
                    </td>
                    <td data-label="Left Side">
                      <?php if (!empty($inspection['left_image'])): ?>
                        <a href="../<?= $inspection['left_image'] ?>" target="_blank">
                          <img src="../<?= $inspection['left_image'] ?>" style="width:50px; height:50px; object-fit:cover; border-radius:4px;">
                        </a>
                      <?php else: ?> — <?php endif; ?>
                    </td>
                    <td data-label="Right Side">
                      <?php if (!empty($inspection['right_image'])): ?>
                        <a href="../<?= $inspection['right_image'] ?>" target="_blank">
                          <img src="../<?= $inspection['right_image'] ?>" style="width:50px; height:50px; object-fit:cover; border-radius:4px;">
                        </a>
                      <?php else: ?> — <?php endif; ?>
                    </td>
                    <td data-label="Reason for Inspection"><?= clean($inspection['reason'] ?: 'No reason provided') ?></td>
                    <td data-label="Date"><?= format_date($inspection['created_at']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

<!-- Send New Message Section -->
      <section class="card" style="margin-bottom:20px;">
        <h3 class="section-title">Send New Message</h3>
        <form method="POST" id="newMessageForm">
          <input type="hidden" name="send_new_message" value="1">
          <?= form_token_input('send_new_message') ?>
          <div class="form-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
<div class="form-group">
              <label>Select Recipient</label>
              <select name="renter_id" class="form-control" required>
                <option value="">-- Select Recipient --</option>
                <optgroup label="Renters">
                  <?php foreach ($renters as $renter): ?>
                    <option value="<?= $renter['id'] ?>"><?= clean($renter['full_name']) ?> (Renter)</option>
                  <?php endforeach; ?>
                </optgroup>
                <optgroup label="Admins">
                  <?php foreach ($admins as $admin): ?>
                    <option value="<?= $admin['id'] ?>"><?= clean($admin['full_name']) ?> (Admin)</option>
                  <?php endforeach; ?>
                </optgroup>
              </select>
            </div>
            <div class="form-group">
              <label>Message</label>
              <textarea name="new_message" rows="3" class="form-control" placeholder="Type your message..." required></textarea>
            </div>
          </div>
          <button type="submit" class="action-btn-small approve" style="margin-top:10px;">Send Message</button>
        </form>
      </section>

      <!-- Inbox Section -->
      <section class="card">
        <h3 class="section-title">Inbox</h3>
        
        <?php if (empty($messages)) : ?>
          <p class="empty-state">No messages yet.</p>
        <?php else : ?>
          <div class="table-wrapper only-desktop">
            <table class="table">
              <thead>
                <tr>
                  <th>From</th>
                  <th>Message</th>
                  <th>Status</th>
                  <th>Date</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($messages as $msg): ?>
                  <tr>
                    <td data-label="From">
                      <?php if ($msg['sender_id'] == $owner['id']): ?>
                        To: <?= clean($msg['receiver_name']) ?>
                      <?php else: ?>
                        From: <?= clean($msg['sender_name']) ?>
                      <?php endif; ?>
                    </td>
                    <td data-label="Message"><?= clean(substr($msg['message'], 0, 100)) ?><?= strlen($msg['message']) > 100 ? '...' : '' ?></td>
                    <td data-label="Status">
                      <span class="status-badge <?= $msg['is_read'] ? 'available' : 'pending' ?>">
                        <?= $msg['is_read'] ? 'Read' : 'New' ?>
                      </span>
                    </td>
                    <td data-label="Date"><?= format_date($msg['created_at']) ?></td>
                    <td data-label="Action" class="cell-actions">
                      <div class="action-group">
                        <?php if ($msg['sender_id'] != $owner['id'] && !$msg['is_read']): ?>
                          <form method="POST">
                            <input type="hidden" name="mark_read" value="1">
                            <input type="hidden" name="message_id" value="<?= $msg['id'] ?>">
                            <button type="submit" class="action-btn-small approve" onclick="return confirm('Mark this message as read?')">Read</button>
                          </form>
                        <?php endif; ?>
                        <?php if ($msg['sender_id'] != $owner['id']): ?>
                          <button type="button" class="action-btn reply-btn" data-id="<?= $msg['id'] ?>" data-message="<?= htmlspecialchars(json_encode($msg['message']), ENT_QUOTES, 'UTF-8') ?>">Reply</button>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- ============================================
               MOBILE INBOX CARDS
               Same $messages data as the table above, card-
               per-message for phones (same .only-desktop/
               .only-mobile pattern used elsewhere). Reply
               buttons here carry the same .reply-btn class +
               data-id/data-message attributes as the desktop
               version, so the existing querySelectorAll
               binding at the bottom of this page wires them
               up automatically - no extra JS needed.
          ============================================ -->
          <div class="message-cards only-mobile">
            <?php foreach ($messages as $msg): ?>
              <div class="message-card">
                <div class="message-card-header">
                  <?php if ($msg['sender_id'] == $owner['id']): ?>
                    To: <?= clean($msg['receiver_name']) ?>
                  <?php else: ?>
                    From: <?= clean($msg['sender_name']) ?>
                  <?php endif; ?>
                </div>
                <div class="message-card-body">
                  <p class="message-card-text"><?= clean(substr($msg['message'], 0, 100)) ?><?= strlen($msg['message']) > 100 ? '...' : '' ?></p>
                  <div class="message-card-row">
                    <span class="label">Status</span>
                    <span class="value">
                      <span class="status-badge <?= $msg['is_read'] ? 'available' : 'pending' ?>"><?= $msg['is_read'] ? 'Read' : 'New' ?></span>
                    </span>
                  </div>
                  <div class="message-card-row">
                    <span class="label">Date</span>
                    <span class="value"><?= format_date($msg['created_at']) ?></span>
                  </div>
                </div>
                <div class="message-card-actions">
                  <?php if ($msg['sender_id'] != $owner['id'] && !$msg['is_read']): ?>
                    <form method="POST">
                      <input type="hidden" name="mark_read" value="1">
                      <input type="hidden" name="message_id" value="<?= $msg['id'] ?>">
                      <button type="submit" class="action-btn-small approve" onclick="return confirm('Mark this message as read?')">Read</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($msg['sender_id'] != $owner['id']): ?>
                    <button type="button" class="action-btn reply-btn" data-id="<?= $msg['id'] ?>" data-message="<?= htmlspecialchars(json_encode($msg['message']), ENT_QUOTES, 'UTF-8') ?>">Reply</button>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </main>
  </div>

  <!-- Reply Modal -->
  <!-- ============================================
       Refactored onto the shared .modal / .modal-content
       classes (same ones the admin panel and owner_reviews.php
       use) instead of one-off inline styles, so it inherits
       the mobile-width treatment once owner_style.css gets it.
       BUG FIX: this form was missing the CSRF/form_token field
       that reply_message's validate_form_token_or_error() call
       requires - every reply submission was failing with
       "Invalid or expired form submission." Added the same
       hidden-token pattern owner_reviews.php's reply modal uses.
  ============================================ -->
  <div id="replyModal" class="modal" style="display:none;">
    <div class="modal-content">
      <h3>Reply to Message</h3>
      <form method="POST">
        <input type="hidden" name="message_id" id="messageId">
        <input type="hidden" name="form_token" id="replyFormToken">
        <div class="form-group">
          <label>Original Message</label>
          <p id="originalMessage" style="background:rgba(255,255,255,0.04); padding:10px; border-radius:6px; color:var(--muted);"></p>
        </div>
        <div class="form-group">
          <label>Your Reply</label>
          <textarea name="reply" id="replyText" rows="4" class="form-control" required></textarea>
        </div>
        <div class="modal-actions">
          <button type="submit" class="primary">Send Reply</button>
          <button type="button" onclick="closeReplyModal()" class="action-btn">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Form token for the reply modal (see BUG FIX note above the modal markup)
    const replyMessageFormToken = '<?= generate_form_token('reply_message') ?>';

    function openReplyModal(id, message) {
      var modal = document.getElementById('replyModal');
      var messageId = document.getElementById('messageId');
      var originalMessage = document.getElementById('originalMessage');
      var replyText = document.getElementById('replyText');
      var replyFormToken = document.getElementById('replyFormToken');
      
      if (modal && messageId && originalMessage && replyText) {
        messageId.value = id;
        originalMessage.textContent = message;
        replyText.value = '';
        if (replyFormToken) {
          replyFormToken.value = replyMessageFormToken;
        }
        modal.style.display = 'flex';
      } else {
        console.error('Modal elements not found');
      }
    }

    function closeReplyModal() {
      var modal = document.getElementById('replyModal');
      if (modal) {
        modal.style.display = 'none';
      }
    }

    document.addEventListener('DOMContentLoaded', function() {
      var modal = document.getElementById('replyModal');
      
      if (modal) {
        // Close modal on outside click
        modal.addEventListener('click', function(e) {
          if (e.target === this) closeReplyModal();
        });
      }
      
      // Handle reply button clicks using data attributes
      document.querySelectorAll('.reply-btn').forEach(function(button) {
        button.addEventListener('click', function() {
          var id = this.getAttribute('data-id');
          var message = this.getAttribute('data-message');
          if (id && message) {
            openReplyModal(id, message);
          }
        });
      });
    });
  </script>

  <script src="js/owner_script.js"></script>
</body>
</html>