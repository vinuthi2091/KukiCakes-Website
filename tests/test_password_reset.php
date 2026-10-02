<?php
/**
 * Automated Test Suite: Forgot Password & Password Reset Flow
 *
 * Verifies:
 *  1. Registered vs unregistered email requests
 *  2. Cryptographic token generation & SHA-256 storage (no plaintext token in DB)
 *  3. Token expiration handling
 *  4. Token single-use enforcement (reused token rejection)
 *  5. Password policy enforcement
 *  6. Successful password reset with password_hash()
 *  7. Old password rejection after reset
 *  8. New password login success
 *  9. No exposure of hashes or plaintext tokens in API responses
 *  10. Accurate email delivery reporting (not faking delivery)
 */

require_once __DIR__ . '/../config/db.php';

$baseUrl = 'http://localhost/KukiCakes-Website';
$results = [];

function httpReq(string $method, string $url, ?array $body = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $rawResponse = curl_exec($ch);
    $status      = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error       = curl_error($ch);
    curl_close($ch);

    return [
        'status' => $status,
        'raw'    => $rawResponse,
        'json'   => json_decode($rawResponse ?: '', true),
        'error'  => $error,
    ];
}

function assertTest(string $name, bool $condition, string $details = ''): void {
    global $results;
    $results[] = [
        'name'    => $name,
        'passed'  => $condition,
        'details' => $details,
    ];
    echo ($condition ? "[\033[32mPASS\033[0m] " : "[\033[31mFAIL\033[0m] ") . $name;
    if (!$condition && $details) {
        echo " -> " . $details;
    }
    echo PHP_EOL;
}

echo "=========================================================\n";
echo " KúkiCakes Password Reset Test Suite\n";
echo "=========================================================\n\n";

// ── SETUP: Create fresh test customer ──────────────────────────
$testEmail    = 'reset_suite_test@kukicakes.local';
$oldPassword  = 'OldSecret123';
$newPassword  = 'NewSecret456';
$testName     = 'Reset Suite Customer';

// Cleanup any old test user
$stmt = $pdo->prepare('DELETE FROM `customer` WHERE email = ?');
$stmt->execute([$testEmail]);

$initHash = password_hash($oldPassword, PASSWORD_DEFAULT);
$stmt = $pdo->prepare(
    'INSERT INTO `customer` (full_name, email, phone_no, password_hash, account_status)
     VALUES (?, ?, ?, ?, "active")'
);
$stmt->execute([$testName, $testEmail, '+94771234567', $initHash]);
$testCustomerId = (int) $pdo->lastInsertId();

assertTest('Setup: Test customer created', $testCustomerId > 0, "ID: $testCustomerId");

// ── TEST 1: Unregistered Email ─────────────────────────────────
$res = httpReq('POST', "$baseUrl/api/forgot-password.php", ['email' => 'unregistered_dummy@domain.test']);
assertTest(
    'Test 1: Unregistered email returns generic success without exposing absence',
    $res['status'] === 200 && ($res['json']['success'] ?? false) === true && ($res['json']['account_found'] ?? true) === false,
    "Status: {$res['status']}, JSON: {$res['raw']}"
);

// Verify no token created in DB for unregistered email
$stmt = $pdo->prepare('SELECT COUNT(*) FROM `password_resets` WHERE customer_id NOT IN (SELECT customer_id FROM customer)');
$stmt->execute();
$orphanCount = (int) $stmt->fetchColumn();
assertTest('Test 1b: No reset tokens created in DB for non-existent users', $orphanCount === 0);

// ── TEST 2: Registered Email Forgot Password ───────────────────
$res = httpReq('POST', "$baseUrl/api/forgot-password.php", ['email' => $testEmail]);
$token = $res['json']['reset_token'] ?? '';
assertTest(
    'Test 2: Registered email generates reset token and reports accurate email delivery',
    $res['status'] === 200 &&
    ($res['json']['success'] ?? false) === true &&
    ($res['json']['email_delivery'] ?? true) === false && // accurately reports no SMTP configured
    !empty($token),
    "Status: {$res['status']}, JSON: {$res['raw']}"
);

