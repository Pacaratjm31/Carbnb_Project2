<?php
// ============================================================
// CARBNB - RATE & COMMENT
// InfinityFree / MySQL Compatible Version
// ============================================================

include '../database/db.php';
include __DIR__ . '/../helpers/duplicate_functions.php';

// ============================================================
// SESSION
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// DATABASE CONNECTION
// ============================================================
$conn = $conn ?? $GLOBALS['conn'] ?? $GLOBALS['pdo'] ?? null;

if (!$conn instanceof PDO) {
    die('Database connection error.');
}

// ============================================================
// LOGIN CHECK
// ============================================================
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];
require_once __DIR__ . '/../auth/require_face_verified.php';

// ============================================================
// CHECK RENTER ACCOUNT
// ============================================================
$stmt = $conn->prepare("
    SELECT id, full_name, status
    FROM users
    WHERE id = ?
      AND is_deleted = 0
    LIMIT 1
");

$stmt->execute([$user_id]);

$renter = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$renter || $renter['status'] !== 'approved') {
    header('Location: browse.php');
    exit;
}

// ============================================================
// VARIABLES
// ============================================================
$success = '';
$error = '';

// ============================================================
// GET VEHICLE ID
// ============================================================
$vehicle_id = (int) ($_GET['vehicle_id'] ?? $_POST['vehicle_id'] ?? 0);

if ($vehicle_id <= 0) {
    header('Location: browse.php');
    exit;
}

