<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| paymongo_webhook.php
|--------------------------------------------------------------------------
|
| Handles:
|
|     checkout_session.payment.paid
|
| PayMongo confirms the payment through this webhook.
|
| IMPORTANT:
| - Do NOT trust success_url as proof of payment.
| - Payment starts as "pending".
| - This webhook changes it to "verified".
| - PAYMONGO_WEBHOOK_SECRET must be configured on the server.
|
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../database/db.php';


/*
|--------------------------------------------------------------------------
| JSON / Plain Response Helper
|--------------------------------------------------------------------------
*/

function webhook_response(
    string $message,
    int $status_code = 200
): void {

    http_response_code($status_code);

    header('Content-Type: text/plain; charset=utf-8');

    echo $message;

    exit;
}


/*
|--------------------------------------------------------------------------
| Database Connection
|--------------------------------------------------------------------------
*/

$conn = $GLOBALS['conn'] ?? $GLOBALS['pdo'] ?? null;

if (!$conn instanceof PDO) {

    http_response_code(500);

    error_log(
        'PayMongo webhook: Database connection failed.'
    );

    exit('Database connection failed');
}


/*
|--------------------------------------------------------------------------
| Read Raw Webhook Payload
|--------------------------------------------------------------------------
|
| IMPORTANT:
| The raw body must be used for signature verification.
|
|--------------------------------------------------------------------------
*/

$raw_payload = file_get_contents('php://input');

if ($raw_payload === false || trim($raw_payload) === '') {

    error_log(
        'PayMongo webhook: Empty payload.'
    );

    webhook_response(
        'Empty payload',
        400
    );
}


/*
|--------------------------------------------------------------------------
| Get PayMongo Webhook Secret
|--------------------------------------------------------------------------
|
| Configure this on your server:
|
| PAYMONGO_WEBHOOK_SECRET
|
| Do NOT put the actual secret in this source code.
|
|--------------------------------------------------------------------------
*/

$webhook_secret = getenv('PAYMONGO_WEBHOOK_SECRET');

if (
    $webhook_secret === false ||
    trim($webhook_secret) === ''
) {

    error_log(
        'PayMongo webhook: PAYMONGO_WEBHOOK_SECRET is not configured.'
    );

    webhook_response(
        'Webhook secret not configured',
        500
    );
}

$webhook_secret = trim($webhook_secret);


/*
|--------------------------------------------------------------------------
| Get PayMongo Signature Header
|--------------------------------------------------------------------------
*/

$signature_header =
    $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

if (
    !is_string($signature_header) ||
    trim($signature_header) === ''
) {

    error_log(
        'PayMongo webhook: Missing PayMongo signature header.'
    );

    webhook_response(
        'Missing signature',
        401
    );
}

$signature_header = trim($signature_header);


/*
|--------------------------------------------------------------------------
| Parse PayMongo Signature
|--------------------------------------------------------------------------
|
| PayMongo signature contains values similar to:
|
| t=timestamp,te=test_signature,li=live_signature
|
|--------------------------------------------------------------------------
*/

$timestamp = null;
$live_signature = null;


/*
|--------------------------------------------------------------------------
| Extract Timestamp
|--------------------------------------------------------------------------
*/

if (
    preg_match(
        '/(?:^|,)t=([^,]+)/',
        $signature_header,
        $matches
    )
) {

    $timestamp = trim($matches[1]);
}


/*
|--------------------------------------------------------------------------
| Extract Live Signature
|--------------------------------------------------------------------------
*/

if (
    preg_match(
        '/(?:^|,)li=([^,]+)/',
        $signature_header,
        $matches
    )
) {

    $live_signature = trim($matches[1]);
}


/*
|--------------------------------------------------------------------------
| Validate Signature Components
|--------------------------------------------------------------------------
*/

if (
    $timestamp === null ||
    $timestamp === '' ||
    $live_signature === null ||
    $live_signature === ''
) {

    error_log(
        'PayMongo webhook: Invalid signature format.'
    );

    webhook_response(
        'Invalid signature format',
        401
    );
}


