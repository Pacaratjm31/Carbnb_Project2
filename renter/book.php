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
            : 'Your account is waiting for admin approval. Booking is disabled.',
        'restricted' => true
    ];
    
    // Show restricted page
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Booking Restricted | Carbnb</title>
    <link rel="stylesheet" href="css/renter_style.css?v=2">
    <link rel="stylesheet" href="css/renter_style_backup.css?v=4">
    </head>
    <body>
        <div class="booking-container">
            <h2>Booking Restricted</h2>
            <div class="approval-card">
                <h3><?= htmlspecialchars($account_state['title']) ?></h3>
                <p><?= htmlspecialchars($account_state['message']) ?></p>
                <a href="browse.php" class="btn-return">← Back to Browse</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if (!isset($_GET['car_id']) || empty($_GET['car_id'])) {
    die('Invalid request.');
}

$car_id = (int) $_GET['car_id'];

$stmt = $conn->prepare("SELECT id, name AS vehicle_name, price_per_day AS rate, image AS car_image, availability_status AS status, approval_status, category, model_year FROM vehicles WHERE id = ?");
$stmt->execute([$car_id]);
$car = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$car) {
    die('Car not found.');
}

if (($car['approval_status'] ?? 'pending') !== 'approved') {
    die('This vehicle is not yet approved by admin. Please check back later.');
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

$imgPath = build_vehicle_image_path($car['car_image'] ?? '');

// ============================================
// POST HANDLER - All responses are JSON
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate form token
    $tokenError = validate_form_token_or_error('book_vehicle');
    if ($tokenError) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $tokenError]);
        exit;
    }

    $locationGranted = isset($_POST['location_granted']) && $_POST['location_granted'] === '1';
    if (!$locationGranted) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Location permission is required to complete booking. Please allow GPS access.'
        ]);
        exit;
    }

    $start = trim($_POST['start'] ?? '');
    $end = trim($_POST['end'] ?? '');

    if (empty($start) || empty($end)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Please select valid dates.']);
        exit;
    }

    if ($start > $end) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'End date must be after start date.']);
        exit;
    }

    $today = date('Y-m-d');
    if ($start < $today) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'You cannot book a past date.']);
        exit;
    }

    if (($car['status'] ?? 'available') !== 'available') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'This vehicle is not available.']);
        exit;
    }

    $latitude = isset($_POST['latitude']) && is_numeric($_POST['latitude']) ? (float) $_POST['latitude'] : null;
    $longitude = isset($_POST['longitude']) && is_numeric($_POST['longitude']) ? (float) $_POST['longitude'] : null;
    $accuracy = isset($_POST['accuracy']) && is_numeric($_POST['accuracy']) ? (float) $_POST['accuracy'] : 0;

    try {
        $conn->beginTransaction();
        
        // Check for existing pending_location booking for THIS user
        $checkStmt = $conn->prepare("
            SELECT id 
            FROM bookings 
            WHERE vehicle_id = ? 
            AND renter_id = ? 
            AND status = 'pending_location' 
            AND start_date = ? 
            AND end_date = ?
            LIMIT 1
        ");
        $checkStmt->execute([$car_id, $user_id, $start, $end]);
        $existingBooking = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingBooking) {
            $booking_id = (int) $existingBooking['id'];
        } else {
            // Conflict check for OTHER users
            $check = $conn->prepare("
                SELECT COUNT(*) 
                FROM bookings 
                WHERE vehicle_id = ? 
                AND status IN ('pending', 'approved', 'pending_location') 
                AND start_date <= ? 
                AND end_date >= ?
            ");
            $check->execute([$car_id, $end, $start]);

            if ($check->fetchColumn() > 0) {
                $conn->rollBack();
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'This car is already booked for the selected dates.'
                ]);
                exit;
            }

            $diff = strtotime($end) - strtotime($start);
            $days = floor($diff / (60 * 60 * 24)) + 1;
            $days = max(1, $days);
            $total_price = $days * (float) $car['rate'];

            $stmt = $conn->prepare("INSERT INTO bookings (renter_id, vehicle_id, start_date, end_date, total_days, total_price, status) VALUES (?, ?, ?, ?, ?, ?, 'pending_location')");
            $stmt->execute([$user_id, $car_id, $start, $end, $days, $total_price]);
            $booking_id = (int) $conn->lastInsertId();
        }

        // Save GPS location atomically within the same transaction
        if ($latitude !== null && $longitude !== null) {
            $recorded_at = date('Y-m-d H:i:s');
            $locStmt = $conn->prepare("
                INSERT INTO location_tracker (user_id, booking_id, latitude, longitude, accuracy, recorded_at)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $locStmt->execute([$user_id, $booking_id, $latitude, $longitude, $accuracy, $recorded_at]);
        }

        $conn->commit();
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'booking_id' => $booking_id,
            'redirect' => 'paid.php?booking_id=' . $booking_id
        ]);
        exit;
        
    } catch (PDOException $e) {
        $conn->rollBack();
        error_log('Booking error: ' . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Booking failed. Please try again.'
        ]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Book Car | Carbnb</title>
<link rel="stylesheet" href="css/renter_style.css?v=2">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

<style>
.car-img-large {
    background: #f0f0f0;
    min-height: 200px;
    transition: opacity 0.3s ease;
    object-fit: cover;
    width: 100%;
    height: auto;
    max-height: 350px;
    border-radius: 8px;
}
.car-img-large.loaded {
    opacity: 1;
}
.car-img-large:not(.loaded) {
    opacity: 0;
}
.car-img-large.loading {
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: shimmer 1.5s infinite;
}
@keyframes shimmer {
    0% { background-position: -200% 0; }
    100% { background-position: 200% 0; }
}

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
</style>
</head>

<body>

<!-- Location Permission Modal -->
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

<div class="booking-container">

    <h2>Book Your Car</h2>

    <div class="car-preview">
        <img src="<?= htmlspecialchars($imgPath) ?>"
             class="car-img-large loading"
             alt="<?= htmlspecialchars($car['vehicle_name']) ?>"
             loading="lazy"
             decoding="async"
             width="400"
             height="250"
             onerror="this.src='../uploads/vehicles/default-car.svg'; this.className='car-img-large loaded';">

        <h3><?= htmlspecialchars($car['vehicle_name']) ?></h3>

        <div class="car-info">
            <p><strong>Rate:</strong> ₱<span id="daily-rate"><?= htmlspecialchars($car['rate']) ?></span> / day</p>
            <p><strong>Category:</strong> <?= htmlspecialchars(str_replace('_', ' ', $car['category'] ?? '')) ?></p>
            <p><strong>Model Year:</strong> <?= htmlspecialchars($car['model_year']) ?></p>
        </div>

    </div>

    <form method="POST" class="booking-form" id="bookingForm">
        <?= form_token_input('book_vehicle') ?>
        <input type="hidden" name="latitude" id="gps_latitude" value="">
        <input type="hidden" name="longitude" id="gps_longitude" value="">
        <input type="hidden" name="accuracy" id="gps_accuracy" value="">

        <label>Start Date</label>
        <input type="text" name="start" id="start_date" required>

        <label>End Date</label>
        <input type="text" name="end" id="end_date" required>

        <div class="price-box">
            <p>Days: <span id="total-days">0</span></p>
            <p>Total: <strong>₱<span id="total-price">0.00</span></strong></p>
        </div>

        <button type="submit" class="btn-book" id="submitBtn">Proceed to Payment</button>
        <a href="browse.php" class="btn-return">Return</a>
    </form>

</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
// ============================================================
// IMAGE LOADING HANDLER
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    const img = document.querySelector('.car-img-large');
    if (img) {
        if (img.complete) {
            img.classList.remove('loading');
            img.classList.add('loaded');
        } else {
            img.addEventListener('load', function() {
                this.classList.remove('loading');
                this.classList.add('loaded');
            });
            img.addEventListener('error', function() {
                this.classList.remove('loading');
                this.classList.add('loaded');
            });
        }
    }
});

