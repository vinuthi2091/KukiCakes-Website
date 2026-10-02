<?php
/**
 * Test Suite: Cart Database Synchronization (tests/test_cart_sync.php)
 *
 * Verifies:
 *  1. Unauthenticated GET /api/cart.php returns authenticated: false, empty cart.
 *  2. Unauthenticated POST /api/cart.php is rejected (HTTP 401).
 *  3. Authenticated customer saves cart items -> persists to user_cart_items table.
 *  4. Direct DB verification: user_cart_items table contains the customer's items.
 *  5. Authenticated GET /api/cart.php returns persisted cart items with correct fields.
 *  6. Updating quantities / item details persists to DB.
 *  7. Merging guest cart on login: combines duplicate items by summing quantity, appends new items.
 *  8. Clearing cart: removes rows from user_cart_items.
 *  9. Multi-user isolation: User A and User B maintain completely separate, isolated carts.
 */

require_once __DIR__ . '/../config/db.php';

$baseUrl = 'http://localhost/KukiCakes-Website';
$cookieUserA = tempnam(sys_get_temp_dir(), 'kuki_cart_a_');
$cookieUserB = tempnam(sys_get_temp_dir(), 'kuki_cart_b_');

function curlReq(string $method, string $url, ?array $body = null, ?string $cookieJar = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $rawResponse = curl_exec($ch);
    $status      = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $status,
        'raw'    => $rawResponse,
        'json'   => json_decode($rawResponse ?: '', true),
    ];
}

$passed = 0;
$failed = 0;

function check(bool $condition, string $title, string $details = '') {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $title\n";
        $passed++;
    } else {
        echo "[FAIL] $title: $details\n";
        $failed++;
    }
}

echo "=========================================================\n";
echo " KúkiCakes Cart Database Synchronization Test Suite\n";
echo "=========================================================\n\n";

// ── 1. Unauthenticated GET /api/cart.php ───────────────────────
$res = curlReq('GET', "$baseUrl/api/cart.php");
check(
    $res['status'] === 200 && ($res['json']['authenticated'] ?? null) === false && empty($res['json']['cart']),
    '1. Unauthenticated GET returns authenticated: false and empty cart'
);

// ── 2. Unauthenticated POST /api/cart.php ──────────────────────
$res = curlReq('POST', "$baseUrl/api/cart.php", ['action' => 'save', 'items' => [['id' => 1, 'qty' => 1]]]);
check(
    $res['status'] === 401,
    '2. Unauthenticated POST /api/cart.php rejected with HTTP 401'
);

// ── Setup Users A & B ──────────────────────────────────────────
$emailA = 'cart_user_a_' . time() . '@kukicakes.local';
$emailB = 'cart_user_b_' . time() . '@kukicakes.local';
$pass = 'CartPass123';

curlReq('POST', "$baseUrl/api/register.php", [
    'full_name' => 'Cart User A', 'email' => $emailA, 'phone' => '+94771112222', 'password' => $pass, 'confirm_password' => $pass
]);
curlReq('POST', "$baseUrl/api/register.php", [
    'full_name' => 'Cart User B', 'email' => $emailB, 'phone' => '+94773334444', 'password' => $pass, 'confirm_password' => $pass
]);

// Login User A
$loginA = curlReq('POST', "$baseUrl/api/login.php", ['email' => $emailA, 'password' => $pass], $cookieUserA);
check($loginA['status'] === 200 && ($loginA['json']['success'] ?? false) === true, '3. User A authenticated successfully');

// Get User A customer_id from DB
$stmt = $pdo->prepare('SELECT customer_id FROM customer WHERE email = ?');
$stmt->execute([$emailA]);
$custAId = (int) $stmt->fetchColumn();

// ── 4. User A saves items to cart ──────────────────────────────
$item1 = [
    'id'     => 1,
    'name'   => 'Midnight Velvet Berry',
    'price'  => 5400,
    'img'    => 'https://example.com/cake1.jpg',
    'weight' => '1kg',
    'flavor' => 'Chocolate',
    'note'   => 'Happy Birthday',
    'qty'    => 2
];
$item2 = [
    'id'     => 3,
    'name'   => 'Blush Blossom Petite',
    'price'  => 4200,
    'img'    => 'https://example.com/cake3.jpg',
    'weight' => '500g',
    'flavor' => 'Vanilla bean',
    'note'   => '',
    'qty'    => 1
];

$saveRes = curlReq('POST', "$baseUrl/api/cart.php", [
    'action' => 'save',
    'items'  => [$item1, $item2]
], $cookieUserA);
check($saveRes['status'] === 200 && ($saveRes['json']['success'] ?? false) === true, '4. Save cart items via POST action: save succeeds');

// ── 5. Direct DB check on user_cart_items ─────────────────────
$stmt = $pdo->prepare('SELECT COUNT(*) FROM user_cart_items WHERE customer_id = ?');
$stmt->execute([$custAId]);
$dbCount = (int) $stmt->fetchColumn();
check($dbCount === 2, '5. MySQL user_cart_items has exactly 2 rows for User A');

// ── 6. Authenticated GET /api/cart.php ─────────────────────────
$getRes = curlReq('GET', "$baseUrl/api/cart.php", null, $cookieUserA);
$cartA = $getRes['json']['cart'] ?? [];
check(
    $getRes['status'] === 200 &&
    ($getRes['json']['authenticated'] ?? false) === true &&
    count($cartA) === 2 &&
    $cartA[0]['id'] === 1 &&
    $cartA[0]['qty'] === 2 &&
    $cartA[0]['weight'] === '1kg' &&
    $cartA[1]['id'] === 3 &&
    $cartA[1]['qty'] === 1,
    '6. Authenticated GET /api/cart.php returns all persisted items with exact quantities and attributes'
);

