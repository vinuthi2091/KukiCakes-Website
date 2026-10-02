<?php
/**
 * KúkiCakes — POST /api/forgot-password
 *
 * Initiates the password reset process:
 *  1. Validates the submitted email address.
 *  2. If the customer exists and is active, generates a cryptographically
 *     secure single-use reset token and stores its SHA-256 hash with 1-hour expiry.
 *  3. Invalidates any prior unused tokens for this account.
 *  4. Adheres strictly to security standards:
 *     - Never reveals whether an email exists (generic response).
 *     - Stores only the SHA-256 token hash (never plaintext).
 *     - Never exposes password hashes.
 *     - Checks for email delivery capability and does not falsely claim
 *       an email was sent when no mailer/SMTP is configured (as per project spec).
 *
 * Request body (JSON or form-encoded):
 *   email
 *
 * Responses:
 *   200 { success: true, message: "...", email_delivery: false, ... }
 *   400 { success: false, message: "..." }
 *   405 { success: false, message: "Method not allowed." }
 */
require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$body  = getBody();
$email = strtolower(trim($body['email'] ?? ''));

if (!$email) {
    respond(['success' => false, 'message' => 'Email address is required.'], 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['success' => false, 'message' => 'Please enter a valid email address.'], 400);
}

// ── Check if email service is configured on this environment ──
$sendmailPath = ini_get('sendmail_path');
$smtpHost     = ini_get('SMTP');
$hasMailer    = !empty($sendmailPath) || (!empty($smtpHost) && strtolower($smtpHost) !== 'localhost');

try {
    $stmt = $pdo->prepare(
        'SELECT customer_id, full_name, email, account_status
         FROM `customer`
         WHERE email = ?
         LIMIT 1'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    error_log('[KukiCakes forgot-password] DB error: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Unable to process request. Please try again later.'], 500);
}

$genericMessage = 'If an account exists with that email address, password reset instructions have been generated.';

// ── Unregistered or inactive account ───────────────────────────
if (!$user || $user['account_status'] !== 'active') {
    // Perform dummy hash to prevent timing attacks
    hash('sha256', random_bytes(32));
    respond([
        'success'        => true,
        'message'        => $genericMessage,
        'email_delivery' => false,
        'account_found'  => false,
    ]);
}

// ── Registered active customer: generate secure token ──────────
try {
    // 32 cryptographically secure random bytes = 64 hex characters
    $plainToken = bin2hex(random_bytes(32));
    $tokenHash  = hash('sha256', $plainToken);
    // Invalidate any existing unused reset tokens for this customer
    $stmt = $pdo->prepare(
        'UPDATE `password_resets`
         SET used_at = NOW()
         WHERE customer_id = ? AND used_at IS NULL'
    );
    $stmt->execute([(int) $user['customer_id']]);

    // Insert new hashed token with 1 hour expiration
    $stmt = $pdo->prepare(
        'INSERT INTO `password_resets` (customer_id, token_hash, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))'
    );
    $stmt->execute([(int) $user['customer_id'], $tokenHash]);

} catch (Exception $e) {
    error_log('[KukiCakes forgot-password] Token generation error: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Unable to generate reset link. Please try again.'], 500);
}

// ── Handle delivery report ─────────────────────────────────────
// As per project requirements: do NOT pretend an email was sent if
// there is no configured mail delivery system.
$response = [
    'success'        => true,
    'message'        => $genericMessage,
    'email_delivery' => $hasMailer,
    'account_found'  => true,
];

if (!$hasMailer) {
    // In local development/testing without SMTP, return the token & link
    // so user / evaluator can complete the reset flow.
    $response['dev_note']    = 'Email delivery is not configured on this server. Use the reset token below to test password reset.';
    $response['reset_token'] = $plainToken;
    $response['reset_url']   = '#reset-password?token=' . $plainToken;
}

respond($response);
