<?php
/**
 * KúkiCakes — POST /api/payhere-notify.php
 *
 * PayHere Server-to-Server Payment Notification (IPN) Webhook.
 *
 * Requirements & Security:
 * 1. Receives asynchronous POST notifications directly from PayHere payment gateway.
 * 2. Validates merchant_id against server configuration.
 * 3. Verifies cryptographic MD5 signature using server-only PAYHERE_MERCHANT_SECRET.
 * 4. Verifies order exists, currency is LKR, and amount exactly matches order total.
 * 5. Updates `payment_status` ('paid', 'failed', 'cancelled', 'charged_back', 'pending').
 * 6. Sets `order_status` to 'confirmed' upon verified successful payment (status_code 2).
 * 7. Stores PayHere payment_id reference for auditing.
 * 8. Implements idempotency: duplicate webhook calls return 200 OK without re-processing.
 * 9. Never trusts client-side callbacks as proof of payment.
 */

// Application timezone
date_default_timezone_set('Asia/Colombo');

// Set JSON headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Load database connection & PayHere configuration
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/payhere.php';

// Helper response function
function ipnRespond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Only POST requests are permitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ipnRespond(['success' => false, 'message' => 'Method not allowed. Use POST.'], 405);
}

// Parse incoming payload (supports urlencoded form data or raw body)
$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    if ($raw) {
        parse_str($raw, $parsed);
        if (!empty($parsed)) {
            $input = $parsed;
        } else {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $input = $json;
            }
        }
    }
}

// Extract PayHere notification parameters
$merchantId      = trim((string) ($input['merchant_id'] ?? ''));
$orderId         = trim((string) ($input['order_id'] ?? ''));
$paymentId       = trim((string) ($input['payment_id'] ?? ''));
$payhereAmount   = trim((string) ($input['payhere_amount'] ?? ''));
$payhereCurrency = trim((string) ($input['payhere_currency'] ?? ''));
$statusCode      = trim((string) ($input['status_code'] ?? ''));
$md5sig          = trim((string) ($input['md5sig'] ?? ''));

// 1. Validate required fields presence
if ($merchantId === '' || $orderId === '' || $payhereAmount === '' || $payhereCurrency === '' || $statusCode === '' || $md5sig === '') {
    error_log('[PayHere IPN] Missing required notification fields: ' . json_encode($input));
    ipnRespond(['success' => false, 'message' => 'Missing required notification fields.'], 400);
}

// 2. Validate merchant_id
if ($merchantId !== PAYHERE_MERCHANT_ID) {
    error_log("[PayHere IPN] Merchant ID mismatch. Expected: " . PAYHERE_MERCHANT_ID . ", Received: {$merchantId}");
    ipnRespond(['success' => false, 'message' => 'Invalid merchant ID.'], 400);
}

// 3. Cryptographically verify MD5 signature
if (!payhere_verify_signature(PAYHERE_MERCHANT_ID, $orderId, $payhereAmount, $payhereCurrency, $statusCode, $md5sig, PAYHERE_MERCHANT_SECRET)) {
    error_log("[PayHere IPN] Signature verification failed for order: {$orderId}");
    ipnRespond(['success' => false, 'message' => 'Signature verification failed.'], 400);
}

// 4. Identify the order in the database (by unique order_number or primary order_id)
try {
    $stmt = $pdo->prepare('
        SELECT order_id, order_number, customer_id, total, payment_status, order_status, payhere_payment_id
        FROM `orders`
        WHERE order_number = :order_num OR (order_id = :order_id AND :is_num REGEXP "^[0-9]+$")
        LIMIT 1
    ');
    $stmt->execute([
        ':order_num' => $orderId,
        ':order_id'  => $orderId,
        ':is_num'    => $orderId,
    ]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[PayHere IPN] Database query error: ' . $e->getMessage());
    ipnRespond(['success' => false, 'message' => 'Database error.'], 500);
}

if (!$order) {
    error_log("[PayHere IPN] Order not found for order reference: {$orderId}");
    ipnRespond(['success' => false, 'message' => 'Order not found.'], 404);
}

// 5. Verify currency and amount match order record
if (strtoupper($payhereCurrency) !== strtoupper(PAYHERE_CURRENCY)) {
    error_log("[PayHere IPN] Currency mismatch: received {$payhereCurrency}, expected " . PAYHERE_CURRENCY);
    ipnRespond(['success' => false, 'message' => 'Currency mismatch.'], 400);
}

$orderTotal = (float) $order['total'];
$receivedAmount = (float) $payhereAmount;
if (abs($orderTotal - $receivedAmount) > 0.01) {
    error_log("[PayHere IPN] Amount mismatch: Order total {$orderTotal}, Received {$receivedAmount}");
    ipnRespond(['success' => false, 'message' => 'Payment amount mismatch.'], 400);
}

// 6. Idempotency: If already marked as paid with status_code 2, acknowledge immediately
if ($order['payment_status'] === 'paid' && $statusCode === '2') {
    ipnRespond([
        'success'        => true,
        'message'        => 'Notification already processed. Order is already marked as paid.',
        'order_number'   => $order['order_number'],
        'payment_status' => 'paid',
    ], 200);
}

// 7. Determine updated payment_status and order_status based on PayHere status_code:
//    2  = Success
//    0  = Pending (e.g. offline bank approval)
//   -1  = Canceled
//   -2  = Failed
//   -3  = Chargedback
$newPaymentStatus = $order['payment_status'];
$newOrderStatus   = $order['order_status'];

switch ($statusCode) {
    case '2': // Payment Successful
        $newPaymentStatus = 'paid';
        // Only advance order_status to 'confirmed' if it was 'pending'
        if ($order['order_status'] === 'pending') {
            $newOrderStatus = 'confirmed';
        }
        break;

    case '0': // Payment Pending
        $newPaymentStatus = 'pending';
        break;

    case '-1': // Payment Canceled
        $newPaymentStatus = 'cancelled';
        break;

    case '-2': // Payment Failed
        $newPaymentStatus = 'failed';
        break;

    case '-3': // Charged back
        $newPaymentStatus = 'charged_back';
        break;

    default:
        $newPaymentStatus = 'failed';
        break;
}

// 8. Update order in database
try {
    $updateStmt = $pdo->prepare('
        UPDATE `orders`
        SET payment_status     = :payment_status,
            order_status       = :order_status,
            payhere_payment_id = :payhere_payment_id
        WHERE order_id = :order_id
    ');
    $updateStmt->execute([
        ':payment_status'     => $newPaymentStatus,
        ':order_status'       => $newOrderStatus,
        ':payhere_payment_id' => $paymentId !== '' ? $paymentId : $order['payhere_payment_id'],
        ':order_id'           => $order['order_id'],
    ]);

    ipnRespond([
        'success'            => true,
        'message'            => 'Payment notification processed successfully.',
        'order_number'       => $order['order_number'],
        'payment_status'     => $newPaymentStatus,
        'order_status'       => $newOrderStatus,
        'payhere_payment_id' => $paymentId !== '' ? $paymentId : $order['payhere_payment_id'],
    ], 200);

} catch (PDOException $e) {
    error_log('[PayHere IPN] Database update error: ' . $e->getMessage());
    ipnRespond(['success' => false, 'message' => 'Failed to update order status.'], 500);
}
