<?php
include '../database/db.php';
include __DIR__ . '/../helpers/duplicate_functions.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);
// Use $pdo from db.php (it's set as a global variable)
$conn = $GLOBALS['pdo'];
if (!$conn) {
    die('Database connection not available');
}
require_once __DIR__ . '/../auth/require_face_verified.php';

// Helper functions
function clean($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function formatDate($date) {
    return $date ? date('M d, Y', strtotime($date)) : '—';
}

// Get renter info
$stmt = $conn->prepare("SELECT id, full_name, status, disapproval_reason FROM users WHERE id = ? AND is_deleted = 0");
$stmt->execute([$user_id]);
$renter = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$renter) {
    session_destroy();
    header('Location: ../auth/login.php');
    exit;
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
            // Get the original message to find the sender (owner)
            $stmt = $conn->prepare("SELECT sender_id FROM messages WHERE id = ? AND receiver_id = ?");
            $stmt->execute([$messageId, $user_id]);
            $originalMsg = $stmt->fetch();
            
            if ($originalMsg) {
                // Insert reply as a new message from renter to owner
                $stmt = $conn->prepare("
                    INSERT INTO messages (sender_id, receiver_id, message) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$user_id, $originalMsg['sender_id'], $reply]);
                
                // Mark original message as read
                $stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE id = ?");
                $stmt->execute([$messageId]);
                
                header('Location: renter_messages.php?success=' . urlencode('Reply sent successfully!'));
                exit;
            }
        }
    }
}

// Handle marking message as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'], $_POST['message_id'])) {
    $messageId = (int)$_POST['message_id'];
    
    $stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE id = ? AND receiver_id = ?");
    $stmt->execute([$messageId, $user_id]);
    header('Location: renter_messages.php?success=' . urlencode('Message marked as read!'));
    exit;
}

// Handle sending new message to owner
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_new_message'], $_POST['owner_id'], $_POST['new_message'])) {
    // Validate form token to prevent duplicate submissions
    $tokenError = validate_form_token_or_error('send_new_message');
    if ($tokenError) {
        $error = $tokenError;
    } else {
        $ownerId = (int)$_POST['owner_id'];
        $newMessage = trim($_POST['new_message']);
        
        if (!empty($newMessage) && $ownerId > 0) {
            $stmt = $conn->prepare("
                INSERT INTO messages (sender_id, receiver_id, message) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$user_id, $ownerId, $newMessage]);
            header('Location: renter_messages.php?success=' . urlencode('Message sent successfully!'));
            exit;
        }
    }
}

