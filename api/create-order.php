<?php
/**
 * KúkiCakes — POST /api/create-order.php
 *
 * Secure server-side order creation for authenticated customers.
 *
 * Requirements:
 * 1. Requires authenticated session (via bootstrap.php).
 * 2. Validates checkout customer and delivery details server-side.
 * 3. Does not trust client-supplied prices, totals, or item details.
 * 4. Loads current cart from `user_cart_items`.
 * 5. Resolves authoritative cake prices and details from catalog (`cake` table).
 * 6. Computes subtotal, adds standard delivery fee (Rs. 500.00), and calculates total.
 * 7. Generates a unique server-side order number (KC-YYYYMMDD-XXXXXX).
 * 8. Inserts order and order_item records within a database transaction.
 * 9. Rejects empty carts and invalid requests with appropriate HTTP status codes.
 */
require_once __DIR__ . '/bootstrap.php';

// Only POST requests are allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed. Use POST.'], 405);
}

// 1 & 2. Require authenticated customer session
$customerId = requireAuth();

// 3. Parse request payload
$body = getBody();

$fullName            = trim((string) ($body['full_name'] ?? ''));
$email               = trim((string) ($body['email'] ?? ''));
$phoneNo             = trim((string) ($body['phone_no'] ?? ''));
$deliveryAddress     = trim((string) ($body['delivery_address'] ?? ''));
$deliveryDate        = trim((string) ($body['delivery_date'] ?? ''));
$deliveryTimeSlot    = trim((string) ($body['delivery_time_slot'] ?? ''));
$specialInstructions = trim((string) ($body['special_instructions'] ?? ''));
$paymentMethod       = trim((string) ($body['payment_method'] ?? ''));

// 4 & 5. Server-side validation
$errors = [];

if (strlen($fullName) < 2) {
    $errors['full_name'] = 'Full name must be at least 2 characters.';
} elseif (strlen($fullName) > 100) {
    $errors['full_name'] = 'Full name must not exceed 100 characters.';
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'A valid email address is required.';
} elseif (strlen($email) > 150) {
    $errors['email'] = 'Email address must not exceed 150 characters.';
}

if ($phoneNo === '' || !preg_match('/^[0-9+\s\-()]{7,20}$/', $phoneNo)) {
    $errors['phone_no'] = 'A valid phone number is required (7 to 20 digits/characters).';
}

if (strlen($deliveryAddress) < 5) {
    $errors['delivery_address'] = 'Delivery address must be at least 5 characters.';
}

if ($deliveryDate === '') {
    $errors['delivery_date'] = 'Delivery date is required.';
} else {
    $d = DateTime::createFromFormat('Y-m-d', $deliveryDate);
    if (!$d || $d->format('Y-m-d') !== $deliveryDate) {
        $errors['delivery_date'] = 'Delivery date must be in YYYY-MM-DD format.';
    } else {
        $today = (new DateTime('today', new DateTimeZone('Asia/Colombo')))->format('Y-m-d');
        if ($deliveryDate < $today) {
            $errors['delivery_date'] = 'Delivery date cannot be in the past.';
        }
    }
}

if ($deliveryTimeSlot === '') {
    $errors['delivery_time_slot'] = 'Delivery time slot is required.';
} elseif (strlen($deliveryTimeSlot) > 50) {
    $errors['delivery_time_slot'] = 'Delivery time slot must not exceed 50 characters.';
}

if (!in_array($paymentMethod, ['card', 'transfer', 'cash'], true)) {
    $errors['payment_method'] = 'Invalid payment method. Allowed methods: card, transfer, cash.';
}

if (!empty($errors)) {
    respond([
        'success' => false,
        'message' => 'Validation failed.',
        'errors'  => $errors,
    ], 422);
}

// 8. Read the authenticated user's current cart from user_cart_items
try {
    $cartStmt = $pdo->prepare(
        'SELECT cart_item_id, product_id, weight, flavor, note, quantity
         FROM `user_cart_items`
         WHERE customer_id = ?
         ORDER BY cart_item_id ASC'
    );
    $cartStmt->execute([$customerId]);
    $cartRows = $cartStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[create-order] Cart fetch error: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Failed to retrieve cart items.'], 500);
}

// 12. Reject request if cart is empty
if (empty($cartRows)) {
    respond([
        'success' => false,
        'message' => 'Your cart is empty. Please add items before placing an order.',
    ], 400);
}