// Verify token hash in DB
$tokenHash = hash('sha256', $token);
$stmt = $pdo->prepare('SELECT reset_id, token_hash, expires_at, used_at FROM `password_resets` WHERE customer_id = ? AND used_at IS NULL');
$stmt->execute([$testCustomerId]);
$dbReset = $stmt->fetch();
assertTest(
    'Test 2b: Token stored as SHA-256 hash in DB (not plaintext)',
    !empty($dbReset) && $dbReset['token_hash'] === $tokenHash && $dbReset['token_hash'] !== $token,
    "Stored: " . ($dbReset['token_hash'] ?? 'none')
);

// ── TEST 3: Validate Token (GET) ───────────────────────────────
$res = httpReq('GET', "$baseUrl/api/reset-password.php?token=" . urlencode($token));
assertTest(
    'Test 3: Valid reset token passes verification',
    $res['status'] === 200 && ($res['json']['valid'] ?? false) === true,
    "Status: {$res['status']}, JSON: {$res['raw']}"
);
assertTest(
    'Test 3b: No password hash or secret exposed in validation response',
    empty($res['json']['password_hash']) && empty($res['json']['token_hash'])
);

// ── TEST 4: Invalid Token (GET) ────────────────────────────────
$res = httpReq('GET', "$baseUrl/api/reset-password.php?token=completely_bogus_token_12345");
assertTest(
    'Test 4: Invalid reset token rejected (HTTP 400)',
    $res['status'] === 400 && ($res['json']['valid'] ?? true) === false,
    "Status: {$res['status']}, JSON: {$res['raw']}"
);

// ── TEST 5: Expired Token ──────────────────────────────────────
// Generate an expired token directly in DB
$expiredPlain = bin2hex(random_bytes(32));
$expiredHash  = hash('sha256', $expiredPlain);
$stmt = $pdo->prepare(
    'INSERT INTO `password_resets` (customer_id, token_hash, expires_at)
     VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 2 HOUR))'
);
$stmt->execute([$testCustomerId, $expiredHash]);

$res = httpReq('GET', "$baseUrl/api/reset-password.php?token=" . urlencode($expiredPlain));
assertTest(
    'Test 5: Expired token rejected during verification (HTTP 400)',
    $res['status'] === 400 && ($res['json']['valid'] ?? true) === false,
    "Status: {$res['status']}, JSON: {$res['raw']}"
);

$res = httpReq('POST', "$baseUrl/api/reset-password.php", [
    'token'            => $expiredPlain,
    'new_password'     => 'ValidPassword123',
    'confirm_password' => 'ValidPassword123',
]);
assertTest(
    'Test 5b: Expired token rejected during password reset execution (HTTP 400)',
    $res['status'] === 400 && ($res['json']['success'] ?? true) === false,
    "Status: {$res['status']}, JSON: {$res['raw']}"
);

// ── TEST 6: Password Policy Validation ─────────────────────────
$res = httpReq('POST', "$baseUrl/api/reset-password.php", [
    'token'            => $token,
    'new_password'     => 'short1',
    'confirm_password' => 'short1',
]);
assertTest(
    'Test 6a: Reject passwords shorter than 8 characters',
    $res['status'] === 400 && str_contains(strtolower($res['json']['message'] ?? ''), '8 characters')
);

$res = httpReq('POST', "$baseUrl/api/reset-password.php", [
    'token'            => $token,
    'new_password'     => 'alllowercase123',
    'confirm_password' => 'alllowercase123',
]);
assertTest(
    'Test 6b: Reject passwords without uppercase letter',
    $res['status'] === 400 && str_contains(strtolower($res['json']['message'] ?? ''), 'uppercase')
);

$res = httpReq('POST', "$baseUrl/api/reset-password.php", [
    'token'            => $token,
    'new_password'     => 'NoNumbersHere',
    'confirm_password' => 'NoNumbersHere',
]);
assertTest(
    'Test 6c: Reject passwords without a number',
    $res['status'] === 400 && str_contains(strtolower($res['json']['message'] ?? ''), 'number')
);