/*
|--------------------------------------------------------------------------
| Validate Timestamp
|--------------------------------------------------------------------------
|
| Prevent accepting extremely old/future webhook requests.
|
| 5 minutes is normally enough for webhook delivery.
|
|--------------------------------------------------------------------------
*/

if (!ctype_digit($timestamp)) {

    error_log(
        'PayMongo webhook: Invalid signature timestamp.'
    );

    webhook_response(
        'Invalid signature timestamp',
        401
    );
}

$timestamp_int = (int) $timestamp;

$current_time = time();

$timestamp_difference =
    abs($current_time - $timestamp_int);

if ($timestamp_difference > 300) {

    error_log(
        'PayMongo webhook: Signature timestamp too old or too far in the future.'
    );

    webhook_response(
        'Expired signature',
        401
    );
}


/*
|--------------------------------------------------------------------------
| Build Signature Payload
|--------------------------------------------------------------------------
|
| PayMongo signs:
|
| timestamp + "." + raw_payload
|
|--------------------------------------------------------------------------
*/

$payload_to_sign =
    $timestamp . '.' . $raw_payload;


/*
|--------------------------------------------------------------------------
| Calculate HMAC SHA-256
|--------------------------------------------------------------------------
*/

$computed_signature = hash_hmac(
    'sha256',
    $payload_to_sign,
    $webhook_secret
);


/*
|--------------------------------------------------------------------------
| Compare Signatures Securely
|--------------------------------------------------------------------------
*/

if (
    !hash_equals(
        $computed_signature,
        $live_signature
    )
) {

    error_log(
        'PayMongo webhook: Invalid webhook signature.'
    );

    webhook_response(
        'Invalid signature',
        401
    );
}


/*
|--------------------------------------------------------------------------
| Decode JSON Payload
|--------------------------------------------------------------------------
*/

$event = json_decode(
    $raw_payload,
    true
);

if (
    !is_array($event) ||
    json_last_error() !== JSON_ERROR_NONE
) {

    error_log(
        'PayMongo webhook: Invalid JSON payload. ' .
        json_last_error_msg()
    );

    webhook_response(
        'Invalid JSON payload',
        400
    );
}


/*
|--------------------------------------------------------------------------
| Get Event Data
|--------------------------------------------------------------------------
*/

$event_data =
    $event['data'] ?? null;

if (!is_array($event_data)) {

    error_log(
        'PayMongo webhook: Missing event data.'
    );

    webhook_response(
        'Invalid event structure',
        400
    );
}


/*
|--------------------------------------------------------------------------
| Get Event Attributes
|--------------------------------------------------------------------------
*/

$event_attributes =
    $event_data['attributes'] ?? null;

if (!is_array($event_attributes)) {

    error_log(
        'PayMongo webhook: Missing event attributes.'
    );

    webhook_response(
        'Invalid event attributes',
        400
    );
}


/*
|--------------------------------------------------------------------------
| Get Event Type
|--------------------------------------------------------------------------
*/

$event_type =
    (string) (
        $event_attributes['type']
        ?? ''
    );


/*
|--------------------------------------------------------------------------
| Ignore Events We Don't Need
|--------------------------------------------------------------------------
*/

if (
    $event_type !==
    'checkout_session.payment.paid'
) {

    /*
     * PayMongo expects a successful response for
     * events that we intentionally ignore.
     */

    error_log(
        'PayMongo webhook: Ignored event type: ' .
        $event_type
    );

    webhook_response(
        'Event ignored',
        200
    );
}


/*
|--------------------------------------------------------------------------
| Verify Live Mode
|--------------------------------------------------------------------------
*/

$livemode =
    $event_attributes['livemode']
    ?? false;

if ($livemode !== true) {

    error_log(
        'PayMongo webhook: Non-live event received.'
    );

    /*
     * We intentionally acknowledge it rather than
     * repeatedly receiving the same test event.
     */

    webhook_response(
        'Non-live event ignored',
        200
    );
}


