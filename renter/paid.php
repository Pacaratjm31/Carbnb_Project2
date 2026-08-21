<?php
include '../database/db.php';
include __DIR__ . '/../helpers/duplicate_functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$conn = $conn ?? $GLOBALS['conn'] ?? $GLOBALS['pdo'] ?? null;

// Check login
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];
require_once __DIR__ . '/../auth/require_face_verified.php';

// Get renter account state
$stmt = $conn->prepare("
    SELECT id, full_name, status, disapproval_reason
    FROM users
    WHERE id = ?
    AND is_deleted = 0
");
$stmt->execute([$user_id]);
$renter = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$renter) {
    session_destroy();
    header('Location: ../auth/login.php');
    exit;
}

// Check renter approval
if (($renter['status'] ?? 'pending') !== 'approved') {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Restricted | Carbnb</title>
    <link rel="stylesheet" href="css/renter_style.css?v=2">
</head>
<body>

    <div class="payment-container">
        <h2>Payment Restricted</h2>

        <div class="payment-box">
            <h3>
                <?= htmlspecialchars(
                    $renter['status'] === 'disapproved'
                        ? 'Account Disapproved'
                        : 'Pending Admin Approval'
                ) ?>
            </h3>

            <p>
                <?= htmlspecialchars(
                    $renter['status'] === 'disapproved'
                        ? ($renter['disapproval_reason'] ?? 'Your account was disapproved.')
                        : 'Your account is waiting for admin approval. Payment is disabled.'
                ) ?>
            </p>

            <a href="browse.php" class="btn-return">← Back to Browse</a>
        </div>
    </div>

</body>
</html>
<?php
    exit;
}

// Check booking ID
if (!isset($_GET['booking_id'])) {
    die('Invalid request.');
}

$booking_id = (int) $_GET['booking_id'];

