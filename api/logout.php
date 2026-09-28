<?php
/**
 * KúkiCakes — POST /api/logout
 *
 * Destroys the server-side session and clears the session cookie.
 *
 * Responses:
 *   200 { success: true, message: "Logged out." }
 */
require_once __DIR__ . '/bootstrap.php';

// Destroy session data
$_SESSION = [];

// Expire the session cookie
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

respond(['success' => true, 'message' => 'Logged out.']);
