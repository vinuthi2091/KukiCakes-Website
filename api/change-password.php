<?php
/**
 * KúkiCakes — POST /api/change-password
 *
 * Changes the authenticated user's password.
 *
 * Security:
 *  - Requires valid session
 *  - Verifies current password against stored hash
 *  - Hashes new password with password_hash(PASSWORD_DEFAULT)
 *  - Never exposes the stored hash
 *
 * Request body (JSON or form-encoded):
 *   current_password, new_password, confirm_password
 *
 * Responses:
 *   200 { success: true, message: "Password changed successfully." }
 *   400 { success: false, message: "..." }
 *   401 { success: false, message: "..." }
 */
require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$customerId = requireAuth();

$body            = getBody();
$currentPassword = $body['current_password'] ?? '';
$newPassword     = $body['new_password']      ?? '';
$confirmPassword = $body['confirm_password']  ?? '';

// ── Validation ─────────────────────────────────────────────────
if (!$currentPassword) {
    respond(['success' => false, 'message' => 'Current password is required.'], 400);
}
if (strlen($newPassword) < 8) {
    respond(['success' => false, 'message' => 'New password must be at least 8 characters.'], 400);
}
if (!preg_match('/[A-Z]/', $newPassword)) {
    respond(['success' => false, 'message' => 'New password must contain at least one uppercase letter.'], 400);
}
if (!preg_match('/[0-9]/', $newPassword)) {
    respond(['success' => false, 'message' => 'New password must contain at least one number.'], 400);
}
if ($newPassword !== $confirmPassword) {
    respond(['success' => false, 'message' => 'Passwords do not match.'], 400);
}

// ── Fetch stored hash ──────────────────────────────────────────
try {
    $stmt = $pdo->prepare('SELECT password_hash FROM `customer` WHERE customer_id = ? LIMIT 1');
    $stmt->execute([$customerId]);
    $row = $stmt->fetch();
} catch (PDOException $e) {
    error_log('[KukiCakes change-password] DB fetch error: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Password change failed. Please try again.'], 500);
}

if (!$row || empty($row['password_hash'])) {
    respond(['success' => false, 'message' => 'Password change failed. Please try again.'], 500);
}

// ── Verify current password ────────────────────────────────────
if (!password_verify($currentPassword, $row['password_hash'])) {
    respond(['success' => false, 'message' => 'Current password is incorrect.'], 401);
}

// ── Hash new password and update ──────────────────────────────
$newHash = password_hash($newPassword, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare('UPDATE `customer` SET password_hash = ? WHERE customer_id = ?');
    $stmt->execute([$newHash, $customerId]);
} catch (PDOException $e) {
    error_log('[KukiCakes change-password] Update error: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Password change failed. Please try again.'], 500);
}

respond(['success' => true, 'message' => 'Password changed successfully.']);
