<?php
/**
 * Test Suite: KúkiCakes Week 7 PayHere Sandbox Integration
 *
 * Verifies:
 * 1. PayHere server-side configuration and cryptographic hash calculation
 * 2. Card checkout returns safe PayHere parameters without leaking Merchant Secret
 * 3. Cash and Bank Transfer checkouts create pending orders without PayHere params
 * 4. Server-to-server IPN (/api/payhere-notify.php) validation guards:
 *    - Rejects non-POST methods (405)
 *    - Rejects missing fields (400)
 *    - Rejects wrong merchant ID (400)
 *    - Rejects forged/invalid MD5 signature (400)
 *    - Rejects mismatched amount / currency (400)
 *    - Rejects non-existent order (404)
 * 5. Valid payment notification (status_code = 2):
 *    - Sets payment_status = 'paid'
 *    - Sets order_status = 'confirmed'
 *    - Stores payhere_payment_id
 * 6. Webhook idempotency (repeated notifications return 200 OK without side-effects)
 * 7. Failed / cancelled notification updates payment_status appropriately without marking paid
 */

$baseUrl = 'http://localhost/KukiCakes-Website/api';

$testsPassed = 0;
$testsFailed = 0;

function assertTest(bool $condition, string $description): void {
    global $testsPassed, $testsFailed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $testsPassed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $testsFailed++;
    }
}

function httpPost(string $url, array $data, ?string $cookieFile = null, string $contentType = 'application/json'): array {
    $ch = curl_init($url);
    if ($contentType === 'application/json') {
        $payload = json_encode($data);
        $headers = ['Content-Type: application/json', 'Content-Length: ' . strlen($payload)];
    } else {
        $payload = http_build_query($data);
        $headers = ['Content-Type: application/x-www-form-urlencoded', 'Content-Length: ' . strlen($payload)];
    }
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'data' => json_decode($response, true) ?: $response, 'raw' => $response];
}

function httpGet(string $url, ?string $cookieFile = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'data' => json_decode($response, true) ?: $response, 'raw' => $response];
}

echo "====================================================\n";
echo " KúkiCakes Week 7 — PayHere Integration Verification\n";
echo "====================================================\n\n";

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/payhere.php';

// -----------------------------------------------------------------
// Test 1: Configuration & Hash Verification
// -----------------------------------------------------------------
echo "[1] Testing PayHere Server-Side Hash Calculation...\n";

// PayHere official test vector:
// merchant_id = "1211111", order_id = "Order123", amount = 1000.00, currency = "LKR", secret = "secret123"
$testMerchantId = '1211111';
$testOrderId    = 'Order123';
$testAmount     = 1000.00;
$testCurrency   = 'LKR';
$testSecret     = 'secret123';

$expectedHash = strtoupper(md5($testMerchantId . $testOrderId . '1000.00' . $testCurrency . strtoupper(md5($testSecret))));
$calculatedHash = payhere_generate_hash($testMerchantId, $testOrderId, $testAmount, $testCurrency, $testSecret);

assertTest($calculatedHash === $expectedHash, "payhere_generate_hash produces exact official PayHere MD5 checksum");

// Verify signature function
$testStatusCode = '2';
$expectedSig = strtoupper(md5($testMerchantId . $testOrderId . '1000.00' . $testCurrency . $testStatusCode . strtoupper(md5($testSecret))));
$sigValid = payhere_verify_signature($testMerchantId, $testOrderId, '1000.00', $testCurrency, $testStatusCode, $expectedSig, $testSecret);
assertTest($sigValid === true, "payhere_verify_signature correctly verifies valid MD5 signature");

$sigInvalid = payhere_verify_signature($testMerchantId, $testOrderId, '1000.00', $testCurrency, $testStatusCode, 'INVALID_SIGNATURE', $testSecret);
assertTest($sigInvalid === false, "payhere_verify_signature rejects forged signature");

// -----------------------------------------------------------------
// Test 2: Authenticate a test customer & set up cart
// -----------------------------------------------------------------
echo "\n[2] Setting Up Test Customer & Cart...\n";
$cookieJar = tempnam(sys_get_temp_dir(), 'kuki_ph_test_');

$testEmail = 'payheretest_' . time() . '@kukicakes.lk';
$testPass  = 'PayHerePass123!';

httpPost("{$baseUrl}/register.php", [
    'full_name'        => 'PayHere Test Customer',
    'email'            => $testEmail,
    'phone'            => '+94 77 555 1234',
    'password'         => $testPass,
    'confirm_password' => $testPass,
], $cookieJar);

$loginRes = httpPost("{$baseUrl}/login.php", [
    'email'    => $testEmail,
    'password' => $testPass,
], $cookieJar);
assertTest($loginRes['code'] === 200, "Test customer registered and logged in");

