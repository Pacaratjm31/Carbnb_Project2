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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply'], $_POST['review_id'])) {
    $reviewId = (int)$_POST['review_id'];
    $reply = trim($_POST['reply_text'] ?? '');
    
    if (!empty($reply)) {
        try {
            // Update the reply field in the reviews table (works for both with and without vehicle)
            $stmt = $pdo->prepare("UPDATE reviews SET reply = ? WHERE id = ? AND owner_id = ?");
            $stmt->execute([$reply, $reviewId, $owner['id']]);
            
            if ($stmt->rowCount() > 0) {
                // Redirect to prevent duplicate submission on refresh
                header('Location: owner_reviews.php?success=' . urlencode('Reply sent successfully!'));
                exit;
            } else {
                $error = 'Invalid review.';
            }
        } catch (PDOException $e) {
            $error = 'Error sending reply.';
        }
    } else {
        $error = 'Please write a reply before submitting.';
    }
}

$reviews = get_owner_reviews($pdo, $owner['id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Owner Reviews | Carbnb</title>
  <link rel="stylesheet" href="css/owner_style.css?v=20260702">
  <link rel="stylesheet" href="css/owner_responsive.css?v=20260803">
</head>
<body>
  <div class="overlay"></div>
  <aside class="sidebar">
    <div class="sidebar-header">
      <h2>Carbnb Owner</h2>
      <button class="sidebar-close" type="button">×</button>
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
      <a href="owner_message.php">Messages</a>
      <a class="active" href="owner_reviews.php">Reviews</a>
      <a href="../auth/logout.php" class="topbar-action" style="display:block; margin-top:1rem; text-align:center;">Logout</a>
    </nav>
  </aside>

  <div class="main-content">
<header class="topbar">
      <button class="sidebar-toggle" type="button" aria-label="Open sidebar"></button>
      <h1>Reviews & Feedback</h1>
      <a class="topbar-action" href="owner_dashboard.php">Home</a>
    </header>

    <main class="page">
      <?php if ($success): ?>
        <div class="alert success"><?= clean($success) ?></div>
      <?php endif; ?>
      
      <?php if ($error): ?>
        <div class="alert error"><?= clean($error) ?></div>
      <?php endif; ?>
      
      <section class="card">
        <h3 class="section-title">Customer Reviews</h3>
        
        <?php if (empty($reviews)) : ?>
          <p class="empty-state">No reviews yet. Reviews from renters will appear here after they complete their bookings.</p>
<?php else : ?>
<div class="table-wrapper only-desktop">
             <table class="table">
<thead>
                <tr>
                  <th>Renter</th>
                  <th>Vehicle</th>
                  <th>Rating</th>
                  <th>Comment</th>
                  <th>Feedback</th>
                  <th>Reply</th>
                  <th>Date</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($reviews as $review): ?>
                  <tr>
                    <td data-label="Renter"><?= clean($review['renter_name']) ?></td>
                    <td data-label="Vehicle"><?= clean($review['vehicle_name'] ?: 'General Feedback') ?></td>
                    <td data-label="Rating">
                      <?php if ($review['rating']): ?>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                          <?= $i <= $review['rating'] ? '★' : '☆' ?>
                        <?php endfor; ?>
                        (<?= $review['rating'] ?>/5)
                      <?php else: ?>
                        <em>No rating</em>
                      <?php endif; ?>
                    </td>
                    <td data-label="Comment"><?= clean($review['comment'] ?: 'No comment') ?></td>
                    <td data-label="Feedback"><?= clean($review['feedback'] ?: 'No feedback') ?></td>
                    <td data-label="Reply"><?= clean($review['reply'] ?: 'No reply yet') ?></td>
                    <td data-label="Date"><?= format_date($review['created_at']) ?></td>
                    <td data-label="Action" class="cell-actions">
                      <div class="action-group">
                        <button class="action-btn" onclick="openReplyModal(<?= $review['id'] ?>, <?= htmlspecialchars(json_encode($review['renter_name']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($review['comment'] ?? $review['feedback'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($review['reply'] ?? ''), ENT_QUOTES) ?>)">Reply</button>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- ============================================
               MOBILE REVIEW CARDS
               Same $reviews data as the table above, card-
               per-review for phones (same .only-desktop/
               .only-mobile pattern used across the owner
               panel). Reply buttons call the same
               openReplyModal(...) function as the desktop
               version - no extra JS needed.
          ============================================ -->
          <div class="review-cards only-mobile">
            <?php foreach ($reviews as $review): ?>
              <div class="review-card">
                <div class="review-card-header"><?= clean($review['renter_name']) ?></div>
                <div class="review-card-body">
                  <div class="review-card-row">
                    <span class="label">Vehicle</span>
                    <span class="value"><?= clean($review['vehicle_name'] ?: 'General Feedback') ?></span>
                  </div>
                  <div class="review-card-row">
                    <span class="label">Rating</span>
                    <span class="value">
                      <?php if ($review['rating']): ?>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                          <?= $i <= $review['rating'] ? '★' : '☆' ?>
                        <?php endfor; ?>
                        (<?= $review['rating'] ?>/5)
                      <?php else: ?>
                        <em>No rating</em>
                      <?php endif; ?>
                    </span>
                  </div>
                  <div class="review-card-row">
                    <span class="label">Comment</span>
                    <span class="value"><?= clean($review['comment'] ?: 'No comment') ?></span>
                  </div>
                  <div class="review-card-row">
                    <span class="label">Feedback</span>
                    <span class="value"><?= clean($review['feedback'] ?: 'No feedback') ?></span>
                  </div>
                  <div class="review-card-row">
                    <span class="label">Reply</span>
                    <span class="value"><?= clean($review['reply'] ?: 'No reply yet') ?></span>
                  </div>
                  <div class="review-card-row">
                    <span class="label">Date</span>
                    <span class="value"><?= format_date($review['created_at']) ?></span>
                  </div>
                </div>
                <div class="review-card-actions">
                  <button class="action-btn" onclick="openReplyModal(<?= $review['id'] ?>, <?= htmlspecialchars(json_encode($review['renter_name']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($review['comment'] ?? $review['feedback'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($review['reply'] ?? ''), ENT_QUOTES) ?>)">Reply</button>
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
       Refactored onto the shared .modal / .modal-content /
       .modal-actions classes (same ones owner_message.php now
       uses) instead of the doubled-up inline-styled wrapper
       divs, so it inherits the mobile-width treatment once
       owner_style.css picks up those rules. Functionality
       (form_token, field IDs) is unchanged.
  ============================================ -->
  <div id="replyModal" class="modal" style="display:none;">
    <div class="modal-content">
      <h3>Reply to Review</h3>
      <form method="POST" id="replyReviewForm">
        <input type="hidden" name="reply" value="1">
        <input type="hidden" name="review_id" id="reviewId">
        <input type="hidden" name="form_token" id="replyFormToken">
        <div class="form-group">
          <label>Renter: <span id="renterName"></span></label>
        </div>
        <div class="form-group">
          <label>Original Review</label>
          <p id="originalReview" style="background:rgba(255,255,255,0.04); padding:10px; border-radius:6px; color:var(--muted); min-height:40px;"></p>
        </div>
        <div class="form-group">
          <label>Your Reply</label>
          <textarea name="reply_text" id="replyText" rows="4" class="form-control" required></textarea>
        </div>
        <div class="modal-actions">
          <button type="submit" class="primary">Send Reply</button>
          <button type="button" onclick="closeReplyModal()" class="action-btn">Cancel</button>
        </div>
      </form>
    </div>
  </div>

<script>
    // Generate form token for reply form
    const replyFormToken = '<?= generate_form_token('reply_review') ?>';

    function openReplyModal(reviewId, renterName, reviewComment, existingReply) {
      document.getElementById('reviewId').value = reviewId;
      document.getElementById('renterName').textContent = renterName;
      document.getElementById('originalReview').textContent = reviewComment || 'No comment';
      document.getElementById('replyText').value = existingReply || '';
      document.getElementById('replyFormToken').value = replyFormToken;
      document.getElementById('replyModal').style.display = 'flex';
    }

    function closeReplyModal() {
      document.getElementById('replyModal').style.display = 'none';
    }

    // Close modal on outside click
    document.getElementById('replyModal').addEventListener('click', function(e) {
      if (e.target === this) closeReplyModal();
    });
  </script>

  <script src="js/owner_script.js"></script>
</body>
</html>