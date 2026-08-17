<?php
include '../database/db.php';
include __DIR__ . '/../helpers/duplicate_functions.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$conn = $conn ?? $GLOBALS['conn'] ?? $GLOBALS['pdo'] ?? null;

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);
require_once __DIR__ . '/../auth/require_face_verified.php';

// Get renter account state
$stmt = $conn->prepare("SELECT id, full_name, status, disapproval_reason FROM users WHERE id = ? AND is_deleted = 0");
$stmt->execute([$user_id]);
$renter = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$renter) {
    session_destroy();
    header('Location: ../auth/login.php');
    exit;
}

// Check if renter is approved
if (($renter['status'] ?? 'pending') !== 'approved') {
    $account_state = [
        'status' => $renter['status'] ?? 'pending',
        'title' => $renter['status'] === 'disapproved' ? 'Account Disapproved' : 'Pending Admin Approval',
        'message' => $renter['status'] === 'disapproved' 
            ? ($renter['disapproval_reason'] ?? 'Your account was disapproved.') 
            : 'Your account is waiting for admin approval. Records are disabled.',
        'restricted' => true
    ];
    
    // Show restricted page
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Records Restricted | Carbnb</title>
        <link rel="stylesheet" href="css/renter_style.css?v=2">
    </head>
    <body>
        <div class="record-container">
            <h2>Records Restricted</h2>
            <div class="approval-card">
                <h3><?= htmlspecialchars($account_state['title']) ?></h3>
                <p><?= htmlspecialchars($account_state['message']) ?></p>
                <a href="browse.php" class="back-link">← Back to Browse</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$msg = '';
$returnMsg = '';

// Get success message from redirect
if (isset($_GET['success'])) {
    $msg = trim($_GET['success']);
}

// Handle return car request
// NOTE: return_car() (in helpers/duplicate_functions.php) only updates
// bookings.status to 'return_requested' and notifies the owner.
// It does NOT and must NOT change vehicles.availability_status.
// Only the owner, via manage_vehicles.php -> make_vehicle_available(),
// is allowed to set a vehicle back to 'available'.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['return_car'], $_POST['booking_id'])) {
    // Validate form token to prevent duplicate submissions
    $tokenError = validate_form_token_or_error('return_car');
    if ($tokenError) {
        $returnMsg = $tokenError;
    } else {
        $booking_id = (int) $_POST['booking_id'];
        $result = return_car($conn, $user_id, $booking_id);
        if ($result['success']) {
            header('Location: record.php?success=' . urlencode($result['message']));
            exit;
        } else {
            $returnMsg = $result['message'];
        }
    }
}

// Handle general feedback submission to owner
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_feedback'])) {
    // Validate form token to prevent duplicate submissions
    $tokenError = validate_form_token_or_error('send_feedback');
    if ($tokenError) {
        $msg = $tokenError;
    } else {
        $feedback = trim($_POST['feedback'] ?? '');
        $selected_owner_id = (int)($_POST['owner_id'] ?? 0);

        if (!empty($feedback) && $selected_owner_id > 0) {
            try {
                // Verify the owner exists
                $ownerStmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'owner' AND is_deleted = 0");
                $ownerStmt->execute([$selected_owner_id]);
                $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);

                if ($owner) {
                    // Insert feedback as a review with NULL vehicle_id (general feedback)
                    $stmt = $conn->prepare("INSERT INTO reviews (renter_id, owner_id, vehicle_id, rating, feedback) VALUES (?, ?, NULL, NULL, ?)");
                    $stmt->execute([$user_id, $selected_owner_id, $feedback]);
                    
                    // Redirect to prevent duplicate submission on refresh
                    header('Location: record.php?success=' . urlencode('Feedback sent successfully!'));
                    exit;
                } else {
                    $msg = 'Invalid owner selected.';
                }
            } catch (PDOException $e) {
                $msg = 'Error sending feedback.';
            }
        } else {
            $msg = 'Please write feedback and select an owner.';
        }
    }
}

// Handle review submission removed - feature not working

