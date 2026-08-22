<?php

declare(strict_types=1);

require_once __DIR__ . '/../database/db.php';

/*
|--------------------------------------------------------------------------
| Start session
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/*
|--------------------------------------------------------------------------
| JSON response
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');


/*
|--------------------------------------------------------------------------
| PAYMONGO LIVE CONFIGURATION
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Replace YOUR_NEW_LIVE_SECRET_KEY with your actual NEW PayMongo
| LIVE secret key.
|
| Do NOT use the old live key that was previously exposed.
|
*/

$secret_key = 'sk_live_Mw72uGtF8YesDSLEaSYTZdeC';


/*
|--------------------------------------------------------------------------
| Your live website URL
|--------------------------------------------------------------------------
|
| Your Carbnb files are inside /renter/
|
| Therefore:
|
| https://carbnb.infinityfree.me/renter/browse.php
| https://carbnb.infinityfree.me/renter/paid.php
|
*/

$app_url = 'http://localhost/Carbnb_Project2';


/*
|--------------------------------------------------------------------------
| Helper: JSON response
|--------------------------------------------------------------------------
*/

function json_response(array $data, int $status_code = 200): void
{
    http_response_code($status_code);

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Database connection
|--------------------------------------------------------------------------
*/

$conn = $GLOBALS['conn'] ?? $GLOBALS['pdo'] ?? null;

if (!$conn instanceof PDO) {

    error_log(
        'PayMongo payment_gateway.php: Database connection failed.'
    );

    json_response([
        'success' => false,
        'message' => 'Database connection failed.'
    ], 500);
}


/*
|--------------------------------------------------------------------------
| Check login
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['user_id'])) {

    json_response([
        'success' => false,
        'message' => 'You must be logged in.'
    ], 401);
}


$user_id = (int) $_SESSION['user_id'];


/*
|--------------------------------------------------------------------------
| Get booking ID
|--------------------------------------------------------------------------
*/

$booking_id = filter_input(
    INPUT_POST,
    'booking_id',
    FILTER_VALIDATE_INT
);

if (!$booking_id || $booking_id <= 0) {

    json_response([
        'success' => false,
        'message' => 'Invalid booking ID.'
    ], 400);
}


/*
|--------------------------------------------------------------------------
| Get booking information
|--------------------------------------------------------------------------
|
| IMPORTANT:
| We get the amount from the database.
|
| We DO NOT trust an amount sent by the browser.
|
*/

try {

    $stmt = $conn->prepare("
        SELECT
            b.id,
            b.total_price,
            v.name AS vehicle_name
        FROM bookings b
        INNER JOIN vehicles v
            ON b.vehicle_id = v.id
        WHERE b.id = ?
          AND b.renter_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $booking_id,
        $user_id
    ]);

    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    error_log(
        'PayMongo payment_gateway.php: Booking lookup failed. ' .
        $e->getMessage()
    );

    json_response([
        'success' => false,
        'message' => 'Unable to retrieve booking information.'
    ], 500);
}


/*
|--------------------------------------------------------------------------
| Booking does not exist
|--------------------------------------------------------------------------
*/

if (!$booking) {

    json_response([
        'success' => false,
        'message' =>
            'Booking not found or you do not have permission to pay for it.'
    ], 404);
}


/*
|--------------------------------------------------------------------------
| Validate booking price
|--------------------------------------------------------------------------
*/

$total_price = (float) $booking['total_price'];

if (!is_finite($total_price) || $total_price <= 0) {

    error_log(
        'PayMongo payment_gateway.php: Invalid booking amount. ' .
        'Booking ID: ' . $booking_id .
        ' Amount: ' . $booking['total_price']
    );

    json_response([
        'success' => false,
        'message' => 'Invalid booking payment amount.'
    ], 400);
}


/*
|--------------------------------------------------------------------------
| Convert PHP pesos to centavos
|--------------------------------------------------------------------------
|
| Example:
|
| ₱1,500.00
|
| becomes:
|
| 150000
|
*/

$amount_cents = (int) round($total_price * 100);


/*
|--------------------------------------------------------------------------
| Minimum payment validation
|--------------------------------------------------------------------------
*/

