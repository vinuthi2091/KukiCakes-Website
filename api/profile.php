<?php
/**
 * KúkiCakes — GET /api/profile
 *
 * Returns the authenticated user's profile.
 * Requires a valid server-side session (created by /api/login).
 *
 * Responses:
 *   200 { success: true, user: { ... safe fields ... } }
 *   401 { success: false, message: "Authentication required." }
 */
require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$customerId = requireAuth(); // exits with 401 if not logged in

try {
    $stmt = $pdo->prepare(
        'SELECT customer_id, full_name, email, phone_no, account_status, created_at
         FROM `customer`
         WHERE customer_id = ?
         LIMIT 1'
    );
    $stmt->execute([$customerId]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    error_log('[KukiCakes profile] DB error: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Could not load profile.'], 500);
}

if (!$user) {
    // Session references a deleted account — invalidate
    session_destroy();
    respond(['success' => false, 'message' => 'Authentication required.'], 401);
}

$joinDate = date('j M Y', strtotime($user['created_at']));

respond([
    'success' => true,
    'user' => [
        'customer_id' => (int) $user['customer_id'],
        'name'        => $user['full_name'],
        'email'       => $user['email'],
        'phone'       => $user['phone_no'],
        'joinDate'    => $joinDate,
        'status'      => $user['account_status'],
    ],
]);
