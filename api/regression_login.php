<?php
/**
 * KúkiCakes — Login Bug Regression Test
 *
 * Proves:
 *  1. Correct password → login succeeds, session created
 *  2. Wrong password   → login fails, session destroyed/not created  ← THE BUG
 *  3. Nonexistent email → login fails, session not created
 *  4. Session persists ONLY after correct login
 *  5. After failed login, check-session returns authenticated=false
 *
 * Run with: php api/regression_login.php
 */
require_once __DIR__ . '/../config/db.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) {
        echo "  ✓ PASS  $label\n";
        $pass++;
    } else {
        echo "  ✗ FAIL  $label" . ($detail ? "\n         Detail: $detail" : '') . "\n";
        $fail++;
    }
}

// ---------------------------------------------------------------------------
// Setup: create a test user
// ---------------------------------------------------------------------------
$pdo->prepare("DELETE FROM customer WHERE email='regrtest@kukicakes.lk'")->execute();
$hash = password_hash('CorrectPass1', PASSWORD_DEFAULT);
$pdo->prepare(
    "INSERT INTO customer (full_name, email, phone_no, password_hash, role, account_status)
     VALUES ('Regr Test', 'regrtest@kukicakes.lk', '+94770000000', ?, 'customer', 'active')"
)->execute([$hash]);

function httpPost($url, $data, &$status = null, &$setCookies = null, $cookieStr = '') {
    $json = json_encode($data);
    $headers = "Content-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\n";
    if ($cookieStr) {
        $headers .= "Cookie: $cookieStr\r\n";
    }
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => $headers,
        'content'       => $json,
        'ignore_errors' => true,
        'timeout'       => 8,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $hdrs = $http_response_header ?? [];
    preg_match('/HTTP\/[\d\.]+ (\d+)/', $hdrs[0] ?? '', $m);
    $status = (int)($m[1] ?? 0);

    // Extract all Set-Cookie headers
    $setCookies = [];
    foreach ($hdrs as $h) {
        if (stripos($h, 'Set-Cookie:') === 0) {
            $parts = explode(';', substr($h, 12));
            $setCookies[] = trim($parts[0]);
        }
    }
    return $body ?: '';
}

function httpGet($url, $cookieStr, &$status = null) {
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => $cookieStr ? "Cookie: $cookieStr\r\n" : '',
        'ignore_errors' => true,
        'timeout'       => 8,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    preg_match('/HTTP\/[\d\.]+ (\d+)/', ($http_response_header ?? [''])[0], $m);
    $status = (int)($m[1] ?? 0);
    return $body ?: '';
}

$base = 'http://localhost/KukiCakes-Website/api/';

// ---------------------------------------------------------------------------
echo "\n=== REGRESSION TEST: Login Flow ===\n\n";

// ── TEST CASE 1: Correct password → success ─────────────────────────────
echo "CASE 1: Correct credentials\n";
$status1 = null;
$cookies1 = [];
$body1 = httpPost($base . 'login.php', ['email' => 'regrtest@kukicakes.lk', 'password' => 'CorrectPass1'], $status1, $cookies1);
$j1 = json_decode($body1, true);

check('HTTP 200 returned',         $status1 === 200,           "HTTP $status1");
check('success=true in body',      ($j1['success'] ?? null) === true, $body1);
check('user object returned',      isset($j1['user']),         $body1);
check('password NOT in response',  strpos($body1, 'password_hash') === false, $body1);
check('Session cookie set',        count($cookies1) > 0,       'No Set-Cookie header');

// Extract the session cookie from successful login
$sessionCookieStr = '';
foreach ($cookies1 as $c) {
    if (strpos($c, 'KUKI_SESS=') === 0) {
        $sessionCookieStr = $c;
        break;
    }
}
// Use most recent KUKI_SESS (session_regenerate_id creates a new one)
foreach ($cookies1 as $c) {
    if (strpos($c, 'KUKI_SESS=') === 0 && $c !== 'KUKI_SESS=deleted') {
        $sessionCookieStr = $c;
    }
}

// ── Verify session is alive after correct login ──────────────────────────
echo "\nCASE 1b: Session state after correct login\n";
$cs1Status = null;
$cs1Body = httpGet($base . 'check-session.php', $sessionCookieStr, $cs1Status);
$cs1 = json_decode($cs1Body, true);

check('HTTP 200 from check-session',     $cs1Status === 200,                   "HTTP $cs1Status");
check('authenticated=true after login',  ($cs1['authenticated'] ?? false) === true, $cs1Body);
check('User data in session response',   isset($cs1['user']['customer_id']),    $cs1Body);