// Get booking details
$stmt = $conn->prepare("
    SELECT 
        b.id,
        b.total_price,
        b.status,
        v.name AS vehicle_name,
        v.image AS car_image
    FROM bookings b
    JOIN vehicles v ON b.vehicle_id = v.id
    WHERE b.id = ?
    AND b.renter_id = ?
");
$stmt->execute([$booking_id, $user_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    die('Booking not found.');
}

// Check for an existing payment record
$paymentStmt = $conn->prepare("
    SELECT status
    FROM payments
    WHERE booking_id = ?
");
$paymentStmt->execute([$booking_id]);
$existingPayment = $paymentStmt->fetch(PDO::FETCH_ASSOC);

$paymentStatus = $existingPayment['status'] ?? null;

// Build vehicle image path
function build_vehicle_image_path($value): string
{
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

$imagePath = build_vehicle_image_path($data['car_image'] ?? '');


// ============================================
// SINGLE FUNCTION TO RENDER PAYMENT UI
// ============================================
function renderPaymentUI(
    $paymentStatus,
    $imagePath,
    $vehicleName,
    $totalPrice,
    $bookingId
) {

    // Determine button text
    $buttonText = 'Submit Payment Proof';

    if ($paymentStatus === 'disapproved') {
        $buttonText = 'Submit New Proof';
    } elseif ($paymentStatus === 'pending') {
        $buttonText = 'Upload Proof';
    }

    ob_start();
    ?>

    <div class="payment-container">

        <?php if ($paymentStatus === 'verified'): ?>

            <!-- ======================================== -->
            <!-- STATUS: VERIFIED - FULLY LOCKED         -->
            <!-- ======================================== -->

            <h2>Payment Verified Successfully</h2>

            <div class="payment-box">

                <div style="background:#d4edda;color:#155724;padding:15px;border-radius:5px;margin-bottom:20px;border:1px solid #c3e6cb;">
                    ✅ Payment Verified Successfully.
                </div>

                <img
                    src="<?= htmlspecialchars($imagePath) ?>"
                    class="payment-image"
                    alt="<?= htmlspecialchars($vehicleName) ?>"
                    loading="lazy"
                    decoding="async"
                    width="300"
                    height="200"
                    onerror="this.src='../uploads/vehicles/default-car.svg'; this.onerror=null;"
                >

                <p>
                    <strong>Vehicle:</strong>
                    <?= htmlspecialchars($vehicleName) ?>
                </p>

                <p>
                    <strong>Total:</strong>
                    ₱<?= htmlspecialchars((string) $totalPrice) ?>
                </p>

            </div>

            <!-- NO PAYMENT FORMS SHOWN FOR VERIFIED -->

        <?php else: ?>

            <!-- ======================================== -->
            <!-- STATUS: NULL, PENDING, OR DISAPPROVED   -->
            <!-- ======================================== -->

            <h2>Payment</h2>

            <?php if ($paymentStatus === 'pending'): ?>

                <div style="background:#fff3cd;color:#856404;padding:15px;border-radius:5px;margin-bottom:20px;border:1px solid #ffeeba;">
                    ⏳ Payment is still pending. Please upload payment proof or wait for verification.
                </div>

            <?php elseif ($paymentStatus === 'disapproved'): ?>

                <div style="background:#f8d7da;color:#721c24;padding:15px;border-radius:5px;margin-bottom:20px;border:1px solid #f5c6cb;">
                    ⚠️ Payment Disapproved. Please check your payment and try again.
                </div>

            <?php endif; ?>


            <!-- Vehicle Details -->

            <div class="payment-box">

                <img
                    src="<?= htmlspecialchars($imagePath) ?>"
                    class="payment-image"
                    alt="<?= htmlspecialchars($vehicleName) ?>"
                    loading="lazy"
                    decoding="async"
                    width="300"
                    height="200"
                    onerror="this.src='../uploads/vehicles/default-car.svg'; this.onerror=null;"
                >

                <p>
                    <strong>Vehicle:</strong>
                    <?= htmlspecialchars($vehicleName) ?>
                </p>

                <p>
                    <strong>Total:</strong>
                    ₱<?= htmlspecialchars((string) $totalPrice) ?>
                </p>

            </div>


            <!-- ======================================== -->
            <!-- PAYMONGO BUTTON - ONLY WHEN NO PAYMENT  -->
            <!-- ======================================== -->

            <?php if ($paymentStatus === null): ?>

                <div class="payment-form">

                    <button
                        class="btn"
                        type="button"
                        id="payWithPayMongo"
                    >
                        Pay with PayMongo
                    </button>

                </div>

            <?php endif; ?>


            <!-- ======================================== -->
            <!-- PROOF UPLOAD FORM                        -->
            <!-- ======================================== -->

            <?php if ($paymentStatus !== null): ?>

                <div
                    class="payment-form"
                    style="margin-top:20px;border-top:1px solid #ddd;padding-top:20px;"
                >

                    <p style="margin-bottom:10px;color:#666;font-weight:bold;">

                        <?php if ($paymentStatus === 'pending'): ?>

                            📤 Upload Payment Proof (Required for verification)

                        <?php elseif ($paymentStatus === 'disapproved'): ?>

                            📤 Upload New Payment Proof

                        <?php endif; ?>

                    </p>


                    <form
                        id="manualPaymentForm"
                        enctype="multipart/form-data"
                    >

                        <input
                            type="file"
                            name="proof_image"
                            id="proof_image"
                            accept="image/jpeg,image/png,image/webp"
                            required
                        >

                        <button
                            class="btn"
                            type="submit"
                            id="submitPaymentBtn"
                            style="margin-top:10px;"
                            data-default-text="<?= $buttonText ?>"
                        >
                            <?= $buttonText ?>
                        </button>

                    </form>


                    <!-- Status message area for upload feedback -->

                    <div
                        id="uploadStatusMessage"
                        style="margin-top:10px;"
                    ></div>

                </div>

            <?php endif; ?>

        <?php endif; ?>


        <!-- ======================================== -->
        <!-- RETURN BUTTON - ALL STATES              -->
        <!-- ======================================== -->

        <div
            style="margin-top:30px;padding-top:20px;border-top:1px solid #eee;text-align:center;"
        >

            <a
                href="browse.php"
                class="btn-return"
            >
                ← Back to Browse
            </a>

        </div>

    </div>

    <?php

    return ob_get_clean();
}


// ============================================
// AJAX ENDPOINT
// ============================================

$ajax =
    isset($_GET['ajax']) &&
    $_GET['ajax'] === '1';

if (
    $ajax &&
    ($_GET['section'] ?? '') === 'payment-status'
) {

    echo renderPaymentUI(
        $paymentStatus,
        $imagePath,
        $data['vehicle_name'],
        $data['total_price'],
        $booking_id
    );

    exit;
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Payment | Carbnb</title>

    <link
        rel="stylesheet"
        href="css/renter_style.css?v=2"
    >

    <style>

    /* Image loading styles */

    .payment-image {
        background: #f0f0f0;
        min-height: 150px;
        transition: opacity 0.3s ease;
        object-fit: cover;
        width: 100%;
        height: auto;
        max-height: 250px;
        border-radius: 8px;
    }

    .payment-image.loaded {
        opacity: 1;
    }

    .payment-image:not(.loaded) {
        opacity: 0;
    }

    .payment-image.loading {
        background: linear-gradient(
            90deg,
            #f0f0f0 25%,
            #e0e0e0 50%,
            #f0f0f0 75%
        );

        background-size: 200% 100%;

        animation: shimmer 1.5s infinite;
    }

    @keyframes shimmer {

        0% {
            background-position: -200% 0;
        }

        100% {
            background-position: 200% 0;
        }

    }

    </style>

</head>

<body>


    <div
        id="renter-payment-status"
        data-live-refresh="paid.php?ajax=1&section=payment-status&booking_id=<?= (int) $booking_id ?>"
        data-live-target="#renter-payment-status"
    >

        <?= renderPaymentUI(
            $paymentStatus,
            $imagePath,
            $data['vehicle_name'],
            $data['total_price'],
            $booking_id
        ) ?>

    </div>


    <script>

    // ============================================
    // IMAGE LOADING HANDLER
    // ============================================

    document.addEventListener(
        'DOMContentLoaded',
        function() {

            document
                .querySelectorAll('.payment-image')
                .forEach(function(img) {

                    img.classList.add('loading');

                    if (img.complete) {

                        img.classList.remove('loading');
                        img.classList.add('loaded');

                    } else {

                        img.addEventListener(
                            'load',
                            function() {

                                this.classList.remove('loading');
                                this.classList.add('loaded');

                            }
                        );

                        img.addEventListener(
                            'error',
                            function() {

                                this.classList.remove('loading');
                                this.classList.add('loaded');

                            }
                        );

                    }

                });

        }
    );


    // ============================================
    // PAYMENT UPLOAD STATE
    // ============================================

    var uploadInProgress = false;
    var uploadCompleted = false;


    // ============================================
    // REFRESH PAYMENT STATUS
    // ============================================

    function refreshPaymentStatus() {

        const paymentStatusNode =
            document.getElementById(
                'renter-payment-status'
            );

        if (
            !paymentStatusNode ||
            !paymentStatusNode.dataset.liveRefresh
        ) {
            return;
        }


        fetch(
            paymentStatusNode.dataset.liveRefresh
        )

        .then(function(response) {

            return response.text();

        })

        .then(function(html) {

            paymentStatusNode.innerHTML = html;


            // Re-apply image loading

            document
                .querySelectorAll('.payment-image')
                .forEach(function(img) {

                    img.classList.add('loading');

                    if (img.complete) {

                        img.classList.remove('loading');
                        img.classList.add('loaded');

                    } else {

                        img.addEventListener(
                            'load',
                            function() {

                                this.classList.remove('loading');
                                this.classList.add('loaded');

                            }
                        );

                        img.addEventListener(
                            'error',
                            function() {

                                this.classList.remove('loading');
                                this.classList.add('loaded');

                            }
                        );

                    }

                });


            uploadInProgress = false;
            uploadCompleted = false;

        })

        .catch(function(error) {

            console.log(
                'Payment status refresh failed:',
                error
            );

        });

    }


    // ============================================
    // UPLOAD STATUS MESSAGE
    // ============================================

    function showUploadStatus(
        message,
        type = 'info',
        permanent = false
    ) {

        const statusDiv =
            document.getElementById(
                'uploadStatusMessage'
            );

        if (!statusDiv) {
            return;
        }


        let bgColor;
        let textColor;
        let borderColor;


        if (type === 'success') {

            bgColor = '#d4edda';
            textColor = '#155724';
            borderColor = '#c3e6cb';

        } else if (type === 'error') {

            bgColor = '#f8d7da';
            textColor = '#721c24';
            borderColor = '#f5c6cb';

        } else {

            bgColor = '#d1ecf1';
            textColor = '#0c5460';
            borderColor = '#bee5eb';

        }


        statusDiv.style.cssText =
            'background:' + bgColor +
            ';color:' + textColor +
            ';padding:12px;' +
            'border-radius:5px;' +
            'border:1px solid ' +
            borderColor +
            ';';


        statusDiv.textContent = message;

        statusDiv.style.display = 'block';


        if (!permanent) {

            setTimeout(function() {

                if (statusDiv) {

                    statusDiv.style.opacity = '0';

                    statusDiv.style.transition =
                        'opacity 0.5s';


                    setTimeout(function() {

                        if (statusDiv) {

                            statusDiv.style.display =
                                'none';

                            statusDiv.style.opacity =
                                '1';

                        }

                    }, 500);

                }

            }, 10000);

        }

    }


    // ============================================
    // PAYMONGO PAYMENT BUTTON
    // ============================================
    //
    // This replaces the old Xendit payment button.
    //
    // It calls:
    //
    //     payment_gateway.php
    //
    // which creates the PayMongo Checkout Session.
    //
    // ============================================

    document.addEventListener(
        'click',
        async function(e) {

            if (
                !e.target ||
                e.target.id !== 'payWithPayMongo'
            ) {
                return;
            }


            const button = e.target;


            // Open new tab immediately to avoid popup blockers.

            const paymentWindow =
                window.open(
                    '',
                    '_blank'
                );


            if (!paymentWindow) {

                showUploadStatus(
                    'Popup blocked by browser. Please allow popups for this site.',
                    'error'
                );

                return;
            }


            button.disabled = true;

            button.textContent =
                'Connecting to PayMongo...';


            const formData =
                new FormData();

            formData.append(
                'booking_id',
                '<?= (int) $booking_id ?>'
            );


            try {

                const response =
                    await fetch(
                        'payment_gateway.php',
                        {
                            method: 'POST',
                            body: formData
                        }
                    );


                const text =
                    await response.text();


                console.log(
                    'PayMongo Response:',
                    text
                );


                let result;


                try {

                    result =
                        JSON.parse(text);

                } catch (jsonError) {

                    console.error(
                        'PayMongo JSON Parse Error:',
                        jsonError,
                        text
                    );

                    throw new Error(
                        'Invalid response from payment gateway.'
                    );

                }


                if (
                    result.success &&
                    result.checkout_url
                ) {

                    paymentWindow.location.href =
                        result.checkout_url;


                    showUploadStatus(
                        'Redirecting to PayMongo payment gateway...',
                        'info'
                    );

                } else {

                    paymentWindow.close();


                    showUploadStatus(
                        result.message ||
                        'Unable to create PayMongo payment.',
                        'error'
                    );

                }


            } catch(error) {

                console.error(
                    'PayMongo Error:',
                    error
                );


                paymentWindow.close();


                showUploadStatus(
                    'PayMongo payment connection failed. Please try again.',
                    'error'
                );

            }


            button.disabled = false;

            button.textContent =
                'Pay with PayMongo';

        }
    );


    // ============================================
    // MANUAL PAYMENT UPLOAD
    // ============================================

    document.addEventListener(
        'submit',
        async function(e) {

            if (
                !e.target ||
                e.target.id !== 'manualPaymentForm'
            ) {
                return;
            }


            e.preventDefault();


            // Prevent multiple submissions

            if (
                uploadInProgress ||
                uploadCompleted
            ) {

                showUploadStatus(
                    'Upload already in progress or completed. Please wait.',
                    'info'
                );

                return;
            }


            const form = e.target;


            const submitBtn =
                document.getElementById(
                    'submitPaymentBtn'
                );


            const defaultText =
                submitBtn.getAttribute(
                    'data-default-text'
                ) ||
                'Submit Payment Proof';


            const fileInput =
                document.getElementById(
                    'proof_image'
                );


            // Check file selection

            if (
                !fileInput ||
                !fileInput.files ||
                fileInput.files.length === 0
            ) {

                showUploadStatus(
                    'Please select a file to upload.',
                    'error'
                );

                return;
            }


            // Check file size

            const fileSize =
                fileInput.files[0].size;


            const maxSize =
                5 * 1024 * 1024;


            if (fileSize > maxSize) {

                showUploadStatus(
                    'File is too large. Please upload an image smaller than 5MB.',
                    'error'
                );

                fileInput.value = '';

                return;
            }


            uploadInProgress = true;

            submitBtn.disabled = true;

            submitBtn.textContent =
                'Uploading...';


            showUploadStatus(
                '⏳ Uploading your payment proof... Please wait.',
                'info',
                true
            );


            const formData =
                new FormData(form);


            formData.append(
                'booking_id',
                '<?= (int) $booking_id ?>'
            );


            try {

                const response =
                    await fetch(
                        'payment_api.php',
                        {
                            method: 'POST',
                            body: formData
                        }
                    );


                const result =
                    await response.json();


                if (result.success) {

                    uploadCompleted = true;

                    uploadInProgress = false;


                    showUploadStatus(
                        '✅ ' +
                        (
                            result.message ||
                            'Payment proof uploaded successfully! Waiting for admin verification.'
                        ),
                        'success',
                        true
                    );


                    submitBtn.disabled = true;

                    submitBtn.textContent =
                        '✓ Uploaded';


                    submitBtn.style.backgroundColor =
                        '#28a745';

                    submitBtn.style.color =
                        'white';


                    refreshPaymentStatusWithRetry();


                } else {

                    showUploadStatus(
                        result.message ||
                        'Unable to submit payment proof. Please try again.',
                        'error'
                    );


                    uploadInProgress = false;

                    submitBtn.disabled = false;

                    submitBtn.textContent =
                        defaultText;

                }


            } catch(error) {

                console.error(
                    'Upload Error:',
                    error
                );


                showUploadStatus(
                    'Upload is taking longer than expected. Checking status...',
                    'info',
                    true
                );


                setTimeout(
                    refreshPaymentStatusWithRetry,
                    2000
                );


                uploadInProgress = false;

                submitBtn.disabled = false;

                submitBtn.textContent =
                    defaultText;

            }

        }
    );


    // ============================================
    // REFRESH WITH RETRY
    // ============================================

    function refreshPaymentStatusWithRetry(
        attempt
    ) {

        attempt =
            attempt || 1;


        const paymentStatusNode =
            document.getElementById(
                'renter-payment-status'
            );


        if (
            !paymentStatusNode ||
            !paymentStatusNode.dataset.liveRefresh
        ) {
            return;
        }


        fetch(
            paymentStatusNode.dataset.liveRefresh
        )

        .then(function(response) {

            return response.text();

        })

        .then(function(html) {

            paymentStatusNode.innerHTML =
                html;


            uploadInProgress = false;
            uploadCompleted = false;

        })

        .catch(function(error) {

            console.log(
                'Payment status refresh failed (attempt ' +
                attempt +
                '):',
                error
            );


            if (attempt < 2) {

                setTimeout(
                    function() {

                        refreshPaymentStatusWithRetry(
                            attempt + 1
                        );

                    },
                    3000
                );

            } else {

                uploadInProgress = false;
                uploadCompleted = false;

            }

        });

    }


    // ============================================
    // AUTO PAYMENT STATUS REFRESH
    // ============================================

    (function() {

        const liveTargets =
            document.querySelectorAll(
                '[data-live-refresh]'
            );


        liveTargets.forEach(function(node) {

            const refreshUrl =
                node.dataset.liveRefresh;


            const targetSelector =
                node.dataset.liveTarget ||
                '#' + node.id;


            let refreshInFlight = false;


            function refreshSection() {

                // Don't poll hidden tabs

                if (document.hidden) {
                    return;
                }


                // Prevent overlapping requests

                if (refreshInFlight) {
                    return;
                }


                // Don't replace a selected file

                const fileInput =
                    document.getElementById(
                        'proof_image'
                    );


                const hasPendingSelection =
                    fileInput &&
                    fileInput.files &&
                    fileInput.files.length > 0;


                if (
                    uploadInProgress ||
                    (
                        hasPendingSelection &&
                        !uploadCompleted
                    )
                ) {

                    return;
                }


                refreshInFlight = true;


                fetch(refreshUrl)

                    .then(function(response) {

                        return response.text();

                    })

                    .then(function(html) {

                        const targetNode =
                            document.querySelector(
                                targetSelector
                            );


                        if (targetNode) {

                            targetNode.innerHTML =
                                html;


                            // Re-apply image loading

                            document
                                .querySelectorAll(
                                    '.payment-image'
                                )
                                .forEach(function(img) {

                                    img.classList.add(
                                        'loading'
                                    );


                                    if (img.complete) {

                                        img.classList.remove(
                                            'loading'
                                        );

                                        img.classList.add(
                                            'loaded'
                                        );

                                    } else {

                                        img.addEventListener(
                                            'load',
                                            function() {

                                                this.classList.remove(
                                                    'loading'
                                                );

                                                this.classList.add(
                                                    'loaded'
                                                );

                                            }
                                        );


                                        img.addEventListener(
                                            'error',
                                            function() {

                                                this.classList.remove(
                                                    'loading'
                                                );

                                                this.classList.add(
                                                    'loaded'
                                                );

                                            }
                                        );

                                    }

                                });


                            uploadInProgress = false;

                            uploadCompleted = false;

                        }

                    })

                    .catch(function(error) {

                        console.log(
                            'Live refresh failed:',
                            error
                        );

                    })

                    .finally(function() {

                        refreshInFlight = false;

                    });

            }


            // Initial refresh

            refreshSection();


            // Refresh every 20 seconds

            setInterval(
                refreshSection,
                20000
            );


            // Refresh when tab becomes visible

            document.addEventListener(
                'visibilitychange',
                function() {

                    if (!document.hidden) {

                        refreshSection();

                    }

                }
            );

        });

    })();

    </script>


    <!-- ============================================ -->
    <!-- GPS LOCATION TRACKING - RESUME ACROSS PAGES  -->
    <!-- ============================================ -->

    <script src="../js/gps_tracker.js?v=<?= time() ?>"></script>

    <script>

    document.addEventListener(
        'DOMContentLoaded',
        function() {

            var bookingStatus =
                <?= json_encode($data['status']) ?>;


            var bookingId =
                <?= (int) $booking_id ?>;


            // Booking already finished

            if (
                bookingStatus === 'completed'
            ) {

                if (window.GPSTracker) {

                    window.GPSTracker.stop();

                }

                return;

            }


            if (!window.GPSTracker) {

                console.warn(
                    '[paid.php] gps_tracker.js failed to load - location will not be tracked here.'
                );

                return;

            }


            var resumed =
                window.GPSTracker.resume();


            if (!resumed) {

                window.GPSTracker.start(
                    bookingId
                );


                console.log(
                    '[paid.php] GPS tracking started fresh for booking',
                    bookingId
                );

            } else {

                console.log(
                    '[paid.php] GPS tracking resumed for booking',
                    bookingId
                );

            }

        }
    );

    </script>

</body>

</html>