// ============================================================
// FLATPICKR DATE PICKER
// ============================================================
const startInput = document.getElementById('start_date');
const endInput = document.getElementById('end_date');
const daysDisplay = document.getElementById('total-days');
const priceDisplay = document.getElementById('total-price');
const rate = parseFloat("<?= $car['rate'] ?>") || 0;
const today = '<?= date('Y-m-d') ?>';

let startPicker = null;
let endPicker = null;
try {
    if (typeof flatpickr === 'undefined') {
        throw new Error('flatpickr library failed to load (CDN may be blocked)');
    }
    startPicker = flatpickr(startInput, {
        dateFormat: 'Y-m-d',
        minDate: today,
        onChange: function(selectedDates, dateStr) {
            if (selectedDates[0]) {
                endPicker.set('minDate', dateStr);
            }
            updatePrice();
        }
    });

    endPicker = flatpickr(endInput, {
        dateFormat: 'Y-m-d',
        minDate: today,
        onChange: updatePrice
    });
} catch (err) {
    console.error('⚠️ Date picker failed to load, falling back to plain text inputs:', err.message);
    startInput.placeholder = 'YYYY-MM-DD';
    endInput.placeholder = 'YYYY-MM-DD';
    startInput.addEventListener('change', updatePrice);
    endInput.addEventListener('change', updatePrice);
}