// ── TEST CASE 2: WRONG PASSWORD — this was the bug ──────────────────────
echo "\nCASE 2: Wrong password (while valid session cookie is active)\n";
echo "  (This is the exact bug scenario: user still has login session cookie)\n";
$status2 = null;
$cookies2 = [];
// Submit wrong password — passing the existing session cookie to simulate the bug
$body2 = httpPost($base . 'login.php', ['email' => 'regrtest@kukicakes.lk', 'password' => 'WrongPassword99'], $status2, $cookies2, $sessionCookieStr);
$j2 = json_decode($body2, true);

check('HTTP 401 returned',        $status2 === 401,            "HTTP $status2");
check('success=false in body',    ($j2['success'] ?? null) === false, $body2);
check('Generic error message',    ($j2['message'] ?? '') === 'Invalid email or password.', $body2);
check('No user object in response', !isset($j2['user']),       $body2);

// ── THE CRITICAL REGRESSION CHECK ────────────────────────────────────────
// After a failed login, check-session must say NOT authenticated.
// If it still says authenticated=true, the bug is still present.
echo "\nCASE 2b: Session state AFTER failed login attempt (THE BUG CHECK)\n";
echo "  Using the SAME session cookie from Case 1...\n";
$cs2Status = null;
$cs2Body = httpGet($base . 'check-session.php', $sessionCookieStr, $cs2Status);
$cs2 = json_decode($cs2Body, true);
$stillAuthenticated = ($cs2['authenticated'] ?? false) === true;

check('HTTP 200 from check-session',      $cs2Status === 200,           "HTTP $cs2Status");
check('authenticated=FALSE after failed login', !$stillAuthenticated,   "BUG! check-session returned: $cs2Body");

if ($stillAuthenticated) {
    echo "\n  ⚠️  BUG STILL PRESENT: The old session was not destroyed by the failed login!\n";
    echo "     check-session returned: $cs2Body\n";
} else {
    echo "\n  ✅ BUG IS FIXED: Session was destroyed by the failed login attempt.\n";
}

// ── TEST CASE 3: Nonexistent email ───────────────────────────────────────
echo "\nCASE 3: Nonexistent email\n";
$status3 = null;
$cookies3 = [];
$body3 = httpPost($base . 'login.php', ['email' => 'nobody@kukicakes.lk', 'password' => 'CorrectPass1'], $status3, $cookies3, $sessionCookieStr);
$j3 = json_decode($body3, true);

check('HTTP 401 returned',        $status3 === 401,           "HTTP $status3");
check('success=false in body',    ($j3['success'] ?? null) === false, $body3);
check('Same generic message (anti-enumeration)', ($j3['message'] ?? '') === 'Invalid email or password.', $body3);

// ── TEST CASE 3b: Session check after nonexistent email attempt ──────────
echo "\nCASE 3b: Session state after nonexistent email attempt\n";
$cs3Status = null;
$cs3Body = httpGet($base . 'check-session.php', $sessionCookieStr, $cs3Status);
$cs3 = json_decode($cs3Body, true);

check('authenticated=false',      ($cs3['authenticated'] ?? false) === false, $cs3Body);

// ── TEST CASE 4: Successful login creates a new valid session ────────────
echo "\nCASE 4: New login with correct credentials after previous session was cleared\n";
$status4 = null;
$cookies4 = [];
$body4 = httpPost($base . 'login.php', ['email' => 'regrtest@kukicakes.lk', 'password' => 'CorrectPass1'], $status4, $cookies4);
$j4 = json_decode($body4, true);

check('HTTP 200 returned',         $status4 === 200,           "HTTP $status4");
check('success=true in body',      ($j4['success'] ?? null) === true, $body4);

// Get the new session cookie
$newSessionCookieStr = '';
foreach ($cookies4 as $c) {
    if (strpos($c, 'KUKI_SESS=') === 0 && $c !== 'KUKI_SESS=deleted') {
        $newSessionCookieStr = $c;
    }
}

$cs4Status = null;
$cs4Body = httpGet($base . 'check-session.php', $newSessionCookieStr, $cs4Status);
$cs4 = json_decode($cs4Body, true);

check('New session is authenticated', ($cs4['authenticated'] ?? false) === true, $cs4Body);
check('Session has correct user',     ($cs4['user']['email'] ?? '') === 'regrtest@kukicakes.lk', $cs4Body);

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
$pdo->prepare("DELETE FROM customer WHERE email='regrtest@kukicakes.lk'")->execute();

echo "\n" . str_repeat('=', 48) . "\n";
echo "Results: $pass passed, $fail failed\n";
if ($fail === 0) {
    echo "✅ ALL REGRESSION TESTS PASSED — Login bug is fixed.\n";
} else {
    echo "⚠️  $fail test(s) FAILED.\n";
}
echo "\n";
