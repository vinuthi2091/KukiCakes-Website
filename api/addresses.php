<?php
/**
 * KúkiCakes — Address API (GET, POST, DELETE)
 *
 * GET  /api/addresses.php?type=shipping|billing  → list addresses
 * POST /api/addresses.php                        → save/update address
 * DELETE /api/addresses.php?id=N                 → delete address
 *
 * POST body fields:
 *   type (shipping|billing), address_id (optional, for edit),
 *   full_name, line1, line2, city, postal, phone, is_default
 *
 * ALL operations use customer_id from the SESSION.
 * A user can only see/edit their own addresses.
 */
require_once __DIR__ . '/bootstrap.php';

$customerId = requireAuth();

// ── GET — list addresses ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $type = isset($_GET['type']) && $_GET['type'] === 'billing' ? 'billing' : 'shipping';

    try {
        $stmt = $pdo->prepare(
            'SELECT address_id, addr_type, full_name, line1, line2, city, postal, phone, is_default
             FROM `user_addresses`
             WHERE customer_id = ? AND addr_type = ?
             ORDER BY is_default DESC, address_id ASC'
        );
        $stmt->execute([$customerId, $type]);
        $addresses = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('[KukiCakes addresses GET] DB error: ' . $e->getMessage());
        respond(['success' => false, 'message' => 'Could not load addresses.'], 500);
    }

    // Cast types for JavaScript
    $addresses = array_map(function ($a) {
        $a['address_id'] = (int) $a['address_id'];
        $a['is_default'] = (bool) $a['is_default'];
        return $a;
    }, $addresses);

    respond(['success' => true, 'addresses' => $addresses]);
}

// ── POST — create or update ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = getBody();

    $addrType  = $body['type'] ?? 'shipping';
    if (!in_array($addrType, ['shipping', 'billing'])) {
        respond(['success' => false, 'message' => 'Invalid address type.'], 400);
    }

    $addrId    = !empty($body['address_id']) ? (int) $body['address_id'] : null;
    $fullName  = trim($body['full_name'] ?? '');
    $line1     = trim($body['line1']     ?? '');
    $line2     = trim($body['line2']     ?? '');
    $city      = trim($body['city']      ?? '');
    $postal    = trim($body['postal']    ?? '');
    $phone     = trim($body['phone']     ?? '');
    $isDefault = !empty($body['is_default']);

    if (!$fullName || !$line1 || !$city) {
        respond(['success' => false, 'message' => 'Full name, address line, and city are required.'], 400);
    }

    try {
        // If setting as default, clear existing defaults first
        if ($isDefault) {
            $stmt = $pdo->prepare(
                'UPDATE `user_addresses` SET is_default = 0
                 WHERE customer_id = ? AND addr_type = ?'
            );
            $stmt->execute([$customerId, $addrType]);
        }

        if ($addrId) {
            // Editing — verify ownership before updating
            $stmt = $pdo->prepare(
                'SELECT address_id FROM `user_addresses`
                 WHERE address_id = ? AND customer_id = ? LIMIT 1'
            );
            $stmt->execute([$addrId, $customerId]);
            if (!$stmt->fetch()) {
                respond(['success' => false, 'message' => 'Address not found.'], 404);
            }

            $stmt = $pdo->prepare(
                'UPDATE `user_addresses`
                 SET full_name=?, line1=?, line2=?, city=?, postal=?, phone=?, is_default=?
                 WHERE address_id = ? AND customer_id = ?'
            );
            $stmt->execute([$fullName, $line1, $line2, $city, $postal, $phone, (int) $isDefault, $addrId, $customerId]);
        } else {
            // Insert new; if first address, make it default automatically
            $countStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM `user_addresses`
                 WHERE customer_id = ? AND addr_type = ?'
            );
            $countStmt->execute([$customerId, $addrType]);
            $count = (int) $countStmt->fetchColumn();
            if ($count === 0) $isDefault = true;

            $stmt = $pdo->prepare(
                'INSERT INTO `user_addresses`
                 (customer_id, addr_type, full_name, line1, line2, city, postal, phone, is_default)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $customerId, $addrType, $fullName, $line1, $line2,
                $city, $postal, $phone, (int) $isDefault,
            ]);
        }
    } catch (PDOException $e) {
        error_log('[KukiCakes addresses POST] DB error: ' . $e->getMessage());
        respond(['success' => false, 'message' => 'Could not save address.'], 500);
    }

    respond(['success' => true, 'message' => 'Address saved.']);
}

// ── DELETE — remove an address ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // id can come from query string or body
    parse_str(file_get_contents('php://input'), $deleteBody);
    $addrId = !empty($_GET['id']) ? (int) $_GET['id'] : (int) ($deleteBody['id'] ?? 0);

    if (!$addrId) {
        respond(['success' => false, 'message' => 'Address ID required.'], 400);
    }

    try {
        // Ownership check — only delete own addresses
        $stmt = $pdo->prepare(
            'DELETE FROM `user_addresses`
             WHERE address_id = ? AND customer_id = ?'
        );
        $stmt->execute([$addrId, $customerId]);
        if ($stmt->rowCount() === 0) {
            respond(['success' => false, 'message' => 'Address not found.'], 404);
        }
    } catch (PDOException $e) {
        error_log('[KukiCakes addresses DELETE] DB error: ' . $e->getMessage());
        respond(['success' => false, 'message' => 'Could not delete address.'], 500);
    }

    respond(['success' => true, 'message' => 'Address removed.']);
}

respond(['success' => false, 'message' => 'Method not allowed.'], 405);
