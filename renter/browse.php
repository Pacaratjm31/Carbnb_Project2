<?php
/**
 * browse.php
 * Vehicle browsing page for renters with JSON approval status checking
 */

// ============================================
// 1. INITIALIZATION & SESSION
// ============================================
include '../database/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$conn = $conn ?? $GLOBALS['conn'] ?? $GLOBALS['pdo'] ?? null;

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);
require_once __DIR__ . '/../auth/require_face_verified.php';

// ============================================
// 2. GET RENTER DATA
// ============================================
$stmt = $conn->prepare("SELECT id, full_name, status, disapproval_reason FROM users WHERE id = ? AND is_deleted = 0");
$stmt->execute([$user_id]);
$renter = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$renter) {
    session_destroy();
    header('Location: ../auth/login.php');
    exit;
}

// ============================================
// 3. HELPER FUNCTIONS
// ============================================
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

function get_renter_account_state(array $renter): array {
    $status = $renter['status'] ?? 'pending';
    $reason = trim($renter['disapproval_reason'] ?? '');

    if ($status === 'approved') {
        return [
            'status' => 'approved',
            'title' => 'Account Approved',
            'message' => 'Your renter account has been approved by admin. Full access is enabled.',
            'restricted' => false,
        ];
    }

    if ($status === 'disapproved') {
        return [
            'status' => 'disapproved',
            'title' => 'Account Disapproved',
            'message' => $reason !== '' ? $reason : 'Your renter account was disapproved by admin.',
            'restricted' => true,
        ];
    }

    return [
        'status' => 'pending',
        'title' => 'Pending Admin Approval',
        'message' => 'Your renter account is waiting for admin approval. You can view the catalog, but booking and other renter actions are temporarily disabled.',
        'restricted' => true,
    ];
}

function build_vehicle_image_path($value): string {
    if (empty($value)) {
        return '../uploads/vehicles/default-car.svg';
    }

    if (preg_match('#^https?://#i', $value)) {
        return $value;
    }

    if (preg_match('#^uploads/#', $value)) {
        return '../' . $value;
    }

    if (strpos($value, '../') === 0 || strpos($value, '/') === 0) {
        return $value;
    }

    return '../uploads/vehicles/' . basename($value);
}

// ============================================
// 4. GET ACCOUNT STATE
// ============================================
$account_state = get_renter_account_state($renter);

// ============================================
// 5. FILTER HANDLING
// ============================================
$filter = trim($_GET['seater'] ?? '');
$categoryMap = [
    '4-5' => '4-5_seater',
    '4-5_seater' => '4-5_seater',
    '6-7' => '6-7_seater',
    '6-7_seater' => '6-7_seater',
    '8-9' => '8-9_seater',
    '8-9_seater' => '8-9_seater',
    '10+' => '10+_seater',
    '10+_seater' => '10+_seater',
];
$normalizedFilter = $categoryMap[$filter] ?? '';

