<?php
/**
 * KúkiCakes — GET & POST /api/cart
 *
 * Persistent database cart synchronization for authenticated customers.
 *
 * GET /api/cart.php
 *   - If authenticated: returns the customer's persistent cart items from MySQL.
 *   - If unauthenticated: returns authenticated: false and empty cart.
 *
 * POST /api/cart.php
 *   - Requires authentication.
 *   - Actions:
 *       1. 'save' (or default): Overwrites persistent DB cart with provided `items`.
 *       2. 'merge': Combines guest localStorage items with existing DB items.
 *       3. 'clear': Deletes all cart items for the authenticated customer.
 */
require_once __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET' && $method !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

// ─────────────────────────────────────────────────────────────
// GET: Retrieve authenticated customer's cart
// ─────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (!isAuthenticated()) {
        respond([
            'success'       => true,
            'authenticated' => false,
            'cart'          => [],
        ]);
    }

    $customerId = (int) $_SESSION['customer_id'];

    try {
        $stmt = $pdo->prepare(
            'SELECT cart_item_id, product_id, weight, flavor, note, quantity, item_data
             FROM `user_cart_items`
             WHERE customer_id = ?
             ORDER BY cart_item_id ASC'
        );
        $stmt->execute([$customerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $cart = [];
        foreach ($rows as $row) {
            $item = [];
            if (!empty($row['item_data'])) {
                $item = json_decode($row['item_data'], true) ?: [];
            }
            // Ensure core fields match DB values
            $item['id']     = (int) $row['product_id'];
            $item['qty']    = (int) $row['quantity'];
            $item['weight'] = (string) ($row['weight'] ?: '500g');
            $item['flavor'] = (string) ($row['flavor'] ?: '');
            $item['note']   = (string) ($row['note'] ?: '');

            $cart[] = $item;
        }

        respond([
            'success'       => true,
            'authenticated' => true,
            'cart'          => $cart,
        ]);
    } catch (PDOException $e) {
        error_log('[KukiCakes cart GET] DB error: ' . $e->getMessage());
        respond(['success' => false, 'message' => 'Failed to load cart.'], 500);
    }
}

// ─────────────────────────────────────────────────────────────
// POST: Save, merge, or clear authenticated customer's cart
// ─────────────────────────────────────────────────────────────
$customerId = requireAuth();
$body = getBody();
$action = $body['action'] ?? 'save';

if ($action === 'clear') {
    try {
        $stmt = $pdo->prepare('DELETE FROM `user_cart_items` WHERE customer_id = ?');
        $stmt->execute([$customerId]);
        respond(['success' => true, 'message' => 'Cart cleared.', 'cart' => []]);
    } catch (PDOException $e) {
        error_log('[KukiCakes cart CLEAR] DB error: ' . $e->getMessage());
        respond(['success' => false, 'message' => 'Failed to clear cart.'], 500);
    }
}

function sanitizeCartItem($raw) {
    if (!is_array($raw) || empty($raw['id'])) return null;
    $id     = (int) $raw['id'];
    $qty    = max(1, (int) ($raw['qty'] ?? 1));
    $weight = trim((string) ($raw['weight'] ?? '500g')) ?: '500g';
    $flavor = trim((string) ($raw['flavor'] ?? ''));
    $note   = trim((string) ($raw['note'] ?? ''));

    // Preserve display attributes
    $clean = $raw;
    $clean['id']     = $id;
    $clean['qty']    = $qty;
    $clean['weight'] = $weight;
    $clean['flavor'] = $flavor;
    $clean['note']   = $note;
    $clean['price']  = isset($raw['price']) ? (float) $raw['price'] : 0;
    $clean['name']   = trim((string) ($raw['name'] ?? ''));
    $clean['img']    = trim((string) ($raw['img'] ?? ''));

    return $clean;
}

if ($action === 'merge') {
    $guestItems = $body['items'] ?? [];
    if (!is_array($guestItems)) $guestItems = [];

    try {
        // Fetch current DB items
        $stmt = $pdo->prepare(
            'SELECT cart_item_id, product_id, weight, flavor, note, quantity, item_data
             FROM `user_cart_items`
             WHERE customer_id = ?
             ORDER BY cart_item_id ASC'
        );
        $stmt->execute([$customerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $merged = [];
        foreach ($rows as $row) {
            $item = !empty($row['item_data']) ? (json_decode($row['item_data'], true) ?: []) : [];
            $item['id']     = (int) $row['product_id'];
            $item['qty']    = (int) $row['quantity'];
            $item['weight'] = (string) ($row['weight'] ?: '500g');
            $item['flavor'] = (string) ($row['flavor'] ?: '');
            $item['note']   = (string) ($row['note'] ?: '');
            $merged[] = $item;
        }

        // Merge guest items
        foreach ($guestItems as $rawGuest) {
            $guest = sanitizeCartItem($rawGuest);
            if (!$guest) continue;

            $found = false;
            foreach ($merged as &$dbItem) {
                if (
                    $dbItem['id'] === $guest['id'] &&
                    $dbItem['weight'] === $guest['weight'] &&
                    $dbItem['flavor'] === $guest['flavor'] &&
                    $dbItem['note'] === $guest['note']
                ) {
                    $dbItem['qty'] += $guest['qty'];
                    $found = true;
                    break;
                }
            }
            unset($dbItem);

            if (!$found) {
                $merged[] = $guest;
            }
        }

        // Save merged items back into DB in a transaction
        $pdo->beginTransaction();
        $del = $pdo->prepare('DELETE FROM `user_cart_items` WHERE customer_id = ?');
        $del->execute([$customerId]);

        $ins = $pdo->prepare(
            'INSERT INTO `user_cart_items` (customer_id, product_id, weight, flavor, note, quantity, item_data)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($merged as $item) {
            $ins->execute([
                $customerId,
                $item['id'],
                $item['weight'],
                $item['flavor'],
                $item['note'],
                $item['qty'],
                json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);
        }
        $pdo->commit();

        respond([
            'success'       => true,
            'authenticated' => true,
            'merged'        => true,
            'cart'          => $merged,
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[KukiCakes cart MERGE] DB error: ' . $e->getMessage());
        respond(['success' => false, 'message' => 'Failed to merge cart.'], 500);
    }
}

// Default action: 'save' (overwrites user's DB cart)
$rawItems = $body['items'] ?? [];
if (!is_array($rawItems)) $rawItems = [];

$cleaned = [];
foreach ($rawItems as $raw) {
    $item = sanitizeCartItem($raw);
    if ($item) $cleaned[] = $item;
}

try {
    $pdo->beginTransaction();
    $del = $pdo->prepare('DELETE FROM `user_cart_items` WHERE customer_id = ?');
    $del->execute([$customerId]);

    $ins = $pdo->prepare(
        'INSERT INTO `user_cart_items` (customer_id, product_id, weight, flavor, note, quantity, item_data)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($cleaned as $item) {
        $ins->execute([
            $customerId,
            $item['id'],
            $item['weight'],
            $item['flavor'],
            $item['note'],
            $item['qty'],
            json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);
    }
    $pdo->commit();

    respond([
        'success'       => true,
        'authenticated' => true,
        'cart'          => $cleaned,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[KukiCakes cart SAVE] DB error: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Failed to save cart.'], 500);
}
