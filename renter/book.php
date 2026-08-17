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

// Check if vehicle is approved
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate form token to prevent duplicate submissions
    $tokenError = validate_form_token_or_error('book_vehicle');
    if ($tokenError) {
        die($tokenError);
    }

    $start = trim($_POST['start'] ?? '');
    $end = trim($_POST['end'] ?? '');

    if (empty($start) || empty($end)) {
        die('Please select valid dates.');
    }

    if ($start > $end) {
        die('End date must be after start date.');
    }

    $today = date('Y-m-d');
    if ($start < $today) {
        die('You cannot book a past date.');
    }

    if (($car['status'] ?? 'available') !== 'available') {
        die('This vehicle is not available.');
    }

    // Use transaction to prevent race condition
    try {
        $conn->beginTransaction();
        
        $check = $conn->prepare("SELECT COUNT(*) FROM bookings WHERE vehicle_id = ? AND status IN ('pending', 'approved') AND (start_date <= ? AND end_date >= ?)");
        $check->execute([$car_id, $end, $start]);

        if ($check->fetchColumn() > 0) {
            $conn->rollBack();
            die('This car is already booked for the selected dates.');
        }

        $diff = strtotime($end) - strtotime($start);
        $days = floor($diff / (60 * 60 * 24)) + 1;
        $days = max(1, $days);

        $total_price = $days * (float) $car['rate'];

        $stmt = $conn->prepare("INSERT INTO bookings (renter_id, vehicle_id, start_date, end_date, total_days, total_price, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([$user_id, $car_id, $start, $end, $days, $total_price]);

        $booking_id = $conn->lastInsertId();

        $update = $conn->prepare("UPDATE vehicles SET availability_status = 'rented' WHERE id = ?");
        $update->execute([$car_id]);

        $conn->commit();
        
        header('Location: paid.php?booking_id=' . $booking_id);
        exit;
    } catch (PDOException $e) {
        $conn->rollBack();
        die('Booking failed. Please try again.');
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
/* Image loading styles */
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
</style>
</head>

<body>

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

        <label>Start Date</label>
        <input type="text" name="start" id="start_date" required>

        <label>End Date</label>
        <input type="text" name="end" id="end_date" required>

        <div class="price-box">
            <p>Days: <span id="total-days">0</span></p>
            <p>Total: <strong>₱<span id="total-price">0.00</span></strong></p>
        </div>

        <button type="submit" class="btn-book">Proceed to Payment</button>
        <a href="browse.php" class="btn-return">Return</a>
    </form>

</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
// Image loading handler
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

const startInput = document.getElementById('start_date');
const endInput = document.getElementById('end_date');
const daysDisplay = document.getElementById('total-days');
const priceDisplay = document.getElementById('total-price');
const rate = parseFloat("<?= $car['rate'] ?>") || 0;
const today = '<?= date('Y-m-d') ?>';

// Initialize Flatpickr for start date
const startPicker = flatpickr(startInput, {
    dateFormat: 'Y-m-d',
    minDate: today,
    onChange: function(selectedDates, dateStr) {
        if (selectedDates[0]) {
            // Update end date minimum to be same as start date
            endPicker.set('minDate', dateStr);
        }
        updatePrice();
    }
});

// Initialize Flatpickr for end date
const endPicker = flatpickr(endInput, {
    dateFormat: 'Y-m-d',
    minDate: today,
    onChange: updatePrice
});

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

// Prevent double-click on submit button
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('bookingForm');
    if (form) {
        form.addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Processing...';
            }
        });
    }
});
</script>

</body>
</html>