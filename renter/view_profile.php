<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include '../database/db.php';
$conn = $conn ?? $GLOBALS['conn'] ?? $GLOBALS['pdo'] ?? null;

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);
require_once __DIR__ . '/../auth/require_face_verified.php';

// Get renter account state
$stmt = $conn->prepare("SELECT id, full_name, email, role, status, disapproval_reason FROM users WHERE id = ? AND is_deleted = 0");
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
            : 'Your account is waiting for admin approval. Profile access is limited.',
        'restricted' => true
    ];
    
    // Show restricted page
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Profile Restricted | Carbnb</title>
        <link rel="stylesheet" href="../bootstrap-5.3.8-dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="css/renter_style.css?v=2">
        <link rel="stylesheet" href="css/renter_style_backup.css?v=4">
    </head>
    <body>
        <div class="container profile-container">
            <a href="browse.php" class="btn-back">← Back to Browse</a>
            <div class="profile-card">
                <h2>Profile Restricted</h2>
                <div class="approval-card">
                    <h3><?= htmlspecialchars($account_state['title']) ?></h3>
                    <p><?= htmlspecialchars($account_state['message']) ?></p>
                </div>
            </div>
        </div>
        <script src="../bootstrap-5.3.8-dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
    exit;
}

$user = $renter;
$message = '';
$type = 'success';

// Handle document upload for renters
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['document'])) {
    $docType = $_POST['document_type'] ?? '';
    $allowedTypes = [
        'id1' => 'uploads/renters/id1/',
        'id2' => 'uploads/renters/id2/',
        'proof_of_billing' => 'uploads/renters/proof_of_billing/'
    ];
    
    if (isset($allowedTypes[$docType]) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../' . $allowedTypes[$docType];
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $extension = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        $filename = $user_id . "_" . time() . "_" . $docType . "." . $extension;
        $destination = $uploadDir . $filename;
        
        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        $fileType = mime_content_type($_FILES['document']['tmp_name']);
        
        if (!in_array($fileType, $allowedMimeTypes)) {
            $message = 'Only JPEG, PNG, WebP images and PDF files are allowed.';
            $type = 'error';
        } elseif ($_FILES['document']['size'] > 5 * 1024 * 1024) {
            $message = 'File must be less than 5MB.';
            $type = 'error';
        } else {
            if (move_uploaded_file($_FILES['document']['tmp_name'], $destination)) {
                $relativePath = 'uploads/renters/' . $docType . '/' . $filename;
                
                // Check if document already exists and update, or insert new
                $checkDoc = $conn->prepare("SELECT id FROM user_documents WHERE user_id = ? AND document_type = ?");
                $checkDoc->execute([$user_id, $docType]);
                
                if ($checkDoc->fetch()) {
                    $updateDoc = $conn->prepare("UPDATE user_documents SET file_path = ? WHERE user_id = ? AND document_type = ?");
                    $updateDoc->execute([$relativePath, $user_id, $docType]);
                } else {
                    $insertDoc = $conn->prepare("INSERT INTO user_documents (user_id, document_type, file_path) VALUES (?, ?, ?)");
                    $insertDoc->execute([$user_id, $docType, $relativePath]);
                }
                
                $message = ucfirst(str_replace('_', ' ', $docType)) . ' uploaded successfully.';
                $type = 'success';
            } else {
                $message = 'Failed to upload file. Please try again.';
                $type = 'error';
            }
        }
    }
}

// Helper functions for status display
function renter_approval_label(string $status): string {
    return match ($status) {
        'approved' => 'Approved',
        'pending' => 'Pending',
        'disapproved' => 'Disapproved',
        default => ucfirst($status),
    };
}

function renter_approval_badge_class(string $status): string {
    return match ($status) {
        'approved' => 'approved',
        'pending' => 'pending',
        'disapproved' => 'disapproved',
        default => 'pending',
    };
}

// Get user documents
try {
    $docStmt = $conn->prepare("SELECT document_type, file_path FROM user_documents WHERE user_id = ? ORDER BY created_at DESC");
    $docStmt->execute([$user_id]);
    $documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Error: ' . $e->getMessage());
}

$docMap = [];
foreach ($documents as $doc) {
    $docMap[$doc['document_type']] = $doc['file_path'];
}