// ============================================
// 6. FUNCTION TO RENDER CAR CARD
// ============================================
function renderCarCard($car, $user_id, $conn, $account_state) {
    $status = $car['status'] ?? 'available';
    $approval = $car['approval_status'] ?? 'pending';
    $imgPath = build_vehicle_image_path($car['car_image'] ?? '');

    if ($status === 'available') {
        $label = 'Available';
        $class = 'status-available';
    } elseif ($status === 'rented') {
        $label = 'In Use';
        $class = 'status-inuse';
    } else {
        $label = 'Maintenance';
        $class = 'status-maintenance';
    }
    
    $hasCompletedBooking = false;
    $hasReviewed = false;
    if (!$account_state['restricted']) {
        $stmt = $conn->prepare("SELECT id FROM bookings WHERE renter_id = ? AND vehicle_id = ? AND status = 'completed' LIMIT 1");
        $stmt->execute([$user_id, $car['id']]);
        $hasCompletedBooking = (bool) $stmt->fetch();
        
        $stmt = $conn->prepare("SELECT id FROM reviews WHERE renter_id = ? AND vehicle_id = ? LIMIT 1");
        $stmt->execute([$user_id, $car['id']]);
        $hasReviewed = (bool) $stmt->fetch();
    }
    
    ?>
    <div class="car-card" data-vehicle-id="<?= (int) $car['id'] ?>">
        <div class="text-center">
            <?php if ($approval === 'approved'): ?>
                <span class="approval-badge bg-approved">Admin Approved</span>
            <?php elseif ($approval === 'disapproved'): ?>
                <span class="approval-badge bg-rejected">Admin Rejected</span>
            <?php else: ?>
                <span class="approval-badge bg-pending">Pending Review</span>
            <?php endif; ?>
        </div>

        <img src="<?= htmlspecialchars($imgPath) ?>"
             class="car-img"
             alt="<?= htmlspecialchars($car['vehicle_name']) ?>"
             loading="lazy"
             decoding="async"
             width="300"
             height="200"
             onerror="this.src='../uploads/vehicles/default-car.svg'; this.onerror=null;">

        <h3><?= htmlspecialchars($car['vehicle_name']) ?></h3>

        <div class="car-details">
            <p><strong>Category:</strong> <?= htmlspecialchars(str_replace('_', ' ', $car['category'] ?? '')) ?></p>
            <p><strong>Owner:</strong> <?= htmlspecialchars($car['owner_name']) ?></p>
            <p><strong>Rate:</strong> ₱<?= number_format((float) $car['rate'], 2) ?>/day</p>
        </div>

        <span class="status <?= $class ?>">
            <?= $label ?>
        </span>

        <div class="card-actions">
            <div class="action-buttons">
                <a href="vehicle_details.php?car_id=<?= (int) $car['id'] ?>" class="book-btn">View Details</a><br><br>
                <a href="comment_rate.php?vehicle_id=<?= (int) $car['id'] ?>" class="book-btn" style="background:#17a2b8;">Comment & Rate</a><br><br>
                <?php if ($account_state['restricted']): ?>
                    <button class="book-btn disabled" disabled>
                        <?= $account_state['status'] === 'disapproved' ? 'Access Restricted' : 'Approval Pending' ?>
                    </button>
                <?php elseif ($approval === 'approved'): ?>
                    <?php if ($status === 'available'): ?>
                        <button class="book-btn book-now-btn" data-car-id="<?= (int) $car['id'] ?>">Book Now</button>
                    <?php endif; ?>
                    <?php if ($hasCompletedBooking && !$hasReviewed): ?>
                        <a href="comment_rate.php?vehicle_id=<?= (int) $car['id'] ?>" class="book-btn" style="background:#17a2b8;">
                            Rate & Comment
                        </a><br><br>
                    <?php elseif ($hasReviewed): ?>
                        <button class="book-btn disabled" disabled>Review Submitted</button>
                    <?php endif; ?>
                <?php elseif ($approval === 'disapproved'): ?>
                    <button class="book-btn disabled" disabled>Rejected</button>
                    <p class="rejection-text">
                        Reason: <?= htmlspecialchars($car['rejection_reason'] ?? 'Contact admin') ?>
                    </p>
                <?php elseif ($approval === 'pending'): ?>
                    <button class="book-btn disabled" disabled>Wait for Approval</button>
                <?php else: ?>
                    <button class="book-btn disabled" disabled>Unavailable</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

// ============================================
// 7. AJAX ENDPOINT FOR VEHICLE LIST (HTML)
// ============================================
$ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
if ($ajax && ($_GET['section'] ?? '') === 'vehicle-list') {
    header('Content-Type: text/html; charset=utf-8');
    
    try {
        $sql = "
            SELECT v.id, v.name AS vehicle_name, v.price_per_day AS rate, v.image AS car_image,
                   v.availability_status AS status, v.approval_status, v.approval_feedback AS rejection_reason,
                   v.category, u.full_name AS owner_name
            FROM vehicles v
            JOIN users u ON v.owner_id = u.id
            WHERE v.is_deleted = 0 AND v.approval_status = 'approved'
        ";

        if ($normalizedFilter !== '') {
            $sql .= ' AND v.category = :category';
        }

        $sql .= ' ORDER BY v.created_at DESC';

        $stmt = $conn->prepare($sql);

        if ($normalizedFilter !== '') {
            $stmt->execute(['category' => $normalizedFilter]);
        } else {
            $stmt->execute();
        }

        $cars = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($cars)) {
            echo '<div class="no-results"><h3>No vehicles found in this category.</h3><a href="browse.php" class="blue">View all cars</a></div>';
        } else {
            foreach ($cars as $car) {
                renderCarCard($car, $user_id, $conn, $account_state);
            }
        }
        exit;
    } catch (PDOException $e) {
        echo '<div class="no-results"><h3>Error loading vehicles</h3></div>';
        exit;
    }
}