if ($amount_cents < 100) {

    json_response([
        'success' => false,
        'message' => 'Payment amount is too small.'
    ], 400);
}


/*
|--------------------------------------------------------------------------
| Validate PayMongo LIVE key
|--------------------------------------------------------------------------
*/

$secret_key = trim($secret_key);

if ($secret_key === '') {

    error_log(
        'PayMongo payment_gateway.php: Secret key is empty.'
    );

    json_response([
        'success' => false,
        'message' => 'PayMongo secret key is not configured.'
    ], 500);
}


/*
|--------------------------------------------------------------------------
| Make sure the key is a PayMongo secret key
|--------------------------------------------------------------------------
*/

if (
    !str_starts_with($secret_key, 'sk_live_') &&
    !str_starts_with($secret_key, 'sk_test_')
) {

    error_log(
        'PayMongo payment_gateway.php: Invalid secret key format.'
    );

    json_response([
        'success' => false,
        'message' => 'Invalid PayMongo secret key configuration.'
    ], 500);
}


/*
|--------------------------------------------------------------------------
| Validate application URL
|--------------------------------------------------------------------------
*/

$app_url = rtrim(trim($app_url), '/');

if (!filter_var($app_url, FILTER_VALIDATE_URL)) {

    error_log(
        'PayMongo payment_gateway.php: Invalid APP URL: ' .
        $app_url
    );

    json_response([
        'success' => false,
        'message' => 'Invalid application URL configuration.'
    ], 500);
}


/*
|--------------------------------------------------------------------------
| Booking reference
|--------------------------------------------------------------------------
|
| This is extremely useful for your webhook.
|
| Example:
|
| CARBNB-BOOKING-123
|
*/

$reference = 'CARBNB-BOOKING-' . $booking_id;


/*
|--------------------------------------------------------------------------
| Create PayMongo Checkout Session
|--------------------------------------------------------------------------
|
| PayMongo currently recommends:
|
| POST /v2/checkout_sessions
|
*/

$payload = [
    'data' => [
        'attributes' => [

            /*
             * PayMongo receipt email.
             *
             * Set to false because your own system can
             * handle receipts/notifications.
             */
            'send_email_receipt' => false,

            /*
             * Show description.
             */
            'show_description' => true,

            /*
             * Show line items.
             */
            'show_line_items' => true,

            /*
             * Booking item.
             */
            'line_items' => [
                [
                    'currency' => 'PHP',

                    'amount' => $amount_cents,

                    'description' =>
                        'Car rental: ' .
                        (string) $booking['vehicle_name'],

                    'name' =>
                        'Carbnb Booking #' .
                        $booking_id,

                    'quantity' => 1
                ]
            ],

            /*
             * Payment methods.
             *
             * These must be enabled for your PayMongo
             * account.
             */
            'payment_method_types' => [
                'card',
                'gcash',
                'paymaya',
                'qrph'
            ],

            /*
             * Your internal booking reference.
             */
            'reference_number' => $reference,

            /*
             * Customer returns here after successful checkout.
             *
             * IMPORTANT:
             *
             * This URL DOES NOT prove that the payment succeeded.
             * Your webhook confirms the payment.
             */
            'success_url' =>
                $app_url .
                '/renter/browse.php' .
                '?payment=success' .
                '&booking_id=' .
                $booking_id,

            /*
             * Customer returns here if they cancel checkout.
             */
            'cancel_url' =>
                $app_url .
                '/renter/paid.php' .
                '?booking_id=' .
                $booking_id .
                '&payment=cancel'
        ]
    ]
];


/*
|--------------------------------------------------------------------------
| Convert payload to JSON
|--------------------------------------------------------------------------
*/