// ── 7. Update quantity / edit cart ─────────────────────────────
$item1['qty'] = 4; // User increased quantity of cake 1
$saveUpdateRes = curlReq('POST', "$baseUrl/api/cart.php", [
    'action' => 'save',
    'items'  => [$item1] // removed item2, updated item1
], $cookieUserA);
check($saveUpdateRes['status'] === 200, '7. Update cart (quantity change and item removal) succeeds');

$stmt = $pdo->prepare('SELECT quantity FROM user_cart_items WHERE customer_id = ? AND product_id = 1');
$stmt->execute([$custAId]);
$qtyInDb = (int) $stmt->fetchColumn();
check($qtyInDb === 4, '8. Quantity in MySQL updated to 4');

// ── 8. Merge guest items on login ─────────────────────────────
// User A currently has cake 1 (qty 4).
// Guest cart has:
//   - cake 1 (qty 2, same weight/flavor/note) -> should sum to qty 6
//   - cake 4 (qty 3, new item) -> should append
$guestItems = [
    [
        'id'     => 1,
        'name'   => 'Midnight Velvet Berry',
        'price'  => 5400,
        'weight' => '1kg',
        'flavor' => 'Chocolate',
        'note'   => 'Happy Birthday',
        'qty'    => 2
    ],
    [
        'id'     => 4,
        'name'   => 'Citrus Cloud Butter Cake',
        'price'  => 3800,
        'weight' => '500g',
        'flavor' => 'Butter cake',
        'note'   => 'Anniversary',
        'qty'    => 3
    ]
];

$mergeRes = curlReq('POST', "$baseUrl/api/cart.php", [
    'action' => 'merge',
    'items'  => $guestItems
], $cookieUserA);

check($mergeRes['status'] === 200 && ($mergeRes['json']['merged'] ?? false) === true, '9. Merge action succeeds');

$getMerged = curlReq('GET', "$baseUrl/api/cart.php", null, $cookieUserA);
$mergedItems = $getMerged['json']['cart'] ?? [];
$mergedCake1 = array_values(array_filter($mergedItems, fn($i) => $i['id'] === 1))[0] ?? null;
$mergedCake4 = array_values(array_filter($mergedItems, fn($i) => $i['id'] === 4))[0] ?? null;

check(
    count($mergedItems) === 2 &&
    $mergedCake1 && $mergedCake1['qty'] === 6 &&
    $mergedCake4 && $mergedCake4['qty'] === 3,
    '10. Merged cart correctly summed matching item quantity (4 + 2 = 6) and appended new item (qty 3)'
);

// ── 9. Multi-user isolation ────────────────────────────────────
// Login User B
curlReq('POST', "$baseUrl/api/login.php", ['email' => $emailB, 'password' => $pass], $cookieUserB);

// Check User B's cart is initially empty
$cartBInitial = curlReq('GET', "$baseUrl/api/cart.php", null, $cookieUserB);
check(
    $cartBInitial['status'] === 200 && empty($cartBInitial['json']['cart']),
    '11. User B cart is empty and does NOT leak User A cart items'
);

// User B saves their own item
$itemUserB = [
    'id'     => 7,
    'name'   => 'Golden Caramel Drizzle',
    'price'  => 4800,
    'weight' => '750g',
    'flavor' => 'Caramel',
    'note'   => 'For Party',
    'qty'    => 5
];
curlReq('POST', "$baseUrl/api/cart.php", ['action' => 'save', 'items' => [$itemUserB]], $cookieUserB);

// Verify User B has cake 7
$cartBAfter = curlReq('GET', "$baseUrl/api/cart.php", null, $cookieUserB);
check(
    count($cartBAfter['json']['cart']) === 1 && $cartBAfter['json']['cart'][0]['id'] === 7,
    '12. User B successfully stored distinct cart item'
);

// Re-check User A's cart is intact
$cartARecheck = curlReq('GET', "$baseUrl/api/cart.php", null, $cookieUserA);
check(
    count($cartARecheck['json']['cart']) === 2 && $cartARecheck['json']['cart'][0]['id'] === 1,
    '13. User A cart remains intact and unaffected by User B operations'
);

// ── 10. Clear cart action ──────────────────────────────────────
$clearRes = curlReq('POST', "$baseUrl/api/cart.php", ['action' => 'clear'], $cookieUserA);
check($clearRes['status'] === 200, '14. Clear cart action succeeds');

$getCleared = curlReq('GET', "$baseUrl/api/cart.php", null, $cookieUserA);
check(empty($getCleared['json']['cart']), '15. User A cart is empty after clear action');

$stmt = $pdo->prepare('SELECT COUNT(*) FROM user_cart_items WHERE customer_id = ?');
$stmt->execute([$custAId]);
$dbAfterClear = (int) $stmt->fetchColumn();
check($dbAfterClear === 0, '16. MySQL user_cart_items rows deleted for User A');

// User B's cart still exists
$stmt = $pdo->prepare('SELECT COUNT(*) FROM user_cart_items WHERE customer_id = (SELECT customer_id FROM customer WHERE email = ?)');
$stmt->execute([$emailB]);
$dbUserBCount = (int) $stmt->fetchColumn();
check($dbUserBCount === 1, '17. User B cart still present in DB after User A clear');

// Cleanup
@unlink($cookieUserA);
@unlink($cookieUserB);

echo "\n=========================================================\n";
echo " Summary: $passed passed, $failed failed.\n";
echo "=========================================================\n";

if ($failed > 0) exit(1);
