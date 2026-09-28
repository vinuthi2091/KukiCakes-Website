<?php
/**
 * KúkiCakes — GET /api/check-session
 *
 * Lightweight endpoint used by the frontend on page load to
 * check whether the user has a valid authenticated session,
 * without needing to call the heavier /api/profile.
 *
 * Responses:
 *   200 { authenticated: true,  user: { customer_id, name, email } }
 *   200 { authenticated: false }
 */
require_once __DIR__ . '/bootstrap.php';

if (!empty($_SESSION['customer_id'])) {
    respond([
        'authenticated' => true,
        'user' => [
            'customer_id' => (int) $_SESSION['customer_id'],
            'name'        => $_SESSION['name']  ?? '',
            'email'       => $_SESSION['email'] ?? '',
        ],
    ]);
}

respond(['authenticated' => false]);