$custStmt = $pdo->prepare('SELECT customer_id FROM customer WHERE email = ?');
$custStmt->execute([$testEmail]);
$customerId = (int) $custStmt->fetchColumn();

// Add cart item: cake_id = 1 (Golden Pearl Anniversary Cake, price 10500)
$addCart = $pdo->prepare('INSERT INTO user_cart_items (customer_id, product_id, weight, flavor, note, quantity) VALUES (?, ?, ?, ?, ?, ?)');
$addCart->execute([$customerId, 1, '1kg', 'Vanilla Bean', 'PayHere Test Cake', 1]);

// -----------------------------------------------------------------
// Test 3: Card Checkout returns safe PayHere parameters
// -----------------------------------------------------------------
echo "\n[3] Testing Card Checkout with PayHere Parameter Generation...\n";

$checkoutPayload = [
    'full_name'            => 'PayHere Test Customer',
    'email'                => $testEmail,
    'phone_no'             => '+94 77 555 1234',
    'delivery_address'     => 'No 100, Galle Road, Colombo 03',
    'delivery_date'        => date('Y-m-d', strtotime('+3 days')),
    'delivery_time_slot'   => 'Morning (8AM - 12PM)',
    'special_instructions' => 'Call before arrival',
    'payment_method'       => 'card',
];

$cardOrderRes = httpPost("{$baseUrl}/create-order.php", $checkoutPayload, $cookieJar);
assertTest($cardOrderRes['code'] === 201, "Card checkout creates order with 201 Created");

$cardOrder = $cardOrderRes['data']['order'] ?? [];
$orderNumber = $cardOrder['order_number'] ?? '';
$orderId = (int) ($cardOrder['order_id'] ?? 0);
$payhereParams = $cardOrderRes['data']['payhere'] ?? null;

assertTest(!empty($orderNumber), "Server generated real order_number: {$orderNumber}");
assertTest($cardOrder['payment_status'] === 'pending', "Initial payment_status is 'pending'");
assertTest(is_array($payhereParams), "PayHere parameters returned in response");

// Verify PayHere safe parameters
assertTest($payhereParams['sandbox'] === true, "PayHere sandbox flag is true");
assertTest($payhereParams['merchant_id'] === PAYHERE_MERCHANT_ID, "PayHere merchant_id matches configured value");
assertTest($payhereParams['order_id'] === $orderNumber, "PayHere order_id matches server order_number");
assertTest($payhereParams['currency'] === 'LKR', "PayHere currency is 'LKR'");
assertTest((float)$payhereParams['amount'] === (float)$cardOrder['total'], "PayHere amount matches server order total (Rs. " . $cardOrder['total'] . ")");
assertTest(!empty($payhereParams['hash']), "PayHere hash is present and populated");

// CRITICAL SECURITY ASSERTION: Merchant Secret must NEVER appear in JSON response
$jsonResponseStr = $cardOrderRes['raw'];
assertTest(strpos($jsonResponseStr, PAYHERE_MERCHANT_SECRET) === false, "CRITICAL: PAYHERE_MERCHANT_SECRET is NOT exposed in API response");

// Verify server-side hash correctness
$recalculatedHash = payhere_generate_hash(
    PAYHERE_MERCHANT_ID,
    $orderNumber,
    (float)$cardOrder['total'],
    PAYHERE_CURRENCY,
    PAYHERE_MERCHANT_SECRET
);
assertTest($payhereParams['hash'] === $recalculatedHash, "Returned PayHere hash matches server-calculated cryptographic hash");

// -----------------------------------------------------------------
// Test 4: Cash & Transfer Checkouts do NOT return PayHere parameters
// -----------------------------------------------------------------
echo "\n[4] Testing Cash & Transfer Checkouts...\n";

// Add item back to cart for cash test
$addCart->execute([$customerId, 2, '500g', 'Chocolate', 'Cash order', 1]);

$cashPayload = $checkoutPayload;
$cashPayload['payment_method'] = 'cash';
$cashRes = httpPost("{$baseUrl}/create-order.php", $cashPayload, $cookieJar);
assertTest($cashRes['code'] === 201, "Cash checkout succeeded with 201 Created");
assertTest($cashRes['data']['payhere'] === null, "Cash checkout does NOT return PayHere parameters");
assertTest($cashRes['data']['order']['payment_status'] === 'pending', "Cash order payment_status is 'pending'");

// Add item back to cart for transfer test
$addCart->execute([$customerId, 2, '500g', 'Chocolate', 'Transfer order', 1]);

