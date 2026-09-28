<?php
/**
 * KúkiCakes — POST /api/update-profile
 *
 * Updates the authenticated user's name and/or phone number.
 * The user_id is taken from the SESSION — never from the request body.
 *
 * Request body (JSON or form-encoded):
 *   full_name, phone
 *
 * Responses:
 *   200 { success: true, user: { ... updated safe fields ... } }
 *   400 { success: false, message: "..." }
 *   401 { success: false, message: "Authentication required." }
 */
require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$customerId = requireAuth();

$body     = getBody();
$fullName = trim($body['full_name'] ?? '');
$phone    = trim($body['phone']     ?? '');

$errors = [];
if (strlen($fullName) < 2) {
    $errors[] = 'Full name must be at least 2 characters.';
}
if ($phone && !preg_match('/^[+\d\s\-()\.\x{00B7}]{7,20}$/u', $phone)) {
    $errors[] = 'Please provide a valid phone number.';
}
if (!empty($errors)) {
    respond(['success' => false, 'message' => implode(' ', $errors)], 400);
}

try {
    $stmt = $pdo->prepare(
        'UPDATE `customer` SET full_name = ?, phone_no = ?
         WHERE customer_id = ?'
    );
    $stmt->execute([$fullName, $phone, $customerId]);
} catch (PDOException $e) {
    error_log('[KukiCakes update-profile] DB error: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Update failed. Please try again.'], 500);
}

// Refresh name in session
$_SESSION['name'] = $fullName;

// Return updated profile
$stmt = $pdo->prepare(
    'SELECT customer_id, full_name, email, phone_no, account_status, created_at
     FROM `customer` WHERE customer_id = ? LIMIT 1'
);
$stmt->execute([$customerId]);
$user = $stmt->fetch();

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
