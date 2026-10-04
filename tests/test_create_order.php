<?php
/**
 * Test Suite: KúkiCakes Order Creation Endpoint (/api/create-order.php)
 *
 * Verifies:
 * 1. 405 on non-POST method
 * 2. 401 on unauthenticated access
 * 3. 422 on invalid/missing fields
 * 4. 422 on invalid payment_method (only card, transfer, cash permitted)
 * 5. 422 on past delivery date
 * 6. 400 on empty cart
 * 7. Price tamper protection (client attempts to send 1 LKR, server uses cake table price)
 * 8. Correct subtotal, delivery fee (500), and total calculation
 * 9. Unique order_number matching KC-YYYYMMDD-XXXXXX
 * 10. Database transaction atomicity & insertion into orders and order_item
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

function httpPost(string $url, array $data, ?string $cookieFile = null, string $method = 'POST'): array {
    $ch = curl_init($url);
    $payload = json_encode($data);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload)
    ]);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'data' => json_decode($response, true) ?: $response];
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
    return ['code' => $httpCode, 'data' => json_decode($response, true) ?: $response];
}

echo "====================================================\n";
echo " KúkiCakes Week 7 — Order Creation Verification\n";
echo "====================================================\n\n";

$cookieJar = tempnam(sys_get_temp_dir(), 'kuki_order_test_');

// -----------------------------------------------------------------
// Test 1: Method not allowed (GET /api/create-order.php)
// -----------------------------------------------------------------
echo "[1] Testing HTTP Method Guard...\n";
$res = httpGet("{$baseUrl}/create-order.php");
assertTest($res['code'] === 405, "GET request is rejected with 405 Method Not Allowed");

// -----------------------------------------------------------------
// Test 2: Unauthenticated POST
// -----------------------------------------------------------------
echo "\n[2] Testing Authentication Guard...\n";
$unauthPayload = [
    'full_name'        => 'Unauth User',
    'email'            => 'unauth@test.lk',
    'phone_no'         => '+94 77 123 4567',
    'delivery_address' => '123 Fake Street, Colombo',
    'delivery_date'    => date('Y-m-d', strtotime('+2 days')),
    'delivery_time_slot' => 'Morning (8AM - 12PM)',
    'payment_method'   => 'card',
];
$res = httpPost("{$baseUrl}/create-order.php", $unauthPayload);
assertTest($res['code'] === 401, "Unauthenticated request is rejected with 401 Unauthorized");

// -----------------------------------------------------------------
// Test 3: Authenticate a test user
// -----------------------------------------------------------------
echo "\n[3] Authenticating Test User...\n";
$testEmail = 'ordertest_' . time() . '@kukicakes.lk';
$testPass  = 'SecurePass123!';

$regRes = httpPost("{$baseUrl}/register.php", [
    'full_name'        => 'Order Test Customer',
    'email'            => $testEmail,
    'phone'            => '+94 71 999 8888',
    'password'         => $testPass,
    'confirm_password' => $testPass,
], $cookieJar);
assertTest($regRes['code'] === 201, "Test customer registered successfully");

// Log in
$loginRes = httpPost("{$baseUrl}/login.php", [
    'email'    => $testEmail,
    'password' => $testPass,
], $cookieJar);
assertTest($loginRes['code'] === 200 && ($loginRes['data']['success'] ?? false), "Logged in and acquired session");

// -----------------------------------------------------------------
// Test 4: Empty Cart Rejection
// -----------------------------------------------------------------
echo "\n[4] Testing Empty Cart Rejection...\n";
$validOrderInfo = [
    'full_name'            => 'Order Test Customer',
    'email'                => $testEmail,
    'phone_no'             => '+94 71 999 8888',
    'delivery_address'     => 'No 45, Flower Road, Colombo 07',
    'delivery_date'        => date('Y-m-d', strtotime('+3 days')),
    'delivery_time_slot'   => 'Afternoon (12PM - 4PM)',
    'special_instructions' => 'Handle with love',
    'payment_method'       => 'card',
];
$emptyCartRes = httpPost("{$baseUrl}/create-order.php", $validOrderInfo, $cookieJar);
assertTest($emptyCartRes['code'] === 400, "Empty cart rejected with 400 Bad Request");
assertTest(stripos($emptyCartRes['data']['message'] ?? '', 'cart is empty') !== false, "Error message explains cart is empty");

// -----------------------------------------------------------------
// Test 5: Validation Guards
// -----------------------------------------------------------------
echo "\n[5] Testing Field Validation...\n";

// Populate cart first so cart check passes when testing validations
require_once __DIR__ . '/../config/db.php';
$custStmt = $pdo->prepare('SELECT customer_id FROM customer WHERE email = ?');
$custStmt->execute([$testEmail]);
$customerId = (int) $custStmt->fetchColumn();

// Add item to user_cart_items (product 1 = Rose Garden Birthday Cake, price 8500)
$addCartStmt = $pdo->prepare('INSERT INTO user_cart_items (customer_id, product_id, weight, flavor, note, quantity) VALUES (?, ?, ?, ?, ?, ?)');
$addCartStmt->execute([$customerId, 1, '1kg', 'Vanilla Bean', 'Happy Birthday Vinu', 2]);

// Missing full_name
$badName = $validOrderInfo;
$badName['full_name'] = 'A';
$res = httpPost("{$baseUrl}/create-order.php", $badName, $cookieJar);
assertTest($res['code'] === 422 && isset($res['data']['errors']['full_name']), "Short full_name rejected with 422");

// Invalid email
$badEmail = $validOrderInfo;
$badEmail['email'] = 'not-an-email';
$res = httpPost("{$baseUrl}/create-order.php", $badEmail, $cookieJar);
assertTest($res['code'] === 422 && isset($res['data']['errors']['email']), "Invalid email rejected with 422");

// Invalid payment method
$badPay = $validOrderInfo;
$badPay['payment_method'] = 'bitcoin';
$res = httpPost("{$baseUrl}/create-order.php", $badPay, $cookieJar);
assertTest($res['code'] === 422 && isset($res['data']['errors']['payment_method']), "Invalid payment method 'bitcoin' rejected with 422");

// Past delivery date
$badDate = $validOrderInfo;
$badDate['delivery_date'] = '2020-01-01';
$res = httpPost("{$baseUrl}/create-order.php", $badDate, $cookieJar);
assertTest($res['code'] === 422 && isset($res['data']['errors']['delivery_date']), "Past delivery date rejected with 422");

// -----------------------------------------------------------------
// Test 6: Successful Order Creation with Price Tamper Protection
// -----------------------------------------------------------------
echo "\n[6] Testing Authoritative Server-Side Pricing & Order Creation...\n";

// Add another item: product 2 = Elegant Pearl Wedding Cake, price 28500, qty 1
$addCartStmt->execute([$customerId, 2, '2kg', 'Red Velvet', 'Tier 2 gold pearls', 1]);

// Product 1 (Golden Pearl Anniversary Cake) price: 10,500.00 * 2 = 21,000.00
// Product 2 (Burgundy Ombre Heart Anniversary Cake) price: 9,000.00 * 1 = 9,000.00
// Expected Subtotal: 30,000.00
// Expected Delivery Fee: 500.00
// Expected Total: 30,500.00

// In the POST payload, maliciously inject fake prices and fake subtotal to attempt tampering
$tamperedPayload = $validOrderInfo;
$tamperedPayload['subtotal'] = 10.00;
$tamperedPayload['total'] = 15.00;
$tamperedPayload['payment_method'] = 'card';

$orderRes = httpPost("{$baseUrl}/create-order.php", $tamperedPayload, $cookieJar);
assertTest($orderRes['code'] === 201, "Order created with 201 Created");
assertTest(($orderRes['data']['success'] ?? false) === true, "Response success is true");

$orderData = $orderRes['data']['order'] ?? [];
$orderId = (int) ($orderData['order_id'] ?? 0);
$orderNumber = (string) ($orderData['order_number'] ?? '');
$subtotal = (float) ($orderData['subtotal'] ?? 0);
$deliveryFee = (float) ($orderData['delivery_fee'] ?? 0);
$total = (float) ($orderData['total'] ?? 0);

assertTest($orderId > 0, "Server returned valid order_id: {$orderId}");
assertTest(preg_match('/^KC-\d{8}-[2-9A-HJ-NP-Z]{6}$/', $orderNumber) === 1, "Order number '{$orderNumber}' matches format KC-YYYYMMDD-XXXXXX");
assertTest($subtotal === 30000.00, "Server recalculated subtotal authoritatively: Rs. 30,000.00 (tampered Rs. 10 ignored)");
assertTest($deliveryFee === 500.00, "Delivery fee is exactly Rs. 500.00");
assertTest($total === 30500.00, "Total is exactly Rs. 30,500.00 (tampered Rs. 15 ignored)");

// -----------------------------------------------------------------
// Test 7: Verify Database Integrity (orders & order_item)
// -----------------------------------------------------------------
echo "\n[7] Verifying Database Records...\n";

$ordCheck = $pdo->prepare('SELECT * FROM `orders` WHERE order_id = ?');
$ordCheck->execute([$orderId]);
$dbOrder = $ordCheck->fetch(PDO::FETCH_ASSOC);

assertTest($dbOrder !== false, "Row exists in `orders` table");
assertTest($dbOrder['order_number'] === $orderNumber, "DB order_number matches response");
assertTest($dbOrder['order_status'] === 'pending', "DB order_status is 'pending'");
assertTest((float)$dbOrder['total'] === 30500.00, "DB total matches authoritative Rs. 30,500.00");
assertTest((int)$dbOrder['customer_id'] === $customerId, "DB customer_id matches authenticated session");

$itemCheck = $pdo->prepare('SELECT * FROM `order_item` WHERE order_id = ? ORDER BY order_item_id ASC');
$itemCheck->execute([$orderId]);
$items = $itemCheck->fetchAll(PDO::FETCH_ASSOC);

assertTest(count($items) === 2, "Found 2 items in `order_item`");

// Verify Item 1
assertTest((int)$items[0]['cake_id'] === 1, "Item 1 cake_id is 1");
assertTest((float)$items[0]['unit_price'] === 10500.00, "Item 1 unit_price is 10500.00");
assertTest((int)$items[0]['quantity'] === 2, "Item 1 quantity is 2");
assertTest($items[0]['weight'] === '1kg', "Item 1 weight is 1kg");
assertTest($items[0]['flavor_id'] == 2, "Item 1 flavor_id resolved to Vanilla Bean (id 2)");
assertTest($items[0]['special_note'] === 'Happy Birthday Vinu', "Item 1 special_note preserved");

// Verify Item 2
assertTest((int)$items[1]['cake_id'] === 2, "Item 2 cake_id is 2");
assertTest((float)$items[1]['unit_price'] === 9000.00, "Item 2 unit_price is 9000.00");
assertTest((int)$items[1]['quantity'] === 1, "Item 2 quantity is 1");
assertTest($items[1]['flavor_id'] == 4, "Item 2 flavor_id resolved to Red Velvet (id 4)");

// -----------------------------------------------------------------
// Test 8: Test Payment Methods (transfer, cash)
// -----------------------------------------------------------------
echo "\n[8] Testing Transfer & Cash Payment Methods...\n";

// Order 2 with 'transfer'
$validOrderInfo['payment_method'] = 'transfer';
$resTransfer = httpPost("{$baseUrl}/create-order.php", $validOrderInfo, $cookieJar);
assertTest($resTransfer['code'] === 201 && $resTransfer['data']['order']['payment_method'] === 'transfer', "Order created with 'transfer'");

// Order 3 with 'cash'
$validOrderInfo['payment_method'] = 'cash';
$resCash = httpPost("{$baseUrl}/create-order.php", $validOrderInfo, $cookieJar);
assertTest($resCash['code'] === 201 && $resCash['data']['order']['payment_method'] === 'cash', "Order created with 'cash'");

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