// ============================================================
// HANDLE REVIEW SUBMISSION
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {

    // --------------------------------------------------------
    // CSRF / FORM TOKEN
    // --------------------------------------------------------
    $tokenError = validate_form_token_or_error('submit_review');

    if ($tokenError) {
        $error = $tokenError;
    } else {

        // ----------------------------------------------------
        // INPUT
        // ----------------------------------------------------
        $vehicle_id = (int) ($_POST['vehicle_id'] ?? 0);

        $rating = filter_var(
            $_POST['rating'] ?? null,
            FILTER_VALIDATE_INT
        );

        $comment = trim($_POST['comment'] ?? '');

        // ----------------------------------------------------
        // VALIDATE VEHICLE ID
        // ----------------------------------------------------
        if ($vehicle_id <= 0) {
            $error = 'Invalid vehicle.';
        }

        // ----------------------------------------------------
        // VALIDATE RATING
        // ----------------------------------------------------
        elseif ($rating === false || $rating < 1 || $rating > 5) {
            $error = 'Please provide a valid rating from 1 to 5 stars.';
        }

        // ----------------------------------------------------
        // VALIDATE COMMENT LENGTH
        // ----------------------------------------------------
        elseif (mb_strlen($comment) > 2000) {
            $error = 'Your comment is too long. Please keep it under 2000 characters.';
        }

        // ----------------------------------------------------
        // PROCESS REVIEW
        // ----------------------------------------------------
        else {

            // ------------------------------------------------
            // GET VEHICLE AND OWNER
            // ------------------------------------------------
            $stmt = $conn->prepare("
                SELECT
                    v.id,
                    v.name,
                    v.owner_id
                FROM vehicles v
                WHERE v.id = ?
                  AND v.is_deleted = 0
                LIMIT 1
            ");

            $stmt->execute([$vehicle_id]);

            $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$vehicle) {

                $error = 'Vehicle not found.';

            } else {

                // --------------------------------------------
                // PREVENT OWNER FROM REVIEWING OWN VEHICLE
                // --------------------------------------------
                if ((int) $vehicle['owner_id'] === $user_id) {

                    $error = 'You cannot review your own vehicle.';

                } else {

                    // ----------------------------------------
                    // CHECK DUPLICATE REVIEW
                    // ----------------------------------------
                    $stmt = $conn->prepare("
                        SELECT id
                        FROM reviews
                        WHERE renter_id = ?
                          AND vehicle_id = ?
                        LIMIT 1
                    ");

                    $stmt->execute([
                        $user_id,
                        $vehicle_id
                    ]);

                    $existingReview = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($existingReview) {

                        $error = 'You have already reviewed this vehicle.';

                    } else {

                        // ------------------------------------
                        // INSERT REVIEW
                        // ------------------------------------
                        try {

                            $stmt = $conn->prepare("
                                INSERT INTO reviews (
                                    renter_id,
                                    owner_id,
                                    vehicle_id,
                                    rating,
                                    comment
                                )
                                VALUES (?, ?, ?, ?, ?)
                            ");

                            $stmt->execute([
                                $user_id,
                                (int) $vehicle['owner_id'],
                                $vehicle_id,
                                $rating,
                                $comment
                            ]);

                            $success = 'Your review has been submitted successfully!';

                        } catch (PDOException $e) {

                            // --------------------------------
                            // DATABASE ERROR
                            // --------------------------------
                            error_log(
                                'Carbnb review insert error: ' .
                                $e->getMessage()
                            );

                            $error = 'Unable to submit your review right now. Please try again.';
                        }
                    }
                }
            }
        }
    }
}

// ============================================================
// GET VEHICLE INFORMATION
// ============================================================
$stmt = $conn->prepare("
    SELECT
        v.id,
        v.name,
        v.image,
        v.price_per_day,
        v.category,
        v.transmission,
        v.model_year,
        u.full_name AS owner_name
    FROM vehicles v
    JOIN users u
        ON v.owner_id = u.id
    WHERE v.id = ?
      AND v.is_deleted = 0
    LIMIT 1
");

$stmt->execute([$vehicle_id]);

$vehicle = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vehicle) {
    header('Location: browse.php');
    exit;
}

// ============================================================
// GET EXISTING REVIEWS
// ============================================================
$stmt = $conn->prepare("
    SELECT
        r.rating,
        r.comment,
        r.created_at,
        u.full_name AS renter_name
    FROM reviews r
    JOIN users u
        ON r.renter_id = u.id
    WHERE r.vehicle_id = ?
      AND r.rating BETWEEN 1 AND 5
    ORDER BY r.created_at DESC
");

$stmt->execute([$vehicle_id]);

$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// CALCULATE AVERAGE RATING
// ============================================================
$stmt = $conn->prepare("
    SELECT
        AVG(rating) AS avg_rating,
        COUNT(*) AS total_reviews
    FROM reviews
    WHERE vehicle_id = ?
      AND rating BETWEEN 1 AND 5
");

$stmt->execute([$vehicle_id]);

$rating_stats = $stmt->fetch(PDO::FETCH_ASSOC);

$avg_rating = (
    isset($rating_stats['avg_rating']) &&
    $rating_stats['avg_rating'] !== null
)
    ? round((float) $rating_stats['avg_rating'], 1)
    : 0;

$total_reviews = (int) ($rating_stats['total_reviews'] ?? 0);

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Rate & Comment | Carbnb</title>

    <link
        rel="stylesheet"
        href="../bootstrap-5.3.8-dist/css/bootstrap.min.css"
    >

    <link
        rel="stylesheet"
        href="css/renter_style.css?v=5"
    >

    <link
        rel="stylesheet"
        href="css/renter_style_backup.css?v=4"
    >

</head>

<body>

<!-- ==========================================================
     TOP NAVIGATION
=========================================================== -->

<div class="top-nav">

    <div class="nav-left">

        <h2>Carbnb</h2>

    </div>

    <!-- Mobile Menu Button -->

    <button
        id="mobileMenuBtn"
        class="mobile-menu-btn"
        type="button"
    >
        ☰ Menu
    </button>

    <!-- Navigation -->

    <div
        class="nav-right"
        id="mobileMenu"
    >

        <a
            href="browse.php"
            class="nav-all-cars"
        >
            All Cars
        </a>

        <a
            href="record.php"
            class="nav-my-records"
        >
            My Records
        </a>

        <a
            href="view_profile.php"
            class="nav-my-profile"
        >
            My Profile
        </a>

        <a
            href="renter_messages.php"
            class="nav-my-messages"
        >
            Messages
        </a>

        <a
            href="../auth/logout.php"
            class="logout-link"
        >
            Logout
        </a>

    </div>

</div>


<!-- ==========================================================
     PAGE TITLE
=========================================================== -->

<div class="header-text">

    <h1>
        <span class="blue">Rate &</span>
        <span class="orange">Comment</span>
    </h1>

</div>


<!-- ==========================================================
     MAIN CONTAINER
=========================================================== -->

<div
    class="record-container"
    style="max-width:800px; margin:120px auto 40px;"
>

    <!-- ======================================================
         SUCCESS MESSAGE
    ======================================================= -->

    <?php if ($success): ?>

        <div class="alert success-msg">

            <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>

        </div>

    <?php endif; ?>


    <!-- ======================================================
         ERROR MESSAGE
    ======================================================= -->

    <?php if ($error): ?>

        <div
            class="alert"
            style="color:#dc3545;"
        >

            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>

        </div>

    <?php endif; ?>


    <!-- ======================================================
         VEHICLE INFORMATION
    ======================================================= -->

    <div
        class="card"
        style="
            background:#2a2a2a;
            border:1px solid #333;
            border-radius:12px;
            padding:20px;
            margin-bottom:20px;
        "
    >

        <h3
            style="
                color:#ffd700;
                margin-bottom:15px;
            "
        >
            <?= htmlspecialchars(
                $vehicle['name'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </h3>

        <p style="color:#cfcfcf;">

            <strong style="color:#aaa;">
                Owner:
            </strong>

            <?= htmlspecialchars(
                $vehicle['owner_name'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>

        </p>

        <p style="color:#cfcfcf;">

            <strong style="color:#aaa;">
                Rate:
            </strong>

            ₱<?= number_format(
                (float) $vehicle['price_per_day'],
                2
            ) ?>/day

        </p>


        <!-- ==================================================
             AVERAGE RATING
        =================================================== -->

        <?php if ($total_reviews > 0): ?>

            <div
                style="
                    margin:15px 0;
                    color:#cfcfcf;
                "
            >

                <strong style="color:#aaa;">
                    Average Rating:
                </strong>

                <?php for ($i = 1; $i <= 5; $i++): ?>

                    <span
                        style="
                            color:
                            <?= $i <= $avg_rating
                                ? '#ffd700'
                                : '#444' ?>;
                        "
                    >
                        <?= $i <= $avg_rating
                            ? '★'
                            : '☆' ?>
                    </span>

                <?php endfor; ?>

                <span style="color:#cfcfcf;">

                    (<?= $avg_rating ?>/5 from
                    <?= $total_reviews ?>
                    review<?= $total_reviews > 1 ? 's' : '' ?>)

                </span>

            </div>

        <?php endif; ?>

    </div>


    <!-- ======================================================
         REVIEW FORM
    ======================================================= -->

    <div
        class="card"
        style="
            background:#2a2a2a;
            border:1px solid #333;
            border-radius:12px;
            padding:20px;
            margin-bottom:20px;
        "
    >

        <h3
            style="
                color:#ffd700;
                margin-bottom:15px;
            "
        >
            Write Your Review
        </h3>


        <form
            method="POST"
            action=""
        >

            <?= form_token_input('submit_review') ?>


            <input
                type="hidden"
                name="vehicle_id"
                value="<?= $vehicle_id ?>"
            >


            <!-- ==============================================
                 RATING
            =============================================== -->

            <div
                class="form-group"
                style="margin-bottom:15px;"
            >

                <label
                    style="
                        color:#aaa;
                        display:block;
                        margin-bottom:5px;
                    "
                >
                    Rating (1-5 stars)
                </label>


                <div
                    class="star-rating"
                    style="
                        display:flex;
                        flex-direction:row-reverse;
                        justify-content:flex-start;
                    "
                >

                    <?php for ($i = 5; $i >= 1; $i--): ?>

                        <input
                            type="radio"
                            name="rating"
                            id="star<?= $i ?>"
                            value="<?= $i ?>"
                            required
                        >

                        <label
                            for="star<?= $i ?>"
                            style="
                                color:#444;
                                font-size:2rem;
                                cursor:pointer;
                            "
                        >
                            ★
                        </label>

                    <?php endfor; ?>

                </div>

            </div>


            <!-- ==============================================
                 COMMENT
            =============================================== -->

            <div
                class="form-group"
                style="margin-bottom:15px;"
            >

                <label
                    style="
                        color:#aaa;
                        display:block;
                        margin-bottom:5px;
                    "
                >
                    Comment
                </label>

                <textarea
                    name="comment"
                    rows="4"
                    maxlength="2000"
                    class="form-control"
                    placeholder="Share your experience with this vehicle..."
                    style="
                        width:100%;
                        padding:10px;
                        border-radius:6px;
                        border:1px solid #555;
                        background:#1e1e1e;
                        color:#cfcfcf;
                    "
                ></textarea>

            </div>


            <!-- ==============================================
                 SUBMIT
            =============================================== -->

            <button
                type="submit"
                name="submit_review"
                class="btn-book"
            >
                Submit Review
            </button>

        </form>

    </div>


    <!-- ======================================================
         PREVIOUS REVIEWS
    ======================================================= -->

    <?php if (!empty($reviews)): ?>

        <div
            class="card"
            style="
                background:#2a2a2a;
                border:1px solid #333;
                border-radius:12px;
                padding:20px;
            "
        >

            <h3
                style="
                    color:#ffd700;
                    margin-bottom:15px;
                "
            >
                Previous Reviews
            </h3>


            <?php foreach ($reviews as $review): ?>

                <div
                    style="
                        border-bottom:1px solid #333;
                        padding-bottom:15px;
                        margin-bottom:15px;
                    "
                >

                    <p>

                        <strong>
                            <?= htmlspecialchars(
                                $review['renter_name'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </strong>


                        <span style="color:#ffd700;">

                            <?php
                            $reviewRating = (int) $review['rating'];

                            for ($i = 1; $i <= 5; $i++):
                            ?>

                                <?= $i <= $reviewRating
                                    ? '★'
                                    : '☆' ?>

                            <?php endfor; ?>

                        </span>

                    </p>


                    <?php if (!empty($review['comment'])): ?>

                        <p style="color:#cfcfcf;">

                            <?= nl2br(
                                htmlspecialchars(
                                    $review['comment'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                )
                            ) ?>

                        </p>

                    <?php endif; ?>


                    <small style="color:#888;">

                        <?= date(
                            'M d, Y',
                            strtotime($review['created_at'])
                        ) ?>

                    </small>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>


    <!-- ======================================================
         BACK TO VEHICLE
    ======================================================= -->

    <a
        href="vehicle_details.php?car_id=<?= $vehicle_id ?>"
        class="back-link"
        style="
            color:#ffd700;
            text-decoration:none;
            display:inline-block;
            margin-top:20px;
        "
    >
        ← Back to Vehicle Details
    </a>

</div>


<!-- ==========================================================
     JAVASCRIPT
=========================================================== -->

<script>

document.addEventListener("DOMContentLoaded", function () {

    // ========================================================
    // MOBILE MENU
    // ========================================================

    const mobileMenuBtn =
        document.getElementById("mobileMenuBtn");

    const mobileMenu =
        document.getElementById("mobileMenu");


    if (mobileMenuBtn && mobileMenu) {

        mobileMenuBtn.addEventListener(
            "click",
            function () {

                mobileMenu.classList.toggle("show");

            }
        );


        document.addEventListener(
            "click",
            function (e) {

                if (
                    !mobileMenu.contains(e.target) &&
                    !mobileMenuBtn.contains(e.target)
                ) {

                    mobileMenu.classList.remove("show");

                }

            }
        );

    }


    // ========================================================
    // STAR RATING
    // ========================================================

    const labels =
        document.querySelectorAll(
            ".star-rating label"
        );


    // --------------------------------------------------------
    // HOVER
    // --------------------------------------------------------

    labels.forEach(function (label) {

        label.addEventListener(
            "mouseover",
            function () {

                const value =
                    parseInt(
                        label.htmlFor.replace(
                            "star",
                            ""
                        )
                    );


                labels.forEach(function (item) {

                    const itemValue =
                        parseInt(
                            item.htmlFor.replace(
                                "star",
                                ""
                            )
                        );


                    item.style.color =
                        itemValue <= value
                            ? "#ffd700"
                            : "#444";

                });

            }
        );

    });


    // --------------------------------------------------------
    // MOUSE LEAVE
    // --------------------------------------------------------

    const starContainer =
        document.querySelector(
            ".star-rating"
        );


    if (starContainer) {

        starContainer.addEventListener(
            "mouseleave",
            function () {

                const checked =
                    document.querySelector(
                        ".star-rating input:checked"
                    );


                if (checked) {

                    const value =
                        parseInt(
                            checked.value
                        );


                    labels.forEach(function (item) {

                        const itemValue =
                            parseInt(
                                item.htmlFor.replace(
                                    "star",
                                    ""
                                )
                            );


                        item.style.color =
                            itemValue <= value
                                ? "#ffd700"
                                : "#444";

                    });

                } else {

                    labels.forEach(
                        function (item) {

                            item.style.color =
                                "#444";

                        }
                    );

                }

            }
        );

    }

});

</script>

</body>
</html>

