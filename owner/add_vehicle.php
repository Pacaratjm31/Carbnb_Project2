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
$message = '';
$type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate form token to prevent duplicate submissions
    $tokenError = validate_form_token_or_error('add_vehicle');
    if ($tokenError) {
        $message = $tokenError;
        $type = 'error';
    } else {
        $result = create_vehicle($pdo, $owner['id'], $_POST, $_FILES);
        if ($result['success']) {
            header('Location: manage_vehicles.php?success=' . urlencode('Vehicle created and submitted for approval.'));
            exit();
        } else {
            $message = implode(' ', $result['errors']);
            $type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Add Vehicle</title>
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
      <a class="active" href="add_vehicle.php">Add Vehicle</a>
      <a href="manage_vehicles.php">Manage Vehicles</a>
      <a href="booking_requests.php">Booking Requests</a>
      <a href="vehicle_status.php">Vehicle Status</a>
      <a href="owner_income.php">Income</a>
      <a href="rental_history.php">Rental History</a>
      <a href="owner_profile.php">Profile</a>
      <a href="owner_message.php">Messages</a>
      <a href="owner_reviews.php">Reviews</a>
      <a href="../auth/logout.php" class="topbar-action" style="display:block; margin-top:1rem; text-align:center;">Logout</a>
    </nav>
  </aside>

  <div class="main-content">
<header class="topbar">
      <button class="sidebar-toggle" type="button" aria-label="Open sidebar"></button>
      <h1>Add New Vehicle</h1>
      <a class="topbar-action" href="owner_profile.php">Profile</a>
    </header>

    <main class="page">
      <section class="form-card">
        <?php if ($message !== '') : ?>
          <div class="alert <?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" id="addVehicleForm">
          <label>Vehicle Name
            <input type="text" name="name" required>
          </label>
          <?= form_token_input('add_vehicle') ?>
          <label>Model Year
            <input type="number" name="model_year" min="1900" required>
          </label>
          <label>Price per Day
            <input type="number" name="price_per_day" step="0.01" min="0" required>
          </label>
          <label>Category
            <select name="category">
              <option value="4-5_seater">4-5 Seater</option>
              <option value="6-7_seater">6-7 Seater</option>
              <option value="8-9_seater">8-9 Seater</option>
              <option value="10+_seater">10+ Seater</option>
            </select>
          </label>
          <label>Transmission
            <select name="transmission">
              <option value="automatic">Automatic</option>
              <option value="manual">Manual</option>
            </select>
          </label>
          <div class="form-group">
            <label for="vehicleImage">Vehicle Image</label>
            <!-- ============================================
                 MOBILE FILE UPLOAD
                 Native <input type="file"> renders as a tiny,
                 hard-to-tap control on phones. This hides it
                 and swaps in a full-width, thumb-friendly
                 "Choose Image" button plus a live filename
                 readout, driven by the script at the bottom
                 of this page. Falls back to the plain input
                 look until owner_style.css adds the
                 .file-upload-* styling.
            ============================================ -->
            <div class="file-upload-wrap">
              <input type="file" name="image" id="vehicleImage" accept="image/jpeg,image/png,image/webp" class="file-input-hidden">
              <label for="vehicleImage" class="file-upload-btn">Choose Image</label>
              <span class="file-upload-name" id="vehicleImageName">No file chosen</span>
            </div>
          </div>
          <label>Description
            <textarea name="description" rows="4" placeholder="Describe the vehicle"></textarea>
          </label>
          <!-- ============================================
               MOBILE STICKY SAVE BAR
               On desktop this is just the button in normal
               flow. On mobile, owner_style.css will pin it to
               the bottom of the screen so the Save action is
               always reachable without scrolling through the
               whole form.
          ============================================ -->
          <div class="form-actions-sticky">
            <button class="primary" type="submit">Save Vehicle</button>
          </div>
        </form>
      </section>
    </main>
  </div>

  <script>
    (function () {
      var fileInput = document.getElementById('vehicleImage');
      var fileName = document.getElementById('vehicleImageName');
      if (!fileInput || !fileName) return;

      fileInput.addEventListener('change', function () {
        if (fileInput.files && fileInput.files.length > 0) {
          fileName.textContent = fileInput.files[0].name;
        } else {
          fileName.textContent = 'No file chosen';
        }
      });
    })();
  </script>
  <script src="js/owner_script.js"></script>
</body>
</html>