$transferPayload = $checkoutPayload;
$transferPayload['payment_method'] = 'transfer';
$transferRes = httpPost("{$baseUrl}/create-order.php", $transferPayload, $cookieJar);
assertTest($transferRes['code'] === 201, "Transfer checkout succeeded with 201 Created");
assertTest($transferRes['data']['payhere'] === null, "Transfer checkout does NOT return PayHere parameters");
assertTest($transferRes['data']['order']['payment_status'] === 'pending', "Transfer order payment_status is 'pending'");

// -----------------------------------------------------------------
// Test 5: PayHere IPN Webhook Security & Validation (/api/payhere-notify.php)
// -----------------------------------------------------------------
echo "\n[5] Testing Server-to-Server IPN Notification Security...\n";

// 5a. Method not allowed (GET)
$getRes = httpGet("{$baseUrl}/payhere-notify.php");
assertTest($getRes['code'] === 405, "GET /api/payhere-notify.php is rejected with 405 Method Not Allowed");

// 5b. Missing required fields
$badPayloadRes = httpPost("{$baseUrl}/payhere-notify.php", ['merchant_id' => PAYHERE_MERCHANT_ID], null, 'form');
assertTest($badPayloadRes['code'] === 400, "Notification missing required fields rejected with 400 Bad Request");

// 5c. Wrong merchant ID
$wrongMerchantRes = httpPost("{$baseUrl}/payhere-notify.php", [
    'merchant_id'      => 'WRONG_MERCHANT_ID',
    'order_id'         => $orderNumber,
    'payment_id'       => '320025112001',
    'payhere_amount'   => number_format((float)$cardOrder['total'], 2, '.', ''),
    'payhere_currency' => 'LKR',
    'status_code'      => '2',
    'md5sig'           => 'SOME_SIG',
], null, 'form');
assertTest($wrongMerchantRes['code'] === 400, "Wrong merchant ID rejected with 400");

// 5d. Forged MD5 signature
$forgedSigRes = httpPost("{$baseUrl}/payhere-notify.php", [
    'merchant_id'      => PAYHERE_MERCHANT_ID,
    'order_id'         => $orderNumber,
    'payment_id'       => '320025112001',
    'payhere_amount'   => number_format((float)$cardOrder['total'], 2, '.', ''),
    'payhere_currency' => 'LKR',
    'status_code'      => '2',
    'md5sig'           => 'FORGED_INVALID_SIGNATURE',
], null, 'form');
assertTest($forgedSigRes['code'] === 400, "Forged/tampered MD5 signature rejected with 400");

// 5e. Tampered Amount (amount doesn't match order total)
$tamperedAmount = '1.00';
$tamperedAmountSig = strtoupper(md5(PAYHERE_MERCHANT_ID . $orderNumber . $tamperedAmount . 'LKR' . '2' . strtoupper(md5(PAYHERE_MERCHANT_SECRET))));
$tamperedRes = httpPost("{$baseUrl}/payhere-notify.php", [
    'merchant_id'      => PAYHERE_MERCHANT_ID,
    'order_id'         => $orderNumber,
    'payment_id'       => '320025112001',
    'payhere_amount'   => $tamperedAmount,
    'payhere_currency' => 'LKR',
    'status_code'      => '2',
    'md5sig'           => $tamperedAmountSig,
], null, 'form');
assertTest($tamperedRes['code'] === 400, "Tampered payment amount rejected with 400");

// 5f. Non-existent order
$ghostOrder = 'KC-99999999-GHOSTX';
$ghostSig = strtoupper(md5(PAYHERE_MERCHANT_ID . $ghostOrder . '1000.00' . 'LKR' . '2' . strtoupper(md5(PAYHERE_MERCHANT_SECRET))));
$ghostRes = httpPost("{$baseUrl}/payhere-notify.php", [
    'merchant_id'      => PAYHERE_MERCHANT_ID,
    'order_id'         => $ghostOrder,
    'payment_id'       => '320025112001',
    'payhere_amount'   => '1000.00',
    'payhere_currency' => 'LKR',
    'status_code'      => '2',
    'md5sig'           => $ghostSig,
], null, 'form');
assertTest($ghostRes['code'] === 404, "Non-existent order rejected with 404 Not Found");

// -----------------------------------------------------------------
// Test 6: Valid Payment Notification (status_code = 2)
// -----------------------------------------------------------------
echo "\n[6] Testing Valid PayHere Payment Success Notification (IPN)...\n";

$validAmount = number_format((float)$cardOrder['total'], 2, '.', '');
$validPaymentId = '320025112999';
$validStatusCode = '2';
$validSig = strtoupper(md5(PAYHERE_MERCHANT_ID . $orderNumber . $validAmount . 'LKR' . $validStatusCode . strtoupper(md5(PAYHERE_MERCHANT_SECRET))));