function updatePrice() {
    if (startInput.value && endInput.value) {
        const start = new Date(startInput.value);
        const end = new Date(endInput.value);

        if (end >= start) {
            const diffTime = Math.abs(end - start);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;

            daysDisplay.innerText = diffDays;
            priceDisplay.innerText = (diffDays * rate).toLocaleString(undefined, {
                minimumFractionDigits: 2
            });
        } else {
            daysDisplay.innerText = '0';
            priceDisplay.innerText = '0.00';
        }
    }
}

// ============================================================
// LOCATION PERMISSION VARIABLES
// ============================================================
const modal = document.getElementById('locationModal');
const allowBtn = document.getElementById('modalAllowBtn');
const denyBtn = document.getElementById('modalDenyBtn');
const modalStatus = document.getElementById('modalStatus');
const bookingForm = document.getElementById('bookingForm');
const submitBtn = document.getElementById('submitBtn');

let isProcessing = false;
let gpsPosition = null;
let isGpsAcquired = false;

function isMobileDevice() {
    var uaCheck = /Android|iPhone|iPad|iPod|Mobile|Windows Phone/i.test(navigator.userAgent);
    var widthCheck = window.innerWidth <= 768;
    return uaCheck || widthCheck;
}

function setModalStatus(message, type) {
    modalStatus.textContent = message;
    modalStatus.className = 'permission-status';
    if (type) {
        modalStatus.classList.add(type);
    }
}

// ============================================================
// GET GPS POSITION – populates hidden fields
// ============================================================
function getGpsPosition() {
    return new Promise(function(resolve, reject) {
        if (!navigator.geolocation) {
            reject(new Error('Geolocation not supported'));
            return;
        }

        setModalStatus('📡 Getting GPS location...', 'loading');
        allowBtn.disabled = true;
        allowBtn.textContent = 'Getting GPS...';

        var gpsTimeout = setTimeout(function() {
            setModalStatus('⏱️ GPS request timed out. Please try again.', 'error');
            allowBtn.disabled = false;
            allowBtn.textContent = 'Try Again';
            isProcessing = false;
            reject(new Error('GPS timeout'));
        }, 20000);

        navigator.geolocation.getCurrentPosition(
            function(position) {
                clearTimeout(gpsTimeout);
                console.log('✅ GPS acquired:', position.coords.latitude, position.coords.longitude);
                document.getElementById('gps_latitude').value = position.coords.latitude;
                document.getElementById('gps_longitude').value = position.coords.longitude;
                document.getElementById('gps_accuracy').value = position.coords.accuracy || 0;
                setModalStatus('📍 GPS acquired! Creating booking...', 'loading');
                resolve(position);
            },
            function(error) {
                clearTimeout(gpsTimeout);
                console.error('❌ GPS Error:', error);
                
                var message = '';
                switch(error.code) {
                    case 1:
                        message = '❌ Location permission denied. You must allow GPS access.';
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
                reject(new Error(message));
            },
            {
                enableHighAccuracy: true,
                timeout: 30000,
                maximumAge: 5000
            }
        );
    });
}

// ============================================================
// SUBMIT BOOKING FORM – includes GPS coords automatically
// ============================================================
function submitBookingForm() {
    var formData = new FormData(bookingForm);
    formData.append('location_granted', '1');

    submitBtn.disabled = true;
    submitBtn.textContent = 'Creating booking...';

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(function(response) {
        return response.json();
    })
    .then(function(data) {
        if (data.success && data.booking_id) {
            var realBookingId = data.booking_id;
            console.log('✅ Booking created with ID:', realBookingId);
            
            // Start GPS tracker immediately
            if (window.GPSTracker) {
                // Ensure any previous tracker is stopped
                window.GPSTracker.stop();
                // Start fresh
                window.GPSTracker.start(realBookingId);
                console.log('✅ GPS tracker started with booking_id:', realBookingId);
            } else {
                console.warn('⚠️ GPSTracker not available. Tracking will not continue.');
            }
            
            setModalStatus('✅ Booking confirmed! GPS tracking active.', 'success');
            
            allowBtn.style.display = 'none';
            denyBtn.style.display = 'none';
            
            var proceedBtn = document.getElementById('proceedToPaymentBtn');
            if (!proceedBtn) {
                proceedBtn = document.createElement('button');
                proceedBtn.id = 'proceedToPaymentBtn';
                proceedBtn.className = 'btn-allow';
                proceedBtn.textContent = 'Proceed to Payment →';
                proceedBtn.style.width = '100%';
                proceedBtn.style.marginTop = '10px';
                
                var modalActions = document.querySelector('.modal-actions');
                if (modalActions) {
                    modalActions.appendChild(proceedBtn);
                }
            }
            proceedBtn.style.display = 'block';
            
            var newProceedBtn = proceedBtn.cloneNode(true);
            proceedBtn.parentNode.replaceChild(newProceedBtn, proceedBtn);
            
            newProceedBtn.addEventListener('click', function() {
                window.location.href = data.redirect;
            });
            
        } else {
            var isTokenError = /invalid or expired/i.test(data.message || '');
            if (isTokenError) {
                setModalStatus('❌ Your form session expired. Reloading for a fresh session...', 'error');
                setTimeout(function() {
                    window.location.reload();
                }, 1800);
            } else {
                setModalStatus('❌ ' + (data.message || 'Booking failed'), 'error');
                allowBtn.disabled = false;
                allowBtn.textContent = 'Try Again';
            }
            submitBtn.disabled = false;
            submitBtn.textContent = 'Proceed to Payment';
            isProcessing = false;
            isGpsAcquired = false;
            gpsPosition = null;
        }
    })
    .catch(function(error) {
        console.error('Booking submission error:', error);
        setModalStatus('❌ Booking failed: ' + error.message, 'error');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Proceed to Payment';
        isProcessing = false;
        isGpsAcquired = false;
        gpsPosition = null;
    });
}

// ============================================================
// DESKTOP BOOKING – no GPS, no modal
// ============================================================
function submitBookingFormDesktop() {
    var formData = new FormData(bookingForm);
    formData.append('location_granted', '1');

    submitBtn.disabled = true;
    submitBtn.textContent = 'Creating booking...';

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success && data.redirect) {
            window.location.href = data.redirect;
        } else {
            alert(data.message || 'Booking failed. Please try again.');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Proceed to Payment';
        }
    })
    .catch(function(error) {
        console.error('Booking submission error:', error);
        alert('Booking failed: ' + error.message);
        submitBtn.disabled = false;
        submitBtn.textContent = 'Proceed to Payment';
    });
}

