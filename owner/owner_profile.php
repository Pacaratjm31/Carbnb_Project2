<?php
require_once __DIR__ . '/owner_logic.php';
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
$message = '';
$type = 'success';

// Handle document upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['document'])) {
    $docType = $_POST['document_type'] ?? '';
    $allowedTypes = [
        'drivers_license' => 'uploads/owners/drivers_license/',
        'nbi_clearance' => 'uploads/owners/nbi_clearance/',
        'intro_video' => 'uploads/owners/intro_video/'
    ];
    
    if (isset($allowedTypes[$docType]) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../' . $allowedTypes[$docType];
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $extension = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        $filename = $owner['id'] . "_" . time() . "_" . $docType . "." . $extension;
        $destination = $uploadDir . $filename;
        
        $isVideo = $docType === 'intro_video';
        $allowedMimeTypes = $isVideo ? ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'] : ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        $fileType = mime_content_type($_FILES['document']['tmp_name']);
        
        if (!in_array($fileType, $allowedMimeTypes)) {
            $message = $isVideo ? 'Only MP4, WebM, Ogg, and QuickTime videos are allowed.' : 'Only JPEG, PNG, WebP images and PDF files are allowed.';
            $type = 'error';
        } elseif ($_FILES['document']['size'] > ($isVideo ? 50 * 1024 * 1024 : 5 * 1024 * 1024)) {
            $message = $isVideo ? 'Video must be less than 50MB.' : 'File must be less than 5MB.';
            $type = 'error';
        } else {
            if (move_uploaded_file($_FILES['document']['tmp_name'], $destination)) {
                $relativePath = 'uploads/owners/' . $docType . '/' . $filename;
                
                // Check if document already exists and update, or insert new
                $checkDoc = $pdo->prepare("SELECT id FROM user_documents WHERE user_id = ? AND document_type = ?");
                $checkDoc->execute([$owner['id'], $docType]);
                
                if ($checkDoc->fetch()) {
                    $updateDoc = $pdo->prepare("UPDATE user_documents SET file_path = ? WHERE user_id = ? AND document_type = ?");
                    $updateDoc->execute([$relativePath, $owner['id'], $docType]);
                } else {
                    $insertDoc = $pdo->prepare("INSERT INTO user_documents (user_id, document_type, file_path) VALUES (?, ?, ?)");
                    $insertDoc->execute([$owner['id'], $docType, $relativePath]);
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

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['full_name'])) {
    $result = update_owner_profile($pdo, $owner['id'], $_POST);
    if ($result['success']) {
        $owner = get_current_owner($pdo);
        $message = 'Profile updated successfully.';
        $type = 'success';
    } else {
        $message = implode(' ', $result['errors']);
        $type = 'error';
    }
}

// Get owner documents
$docMap = [];
$docStmt = $pdo->prepare("SELECT document_type, file_path FROM user_documents WHERE user_id = ?");
$docStmt->execute([$owner['id']]);
$documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($documents as $doc) {
    $docMap[$doc['document_type']] = $doc['file_path'];
}

// Function to build proper file path for viewing
function build_owner_upload_path($value): string {
    if (empty($value)) {
        return '#';
    }
    
    if (preg_match('#^https?://#i', $value)) {
        return $value;
    }
    
    // owner_profile.php is in owner/ folder, so we need ../ for uploads
    return '../' . $value;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Owner Profile</title>
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
      <a class="active" href="owner_profile.php">Profile</a>
      <a href="owner_message.php">Messages</a>
      <a href="owner_reviews.php">Reviews</a>
      <a href="../auth/logout.php" class="topbar-action" style="display:block; margin-top:1rem; text-align:center;">Logout</a>
    </nav>
  </aside>

  <div class="main-content">
<header class="topbar">
      <button class="sidebar-toggle" type="button" aria-label="Open sidebar"></button>
      <h1>Profile</h1>
      <a class="topbar-action" href="owner_dashboard.php">Home</a>
    </header>

    <main class="page">
      <section class="form-card">
        <?php if ($message !== '') : ?>
          <div class="alert <?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <h2 class="section-title">Account Information</h2>
        <form method="post">
          <label>Full Name
            <input type="text" name="full_name" value="<?php echo htmlspecialchars($owner['full_name']); ?>" required>
          </label>
          <label>Email
            <input type="email" name="email" value="<?php echo htmlspecialchars($owner['email']); ?>" required>
          </label>
          <label>New Password
            <input type="password" name="password" placeholder="Leave blank to keep current password">
          </label>
          <button class="primary" type="submit">Update Profile</button>
        </form>
      </section>

      <section class="form-card">
        <h2 class="section-title">Submitted Documents</h2>
        <div class="stats-grid">
          <div class="stat-box">
            <h3>Driver's License</h3>
            <?php if (!empty($docMap['drivers_license'])): ?>
              <a href="<?= build_owner_upload_path($docMap['drivers_license']) ?>" target="_blank" class="text-info text-decoration-none">View Document</a>
            <?php else: ?>
              <p class="empty-state">No document uploaded</p>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" style="margin-top: 10px;">
              <input type="hidden" name="document_type" value="drivers_license">
              <div class="file-upload-wrap">
                <input type="file" name="document" id="docDriversLicense" accept=".jpg,.jpeg,.png,.pdf" class="file-input-hidden" required>
                <label for="docDriversLicense" class="file-upload-btn">Choose File</label>
                <span class="file-upload-name">No file chosen</span>
              </div>
              <button class="primary" type="submit" style="margin-top: 8px; font-size: 0.85rem; padding: 6px 12px;">Upload New</button>
            </form>
          </div>
          
          <div class="stat-box">
            <h3>NBI Clearance</h3>
            <?php if (!empty($docMap['nbi_clearance'])): ?>
              <a href="<?= build_owner_upload_path($docMap['nbi_clearance']) ?>" target="_blank" class="text-info text-decoration-none">View Document</a>
            <?php else: ?>
              <p class="empty-state">No document uploaded</p>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" style="margin-top: 10px;">
              <input type="hidden" name="document_type" value="nbi_clearance">
              <div class="file-upload-wrap">
                <input type="file" name="document" id="docNbiClearance" accept=".jpg,.jpeg,.png,.pdf" class="file-input-hidden" required>
                <label for="docNbiClearance" class="file-upload-btn">Choose File</label>
                <span class="file-upload-name">No file chosen</span>
              </div>
              <button class="primary" type="submit" style="margin-top: 8px; font-size: 0.85rem; padding: 6px 12px;">Upload New</button>
            </form>
          </div>
          
          <div class="stat-box">
            <h3>Introduction Video</h3>
            <?php if (!empty($docMap['intro_video'])): ?>
              <a href="<?= build_owner_upload_path($docMap['intro_video']) ?>" target="_blank" class="text-info text-decoration-none">View Video</a>
            <?php else: ?>
              <p class="empty-state">No document uploaded</p>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" style="margin-top: 10px;">
              <input type="hidden" name="document_type" value="intro_video">
              <div class="file-upload-wrap">
                <input type="file" name="document" id="docIntroVideo" accept="video/*" class="file-input-hidden" required>
                <label for="docIntroVideo" class="file-upload-btn">Choose File</label>
                <span class="file-upload-name">No file chosen</span>
              </div>
              <button class="primary" type="submit" style="margin-top: 8px; font-size: 0.85rem; padding: 6px 12px;">Upload New</button>
            </form>
          </div>
        </div>
      </section>
    </main>
  </div>

  <!-- ============================================
       MOBILE FILE UPLOAD (all 3 document forms)
       Same .file-upload-wrap pattern as add_vehicle.php's
       Vehicle Image field - hides the native <input
       type="file"> and swaps in a full-width, thumb-friendly
       button with a live filename readout. One shared script
       wires up every .file-input-hidden on the page instead
       of repeating the listener three times.
  ============================================ -->
  <script>
    (function () {
      document.querySelectorAll('.file-input-hidden').forEach(function (input) {
        var nameEl = input.parentElement.querySelector('.file-upload-name');
        if (!nameEl) return;

        input.addEventListener('change', function () {
          nameEl.textContent = (input.files && input.files.length > 0)
            ? input.files[0].name
            : 'No file chosen';
        });
      });
    })();
  </script>
  <script src="js/owner_script.js"></script>
</body>
</html>