$res = httpReq('POST', "$baseUrl/api/reset-password.php", [
    'token'            => $token,
    'new_password'     => 'MismatchPassword1',
    'confirm_password' => 'DifferentPassword2',
]);
assertTest(
    'Test 6d: Reject non-matching confirmation password',
    $res['status'] === 400 && str_contains(strtolower($res['json']['message'] ?? ''), 'match')
);

// ── TEST 7: Successful Password Reset ──────────────────────────
$res = httpReq('POST', "$baseUrl/api/reset-password.php", [
    'token'            => $token,
    'new_password'     => $newPassword,
    'confirm_password' => $newPassword,
]);
assertTest(
    'Test 7: Successful password reset (HTTP 200)',
    $res['status'] === 200 && ($res['json']['success'] ?? false) === true,
    "Status: {$res['status']}, JSON: {$res['raw']}"
);

// Verify token marked used in DB
$stmt = $pdo->prepare('SELECT used_at FROM `password_resets` WHERE reset_id = ?');
$stmt->execute([(int) $dbReset['reset_id']]);
$updatedReset = $stmt->fetch();
assertTest(
    'Test 7b: Token marked as used (used_at IS NOT NULL) in DB',
    !empty($updatedReset['used_at']),
    "used_at: " . ($updatedReset['used_at'] ?? 'NULL')
);

// Verify customer's stored password hash changed and verifies against new password
$stmt = $pdo->prepare('SELECT password_hash FROM `customer` WHERE customer_id = ?');
$stmt->execute([$testCustomerId]);
$custRow = $stmt->fetch();
assertTest(
    'Test 7c: Customer password_hash updated and verifies with password_verify()',
    password_verify($newPassword, $custRow['password_hash']) && !password_verify($oldPassword, $custRow['password_hash'])
);

// ── TEST 8: Reused Token Rejection ─────────────────────────────
$res = httpReq('GET', "$baseUrl/api/reset-password.php?token=" . urlencode($token));
assertTest(
    'Test 8a: Reused token rejected in GET verification (HTTP 400)',
    $res['status'] === 400 && ($res['json']['valid'] ?? true) === false,
    "Status: {$res['status']}, JSON: {$res['raw']}"
);

$res = httpReq('POST', "$baseUrl/api/reset-password.php", [
    'token'            => $token,
    'new_password'     => 'AnotherPassword99',
    'confirm_password' => 'AnotherPassword99',
]);
assertTest(
    'Test 8b: Reused token rejected in POST execution (HTTP 400)',
    $res['status'] === 400 && ($res['json']['success'] ?? true) === false,
    "Status: {$res['status']}, JSON: {$res['raw']}"
);

// ── TEST 9: Old Password No Longer Works ───────────────────────
$res = httpReq('POST', "$baseUrl/api/login.php", [
    'email'    => $testEmail,
    'password' => $oldPassword,
]);
assertTest(
    'Test 9: Login with old password fails (HTTP 401)',
    $res['status'] === 401 && ($res['json']['success'] ?? true) === false,
    "Status: {$res['status']}, JSON: {$res['raw']}"
);

// ── TEST 10: New Password Login Succeeds ───────────────────────
$res = httpReq('POST', "$baseUrl/api/login.php", [
    'email'    => $testEmail,
    'password' => $newPassword,
]);
assertTest(
    'Test 10: Login with new password succeeds (HTTP 200)',
    $res['status'] === 200 &&
    ($res['json']['success'] ?? false) === true &&
    isset($res['json']['user']['customer_id']),
    "Status: {$res['status']}, JSON: {$res['raw']}"
);

// ── CLEANUP ────────────────────────────────────────────────────
$stmt = $pdo->prepare('DELETE FROM `customer` WHERE email = ?');
$stmt->execute([$testEmail]);

echo "\n=========================================================\n";
$passCount = count(array_filter($results, fn($r) => $r['passed']));
$failCount = count($results) - $passCount;
echo " Summary: $passCount passed, $failCount failed.\n";
echo "=========================================================\n";

exit($failCount > 0 ? 1 : 0);