// Function to build proper file path for viewing
function build_upload_path($value): string {
    if (empty($value)) {
        return '#';
    }
    
    // If it's already a full URL, return as is
    if (preg_match('#^https?://#i', $value)) {
        return $value;
    }
    
    // The file_path is stored as relative path from project root (e.g., uploads/renters/id1/123_123456_id1.jpg)
    // We need to serve it from the current file location
    // view_profile.php is in renter/ folder, so we need ../ for uploads
    return '../' . $value;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | Carbnb</title>
    <link rel="stylesheet" href="../bootstrap-5.3.8-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/renter_style.css?v=2">
    <link rel="stylesheet" href="css/renter_style_backup.css?v=4">
</head>
<body>

<div class="container profile-container">
    <a href="browse.php" class="btn-back">← Back to Browse</a>

    <?php if ($message !== '') : ?>
        <div class="alert <?= htmlspecialchars($type) ?>" style="margin-bottom: 20px;">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="profile-card">
        <div class="profile-header d-flex justify-content-between align-items-center">
            <div>
                <h1 class="mb-0">My <span class="text-gold">Profile</span></h1>
                <p class="text-secondary mb-0">Role: <span class="text-capitalize text-white"><?= htmlspecialchars($user['role']) ?></span></p>
            </div>
            <div>
                <span class="status-badge <?= htmlspecialchars(renter_approval_badge_class($user['status'] ?? 'pending')) ?>">
                    <?= htmlspecialchars(renter_approval_label($user['status'] ?? 'pending')) ?>
                </span>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <p class="info-label">Full Name</p>
                <p class="info-value"><?= htmlspecialchars($user['full_name']) ?></p>
            </div>
            <div class="col-md-6">
                <p class="info-label">Email Address</p>
                <p class="info-value"><?= htmlspecialchars($user['email']) ?></p>
            </div>
        </div>

        <hr class="profile-divider">

        <h5 class="text-gold mb-3">Submitted Documents</h5>
        <div class="row g-3">
            <?php if ($user['role'] === 'owner'): ?>
                <div class="col-md-6">
                    <div class="doc-box">
                        <p class="info-label mb-1">Driver's License</p>
                        <?php if (!empty($docMap['drivers_license'])): ?>
                            <a href="<?= build_upload_path($docMap['drivers_license']) ?>" target="_blank" class="text-info text-decoration-none">View Document</a>
                        <?php else: ?>
                            <span class="text-muted">No document uploaded</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="doc-box">
                        <p class="info-label mb-1">NBI Clearance</p>
                        <?php if (!empty($docMap['nbi_clearance'])): ?>
                            <a href="<?= build_upload_path($docMap['nbi_clearance']) ?>" target="_blank" class="text-info text-decoration-none">View Document</a>
                        <?php else: ?>
                            <span class="text-muted">No document uploaded</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="doc-box">
                        <p class="info-label mb-1">Introduction Video</p>
                        <?php if (!empty($docMap['intro_video'])): ?>
                            <a href="<?= build_upload_path($docMap['intro_video']) ?>" target="_blank" class="text-info text-decoration-none">View Video</a>
                        <?php else: ?>
                            <span class="text-muted">No document uploaded</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="col-md-4">
                    <div class="doc-box">
                        <p class="info-label mb-1">Primary ID</p>
                        <?php if (!empty($docMap['id1'])): ?>
                            <a href="<?= build_upload_path($docMap['id1']) ?>" target="_blank" class="text-info text-decoration-none">View ID</a>
                        <?php else: ?>
                            <span class="text-muted">No document uploaded</span>
                        <?php endif; ?>
                        <form method="post" enctype="multipart/form-data" style="margin-top: 10px;">
                            <input type="hidden" name="document_type" value="id1">
                            <input type="file" name="document" accept=".jpg,.jpeg,.png,.pdf" required>
                            <button class="btn-primary" type="submit" style="margin-top: 8px; font-size: 0.85rem; padding: 6px 12px;">Upload New</button>
                        </form>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="doc-box">
                        <p class="info-label mb-1">Secondary ID</p>
                        <?php if (!empty($docMap['id2'])): ?>
                            <a href="<?= build_upload_path($docMap['id2']) ?>" target="_blank" class="text-info text-decoration-none">View ID</a>
                        <?php else: ?>
                            <span class="text-muted">No document uploaded</span>
                        <?php endif; ?>
                        <form method="post" enctype="multipart/form-data" style="margin-top: 10px;">
                            <input type="hidden" name="document_type" value="id2">
                            <input type="file" name="document" accept=".jpg,.jpeg,.png,.pdf" required>
                            <button class="btn-primary" type="submit" style="margin-top: 8px; font-size: 0.85rem; padding: 6px 12px;">Upload New</button>
                        </form>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="doc-box">
                        <p class="info-label mb-1">Proof of Billing</p>
                        <?php if (!empty($docMap['proof_of_billing'])): ?>
                            <a href="<?= build_upload_path($docMap['proof_of_billing']) ?>" target="_blank" class="text-info text-decoration-none">View File</a>
                        <?php else: ?>
                            <span class="text-muted">No document uploaded</span>
                        <?php endif; ?>
                        <form method="post" enctype="multipart/form-data" style="margin-top: 10px;">
                            <input type="hidden" name="document_type" value="proof_of_billing">
                            <input type="file" name="document" accept=".jpg,.jpeg,.png,.pdf" required>
                            <button class="btn-primary" type="submit" style="margin-top: 8px; font-size: 0.85rem; padding: 6px 12px;">Upload New</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="../bootstrap-5.3.8-dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>