// ============================================================
// FORM SUBMISSION HANDLER
// ============================================================
bookingForm.addEventListener('submit', function(e) {
    e.preventDefault();
    
    if (!startInput.value || !endInput.value) {
        alert('Please select both start and end dates.');
        return;
    }
    
    var start = new Date(startInput.value);
    var end = new Date(endInput.value);
    var todayDate = new Date();
    todayDate.setHours(0, 0, 0, 0);
    
    if (start < todayDate) {
        alert('Start date cannot be in the past.');
        return;
    }
    
    if (end < start) {
        alert('End date must be after start date.');
        return;
    }
    
    // Desktop: skip GPS
    if (!isMobileDevice()) {
        submitBookingFormDesktop();
        return;
    }
    
    // Mobile: show modal
    isProcessing = false;
    allowBtn.disabled = false;
    allowBtn.textContent = 'Allow Location';
    setModalStatus('Please allow location access to continue booking.', '');
    modal.classList.add('show');
});

// ============================================================
// ALLOW BUTTON
// ============================================================
allowBtn.onclick = function() {
    if (isProcessing) return;
    isProcessing = true;
    
    getGpsPosition()
        .then(function(position) {
            isGpsAcquired = true;
            gpsPosition = position;
            setModalStatus('✅ GPS acquired! Creating booking...', 'success');
            submitBookingForm();
        })
        .catch(function(error) {
            console.error('GPS error:', error.message);
            isProcessing = false;
            isGpsAcquired = false;
        });
};

// ============================================================
// DENY BUTTON
// ============================================================
denyBtn.onclick = function() {
    setModalStatus('❌ Location permission is required to book.', 'error');
    isProcessing = false;
    isGpsAcquired = false;
    gpsPosition = null;
    modal.classList.remove('show');
};
</script>

<!-- ============================================================
     GPS TRACKER - PATH IS CORRECT: js/gps_tracker.js
     ============================================================ -->
<script src="js/gps_tracker.js?v=<?= time() ?>"></script>

</body>
</html>