/*
|--------------------------------------------------------------------------
| Get Checkout Session
|--------------------------------------------------------------------------
|
| The webhook event contains the Checkout Session
| under:
|
| data.attributes.data
|
|--------------------------------------------------------------------------
*/

$session_data =
    $event_attributes['data']
    ?? null;

if (!is_array($session_data)) {

    error_log(
        'PayMongo webhook: Missing checkout session data.'
    );

    webhook_response(
        'Invalid checkout session data',
        400
    );
}


/*
|--------------------------------------------------------------------------
| Checkout Session ID
|--------------------------------------------------------------------------
*/

$checkout_session_id =
    (string) (
        $session_data['id']
        ?? ''
    );

if ($checkout_session_id === '') {

    error_log(
        'PayMongo webhook: Missing checkout session ID.'
    );

    webhook_response(
        'Missing checkout session ID',
        400
    );
}


/*
|--------------------------------------------------------------------------
| Checkout Session Attributes
|--------------------------------------------------------------------------
*/

$session_attributes =
    $session_data['attributes']
    ?? null;

if (!is_array($session_attributes)) {

    error_log(
        'PayMongo webhook: Missing checkout session attributes.'
    );

    webhook_response(
        'Invalid checkout session attributes',
        400
    );
}


/*
|--------------------------------------------------------------------------
| Get Booking Reference
|--------------------------------------------------------------------------
|
| Created by payment_gateway.php:
|
| CARBNB-BOOKING-{booking_id}
|
|--------------------------------------------------------------------------
*/

$reference =
    (string) (
        $session_attributes['reference_number']
        ?? ''
    );

$reference_prefix =
    'CARBNB-BOOKING-';


if (
    $reference === '' ||
    !str_starts_with(
        $reference,
        $reference_prefix
    )
) {

    error_log(
        'PayMongo webhook: Invalid booking reference: ' .
        $reference
    );

    webhook_response(
        'Invalid booking reference',
        400
    );
}


/*
|--------------------------------------------------------------------------
| Extract Booking ID
|--------------------------------------------------------------------------
*/

$booking_id_string =
    substr(
        $reference,
        strlen($reference_prefix)
    );


if (
    $booking_id_string === '' ||
    !ctype_digit($booking_id_string)
) {

    error_log(
        'PayMongo webhook: Invalid booking ID in reference: ' .
        $reference
    );

    webhook_response(
        'Invalid booking ID',
        400
    );
}


$booking_id =
    (int) $booking_id_string;


if ($booking_id <= 0) {

    error_log(
        'PayMongo webhook: Booking ID is not valid.'
    );

    webhook_response(
        'Invalid booking ID',
        400
    );
}


/*
|--------------------------------------------------------------------------
| Get Payments From Checkout Session
|--------------------------------------------------------------------------
*/

$payments =
    $session_attributes['payments']
    ?? [];


if (!is_array($payments)) {

    error_log(
        'PayMongo webhook: Invalid payments data for booking ' .
        $booking_id
    );

    webhook_response(
        'Invalid payment data',
        400
    );
}


/*
|--------------------------------------------------------------------------
| Find Paid Payment
|--------------------------------------------------------------------------
*/

$paid_payment = null;


foreach ($payments as $payment) {

    if (!is_array($payment)) {
        continue;
    }

    $payment_attributes =
        $payment['attributes']
        ?? null;

    if (!is_array($payment_attributes)) {
        continue;
    }

    $payment_status =
        (string) (
            $payment_attributes['status']
            ?? ''
        );

    if ($payment_status === 'paid') {

        $paid_payment = $payment_attributes;

        break;
    }
}


/*
|--------------------------------------------------------------------------
| Make Sure a Paid Payment Exists
|--------------------------------------------------------------------------
*/

if (!is_array($paid_payment)) {

    error_log(
        'PayMongo webhook: checkout_session.payment.paid received ' .
        'but no paid payment was found. Booking ID: ' .
        $booking_id
    );

    /*
     * Acknowledge the event.
     */

    webhook_response(
        'No paid payment found',
        200
    );
}