$json_payload = json_encode(
    $payload,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

if ($json_payload === false) {

    error_log(
        'PayMongo payment_gateway.php: JSON encoding failed. ' .
        json_last_error_msg()
    );

    json_response([
        'success' => false,
        'message' => 'Unable to prepare payment request.'
    ], 500);
}


/*
|--------------------------------------------------------------------------
| Initialize cURL
|--------------------------------------------------------------------------
*/

$ch = curl_init(
    'https://api.paymongo.com/v2/checkout_sessions'
);

if ($ch === false) {

    error_log(
        'PayMongo payment_gateway.php: curl_init() failed.'
    );

    json_response([
        'success' => false,
        'message' => 'Unable to connect to PayMongo.'
    ], 502);
}


/*
|--------------------------------------------------------------------------
| Configure cURL
|--------------------------------------------------------------------------
*/

curl_setopt_array($ch, [

    /*
     * Return PayMongo response instead of printing it.
     */
    CURLOPT_RETURNTRANSFER => true,

    /*
     * POST request.
     */
    CURLOPT_POST => true,

    /*
     * JSON request.
     */
    CURLOPT_POSTFIELDS => $json_payload,

    /*
     * HTTP headers.
     */
    CURLOPT_HTTPHEADER => [

        'Content-Type: application/json',

        'Accept: application/json',

        /*
         * PayMongo uses HTTP Basic Authentication.
         *
         * Secret key = username
         * Password = empty
         *
         * Therefore:
         *
         * base64(secret_key + ":")
         */
        'Authorization: Basic ' .
        base64_encode($secret_key . ':')
    ],

    /*
     * Connection timeout.
     */
    CURLOPT_CONNECTTIMEOUT => 10,

    /*
     * Maximum request time.
     */
    CURLOPT_TIMEOUT => 30,

    /*
     * Verify SSL certificate.
     */
    CURLOPT_SSL_VERIFYPEER => true,

    CURLOPT_SSL_VERIFYHOST => 2
]);


/*
|--------------------------------------------------------------------------
| Send request
|--------------------------------------------------------------------------
*/

$response = curl_exec($ch);

$http_code = (int) curl_getinfo(
    $ch,
    CURLINFO_HTTP_CODE
);

$curl_error = curl_error($ch);

curl_close($ch);


/*
|--------------------------------------------------------------------------
| cURL error
|--------------------------------------------------------------------------
*/

if ($response === false) {

    error_log(
        'PayMongo payment_gateway.php: cURL failed. ' .
        $curl_error
    );

    json_response([
        'success' => false,
        'message' => 'Unable to connect to PayMongo.'
    ], 502);
}


/*
|--------------------------------------------------------------------------
| Decode PayMongo response
|--------------------------------------------------------------------------
*/

$result = json_decode(
    $response,
    true
);

if (!is_array($result)) {

    error_log(
        'PayMongo payment_gateway.php: Invalid JSON response. ' .
        'HTTP Code: ' .
        $http_code .
        ' Response: ' .
        substr($response, 0, 3000)
    );

    json_response([
        'success' => false,
        'message' => 'Invalid response from PayMongo.'
    ], 502);
}


/*
|--------------------------------------------------------------------------
| Handle PayMongo API errors
|--------------------------------------------------------------------------
*/

if ($http_code < 200 || $http_code >= 300) {

    $error_message =
        'Unable to create PayMongo checkout.';

    if (
        !empty($result['errors']) &&
        is_array($result['errors'])
    ) {

        $details = [];

        foreach ($result['errors'] as $error) {

            if (!empty($error['detail'])) {

                $details[] =
                    (string) $error['detail'];
            }
        }

        if (!empty($details)) {

            $error_message .=
                ' ' .
                implode(' ', $details);
        }
    }

    /*
     * Log complete PayMongo error server-side.
     */
    error_log(
        'PayMongo API error. ' .
        'HTTP Code: ' .
        $http_code .
        ' Response: ' .
        substr($response, 0, 5000)
    );

    json_response([
        'success' => false,
        'message' => $error_message
    ], 502);
}


/*
|--------------------------------------------------------------------------
| Extract Checkout Session
|--------------------------------------------------------------------------
*/

$session_data =
    $result['data'] ?? null;

if (!is_array($session_data)) {

    error_log(
        'PayMongo payment_gateway.php: Missing data object.'
    );

    json_response([
        'success' => false,
        'message' =>
            'PayMongo returned an unexpected response.'
    ], 502);
}


/*
|--------------------------------------------------------------------------
| Get Checkout Session ID
|--------------------------------------------------------------------------
*/

$session_id =
    (string) ($session_data['id'] ?? '');


/*
|--------------------------------------------------------------------------
| Get Checkout URL
|--------------------------------------------------------------------------
*/

$checkout_url =
    (string) (
        $session_data['attributes']['checkout_url']
        ?? ''
    );


/*
|--------------------------------------------------------------------------
| Make sure PayMongo returned the required data
|--------------------------------------------------------------------------
*/

if (
    $session_id === '' ||
    $checkout_url === ''
) {

    error_log(
        'PayMongo payment_gateway.php: Missing checkout URL/session ID. ' .
        'Response: ' .
        substr($response, 0, 5000)
    );

    json_response([
        'success' => false,
        'message' =>
            'PayMongo did not return a checkout URL.'
    ], 502);
}


/*
|--------------------------------------------------------------------------
| Make sure Checkout URL is HTTPS
|--------------------------------------------------------------------------
*/

if (!filter_var($checkout_url, FILTER_VALIDATE_URL)) {

    error_log(
        'PayMongo payment_gateway.php: Invalid checkout URL returned.'
    );

    json_response([
        'success' => false,
        'message' =>
            'PayMongo returned an invalid checkout URL.'
    ], 502);
}


/*
|--------------------------------------------------------------------------
| Save payment in database
|--------------------------------------------------------------------------
|
| Payment starts as:
|
| pending
|
| It becomes:
|
| paid
|
| ONLY after the PayMongo webhook confirms:
|
| checkout_session.payment.paid
|
*/

try {

    /*
     * Check if this booking already has a payment.
     */
    $check = $conn->prepare("
        SELECT
            id,
            status
        FROM payments
        WHERE booking_id = ?
        LIMIT 1
    ");

    $check->execute([
        $booking_id
    ]);

    $existing_payment =
        $check->fetch(PDO::FETCH_ASSOC);


    /*
     * Existing payment
     */
    if ($existing_payment) {

        /*
         * Do not create another payment for an
         * already-paid booking.
         */
        if (
            isset($existing_payment['status']) &&
            strtolower(
                (string) $existing_payment['status']
            ) === 'paid'
        ) {

            json_response([
                'success' => false,
                'message' =>
                    'This booking has already been paid.'
            ], 409);
        }


        /*
         * Update existing payment.
         */
        $update = $conn->prepare("
            UPDATE payments
            SET
                amount = ?,
                payment_method = 'paymongo',
                transaction_reference = ?,
                gateway_response = ?,
                payment_url = ?,
                status = 'pending'
            WHERE id = ?
        ");

        $gateway_response = json_encode(
            $result,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        );

        $update->execute([

            $total_price,

            $session_id,

            $gateway_response,

            $checkout_url,

            $existing_payment['id']
        ]);
    }


    /*
     * New payment
     */
    else {

        $gateway_response = json_encode(
            $result,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        );

        $insert = $conn->prepare("
            INSERT INTO payments
            (
                booking_id,
                amount,
                payment_method,
                transaction_reference,
                gateway_response,
                payment_url,
                status
            )
            VALUES
            (
                ?,
                ?,
                'paymongo',
                ?,
                ?,
                ?,
                'pending'
            )
        ");

        $insert->execute([

            $booking_id,

            $total_price,

            $session_id,

            $gateway_response,

            $checkout_url
        ]);
    }

} catch (PDOException $e) {

    /*
     * Log database error.
     *
     * Never expose the actual SQL error to the customer.
     */
    error_log(
        'PayMongo payment_gateway.php: Database payment save failed. ' .
        'Booking ID: ' .
        $booking_id .
        ' Error: ' .
        $e->getMessage()
    );

    json_response([
        'success' => false,
        'message' =>
            'Checkout was created, but we could not save the payment information.'
    ], 500);
}


/*
|--------------------------------------------------------------------------
| SUCCESS
|--------------------------------------------------------------------------
|
| Return the Checkout URL to your frontend.
|
*/

json_response([

    'success' => true,

    'checkout_url' =>
        $checkout_url,

    'payment_reference' =>
        $reference,

    'booking_id' =>
        $booking_id

], 200);