// ============================================
// 8. MAIN VEHICLE QUERY
// ============================================
try {
    $sql = "
        SELECT v.id, v.name AS vehicle_name, v.price_per_day AS rate, v.image AS car_image,
               v.availability_status AS status, v.approval_status, v.approval_feedback AS rejection_reason,
               v.category, u.full_name AS owner_name
        FROM vehicles v
        JOIN users u ON v.owner_id = u.id
        WHERE v.is_deleted = 0 AND v.approval_status = 'approved'
    ";

    if ($normalizedFilter !== '') {
        $sql .= ' AND v.category = :category';
    }

    $sql .= ' ORDER BY v.created_at DESC';

    $stmt = $conn->prepare($sql);

    if ($normalizedFilter !== '') {
        $stmt->execute(['category' => $normalizedFilter]);
    } else {
        $stmt->execute();
    }

    $cars = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
}

// ============================================
// 9. HTML OUTPUT
// ============================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Browse Cars | Carbnb</title>
<link rel="stylesheet" href="../bootstrap-5.3.8-dist/css/bootstrap.min.css">
<link rel="stylesheet" href="css/renter_style.css?v=4">
<link rel="stylesheet" href="css/renter_style_backup.css?v=4">
<style>
.car-img {
    background: #f0f0f0;
    min-height: 200px;
    transition: opacity 0.3s ease;
    object-fit: cover;
    width: 100%;
    height: auto;
    aspect-ratio: 16/10;
}
.car-img.loaded {
    opacity: 1;
}
.car-img:not(.loaded) {
    opacity: 0;
}
.car-img.loading {
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: shimmer 1.5s infinite;
}
@keyframes shimmer {
    0% { background-position: -200% 0; }
    100% { background-position: 200% 0; }
}

/* ============================================================
   LOCATION PERMISSION MODAL
   ============================================================ */