/*
|--------------------------------------------------------------------------
| Extract PayMongo Payment Information
|--------------------------------------------------------------------------
*/

$paid_amount_cents =
    (int) (
        $paid_payment['amount']
        ?? 0
    );

$paid_currency =
    strtoupper(
        (string) (
            $paid_payment['currency']
            ?? ''
        )
    );

$paymongo_payment_id =
    (string) (
        $paid_payment['id']
        ?? ''
    );


/*
|--------------------------------------------------------------------------
| Validate Amount
|--------------------------------------------------------------------------
*/

if ($paid_amount_cents <= 0) {

    error_log(
        'PayMongo webhook: Invalid paid amount. ' .
        'Booking ID: ' .
        $booking_id
    );

    webhook_response(
        'Invalid payment amount',
        400
    );
}


/*
|--------------------------------------------------------------------------
| Validate Currency
|--------------------------------------------------------------------------
*/

if ($paid_currency !== 'PHP') {

    error_log(
        'PayMongo webhook: Invalid currency. ' .
        'Booking ID: ' .
        $booking_id .
        ' Currency: ' .
        $paid_currency
    );

    webhook_response(
        'Invalid payment currency',
        400
    );
}


/*
|--------------------------------------------------------------------------
| Find Booking
|--------------------------------------------------------------------------
*/

try {

    $stmt = $conn->prepare("
        SELECT
            id,
            total_price
        FROM bookings
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $booking_id
    ]);

    $booking =
        $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    error_log(
        'PayMongo webhook: Booking lookup failed. ' .
        'Booking ID: ' .
        $booking_id .
        ' Error: ' .
        $e->getMessage()
    );

    webhook_response(
        'Database error',
        500
    );
}


/*
|--------------------------------------------------------------------------
| Booking Must Exist
|--------------------------------------------------------------------------
*/

if (!$booking) {

    error_log(
        'PayMongo webhook: Booking not found. ' .
        'Booking ID: ' .
        $booking_id
    );

    webhook_response(
        'Booking not found',
        404
    );
}


/*
|--------------------------------------------------------------------------
| Calculate Expected Amount
|--------------------------------------------------------------------------
*/

$booking_total =
    (float) $booking['total_price'];


if (
    !is_finite($booking_total) ||
    $booking_total <= 0
) {

    error_log(
        'PayMongo webhook: Invalid booking total. ' .
        'Booking ID: ' .
        $booking_id
    );

    webhook_response(
        'Invalid booking amount',
        400
    );
}


$expected_amount_cents =
    (int) round(
        $booking_total * 100
    );


/*
|--------------------------------------------------------------------------
| Verify Amount
|--------------------------------------------------------------------------
*/

if (
    $paid_amount_cents !==
    $expected_amount_cents
) {

    error_log(
        'PayMongo webhook: Amount mismatch. ' .
        'Booking ID: ' .
        $booking_id .
        ' Expected: ' .
        $expected_amount_cents .
        ' PHP cents, Received: ' .
        $paid_amount_cents .
        ' PHP cents.'
    );

    webhook_response(
        'Payment amount mismatch',
        400
    );
}


/*
|--------------------------------------------------------------------------
| Find Payment Record
|--------------------------------------------------------------------------
*/

try {

    $stmt = $conn->prepare("
        SELECT
            id,
            booking_id,
            amount,
            payment_method,
            transaction_reference,
            status
        FROM payments
        WHERE booking_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $booking_id
    ]);

    $payment =
        $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    error_log(
        'PayMongo webhook: Payment lookup failed. ' .
        'Booking ID: ' .
        $booking_id .
        ' Error: ' .
        $e->getMessage()
    );

    webhook_response(
        'Database error',
        500
    );
}


/*
|--------------------------------------------------------------------------
| Payment Record Must Exist
|--------------------------------------------------------------------------
*/

if (!$payment) {

    error_log(
        'PayMongo webhook: Payment record not found. ' .
        'Booking ID: ' .
        $booking_id
    );

    webhook_response(
        'Payment record not found',
        404
    );
}