$validIpnRes = httpPost("{$baseUrl}/payhere-notify.php", [
    'merchant_id'      => PAYHERE_MERCHANT_ID,
    'order_id'         => $orderNumber,
    'payment_id'       => $validPaymentId,
    'payhere_amount'   => $validAmount,
    'payhere_currency' => 'LKR',
    'status_code'      => $validStatusCode,
    'md5sig'           => $validSig,
    'method'           => 'TEST',
], null, 'form');

assertTest($validIpnRes['code'] === 200, "Valid PayHere notification accepted with HTTP 200");
assertTest(($validIpnRes['data']['success'] ?? false) === true, "IPN response reports success = true");

// Verify Database State
$dbCheck = $pdo->prepare('SELECT payment_status, order_status, payhere_payment_id FROM `orders` WHERE order_number = ?');
$dbCheck->execute([$orderNumber]);
$updatedOrder = $dbCheck->fetch(PDO::FETCH_ASSOC);

assertTest($updatedOrder['payment_status'] === 'paid', "Database: payment_status updated to 'paid'");
assertTest($updatedOrder['order_status'] === 'confirmed', "Database: order_status updated to 'confirmed'");
assertTest($updatedOrder['payhere_payment_id'] === $validPaymentId, "Database: payhere_payment_id recorded as {$validPaymentId}");

// -----------------------------------------------------------------
// Test 7: Webhook Idempotency (Duplicate notification handling)
// -----------------------------------------------------------------
echo "\n[7] Testing Webhook Idempotency...\n";

$duplicateIpnRes = httpPost("{$baseUrl}/payhere-notify.php", [
    'merchant_id'      => PAYHERE_MERCHANT_ID,
    'order_id'         => $orderNumber,
    'payment_id'       => $validPaymentId,
    'payhere_amount'   => $validAmount,
    'payhere_currency' => 'LKR',
    'status_code'      => $validStatusCode,
    'md5sig'           => $validSig,
], null, 'form');

assertTest($duplicateIpnRes['code'] === 200, "Duplicate IPN notification safely acknowledged with HTTP 200");
assertTest($duplicateIpnRes['data']['payment_status'] === 'paid', "Order remains 'paid' without state corruption");

// -----------------------------------------------------------------
// Test 8: Failed / Cancelled PayHere Notifications
// -----------------------------------------------------------------
echo "\n[8] Testing Failed & Cancelled Payment Notifications...\n";

// Create a new order to test failed status
$addCart->execute([$customerId, 1, '500g', 'Coffee', 'Failed test', 1]);
$failOrderRes = httpPost("{$baseUrl}/create-order.php", $checkoutPayload, $cookieJar);
$failOrderNumber = $failOrderRes['data']['order']['order_number'];
$failAmount = number_format((float)$failOrderRes['data']['order']['total'], 2, '.', '');

// Send failed notification (status_code = -2)
$failSig = strtoupper(md5(PAYHERE_MERCHANT_ID . $failOrderNumber . $failAmount . 'LKR' . '-2' . strtoupper(md5(PAYHERE_MERCHANT_SECRET))));
$failIpnRes = httpPost("{$baseUrl}/payhere-notify.php", [
    'merchant_id'      => PAYHERE_MERCHANT_ID,
    'order_id'         => $failOrderNumber,
    'payment_id'       => '320025112002',
    'payhere_amount'   => $failAmount,
    'payhere_currency' => 'LKR',
    'status_code'      => '-2',
    'md5sig'           => $failSig,
], null, 'form');

assertTest($failIpnRes['code'] === 200, "Failed payment notification acknowledged with HTTP 200");

$dbCheck->execute([$failOrderNumber]);
$failDb = $dbCheck->fetch(PDO::FETCH_ASSOC);
assertTest($failDb['payment_status'] === 'failed', "Database: payment_status set to 'failed'");
assertTest($failDb['order_status'] === 'pending', "Database: order_status remains 'pending' (NOT confirmed or delivered)");

// -----------------------------------------------------------------
// Cleanup
// -----------------------------------------------------------------
echo "\n[9] Cleaning up test records...\n";
$delOrd = $pdo->prepare('DELETE FROM `orders` WHERE customer_id = ?');
$delOrd->execute([$customerId]);
$delCart = $pdo->prepare('DELETE FROM `user_cart_items` WHERE customer_id = ?');
$delCart->execute([$customerId]);
$delCust = $pdo->prepare('DELETE FROM `customer` WHERE customer_id = ?');
$delCust->execute([$customerId]);
if (file_exists($cookieJar)) @unlink($cookieJar);

echo "Cleanup complete.\n";

echo "\n====================================================\n";
echo " Test Results: {$testsPassed} Passed, {$testsFailed} Failed\n";
echo "====================================================\n";

exit($testsFailed === 0 ? 0 : 1);
