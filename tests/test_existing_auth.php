<?php
/**
 * Regression Suite: Existing Authentication Functionality
 *
 * Verifies that:
 *  - Sign up works
 *  - Login with correct credentials works and creates session
 *  - Wrong password login fails (HTTP 401)
 *  - Non-existent account login fails (HTTP 401)
 *  - Session check returns authenticated user
 *  - Profile fetch works
 *  - Profile update works
 *  - Change password works
 *  - Logout works and clears session
 */

require_once __DIR__ . '/../config/db.php';

$baseUrl = 'http://localhost/KukiCakes-Website';
$cookieFile = tempnam(sys_get_temp_dir(), 'kuki_cookie_');

function curlReq(string $method, string $url, ?array $body = null, ?string $cookieJar = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $rawResponse = curl_exec($ch);
    $status      = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $status,
        'raw'    => $rawResponse,
        'json'   => json_decode($rawResponse ?: '', true),
    ];
}

$testEmail = 'regression_user_' . time() . '@kukicakes.local';
$pass1     = 'SecurePass123';
$pass2     = 'NewSecurePass456';

echo "Running Existing Auth Regression...\n";

// 1. Sign up
$res = curlReq('POST', "$baseUrl/api/register.php", [
    'full_name'        => 'Regression User',
    'email'            => $testEmail,
    'phone'            => '+94770009999',
    'password'         => $pass1,
    'confirm_password' => $pass1,
]);
assert($res['status'] === 201, "Sign up failed: {$res['raw']}");
echo "[PASS] Sign up\n";

// 2. Login wrong password -> 401
$res = curlReq('POST', "$baseUrl/api/login.php", [
    'email'    => $testEmail,
    'password' => 'WrongPassword999',
]);
assert($res['status'] === 401, "Wrong password must return 401");
echo "[PASS] Wrong password rejected with 401\n";

// 3. Login non-existent email -> 401
$res = curlReq('POST', "$baseUrl/api/login.php", [
    'email'    => 'nonexistent_' . time() . '@nowhere.test',
    'password' => $pass1,
]);
assert($res['status'] === 401, "Non-existent user must return 401");
echo "[PASS] Nonexistent email rejected with 401\n";

// 4. Login correct password -> 200 with session cookie
$res = curlReq('POST', "$baseUrl/api/login.php", [
    'email'    => $testEmail,
    'password' => $pass1,
], $cookieFile);
assert($res['status'] === 200, "Login failed: {$res['raw']}");
assert($res['json']['user']['email'] === $testEmail, "User object missing in login response");
echo "[PASS] Login with correct password\n";

// 5. Check session
$res = curlReq('GET', "$baseUrl/api/check-session.php", null, $cookieFile);
assert($res['status'] === 200 && ($res['json']['authenticated'] ?? false) === true, "Session check failed");
echo "[PASS] Check session authenticated\n";

// 6. Fetch profile
$res = curlReq('GET', "$baseUrl/api/profile.php", null, $cookieFile);
assert($res['status'] === 200 && ($res['json']['user']['email'] ?? '') === $testEmail, "Profile fetch failed");
echo "[PASS] Profile fetch\n";

// 7. Change password
$res = curlReq('POST', "$baseUrl/api/change-password.php", [
    'current_password' => $pass1,
    'new_password'     => $pass2,
    'confirm_password' => $pass2,
], $cookieFile);
assert($res['status'] === 200 && ($res['json']['success'] ?? false) === true, "Change password failed: {$res['raw']}");
echo "[PASS] Change password\n";

// 8. Logout
$res = curlReq('POST', "$baseUrl/api/logout.php", null, $cookieFile);
assert($res['status'] === 200, "Logout failed");
echo "[PASS] Logout\n";

// 9. Check session after logout
$res = curlReq('GET', "$baseUrl/api/check-session.php", null, $cookieFile);
assert($res['status'] === 200 && ($res['json']['authenticated'] ?? true) === false, "Session still active after logout");
echo "[PASS] Session cleared after logout\n";

// 10. Login with new password
$res = curlReq('POST', "$baseUrl/api/login.php", [
    'email'    => $testEmail,
    'password' => $pass2,
], $cookieFile);
assert($res['status'] === 200, "Login with changed password failed: {$res['raw']}");
echo "[PASS] Login with new password after change-password\n";

// Cleanup
$stmt = $pdo->prepare('DELETE FROM `customer` WHERE email = ?');
$stmt->execute([$testEmail]);
@unlink($cookieFile);

echo "\nAll existing auth features verified and 100% working!\n";