/*
|--------------------------------------------------------------------------
| Duplicate Protection
|--------------------------------------------------------------------------
*/

$current_status =
    strtolower(
        trim(
            (string) (
                $payment['status']
                ?? ''
            )
        )
    );


if ($current_status === 'verified') {

    error_log(
        'PayMongo webhook: Payment already verified. ' .
        'Booking ID: ' .
        $booking_id
    );

    webhook_response(
        'Payment already verified',
        200
    );
}


/*
|--------------------------------------------------------------------------
| Verify Payment Belongs to PayMongo
|--------------------------------------------------------------------------
*/

$current_method =
    strtolower(
        trim(
            (string) (
                $payment['payment_method']
                ?? ''
            )
        )
    );


if ($current_method !== 'paymongo') {

    error_log(
        'PayMongo webhook: Payment method mismatch. ' .
        'Booking ID: ' .
        $booking_id .
        ' Method: ' .
        $current_method
    );

    webhook_response(
        'Payment method mismatch',
        400
    );
}


/*
|--------------------------------------------------------------------------
| Update Payment
|--------------------------------------------------------------------------
|
| The gateway initially creates:
|
|     status = pending
|
| This webhook changes it to:
|
|     status = verified
|
|--------------------------------------------------------------------------
*/

try {

    $update = $conn->prepare("
        UPDATE payments
        SET
            status = 'verified',
            transaction_reference = ?,
            gateway_response = ?
        WHERE booking_id = ?
          AND status <> 'verified'
        LIMIT 1
    ");

    $gateway_response =
        json_encode(
            $event,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        );

    if ($gateway_response === false) {

        $gateway_response =
            '{"webhook":"checkout_session.payment.paid"}';
    }

    $update->execute([

        /*
         * Use the actual PayMongo payment ID when
         * available.
         */
        $paymongo_payment_id !== ''
            ? $paymongo_payment_id
            : $checkout_session_id,

        $gateway_response,

        $booking_id
    ]);

} catch (PDOException $e) {

    error_log(
        'PayMongo webhook: Payment update failed. ' .
        'Booking ID: ' .
        $booking_id .
        ' Error: ' .
        $e->getMessage()
    );

    /*
     * Return 500 so PayMongo can retry the webhook.
     */

    webhook_response(
        'Database update failed',
        500
    );
}


/*
|--------------------------------------------------------------------------
| Verify Update Actually Happened
|--------------------------------------------------------------------------
*/

if ($update->rowCount() === 0) {

    /*
     * This can happen if another webhook request processed
     * the same payment at almost the same time.
     *
     * Check the current database status.
     */

    try {

        $verify = $conn->prepare("
            SELECT status
            FROM payments
            WHERE booking_id = ?
            LIMIT 1
        ");

        $verify->execute([
            $booking_id
        ]);

        $verified_payment =
            $verify->fetch(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {

        error_log(
            'PayMongo webhook: Unable to verify payment update. ' .
            'Booking ID: ' .
            $booking_id .
            ' Error: ' .
            $e->getMessage()
        );

        webhook_response(
            'Database verification failed',
            500
        );
    }


    if (
        !$verified_payment ||
        strtolower(
            (string) $verified_payment['status']
        ) !== 'verified'
    ) {

        error_log(
            'PayMongo webhook: Payment status was not updated. ' .
            'Booking ID: ' .
            $booking_id
        );

        webhook_response(
            'Payment status update failed',
            500
        );
    }
}


/*
|--------------------------------------------------------------------------
| SUCCESS
|--------------------------------------------------------------------------
*/

error_log(
    'PayMongo webhook: Payment verified successfully. ' .
    'Booking ID: ' .
    $booking_id .
    ' Checkout Session: ' .
    $checkout_session_id .
    ' PayMongo Payment: ' .
    ($paymongo_payment_id !== ''
        ? $paymongo_payment_id
        : 'N/A')
);


webhook_response(
    'OK',
    200
);