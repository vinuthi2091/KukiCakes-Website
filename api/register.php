<?php
/**
 * KúkiCakes — POST /api/register
 *
 * Registers a new user account.
 * Validates input, checks for duplicate email, hashes password,
 * inserts into the `customer` table.
 *
 * Request body (JSON or form-encoded):
 *   full_name, email, password, confirm_password, phone
 *
 * Responses:
 *   201 { success: true, message: "Registration successful" }
 *   400 { success: false, message: "..." }   (validation failure)
 *   409 { success: false, message: "..." }   (email already in use)
 *   500 { success: false, message: "..." }   (server error — generic)
 */
require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$body = getBody();

$fullName        = trim($body['full_name']        ?? '');
$email           = trim($body['email']            ?? '');
$password        = $body['password']              ?? '';
$confirmPassword = $body['confirm_password']      ?? '';
$phone           = trim($body['phone']            ?? '');

// ── Server-side validation ─────────────────────────────────────

$errors = [];

if (strlen($fullName) < 2) {
    $errors[] = 'Full name must be at least 2 characters.';
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please provide a valid email address.';
}

if (!preg_match('/^[+\d\s\-()\.\x{00B7}]{7,20}$/u', $phone)) {
    $errors[] = 'Please provide a valid phone number.';
}

if (strlen($password) < 8) {
    $errors[] = 'Password must be at least 8 characters.';
} elseif (!preg_match('/[A-Z]/', $password)) {
    $errors[] = 'Password must contain at least one uppercase letter.';
} elseif (!preg_match('/[0-9]/', $password)) {
    $errors[] = 'Password must contain at least one number.';
}

if ($password !== $confirmPassword) {
    $errors[] = 'Passwords do not match.';
}

if (!empty($errors)) {
    respond(['success' => false, 'message' => implode(' ', $errors)], 400);
}

// ── Duplicate email check ──────────────────────────────────────
try {
    $stmt = $pdo->prepare('SELECT customer_id FROM `customer` WHERE email = ? LIMIT 1');
    $stmt->execute([strtolower($email)]);
    if ($stmt->fetch()) {
        // Use a clear but non-exploitable message
        respond(['success' => false, 'message' => 'An account with that email address already exists.'], 409);
    }
} catch (PDOException $e) {
    error_log('[KukiCakes register] DB check error: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Registration failed. Please try again.'], 500);
}

// ── Hash password ──────────────────────────────────────────────
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

// ── Insert new customer ────────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        'INSERT INTO `customer` (full_name, email, phone_no, password_hash, role, account_status)
         VALUES (?, ?, ?, ?, \'customer\', \'active\')'
    );
    $stmt->execute([
        $fullName,
        strtolower($email),
        $phone,
        $passwordHash,
    ]);
} catch (PDOException $e) {
    error_log('[KukiCakes register] Insert error: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Registration failed. Please try again.'], 500);
}

respond(['success' => true, 'message' => 'Registration successful'], 201);