// 9 & 10. Recalculate price and quantity server-side using authoritative catalog
$cakeStmt   = $pdo->prepare('SELECT cake_id, cake_name, price, availability FROM `cake` WHERE cake_id = ?');
$flavorStmt = $pdo->prepare('SELECT flavor_id FROM `flavor` WHERE LOWER(flavor_name) = LOWER(?) LIMIT 1');

$subtotal = 0.00;
$orderItemsToInsert = [];

foreach ($cartRows as $row) {
    $productId = (int) $row['product_id'];
    $cakeStmt->execute([$productId]);
    $cake = $cakeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$cake) {
        respond([
            'success' => false,
            'message' => "Product #{$productId} is no longer available in the catalog.",
        ], 400);
    }

    if (isset($cake['availability']) && (int) $cake['availability'] === 0) {
        respond([
            'success' => false,
            'message' => "Product '{$cake['cake_name']}' is currently out of stock.",
        ], 400);
    }

    $qty = max(1, (int) $row['quantity']);
    $unitPrice = (float) $cake['price'];
    $lineTotal = round($unitPrice * $qty, 2);
    $subtotal += $lineTotal;

    // Resolve flavor foreign key if provided
    $flavorId = null;
    $rawFlavor = trim((string) ($row['flavor'] ?? ''));
    if ($rawFlavor !== '') {
        $flavorStmt->execute([$rawFlavor]);
        $fId = $flavorStmt->fetchColumn();
        if ($fId !== false) {
            $flavorId = (int) $fId;
        }
    }

    $orderItemsToInsert[] = [
        'cake_id'      => (int) $cake['cake_id'],
        'flavor_id'    => $flavorId,
        'cake_name'    => (string) $cake['cake_name'],
        'unit_price'   => $unitPrice,
        'quantity'     => $qty,
        'weight'       => !empty($row['weight']) ? substr(trim((string) $row['weight']), 0, 20) : '500g',
        'special_note' => !empty($row['note']) ? trim((string) $row['note']) : null,
    ];
}

// 11. Calculate totals
$subtotal    = round($subtotal, 2);
$deliveryFee = 500.00; // Flat delivery fee
$total       = round($subtotal + $deliveryFee, 2);

// 13. Generate unique server-side order number (KC-YYYYMMDD-XXXXXX)
function generateOrderNumber(PDO $pdo): string {
    $datePart = date('Ymd');
    $checkStmt = $pdo->prepare('SELECT 1 FROM `orders` WHERE order_number = ? LIMIT 1');
    $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $attempts = 0;

    do {
        $random = '';
        $bytes = random_bytes(6);
        for ($i = 0; $i < 6; $i++) {
            $random .= $chars[ord($bytes[$i]) % strlen($chars)];
        }
        $orderNumber = "KC-{$datePart}-{$random}";
        $checkStmt->execute([$orderNumber]);
        $exists = $checkStmt->fetchColumn();
        $attempts++;
    } while ($exists && $attempts < 10);

    if ($exists) {
        throw new RuntimeException('Failed to generate unique order number.');
    }
    return $orderNumber;
}

try {
    $orderNumber = generateOrderNumber($pdo);
} catch (RuntimeException $e) {
    error_log('[create-order] Order number generation error: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Could not generate order reference.'], 500);
}