// Get messages for this renter (both received and sent)
$stmt = $conn->prepare("
    SELECT m.id, m.message, m.is_read, m.created_at, m.sender_id, m.receiver_id,
           s.full_name AS sender_name, s.role AS sender_role,
           r.full_name AS receiver_name, r.role AS receiver_role
    FROM messages m
    LEFT JOIN users s ON s.id = m.sender_id
    LEFT JOIN users r ON r.id = m.receiver_id
    WHERE m.receiver_id = ? OR m.sender_id = ?
    ORDER BY m.created_at DESC
");
$stmt->execute([$user_id, $user_id]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all approved owners for the dropdown (not just those who have messaged)
$owners = [];
try {
    $stmt = $conn->prepare("
        SELECT id, full_name, email
        FROM users
        WHERE role = 'owner' AND status = 'approved' AND is_deleted = 0
        ORDER BY full_name
    ");
    $stmt->execute();
    $owners = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = $e->getMessage();
}

// Get all admins for the dropdown
$admins = [];
try {
    $stmt = $conn->prepare("
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

// Handle inspection image upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_inspection'])) {
    $tokenError = validate_form_token_or_error('submit_inspection');
    if ($tokenError) {
        $error = $tokenError;
    } else {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        
        if ($bookingId > 0) {
            // Verify the booking belongs to this renter
            $stmt = $conn->prepare("SELECT b.id, b.vehicle_id, v.owner_id FROM bookings b JOIN vehicles v ON v.id = b.vehicle_id WHERE b.id = ? AND b.renter_id = ?");
            $stmt->execute([$bookingId, $user_id]);
            $booking = $stmt->fetch();
            
            if ($booking) {
                $uploadDir = __DIR__ . '/../uploads/inspection/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $imageFields = ['front_image', 'back_image', 'left_image', 'right_image'];
                $imagePaths = [];
                
                foreach ($imageFields as $field) {
                    if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
                        $fileName = 'inspect_' . $bookingId . '_' . $field . '_' . time() . '_' . basename($_FILES[$field]['name']);
                        $uploadPath = $uploadDir . $fileName;
                        
                        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
                        $fileType = mime_content_type($_FILES[$field]['tmp_name']);
                        
                        if (in_array($fileType, $allowedTypes) && $_FILES[$field]['size'] <= 5 * 1024 * 1024) {
                            if (move_uploaded_file($_FILES[$field]['tmp_name'], $uploadPath)) {
                                $imagePaths[$field] = 'uploads/inspection/' . $fileName;
                            }
                        }
                    }
                }
                
                // Check if inspection already exists for this booking
                $stmt = $conn->prepare("SELECT id FROM inspect WHERE booking_id = ?");
                $stmt->execute([$bookingId]);
                $existing = $stmt->fetch();
                
                if ($existing) {
                    // Update existing inspection
                    $stmt = $conn->prepare("UPDATE inspect SET front_image = COALESCE(?, front_image), back_image = COALESCE(?, back_image), left_image = COALESCE(?, left_image), right_image = COALESCE(?, right_image), reason = ? WHERE booking_id = ?");
                    $stmt->execute([
                        $imagePaths['front_image'] ?? null,
                        $imagePaths['back_image'] ?? null,
                        $imagePaths['left_image'] ?? null,
                        $imagePaths['right_image'] ?? null,
                        $reason,
                        $bookingId
                    ]);
                } else {
                    // Insert new inspection
                    $stmt = $conn->prepare("INSERT INTO inspect (booking_id, renter_id, owner_id, vehicle_id, front_image, back_image, left_image, right_image, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $bookingId,
                        $user_id,
                        $booking['owner_id'],
                        $booking['vehicle_id'],
                        $imagePaths['front_image'] ?? null,
                        $imagePaths['back_image'] ?? null,
                        $imagePaths['left_image'] ?? null,
                        $imagePaths['right_image'] ?? null,
                        $reason
                    ]);
                }
                
                header('Location: renter_messages.php?success=' . urlencode('Inspection images uploaded successfully!'));
                exit;
            } else {
                $error = 'Invalid booking selected.';
            }
        } else {
            $error = 'Please select a booking.';
        }
    }
}

// Get approved bookings for inspection dropdown
$approvedBookings = [];
try {
    $stmt = $conn->prepare("
        SELECT b.id, v.name AS vehicle_name, u.full_name AS owner_name
        FROM bookings b
        JOIN vehicles v ON v.id = b.vehicle_id
        JOIN users u ON u.id = v.owner_id
        WHERE b.renter_id = ? AND b.status = 'approved'
        ORDER BY b.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $approvedBookings = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = $e->getMessage();
}

// Get existing inspections
$inspections = [];
try {
    $stmt = $conn->prepare("
        SELECT i.*, v.name AS vehicle_name, u.full_name AS owner_name
        FROM inspect i
        JOIN vehicles v ON v.id = i.vehicle_id
        JOIN users u ON u.id = i.owner_id
        WHERE i.renter_id = ?
        ORDER BY i.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $inspections = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = $e->getMessage();
}

$ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
if ($ajax && ($_GET['section'] ?? '') === 'message-list') {
    if (empty($messages)) {
        echo '<div class="no-results"><h3>No messages yet.</h3><p>Messages from vehicle owners will appear here.</p></div>';
    } else {
        foreach ($messages as $msg) {
            echo '<div class="record-card">';
            echo '<div class="record-info">';
            if ($msg['sender_id'] == $user_id) {
                echo '<p><strong>To:</strong> ' . clean($msg['receiver_name']) . ' (' . ucfirst($msg['receiver_role']) . ')</p>';
            } else {
                echo '<p><strong>From:</strong> ' . clean($msg['sender_name']) . ' (' . ucfirst($msg['sender_role']) . ')</p>';
            }
            echo '<p><strong>Message:</strong> ' . clean($msg['message']) . '</p>';
            echo '<p><small>' . formatDate($msg['created_at']) . '</small></p>';
            echo '</div>';
            echo '<div>';
            echo '<span class="status-badge ' . ($msg['is_read'] ? 'status-approved' : 'status-pending') . '">' . ($msg['is_read'] ? 'Read' : 'New') . '</span>';
            if ($msg['sender_id'] != $user_id && !$msg['is_read']) {
                echo '<form method="POST" style="display:inline;">';
                echo '<input type="hidden" name="mark_read" value="1">';
                echo '<input type="hidden" name="message_id" value="' . $msg['id'] . '">';
                echo '<button type="submit" class="btn-book" style="margin-top: 10px; background:#17a2b8;" onclick="return confirm(\'Mark this message as read?\')">Mark as Read</button>';
                echo '</form>';
            }
            if ($msg['sender_id'] != $user_id) {
                echo '<button type="button" class="btn-book reply-btn" style="margin-top: 10px;" data-id="' . $msg['id'] . '" data-message="' . clean($msg['message']) . '">Reply</button>';
            }
            echo '</div></div>';
        }
    }
    exit;
}

if ($ajax && ($_GET['section'] ?? '') === 'inspection-list') {
    if (empty($inspections)) {
        echo '<p class="empty-state">No inspections uploaded yet.</p>';
    } else {
        foreach ($inspections as $inspection) {
            echo '<div class="record-card">';
            echo '<div class="record-info">';
            echo '<p><strong>Vehicle:</strong> ' . clean($inspection['vehicle_name']) . '</p>';
            echo '<p><strong>Owner:</strong> ' . clean($inspection['owner_name']) . '</p>';
            if (!empty($inspection['reason'])) {
                echo '<p><strong>Reason:</strong> ' . clean($inspection['reason']) . '</p>';
            }
            echo '<p><small>' . formatDate($inspection['created_at']) . '</small></p>';
            echo '</div>';
            echo '<div style="display:flex; flex-wrap:wrap; gap:10px;">';
            if (!empty($inspection['front_image'])) {
                echo '<a href="../' . $inspection['front_image'] . '" target="_blank" style="text-decoration:none;"><img src="../' . $inspection['front_image'] . '" style="width:60px; height:60px; object-fit:cover; border-radius:6px; border:1px solid #ffd700;"><small style="display:block; text-align:center; color:#aaa;">Front</small></a>';
            }
            if (!empty($inspection['back_image'])) {
                echo '<a href="../' . $inspection['back_image'] . '" target="_blank" style="text-decoration:none;"><img src="../' . $inspection['back_image'] . '" style="width:60px; height:60px; object-fit:cover; border-radius:6px; border:1px solid #ffd700;"><small style="display:block; text-align:center; color:#aaa;">Back</small></a>';
            }
            if (!empty($inspection['left_image'])) {
                echo '<a href="../' . $inspection['left_image'] . '" target="_blank" style="text-decoration:none;"><img src="../' . $inspection['left_image'] . '" style="width:60px; height:60px; object-fit:cover; border-radius:6px; border:1px solid #ffd700;"><small style="display:block; text-align:center; color:#aaa;">Left</small></a>';
            }
            if (!empty($inspection['right_image'])) {
                echo '<a href="../' . $inspection['right_image'] . '" target="_blank" style="text-decoration:none;"><img src="../' . $inspection['right_image'] . '" style="width:60px; height:60px; object-fit:cover; border-radius:6px; border:1px solid #ffd700;"><small style="display:block; text-align:center; color:#aaa;">Right</small></a>';
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
    <title>Messages | Carbnb</title>
    <link rel="stylesheet" href="../bootstrap-5.3.8-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/renter_style.css?v=5">
    <link rel="stylesheet" href="css/renter_style_backup.css?v=4">
</head>
<body>
    <div class="top-nav">

    <div class="nav-left">
        <h2>Carbnb</h2>
    </div>

    <!-- Mobile Menu Button -->
    <button id="mobileMenuBtn" class="mobile-menu-btn">
        ☰ Menu
    </button>

    <!-- Navigation -->
    <div class="nav-right" id="mobileMenu">

        <a href="browse.php" class="nav-all-cars">All Cars</a>

        <a href="record.php" class="nav-my-records">My Records</a>

        <a href="view_profile.php" class="nav-my-profile">My Profile</a>

        <a href="renter_messages.php" class="nav-my-messages">Messages</a>

        <a href="../auth/logout.php" class="logout-link">Logout</a>

    </div>

</div>

    <div class="header-text">
        <h1><span class="blue">My</span> <span class="orange">Messages</span></h1>
    </div>

    <div class="record-container">
        <?php if ($success): ?>
            <div class="alert success-msg"><?= clean($success) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert" style="color: #dc3545;"><?= clean($error) ?></div>
        <?php endif; ?>

<!-- Car Inspection Section -->
        <div class="record-card" style="margin-bottom:20px;">
            <h3 style="margin-bottom:15px; color:#ffd700;">Car Inspection Upload</h3>
            <form method="POST" enctype="multipart/form-data" id="inspectionForm">
                <input type="hidden" name="submit_inspection" value="1">
                <?= form_token_input('submit_inspection') ?>
                <div class="form-group" style="margin-bottom:15px;">
                    <label style="color:#aaa;">Select Booking</label>
                    <select name="booking_id" class="form-control" required style="width:100%; padding:10px; border-radius:6px; border:1px solid #555; background:#1e1e1e; color:#cfcfcf;">
                        <option value="">-- Select Approved Booking --</option>
                        <?php foreach ($approvedBookings as $booking): ?>
                            <option value="<?= $booking['id'] ?>"><?= clean($booking['vehicle_name']) ?> (Owner: <?= clean($booking['owner_name']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
<div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:15px;">
                    <div class="form-group">
                        <label style="color:#aaa;">Front Car Image</label>
                        <input type="file" name="front_image" accept="image/*" class="form-control" style="width:100%; padding:10px; border-radius:6px; border:1px solid #555; background:#1e1e1e; color:#cfcfcf;">
                    </div>
                    <div class="form-group">
                        <label style="color:#aaa;">Back Car Image</label>
                        <input type="file" name="back_image" accept="image/*" class="form-control" style="width:100%; padding:10px; border-radius:6px; border:1px solid #555; background:#1e1e1e; color:#cfcfcf;">
                    </div>
                    <div class="form-group">
                        <label style="color:#aaa;">Left Side Car Image</label>
                        <input type="file" name="left_image" accept="image/*" class="form-control" style="width:100%; padding:10px; border-radius:6px; border:1px solid #555; background:#1e1e1e; color:#cfcfcf;">
                    </div>
                    <div class="form-group">
                        <label style="color:#aaa;">Right Side Car Image</label>
                        <input type="file" name="right_image" accept="image/*" class="form-control" style="width:100%; padding:10px; border-radius:6px; border:1px solid #555; background:#1e1e1e; color:#cfcfcf;">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:15px;">
                    <label style="color:#aaa;">Reason for Inspection (optional)</label>
                    <textarea name="reason" rows="3" class="form-control" placeholder="Describe the reason for inspection..." style="width:100%; padding:10px; border-radius:6px; border:1px solid #555; background:#1e1e1e; color:#cfcfcf;"></textarea>
                </div>
                <button type="submit" class="btn-book">Upload Inspection Images</button>
            </form>
        </div>

<!-- Send New Message Section -->
        <div class="record-card" style="margin-bottom:20px;">
            <h3 style="margin-bottom:15px; color:#ffd700;">Send New Message</h3>
            <form method="POST" id="newMessageForm">
                <input type="hidden" name="send_new_message" value="1">
                <?= form_token_input('send_new_message') ?>
                <script>
                // Prevent double-click on send message button
                document.addEventListener('DOMContentLoaded', function() {
                    var form = document.getElementById('newMessageForm');
                    if (form) {
                        form.addEventListener('submit', function() {
                            var submitBtn = this.querySelector('button[type="submit"]');
                            if (submitBtn) {
                                submitBtn.disabled = true;
                                submitBtn.textContent = 'Sending...';
                            }
                        });
                    }
                });
                </script>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <div class="form-group">
                        <label style="color:#aaa;">Select Recipient</label>
                        <select name="owner_id" class="form-control" required style="width:100%; padding:10px; border-radius:6px; border:1px solid #555; background:#1e1e1e; color:#cfcfcf;">
                            <option value="">-- Select Recipient --</option>
                            <optgroup label="Owners">
                                <?php foreach ($owners as $owner): ?>
                                    <option value="<?= $owner['id'] ?>"><?= clean($owner['full_name']) ?> (Owner)</option>
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
                        <label style="color:#aaa;">Message</label>
                        <textarea name="new_message" rows="3" class="form-control" placeholder="Type your message..." required style="width:100%; padding:10px; border-radius:6px; border:1px solid #555; background:#1e1e1e; color:#cfcfcf;"></textarea>
                    </div>
                </div>
                <button type="submit" class="btn-book" style="margin-top:10px;">Send Message</button>
            </form>
        </div>

        <?php if (empty($messages)): ?>
            <div class="no-results">
                <h3>No messages yet.</h3>
                <p>Messages from vehicle owners will appear here.</p>
            </div>
        <?php else: ?>
            <div class="message-list" id="renter-message-list" data-live-refresh="renter_messages.php?ajax=1&section=message-list" data-live-target="#renter-message-list">
                <?php foreach ($messages as $msg): ?>
                    <div class="record-card">
                        <div class="record-info">
                            <?php if ($msg['sender_id'] == $user_id): ?>
                                <p><strong>To:</strong> <?= clean($msg['receiver_name']) ?> (<?= ucfirst($msg['receiver_role']) ?>)</p>
                            <?php else: ?>
                                <p><strong>From:</strong> <?= clean($msg['sender_name']) ?> (<?= ucfirst($msg['sender_role']) ?>)</p>
                            <?php endif; ?>
                            <p><strong>Message:</strong> <?= clean($msg['message']) ?></p>
                            <p><small><?= formatDate($msg['created_at']) ?></small></p>
                        </div>
                        <div>
                            <span class="status-badge <?= $msg['is_read'] ? 'status-approved' : 'status-pending' ?>">
                                <?= $msg['is_read'] ? 'Read' : 'New' ?>
                            </span>
                            <?php if ($msg['sender_id'] != $user_id && !$msg['is_read']): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="mark_read" value="1">
                                    <input type="hidden" name="message_id" value="<?= $msg['id'] ?>">
                                    <button type="submit" class="btn-book" style="margin-top: 10px; background:#17a2b8;" onclick="return confirm('Mark this message as read?')">Mark as Read</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($msg['sender_id'] != $user_id): ?>
                                <button type="button" class="btn-book reply-btn" style="margin-top: 10px;" data-id="<?= $msg['id'] ?>" data-message="<?= clean($msg['message']) ?>">Reply</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- My Inspections Section -->
        <?php if (!empty($inspections)): ?>
            <h3 style="color:#ffd700; margin-top:30px;">My Car Inspections</h3>
            <div class="message-list" id="renter-inspection-list" data-live-refresh="renter_messages.php?ajax=1&section=inspection-list" data-live-target="#renter-inspection-list">
                <?php foreach ($inspections as $inspection): ?>
                    <div class="record-card">
                        <div class="record-info">
                            <p><strong>Vehicle:</strong> <?= clean($inspection['vehicle_name']) ?></p>
                            <p><strong>Owner:</strong> <?= clean($inspection['owner_name']) ?></p>
                            <?php if (!empty($inspection['reason'])): ?>
                                <p><strong>Reason:</strong> <?= clean($inspection['reason']) ?></p>
                            <?php endif; ?>
                            <p><small><?= formatDate($inspection['created_at']) ?></small></p>
                        </div>
                        <div style="display:flex; flex-wrap:wrap; gap:10px;">
                            <?php if (!empty($inspection['front_image'])): ?>
                                <a href="../<?= $inspection['front_image'] ?>" target="_blank" style="text-decoration:none;">
                                    <img src="../<?= $inspection['front_image'] ?>" style="width:60px; height:60px; object-fit:cover; border-radius:6px; border:1px solid #ffd700;">
                                    <small style="display:block; text-align:center; color:#aaa;">Front</small>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($inspection['back_image'])): ?>
                                <a href="../<?= $inspection['back_image'] ?>" target="_blank" style="text-decoration:none;">
                                    <img src="../<?= $inspection['back_image'] ?>" style="width:60px; height:60px; object-fit:cover; border-radius:6px; border:1px solid #ffd700;">
                                    <small style="display:block; text-align:center; color:#aaa;">Back</small>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($inspection['left_image'])): ?>
                                <a href="../<?= $inspection['left_image'] ?>" target="_blank" style="text-decoration:none;">
                                    <img src="../<?= $inspection['left_image'] ?>" style="width:60px; height:60px; object-fit:cover; border-radius:6px; border:1px solid #ffd700;">
                                    <small style="display:block; text-align:center; color:#aaa;">Left</small>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($inspection['right_image'])): ?>
                                <a href="../<?= $inspection['right_image'] ?>" target="_blank" style="text-decoration:none;">
                                    <img src="../<?= $inspection['right_image'] ?>" style="width:60px; height:60px; object-fit:cover; border-radius:6px; border:1px solid #ffd700;">
                                    <small style="display:block; text-align:center; color:#aaa;">Right</small>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

<!-- Reply Modal -->
    <div id="replyModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:2000; align-items:center; justify-content:center;">
        <div class="card" style="max-width:500px; width:90%; padding:20px; background:#2a2a2a; border-radius:12px;">
            <h3 style="margin-bottom:15px; color:#ffd700;">Reply to Message</h3>
            <form method="POST" id="replyForm">
                <input type="hidden" name="message_id" id="messageId">
                <input type="hidden" name="form_token" id="replyFormToken">
                <div class="form-group" style="margin-bottom:15px;">
                    <label style="color:#aaa;">Original Message</label>
                    <p id="originalMessage" style="background:#1e1e1e; padding:10px; border-radius:6px; margin-bottom:10px; color:#cfcfcf;"></p>
                </div>
                <div class="form-group" style="margin-bottom:15px;">
                    <label style="color:#aaa;">Your Reply</label>
                    <textarea name="reply" id="replyText" rows="4" style="width:100%; padding:10px; border-radius:6px; border:1px solid #555; background:#1e1e1e; color:#cfcfcf;" required></textarea>
                </div>
                <div style="display:flex; gap:10px;">
                    <button type="submit" class="btn-book" style="flex:1;">Send Reply</button>
                    <button type="button" onclick="closeReplyModal()" class="btn-return" style="flex:1;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
       document.addEventListener("DOMContentLoaded", function () {

    // ===============================
    // Mobile Menu
    // ===============================

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

    // ===============================
    // Star Rating
    // ===============================

    const stars = document.querySelectorAll(".star-rating input");
    const labels = document.querySelectorAll(".star-rating label");

    labels.forEach(function(label, index){

        label.addEventListener("mouseover", function(){

            const value = 5 - index;

            labels.forEach(function(item, i){

                item.style.color =
                    (5 - i) <= value
                    ? "#ffd700"
                    : "#444";

            });

        });

        label.addEventListener("mouseout", function(){

            const checked =
                document.querySelector(".star-rating input:checked");

            if(checked){

                const value = parseInt(checked.value);

                labels.forEach(function(item,i){

                    item.style.color =
                        (5 - i) <= value
                        ? "#ffd700"
                        : "#444";

                });

            }
            else{

                labels.forEach(function(item){

                    item.style.color="#444";

                });

            }

        });

    });

});
</script>

    <footer>
        <p>&copy; 2026 Carbnb Philippines. All rights reserved.</p>
    </footer>
</body>
</html>