$stmt = $conn->prepare("SELECT b.id, b.vehicle_id, b.start_date, b.end_date, b.total_price, b.status, v.name AS vehicle_name, v.owner_id FROM bookings b JOIN vehicles v ON b.vehicle_id = v.id WHERE b.renter_id = ? ORDER BY b.created_at DESC");
$stmt->execute([$user_id]);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("SELECT p.id, p.amount, p.proof_image AS receipt_image, p.status AS payment_status FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE b.renter_id = ? ORDER BY p.created_at DESC");
$stmt->execute([$user_id]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get completed bookings that haven't been reviewed yet (kept for future use)
// $reviewableBookings removed - rate and comment feature not working

// Get all owners for feedback dropdown
$stmt = $conn->prepare("
    SELECT id AS owner_id, full_name AS owner_name
    FROM users 
    WHERE role = 'owner' AND is_deleted = 0
    ORDER BY full_name ASC
");
$stmt->execute();
$ownersForFeedback = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get renter's submitted reviews (for both vehicle reviews and general feedback)
$stmt = $conn->prepare("
    SELECT r.id, r.rating, r.feedback, r.reply, r.created_at, u.full_name AS owner_name, v.name AS vehicle_name
    FROM reviews r
    JOIN users u ON u.id = r.owner_id
    LEFT JOIN vehicles v ON v.id = r.vehicle_id
    WHERE r.renter_id = ?
    ORDER BY r.created_at DESC
");
$stmt->execute([$user_id]);
$myReviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
if ($ajax && ($_GET['section'] ?? '') === 'booking-history') {
    if (empty($bookings)) {
        echo '<p>No rental history found.</p>';
    } else {
        foreach ($bookings as $b) {
            echo '<div class="record-card">';
            echo '<div class="record-info">';
            echo '<p><strong>Car:</strong> ' . htmlspecialchars($b['vehicle_name']) . '</p>';
            echo '<p><small>' . htmlspecialchars($b['start_date']) . ' → ' . htmlspecialchars($b['end_date']) . '</small></p>';
            echo '<p><strong>Total:</strong> ₱' . number_format((float) $b['total_price'], 2) . '</p>';
            echo '</div>';
            echo '<span class="status status-' . strtolower($b['status']) . '">' . htmlspecialchars(ucfirst(str_replace('_', ' ', $b['status']))) . '</span>';
            if ($b['status'] === 'approved') {
                echo '<form method="POST" style="display:inline; margin-left:10px;" onsubmit="return confirm(\'Confirm that you have returned this car?\');">';
                echo form_token_input('return_car');
                echo '<input type="hidden" name="booking_id" value="' . (int) $b['id'] . '">';
                echo '<button type="submit" name="return_car" class="btn-small">Return Car</button>';
                echo '</form>';
            } elseif ($b['status'] === 'return_requested') {
                echo '<span class="text-muted" style="margin-left:10px; font-size:0.9em;">Waiting for owner to inspect and mark vehicle available.</span>';
            }
            echo '</div>';
        }
    }
    exit;
}

if ($ajax && ($_GET['section'] ?? '') === 'payment-history') {
    if (empty($payments)) {
        echo '<p>No payment records found.</p>';
    } else {
        foreach ($payments as $p) {
            echo '<div class="record-card">';
            echo '<div class="record-info">';
            echo '<p><strong>Amount:</strong> ₱' . number_format((float) $p['amount'], 2) . '</p>';
            echo '<span class="status payment-' . strtolower($p['payment_status']) . '">' . ucfirst($p['payment_status']) . '</span>';
            echo '</div>';
            if (!empty($p['receipt_image'])) {
                echo '<a href="../uploads/payments/' . htmlspecialchars($p['receipt_image']) . '" target="_blank"><img src="../uploads/payments/' . htmlspecialchars($p['receipt_image']) . '" class="receipt-img"></a>';
            }
            echo '</div>';
        }
    }
    exit;
}

// Display-only helper for booking status labels (handles multi-word
// statuses like 'return_requested' -> "Return requested").
// This is purely cosmetic and does not affect any booking/vehicle logic.
function booking_status_label(string $status): string {
    return ucfirst(str_replace('_', ' ', $status));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Records | Carbnb</title>
<link rel="stylesheet" href="../bootstrap-5.3.8-dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="css/renter_style.css?v=5">
        <link rel="stylesheet" href="css/renter_style_backup.css?v=4">
</head>

<body>

<div class="top-nav">
    <div class="nav-left">
        <h2>Carbnb</h2>
    </div>
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        ☰
    </button>
    <div class="nav-right" id="mobileMenu">
        <a href="browse.php" class="nav-all-cars">All Cars</a>
        <a href="record.php" class="nav-my-records">My Records</a>
        <a href="view_profile.php" class="nav-my-profile">My Profile</a>
        <a href="renter_messages.php" class="nav-my-messages">Messages</a>
        <a href="../auth/logout.php" class="logout-link">Logout</a>
    </div>
</div>


<div class="header-text">
    <h1><span class="blue">My</span> <span class="orange">Records</span></h1>
</div>

<div class="record-container">

    <div class="header-row">
        <h2>My Rental Records</h2>
        <a href="browse.php" class="back-link">← Back to Browse</a>
    </div>

<h3>Booking History</h3>

    <?php if ($returnMsg): ?>
        <p class="error-msg" style="color:#dc3545;"><?= htmlspecialchars($returnMsg) ?></p>
    <?php endif; ?>

    <div id="renter-booking-history-content" data-live-refresh="record.php?ajax=1&section=booking-history" data-live-target="#renter-booking-history-content">
    <?php if (empty($bookings)): ?>
        <p>No rental history found.</p>
    <?php else: ?>
        <?php foreach ($bookings as $b): ?>
            <div class="record-card">
                <div class="record-info">
                    <p><strong>Car:</strong> <?= htmlspecialchars($b['vehicle_name']) ?></p>
                    <p><small><?= htmlspecialchars($b['start_date']) ?> → <?= htmlspecialchars($b['end_date']) ?></small></p>
                    <p><strong>Total:</strong> ₱<?= number_format((float) $b['total_price'], 2) ?></p>
                </div>

                <span class="status status-<?= strtolower($b['status']) ?>">
                    <?= htmlspecialchars(booking_status_label($b['status'])) ?>
                </span>

                <?php if ($b['status'] === 'approved'): ?>
                    <form method="POST" style="display:inline; margin-left:10px;" onsubmit="return confirm('Confirm that you have returned this car?');">
                        <?= form_token_input('return_car') ?>
                        <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                        <button type="submit" name="return_car" class="btn-small">Return Car</button>
                    </form>
<?php elseif ($b['status'] === 'return_requested'): ?>
                    <span class="text-muted" style="margin-left:10px; font-size:0.9em;">
                        Waiting for owner to inspect and mark vehicle available.
                    </span>
                    <?php
                    // Check if this vehicle has been reviewed
                    $stmt = $conn->prepare("SELECT id FROM reviews WHERE renter_id = ? AND vehicle_id = ? LIMIT 1");
                    $stmt->execute([$user_id, $b['vehicle_id'] ?? 0]);
                    $hasReviewed = (bool) $stmt->fetch();
                    ?>
                    <?php if (!$hasReviewed): ?>
                    <a href="commet_rate.php?vehicle_id=<?= (int) $b['vehicle_id'] ?>" class="btn-small" style="margin-left:10px; background:#17a2b8; text-decoration:none;">
                        Rate & Comment
                    </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>

    <h3>Payment History</h3>

    <div id="renter-payment-history-content" data-live-refresh="record.php?ajax=1&section=payment-history" data-live-target="#renter-payment-history-content">
    <?php if (empty($payments)): ?>
        <p>No payment records found.</p>
    <?php else: ?>
        <?php foreach ($payments as $p): ?>
            <div class="record-card">
                <div class="record-info">
                    <p><strong>Amount:</strong> ₱<?= number_format((float) $p['amount'], 2) ?></p>
                    <span class="status payment-<?= strtolower($p['payment_status']) ?>">
                        <?= ucfirst($p['payment_status']) ?>
                    </span>
                </div>

                <?php if (!empty($p['receipt_image'])): ?>
                    <a href="../uploads/payments/<?= htmlspecialchars($p['receipt_image']) ?>" target="_blank">
                        <img src="../uploads/payments/<?= htmlspecialchars($p['receipt_image']) ?>" class="receipt-img">
                    </a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>

    <!-- Rate Your Experience section removed - feature not working -->

<?php if (!empty($ownersForFeedback)): ?>
    <div class="feedback-box">
        <h3>Send Feedback to Owner</h3>

        <?php if ($msg): ?>
            <p class="success-msg"><?= $msg ?></p>
        <?php endif; ?>

<form method="POST">
            <?= form_token_input('send_feedback') ?>
            <input type="hidden" name="send_feedback" value="1">
            <div class="form-group">
                <label for="owner_id">Select Owner:</label>
                <select name="owner_id" id="owner_id" required>
                    <option value="">-- Select an owner --</option>
                    <?php foreach ($ownersForFeedback as $of): ?>
                        <option value="<?= $of['owner_id'] ?>"><?= htmlspecialchars($of['owner_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <textarea name="feedback" placeholder="Share your feedback with the owner..." required></textarea>
            <button type="submit">Submit Feedback</button>
        </form>
    </div>
    <?php else: ?>
    <div class="feedback-box">
        <h3>Send Feedback to Owner</h3>
        <p class="text-muted">You can send feedback to owners after completing a booking.</p>
    </div>
    <?php endif; ?>

    <?php if (!empty($myReviews)): ?>
    <h3>My Reviews & Feedback</h3>
    
    <?php foreach ($myReviews as $review): ?>
    <div class="record-card">
        <div class="record-info">
            <p><strong>Owner:</strong> <?= htmlspecialchars($review['owner_name']) ?></p>
            <?php if (!empty($review['vehicle_name'])): ?>
                <p><strong>Vehicle:</strong> <?= htmlspecialchars($review['vehicle_name']) ?></p>
            <?php else: ?>
                <p><strong>Type:</strong> General Feedback</p>
            <?php endif; ?>
            <?php if (!empty($review['rating'])): ?>
                <p><strong>Rating:</strong> 
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <?= $i <= $review['rating'] ? '★' : '☆' ?>
                    <?php endfor; ?>
                    (<?= $review['rating'] ?>/5)
                </p>
            <?php endif; ?>
<?php if (!empty($review['feedback'])): ?>
                <p><strong>My Feedback:</strong> <?= nl2br(htmlspecialchars($review['feedback'])) ?></p>
            <?php endif; ?>
            <?php if (!empty($review['reply'])): ?>
                <p><strong>Owner Reply:</strong> <span style="color:#198754;"><?= nl2br(htmlspecialchars($review['reply'])) ?></span></p>
            <?php endif; ?>
            <p><small>Date: <?= date('M d, Y', strtotime($review['created_at'])) ?></small></p>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

</div>

<script>
(function () {

    // ============================
    // Mobile Menu
    // ============================

    const mobileMenuBtn = document.getElementById("mobileMenuBtn");
    const mobileMenu = document.getElementById("mobileMenu");

    if (mobileMenuBtn && mobileMenu) {

        mobileMenuBtn.addEventListener("click", function () {

            mobileMenu.classList.toggle("show");

        });

        document.addEventListener("click", function (e) {

            if (
                !mobileMenu.contains(e.target) &&
                !mobileMenuBtn.contains(e.target)
            ) {
                mobileMenu.classList.remove("show");
            }

        });

    }


    // ============================
    // Reply Modal
    // ============================

    function openReplyModal(id, message) {

        const modal = document.getElementById("replyModal");
        const messageId = document.getElementById("messageId");
        const originalMessage = document.getElementById("originalMessage");
        const replyText = document.getElementById("replyText");

        if (!modal) return;

        messageId.value = id;
        originalMessage.textContent = message;
        replyText.value = "";

        modal.style.display = "flex";

    }


    function closeReplyModal() {

        const modal = document.getElementById("replyModal");

        if (modal) {
            modal.style.display = "none";
        }

    }


    window.closeReplyModal = closeReplyModal;


    const modal = document.getElementById("replyModal");

    if (modal) {

        modal.addEventListener("click", function (e) {

            if (e.target === modal) {

                closeReplyModal();

            }

        });

    }


    function bindReplyButtons() {

        document.querySelectorAll(".reply-btn").forEach(function (button) {

            button.onclick = function () {

                openReplyModal(
                    this.dataset.id,
                    this.dataset.message
                );

            };

        });

    }


    bindReplyButtons();



    // ============================
    // Auto Refresh
    // ============================

    document.querySelectorAll("[data-live-refresh]").forEach(function (node) {

        const refreshUrl = node.dataset.liveRefresh;

        const targetSelector = node.dataset.liveTarget || "#" + node.id;


        function refreshSection() {

            fetch(refreshUrl)

                .then(function (response) {

                    return response.text();

                })

                .then(function (html) {

                    const target = document.querySelector(targetSelector);


                    if (target) {

                        target.innerHTML = html;


                        // Reconnect reply buttons after refresh
                        bindReplyButtons();

                    }

                })

                .catch(function (error) {

                    console.log("Records refresh failed:", error);

                });

        }


        refreshSection();


        setInterval(refreshSection, 8000);


    });


})();
</script>

</body>
</html>