.location-modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.6);
    z-index: 9999;
    justify-content: center;
    align-items: center;
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
}
.location-modal-overlay.show {
    display: flex;
}
.location-modal {
    background: #fff;
    border-radius: 20px;
    padding: 30px 28px;
    max-width: 420px;
    width: 92%;
    box-shadow: 0 25px 80px rgba(0,0,0,0.35);
    text-align: center;
    animation: modalFadeIn 0.4s ease;
    position: relative;
    margin: 20px;
}
@keyframes modalFadeIn {
    from { opacity: 0; transform: scale(0.92) translateY(-20px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}
.location-modal .modal-icon {
    font-size: 48px;
    margin-bottom: 12px;
}
.location-modal h3 {
    margin: 0 0 8px 0;
    color: #1a1a2e;
    font-size: 20px;
}
.location-modal p {
    color: #4b5563;
    margin-bottom: 20px;
    font-size: 14px;
    line-height: 1.6;
}
.location-modal .modal-actions {
    display: flex;
    gap: 12px;
    justify-content: center;
    flex-wrap: wrap;
}
.location-modal .modal-actions button {
    padding: 12px 28px;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    font-size: 15px;
    transition: all 0.2s;
    min-width: 110px;
}
.location-modal .btn-allow {
    background: #0d6efd;
    color: #fff;
}
.location-modal .btn-allow:hover:not(:disabled) {
    background: #0b5ed7;
}
.location-modal .btn-allow:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
.location-modal .btn-deny {
    background: #e5e7eb;
    color: #1a1a2e;
}
.location-modal .btn-deny:hover {
    background: #d1d5db;
}
.location-modal .permission-status {
    margin-top: 16px;
    font-size: 14px;
    color: #6b7280;
    min-height: 24px;
    padding: 8px 12px;
    border-radius: 8px;
    transition: all 0.3s;
}
.location-modal .permission-status.loading {
    color: #0d6efd;
    background: #eff6ff;
}
.location-modal .permission-status.success {
    color: #16a34a;
    background: #dcfce7;
}
.location-modal .permission-status.error {
    color: #dc2626;
    background: #fee2e2;
}
.location-modal .permission-status .spinner {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid #e5e7eb;
    border-top-color: #0d6efd;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin-right: 8px;
    vertical-align: middle;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Status badge colors */
.status-badge.approved {
    background: #28a745;
    color: white;
}
.status-badge.pending {
    background: #ffc107;
    color: #212529;
}
.status-badge.disapproved {
    background: #dc3545;
    color: white;
}
.status-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 600;
    display: inline-block;
}
</style>
</head>
<body data-user-id="<?php echo (int) $user_id; ?>" data-current-status="<?php echo htmlspecialchars($renter['status'] ?? 'pending'); ?>">

<!-- ============================================================
     LOCATION PERMISSION MODAL
     ============================================================ -->
<div id="locationModal" class="location-modal-overlay">
    <div class="location-modal">
        <div class="modal-icon">📍</div>
        <h3>Location Permission Required</h3>
        <p>To complete your booking, we need access to your real GPS location. This helps us ensure a safe and secure rental experience.</p>
        <div class="modal-actions">
            <button id="modalAllowBtn" class="btn-allow">Allow Location</button>
            <button id="modalDenyBtn" class="btn-deny">Don't Allow</button>
        </div>
        <div id="modalStatus" class="permission-status">Please allow location access to continue booking.</div>
    </div>
</div>

<!-- ============================================================
     TOP NAVIGATION
     ============================================================ -->
<div class="top-nav">
    <div class="nav-left">
        <h2>Carbnb</h2>
    </div>
    <button id="mobileMenuBtn" class="mobile-menu-btn">☰ Menu</button>
    <div class="nav-right" id="mobileMenu">
        <a href="browse.php" class="nav-all-cars active">All Cars</a>
        <?php if ($account_state['restricted']): ?>
            <span class="nav-link disabled-link">📋 My Records</span>
            <span class="nav-link disabled-link">👤 My Profile</span>
            <span class="nav-link disabled-link">💬 Messages</span>
        <?php else: ?>
            <a href="record.php" class="nav-my-records">My Records</a>
            <a href="view_profile.php" class="nav-my-profile">My Profile</a>
            <a href="renter_messages.php" class="nav-my-messages">Messages</a>
        <?php endif; ?>
        <a href="../auth/logout.php" class="logout-link">Logout</a>
    </div>
</div>

<!-- ============================================================
     HEADER
     ============================================================ -->
<div class="header-text">
    <h1>Browse <span class="blue">Available</span> <span class="orange">Cars</span></h1>
    <?php if (!empty($normalizedFilter)): ?>
        <p>Filtering by: <span class="blue"><?= htmlspecialchars($filter) ?></span></p>
    <?php else: ?>
        <p>Find the perfect ride for your next trip.</p>
    <?php endif; ?>
</div>

<!-- ============================================================
     STATUS SECTION
     ============================================================ -->
<div class="status-section">
    <div class="status-banner <?= $account_state['restricted'] ? 'warning' : 'success' ?>">
        <div class="banner-content">
            <h3 class="banner-title"><?= htmlspecialchars($account_state['title']) ?></h3>
            <p class="banner-message"><?= htmlspecialchars($account_state['message']) ?></p>
        </div>
        <span id="renter-approval-badge" class="status-badge <?= htmlspecialchars(renter_approval_badge_class($renter['status'] ?? 'pending')) ?>">
            <?= htmlspecialchars(renter_approval_label($renter['status'] ?? 'pending')) ?>
        </span>
    </div>
    <div class="status-card">
        <h3 class="status-card-title">Account Status</h3>
        <div class="status-grid">
            <div class="status-item">
                <span class="status-label">Account Status</span>
                <span id="renter-approval-status" class="status-badge <?= htmlspecialchars(renter_approval_badge_class($renter['status'] ?? 'pending')) ?>">
                    <?= htmlspecialchars(renter_approval_label($renter['status'] ?? 'pending')) ?>
                </span>
            </div>
            <div class="status-item">
                <span class="status-label">Admin Note</span>
                <span id="renter-approval-note" class="status-note"><?= htmlspecialchars(($renter['disapproval_reason'] ?? '') !== '' ? $renter['disapproval_reason'] : 'No admin note yet.') ?></span>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     FILTER BAR
     ============================================================ -->
<div class="filter-bar">
    <a href="browse.php" data-filter="all" class="<?= empty($normalizedFilter) ? 'active' : '' ?>">All</a>
    <a href="browse.php?seater=4-5" data-filter="4-5" class="<?= $normalizedFilter === '4-5_seater' ? 'active' : '' ?>">4-5 Seater</a>
    <a href="browse.php?seater=6-7" data-filter="6-7" class="<?= $normalizedFilter === '6-7_seater' ? 'active' : '' ?>">6-7 Seater</a>
    <a href="browse.php?seater=8-9" data-filter="8-9" class="<?= $normalizedFilter === '8-9_seater' ? 'active' : '' ?>">8-9 Seater</a>
    <a href="browse.php?seater=10+" data-filter="10+" class="<?= $normalizedFilter === '10+_seater' ? 'active' : '' ?>">10+ Seater</a>
</div>

<!-- ============================================================
     CAR CONTAINER
     ============================================================ -->
<div class="car-container" id="carContainer">
<?php if (empty($cars)): ?>
    <div class="no-results">
        <h3>No vehicles found in this category.</h3>
        <a href="browse.php" class="blue">View all cars</a>
    </div>
<?php else: ?>
    <?php foreach ($cars as $car): ?>
        <?php renderCarCard($car, $user_id, $conn, $account_state); ?>
    <?php endforeach; ?>
<?php endif; ?>
</div>

<!-- ============================================================
     FOOTER
     ============================================================ -->
<footer>
    <p>&copy; 2026 Carbnb Philippines. All rights reserved.</p>
</footer>

<!-- ============================================================
     JAVASCRIPT
     ============================================================ -->
<script>
(function () {
    'use strict';
    
    // ============================================================
    // PAGE ELEMENTS
    // ============================================================
    const userId = document.body.dataset.userId;
    const currentStatus = document.body.dataset.currentStatus;

    const statusBadge = document.getElementById("renter-approval-status");
    const approvalNote = document.getElementById("renter-approval-note");
    const approvalBannerBadge = document.getElementById("renter-approval-badge");

    const mobileMenuBtn = document.getElementById("mobileMenuBtn");
    const mobileMenu = document.getElementById("mobileMenu");

    const carContainer = document.getElementById("carContainer");
    const currentFilter = new URLSearchParams(window.location.search).get('seater') || 'all';

    // ============================================================
    // LOCATION PERMISSION MODAL
    // ============================================================
    const modal = document.getElementById('locationModal');
    const allowBtn = document.getElementById('modalAllowBtn');
    const denyBtn = document.getElementById('modalDenyBtn');
    const modalStatus = document.getElementById('modalStatus');
    let pendingCarId = null;
    let watchId = null;
    let isLocationGranted = false;
    let isTracking = false;
    let isProcessing = false;

    function setModalStatus(message, type) {
        modalStatus.textContent = message;
        modalStatus.className = 'permission-status';
        if (type) {
            modalStatus.classList.add(type);
        }
    }

    // ============================================================
    // DEVICE DETECTION
    // ============================================================
    function isMobileDevice() {
        return /Android|iPhone|iPad|iPod|BlackBerry|Windows Phone|Mobile|webOS|IEMobile|Opera Mini/i.test(
            navigator.userAgent || ''
        );
    }

    // ============================================================
    // IMAGE LAZY LOADING
    // ============================================================
    function handleImageLoad(img) {
        img.classList.remove('loading');
        img.classList.add('loaded');
    }

    function handleImageError(img) {
        img.classList.remove('loading');
        img.classList.add('loaded');
        if (!img.src.includes('default-car.svg')) {
            img.src = '../uploads/vehicles/default-car.svg';
        }
    }

    document.querySelectorAll('.car-img').forEach(function(img) {
        img.classList.add('loading');
        if (img.complete) {
            handleImageLoad(img);
        } else {
            img.addEventListener('load', function() { handleImageLoad(img); });
            img.addEventListener('error', function() { handleImageError(img); });
        }
    });

    // ============================================================
    // AUTO-REFRESH VEHICLE LIST
    // ============================================================
    function refreshVehicleList() {
        if (!carContainer) return;
        var filterParam = currentFilter !== 'all' ? '&seater=' + encodeURIComponent(currentFilter) : '';
        var url = 'browse.php?ajax=1&section=vehicle-list' + filterParam;
        
        fetch(url)
            .then(function(response) { 
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.text(); 
            })
            .then(function(html) {
                if (html.trim().length > 0) {
                    carContainer.innerHTML = html;
                    document.querySelectorAll('.car-img').forEach(function(img) {
                        img.classList.add('loading');
                        if (img.complete) {
                            handleImageLoad(img);
                        } else {
                            img.addEventListener('load', function() { handleImageLoad(img); });
                            img.addEventListener('error', function() { handleImageError(img); });
                        }
                    });
                    bindBookNowButtons();
                }
            })
            .catch(function(error) { 
                console.log('Auto-refresh failed:', error); 
            });
    }
    setInterval(refreshVehicleList, 30000);

    // ============================================================
    // MOBILE MENU
    // ============================================================
    if (mobileMenuBtn && mobileMenu) {
        mobileMenuBtn.addEventListener("click", function () {
            mobileMenu.classList.toggle("show");
            mobileMenuBtn.innerHTML = mobileMenu.classList.contains("show") ? "✖ Close" : "☰ Menu";
        });
        window.addEventListener("resize", function () {
            if (window.innerWidth > 768) {
                mobileMenu.classList.remove("show");
                mobileMenuBtn.innerHTML = "☰ Menu";
            }
        });
    }

    // ============================================================
    // ACCOUNT APPROVAL CHECKER - PRESERVED
    // ============================================================
    if (userId && statusBadge && currentStatus === "pending") {
        console.log('🔍 Starting approval status check for user:', userId);
        
        let checkCount = 0;
        const maxChecks = 60;
        
        const pollInterval = setInterval(function () {
            checkCount++;
            
            if (checkCount > maxChecks) {
                console.log('⏹️ Stopped polling after ' + maxChecks + ' attempts');
                clearInterval(pollInterval);
                return;
            }
            
            const checkUrl = '/renter/approval_status.json';
            
            console.log('📡 Checking approval status (attempt ' + checkCount + ')...');
            console.log('📡 URL:', checkUrl);
            
            fetch(checkUrl, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                }
            })
            .then(function(response) {
                console.log('📡 Response status:', response.status);
                
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                }
                
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    console.warn('⚠️ Expected JSON but got:', contentType);
                    throw new Error('Server returned non-JSON response');
                }
                
                return response.json();
            })
            .then(function(data) {
                console.log('📡 Response data:', data);
                
                if (!data.success) {
                    console.warn('⚠️ API error:', data.error);
                    return;
                }
                
                if (data.status === "approved") {
                    console.log('✅ Account approved! Refreshing page...');
                    clearInterval(pollInterval);
                    
                    if (statusBadge) {
                        statusBadge.textContent = "Approved";
                        statusBadge.className = "status-badge approved";
                    }
                    if (approvalBannerBadge) {
                        approvalBannerBadge.textContent = "Approved";
                        approvalBannerBadge.className = "status-badge approved";
                    }
                    
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } 
                else if (data.status === "disapproved") {
                    console.log('❌ Account disapproved');
                    clearInterval(pollInterval);
                    
                    if (statusBadge) {
                        statusBadge.textContent = "Disapproved";
                        statusBadge.className = "status-badge disapproved";
                    }
                    if (approvalNote) {
                        approvalNote.textContent = data.disapproval_reason || "Your account was disapproved.";
                    }
                    if (approvalBannerBadge) {
                        approvalBannerBadge.textContent = "Disapproved";
                        approvalBannerBadge.className = "status-badge disapproved";
                    }
                }
                else if (data.status === "pending") {
                    console.log('⏳ Still pending approval...');
                }
                else {
                    console.log('ℹ️ Unknown status:', data.status);
                }
            })
            .catch(function(err) { 
                console.error('❌ Approval check failed:', err.message);
                console.log('🔄 Will retry in 5 seconds...');
            });
            
        }, 5000);
    }

    // ============================================================
    // GPS FUNCTIONS
    // ============================================================
    function sendLocationToServer(position) {
        var latitude = position.coords.latitude;
        var longitude = position.coords.longitude;
        var accuracy = position.coords.accuracy || 0;
        var recorded_at = new Date().toISOString().slice(0,19).replace("T"," ");

        console.log('📍 Sending GPS:', latitude, longitude, accuracy);

        var formData = new URLSearchParams();
        formData.append('latitude', latitude);
        formData.append('longitude', longitude);
        formData.append('accuracy', accuracy);
        formData.append('recorded_at', recorded_at);

        var apiUrl = '/admin/location_tracker.php';
        
        console.log('📍 API URL:', apiUrl);

        return fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: formData.toString()
        })
        .then(function(response) {
            console.log('📡 Response status:', response.status);
            
            if (!response.ok) {
                throw new Error('HTTP ' + response.status + ': ' + response.statusText);
            }
            
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                console.warn('⚠️ Expected JSON but got:', contentType);
                return response.text().then(function(text) {
                    console.error('❌ Server returned non-JSON response:', text.substring(0, 200));
                    throw new Error('Server returned non-JSON response. Expected JSON, got ' + contentType);
                });
            }
            
            return response.json();
        })
        .then(function(data) {
            console.log('📡 Server response:', data);
            if (!data.success) {
                throw new Error(data.message || 'Server returned success: false');
            }
            return data;
        });
    }

    // ============================================================
    // startContinuousTracking - KEPT EXACTLY AS IS
    // ============================================================
    function startContinuousTracking() {
        // Prevent multiple simultaneous GPS requests
        if (isProcessing) {
            console.log('⏳ Already processing GPS request');
            return false;
        }
        isProcessing = true;
        
        if (!navigator.geolocation) {
            setModalStatus('❌ Geolocation is not supported on this device.', 'error');
            allowBtn.disabled = false;
            allowBtn.textContent = 'Try Again';
            isProcessing = false;
            return false;
        }

        setModalStatus('📡 Getting real GPS location...', 'loading');
        allowBtn.disabled = true;
        allowBtn.textContent = 'Getting GPS...';

        var gpsTimeout = setTimeout(function() {
            console.warn('⏱️ GPS timeout');
            setModalStatus('⏱️ GPS request timed out. Please ensure GPS is enabled.', 'error');
            allowBtn.disabled = false;
            allowBtn.textContent = 'Try Again';
            isProcessing = false;
        }, 20000);

        navigator.geolocation.getCurrentPosition(
            function(position) {
                clearTimeout(gpsTimeout);
                console.log('✅ GPS acquired:', position.coords.latitude, position.coords.longitude);
                
                setModalStatus('📍 GPS acquired! Saving location...', 'loading');

                sendLocationToServer(position)
                    .then(function(data) {
                        console.log('✅ First location saved!');
                        isLocationGranted = true;
                        isTracking = true;
                        isProcessing = false;
                        
                        setModalStatus('✅ GPS tracking active!', 'success');
                        
                        if (watchId === null) {
                            watchId = navigator.geolocation.watchPosition(
                                function(newPosition) {
                                    console.log('🔄 GPS update');
                                    sendLocationToServer(newPosition).catch(function(err) {
                                        console.log('GPS update error:', err.message);
                                    });
                                },
                                function(error) {
                                    console.warn('GPS watch error:', error.message);
                                },
                                {
                                    enableHighAccuracy: true,
                                    timeout: 30000,
                                    maximumAge: 5000
                                }
                            );
                            console.log('🔄 Continuous GPS tracking started with watchId:', watchId);
                        }
                        
                        modal.classList.remove('show');
                        if (pendingCarId) {
                            window.location.href = 'book.php?car_id=' + pendingCarId;
                        }
                    })
                    .catch(function(error) {
                        console.error('❌ Save failed:', error);
                        setModalStatus('❌ ' + error.message, 'error');
                        allowBtn.disabled = false;
                        allowBtn.textContent = 'Try Again';
                        isProcessing = false;
                    });
            },
            function(error) {
                clearTimeout(gpsTimeout);
                console.error('❌ GPS Error:', error);
                
                var message = '';
                switch(error.code) {
                    case 1:
                        message = '❌ Location permission denied. Please allow GPS access.';
                        break;
                    case 2:
                        message = '⚠️ GPS unavailable. Please enable GPS on your device.';
                        break;
                    case 3:
                        message = '⏱️ GPS request timed out. Please try again.';
                        break;
                    default:
                        message = '⚠️ GPS error: ' + error.message;
                }
                
                setModalStatus(message, 'error');
                allowBtn.disabled = false;
                allowBtn.textContent = 'Try Again';
                isProcessing = false;
            },
            {
                enableHighAccuracy: true,
                timeout: 30000,
                maximumAge: 5000
            }
        );
        return true;
    }

    function stopContinuousTracking() {
        if (watchId !== null) {
            navigator.geolocation.clearWatch(watchId);
            watchId = null;
            console.log('🛑 GPS tracking stopped');
        }
        isTracking = false;
    }

    // ============================================================
    // FIXED: handleBookNow - Removed cloneNode() / replaceChild()
    // ============================================================
    function handleBookNow(carId) {
        pendingCarId = carId;
        
        const isMobile = isMobileDevice();
        console.log('📱 Device detection:', isMobile ? 'Mobile' : 'Desktop');
        
        if (!isMobile) {
            console.log('💻 Desktop detected - skipping GPS, going directly to book.php');
            window.location.href = 'book.php?car_id=' + carId;
            return;
        }
        
        if (isLocationGranted && isTracking) {
            console.log('📍 GPS already active - going to book.php');
            window.location.href = 'book.php?car_id=' + carId;
            return;
        }

        console.log('📱 Mobile detected - showing GPS modal');
        isProcessing = false;
        allowBtn.disabled = false;
        allowBtn.textContent = 'Allow Location';
        denyBtn.disabled = false;
        
        setModalStatus('Please allow location access to continue booking.', '');
        modal.classList.add('show');
    }

    // ============================================================
    // ALLOW BUTTON - Direct click handler (FIXED)
    // ============================================================
    allowBtn.addEventListener('click', function() {
        if (isProcessing) {
            console.log('⏳ GPS request already in progress');
            return;
        }
        startContinuousTracking();
    });

    // ============================================================
    // DENY BUTTON - Direct click handler (FIXED)
    // ============================================================
    denyBtn.addEventListener('click', function() {
        setModalStatus('❌ GPS permission denied. Booking cannot continue.', 'error');
        pendingCarId = null;
        isProcessing = false;
    });

    // ============================================================
    // BIND BOOK NOW BUTTONS
    // ============================================================
    function bindBookNowButtons() {
        document.querySelectorAll('.book-now-btn').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var carId = this.dataset.carId;
                if (carId) {
                    handleBookNow(carId);
                }
            });
        });
    }

    bindBookNowButtons();

    // ============================================================
    // CLEANUP
    // ============================================================
    window.addEventListener('beforeunload', function() {
        stopContinuousTracking();
    });

})();
</script>
<script src="../bootstrap-5.3.8-dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>