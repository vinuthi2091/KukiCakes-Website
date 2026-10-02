<?php
/**
 * KúkiCakes — GET & POST /api/reset-password
 *
 * Handles password reset verification and execution:
 *
 * GET /api/reset-password?token=...
 *   - Verifies whether a token is valid, unexpired, and not yet used.
 *   - Response: 200 { success: true, valid: true } or 400 { success: false, valid: false, message: "..." }
 *
 * POST /api/reset-password
 *   - Accepts { token, new_password, confirm_password }
 *   - Verifies token validity and single-use constraint.
 *   - Validates password complexity policy.
 *   - Hashes new password with password_hash(PASSWORD_DEFAULT).
 *   - Marks token as used (used_at = NOW()).
 *   - Invalidates any existing session for the user.
 *   - Response: 200 { success: true, message: "..." } or 400 on error.
 */
require_once __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET' && $method !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

// ─────────────────────────────────────────────────────────────
// GET: Verify token validity for the reset UI
// ─────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $token = trim($_GET['token'] ?? '');

    if (!$token) {
        respond(['success' => false, 'valid' => false, 'message' => 'Reset token is missing.'], 400);
    }

    $tokenHash = hash('sha256', $token);

    try {
        $stmt = $pdo->prepare(
            'SELECT pr.reset_id, pr.customer_id, pr.expires_at, pr.used_at,
                    (pr.expires_at < NOW()) AS is_expired,
                    c.account_status, c.email
             FROM `password_resets` pr
             JOIN `customer` c ON pr.customer_id = c.customer_id
             WHERE pr.token_hash = ?
             LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('[KukiCakes reset-password] DB lookup error: ' . $e->getMessage());
        respond(['success' => false, 'valid' => false, 'message' => 'Unable to verify reset link.'], 500);
    }

    if (!$row) {
        respond(['success' => false, 'valid' => false, 'message' => 'This reset link is invalid or does not exist.'], 400);
    }

    if ($row['used_at'] !== null) {
        respond(['success' => false, 'valid' => false, 'message' => 'This reset link has already been used. Please request a new one.'], 400);
    }

    if ((int) $row['is_expired'] === 1 || strtotime($row['expires_at']) < time()) {
        respond(['success' => false, 'valid' => false, 'message' => 'This reset link has expired. Please request a new one.'], 400);
    }

    if ($row['account_status'] !== 'active') {
        respond(['success' => false, 'valid' => false, 'message' => 'This account is inactive or disabled.'], 400);
    }

    // Masked email for display reassurance (e.g. a***a@email.com)
    $emailParts = explode('@', $row['email']);
    $maskedName = substr($emailParts[0], 0, 1) . str_repeat('*', max(1, strlen($emailParts[0]) - 2)) . (strlen($emailParts[0]) > 1 ? substr($emailParts[0], -1) : '');
    $maskedEmail = $maskedName . '@' . ($emailParts[1] ?? '');

    respond([
        'success'      => true,
        'valid'        => true,
        'message'      => 'Reset token is valid.',
        'masked_email' => $maskedEmail,
    ]);
}

// ─────────────────────────────────────────────────────────────
// POST: Execute the password reset
// ─────────────────────────────────────────────────────────────
$body            = getBody();
$token           = trim($body['token'] ?? '');
$newPassword     = $body['new_password'] ?? '';
$confirmPassword = $body['confirm_password'] ?? '';

// ── Validation ───────────────────────────────────────────────
if (!$token) {
    respond(['success' => false, 'message' => 'Reset token is required.'], 400);
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

// ── Token verification ───────────────────────────────────────
$tokenHash = hash('sha256', $token);

try {
    $stmt = $pdo->prepare(
        'SELECT pr.reset_id, pr.customer_id, pr.expires_at, pr.used_at,
                (pr.expires_at < NOW()) AS is_expired,
                c.account_status
         FROM `password_resets` pr
         JOIN `customer` c ON pr.customer_id = c.customer_id
         WHERE pr.token_hash = ?
         LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    $reset = $stmt->fetch();
} catch (PDOException $e) {
    error_log('[KukiCakes reset-password] Token fetch error: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Unable to process reset request. Please try again.'], 500);
}

if (!$reset) {
    respond(['success' => false, 'message' => 'Invalid or unrecognized reset token.'], 400);
}

if ($reset['used_at'] !== null) {
    respond(['success' => false, 'message' => 'This reset token has already been used. Please request a new one.'], 400);
}

if ((int) $reset['is_expired'] === 1 || strtotime($reset['expires_at']) < time()) {
    respond(['success' => false, 'message' => 'This reset token has expired. Please request a new one.'], 400);
}

if ($reset['account_status'] !== 'active') {
    respond(['success' => false, 'message' => 'This account is inactive or disabled.'], 400);
}

// ── Update password and invalidate token ─────────────────────
try {
    $pdo->beginTransaction();

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

    // Update customer password hash
    $stmt = $pdo->prepare('UPDATE `customer` SET password_hash = ? WHERE customer_id = ?');
    $stmt->execute([$newHash, (int) $reset['customer_id']]);

    // Mark current reset token as used (single-use constraint)
    $stmt = $pdo->prepare('UPDATE `password_resets` SET used_at = NOW() WHERE reset_id = ?');
    $stmt->execute([(int) $reset['reset_id']]);

    // Invalidate any other active tokens for this customer as a security measure
    $stmt = $pdo->prepare('UPDATE `password_resets` SET used_at = NOW() WHERE customer_id = ? AND used_at IS NULL');
    $stmt->execute([(int) $reset['customer_id']]);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[KukiCakes reset-password] Password update error: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Failed to update password. Please try again.'], 500);
}

// ── Clear any existing session to enforce fresh login ────────
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];
    session_destroy();
}

respond([
    'success' => true,
    'message' => 'Password reset successful! Please log in with your new password.',
]);
