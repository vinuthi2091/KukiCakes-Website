<?php
/**
 * KúkiCakes — POST /api/login
 *
 * Authenticates an existing user and creates a server-side session.
 *
 * IMPORTANT: Any existing session is destroyed at the start of every
 * login attempt — before credentials are verified. This prevents a
 * previously authenticated session from persisting when the submitted
 * password is wrong.
 *
 * Request body (JSON or form-encoded):
 *   email, password
 *
 * Responses:
 *   200 { success: true, user: { customer_id, name, email, phone, joinDate } }
 *   401 { success: false, message: "Invalid email or password." }   (generic — no enumeration)
 *   500 { success: false, message: "..." }
 */
require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

// ── Destroy any existing session BEFORE validating credentials ─
// This guarantees that any previous session is completely invalidated.
// If the user submits wrong credentials, they will NOT retain any
// active session or authenticated state.
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}

$body     = getBody();
$email    = strtolower(trim($body['email']    ?? ''));
$password = $body['password'] ?? '';

if (!$email || !$password) {
    respond(['success' => false, 'message' => 'Invalid email or password.'], 401);
}

// ── Look up user ───────────────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        'SELECT customer_id, full_name, email, phone_no, password_hash,
                account_status, created_at
         FROM `customer`
         WHERE email = ?
         LIMIT 1'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    error_log('[KukiCakes login] DB error: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Invalid email or password.'], 401);
}

// ── Generic error — do NOT reveal whether email exists ─────────
if (!$user || empty($user['password_hash'])) {
    // Perform a dummy verify to prevent timing attacks
    password_verify($password, '$2y$10$invalidhashpaddinginvalidinvalidinvalidinvalidinvalid00');
    // Session was destroyed above — this request ends unauthenticated
    respond(['success' => false, 'message' => 'Invalid email or password.'], 401);
}

if (!password_verify($password, $user['password_hash'])) {
    // Session was destroyed above — this request ends unauthenticated
    respond(['success' => false, 'message' => 'Invalid email or password.'], 401);
}

// ── Check account status ───────────────────────────────────────
if ($user['account_status'] !== 'active') {
    respond(['success' => false, 'message' => 'Invalid email or password.'], 401);
}

// ── Credentials verified — start a fresh authenticated session ─
session_name('KUKI_SESS');
session_start();
session_regenerate_id(true);

// ── Store minimal, safe info in session ────────────────────────
$_SESSION['customer_id'] = (int) $user['customer_id'];
$_SESSION['email']       = $user['email'];
$_SESSION['name']        = $user['full_name'];

// ── Verify the session was actually written ────────────────────
if (empty($_SESSION['customer_id'])) {
    error_log('[KukiCakes login] Session write failed for customer_id=' . $user['customer_id']);
    respond(['success' => false, 'message' => 'Login failed. Please try again.'], 500);
}

// ── Build safe public response (NO password_hash) ─────────────
$joinDate = date('j M Y', strtotime($user['created_at']));

respond([
    'success' => true,
    'user' => [
        'customer_id' => (int) $user['customer_id'],
        'name'        => $user['full_name'],
        'email'       => $user['email'],
        'phone'       => $user['phone_no'],
        'joinDate'    => $joinDate,
    ],
]);