// 14, 15 & 16. Database Transaction: insert order and all order items
try {
    $pdo->beginTransaction();

    // 14. Insert order record
    $orderInsert = $pdo->prepare('
        INSERT INTO `orders` (
            customer_id,
            order_number,
            full_name,
            email,
            phone_no,
            delivery_address,
            delivery_date,
            delivery_time_slot,
            special_instructions,
            payment_method,
            payment_status,
            subtotal,
            delivery_fee,
            total,
            order_status
        ) VALUES (
            :customer_id,
            :order_number,
            :full_name,
            :email,
            :phone_no,
            :delivery_address,
            :delivery_date,
            :delivery_time_slot,
            :special_instructions,
            :payment_method,
            :payment_status,
            :subtotal,
            :delivery_fee,
            :total,
            :order_status
        )
    ');

    $orderInsert->execute([
        ':customer_id'          => $customerId,
        ':order_number'         => $orderNumber,
        ':full_name'            => $fullName,
        ':email'                => $email,
        ':phone_no'             => $phoneNo,
        ':delivery_address'     => $deliveryAddress,
        ':delivery_date'        => $deliveryDate,
        ':delivery_time_slot'   => $deliveryTimeSlot,
        ':special_instructions' => $specialInstructions !== '' ? $specialInstructions : null,
        ':payment_method'       => $paymentMethod,
        ':payment_status'       => 'pending',
        ':subtotal'             => $subtotal,
        ':delivery_fee'         => $deliveryFee,
        ':total'                => $total,
        ':order_status'         => 'pending',
    ]);

    $orderId = (int) $pdo->lastInsertId();

    // 15. Insert order items
    $itemInsert = $pdo->prepare('
        INSERT INTO `order_item` (
            order_id,
            cake_id,
            flavor_id,
            cake_name,
            unit_price,
            quantity,
            weight,
            special_note
        ) VALUES (
            :order_id,
            :cake_id,
            :flavor_id,
            :cake_name,
            :unit_price,
            :quantity,
            :weight,
            :special_note
        )
    ');

    foreach ($orderItemsToInsert as $item) {
        $itemInsert->execute([
            ':order_id'     => $orderId,
            ':cake_id'      => $item['cake_id'],
            ':flavor_id'    => $item['flavor_id'],
            ':cake_name'    => $item['cake_name'],
            ':unit_price'   => $item['unit_price'],
            ':quantity'     => $item['quantity'],
            ':weight'       => $item['weight'],
            ':special_note' => $item['special_note'],
        ]);
    }

    // 16. Commit transaction
    $pdo->commit();

    // Generate PayHere payment parameters for card payments
    $payhereParams = null;
    if ($paymentMethod === 'card') {
        require_once __DIR__ . '/../config/payhere.php';

        $nameParts = preg_split('/\s+/', $fullName, 2);
        $firstName = $nameParts[0] ?? $fullName;
        $lastName  = !empty($nameParts[1]) ? $nameParts[1] : 'Customer';

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
        $protocol = $isHttps ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseAppUrl = rtrim($protocol . $host, '/') . '/KukiCakes-Website';

        // Cryptographic hash calculated on server using PAYHERE_MERCHANT_SECRET
        $hash = payhere_generate_hash(
            PAYHERE_MERCHANT_ID,
            $orderNumber,
            $total,
            PAYHERE_CURRENCY,
            PAYHERE_MERCHANT_SECRET
        );

        // Safe frontend parameters — Merchant Secret is NEVER included
        $payhereParams = [
            'sandbox'          => (bool) PAYHERE_SANDBOX_MODE,
            'merchant_id'      => PAYHERE_MERCHANT_ID,
            'return_url'       => $baseAppUrl . '/#confirmation',
            'cancel_url'       => $baseAppUrl . '/#checkout',
            'notify_url'       => $baseAppUrl . '/api/payhere-notify.php',
            'order_id'         => $orderNumber,
            'items'            => 'KúkiCakes Order ' . $orderNumber,
            'amount'           => number_format($total, 2, '.', ''),
            'currency'         => PAYHERE_CURRENCY,
            'hash'             => $hash,
            'first_name'       => $firstName,
            'last_name'        => $lastName,
            'email'            => $email,
            'phone'            => $phoneNo,
            'address'          => $deliveryAddress,
            'city'             => 'Colombo',
            'country'          => 'Sri Lanka',
            'delivery_address' => $deliveryAddress,
            'delivery_city'    => 'Colombo',
            'delivery_country' => 'Sri Lanka',
            'custom_1'         => (string) $orderId,
            'custom_2'         => (string) $customerId,
        ];
    }

    respond([
        'success' => true,
        'message' => 'Order created successfully.',
        'order'   => [
            'order_id'             => $orderId,
            'order_number'         => $orderNumber,
            'customer_id'          => $customerId,
            'full_name'            => $fullName,
            'email'                => $email,
            'phone_no'             => $phoneNo,
            'delivery_address'     => $deliveryAddress,
            'delivery_date'        => $deliveryDate,
            'delivery_time_slot'   => $deliveryTimeSlot,
            'special_instructions' => $specialInstructions,
            'payment_method'       => $paymentMethod,
            'payment_status'       => 'pending',
            'subtotal'             => $subtotal,
            'delivery_fee'         => $deliveryFee,
            'total'                => $total,
            'order_status'         => 'pending',
            'item_count'           => count($orderItemsToInsert),
            'items'                => $orderItemsToInsert,
            'payhere'              => $payhereParams,
        ],
        'payhere' => $payhereParams,
    ], 201);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[create-order] Transaction failed: ' . $e->getMessage());
    respond([
        'success' => false,
        'message' => 'Failed to place order due to a database error. Please try again.',
        'error'   => $e->getMessage(),
    ], 500);
}
