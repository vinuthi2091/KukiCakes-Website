<?php
/**
 * KúkiCakes — PayHere Payment Gateway Configuration
 *
 * Secure server-side configuration for PayHere Sandbox integration.
 *
 * IMPORTANT SECURITY RULES:
 * 1. This file resides server-side and must NEVER be exposed to clients.
 * 2. The PAYHERE_MERCHANT_SECRET must NEVER be sent in JSON, HTML, or JavaScript.
 * 3. Replace the placeholder values with your real Sandbox credentials from the PayHere Dashboard.
 */

// Prevent direct execution via web request
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'payhere.php') {
    http_response_code(403);
    exit('Access forbidden.');
}

// ---------------------------------------------------------------
// PayHere Sandbox Credentials
// (Can be overridden via environment variables if desired)
// ---------------------------------------------------------------

// Replace with your real Sandbox Merchant ID (e.g. '1211111' or your merchant number)
if (!defined('PAYHERE_MERCHANT_ID')) {
    define('PAYHERE_MERCHANT_ID', getenv('PAYHERE_MERCHANT_ID') ?: '12XXXXX');
}

// Replace with your real Sandbox Merchant Secret from the PayHere Dashboard
// CRITICAL: MUST REMAIN SERVER-SIDE ONLY. NEVER EXPOSE IN FRONTEND CODE OR JSON.
if (!defined('PAYHERE_MERCHANT_SECRET')) {
    define('PAYHERE_MERCHANT_SECRET', getenv('PAYHERE_MERCHANT_SECRET') ?: 'YOUR_PAYHERE_MERCHANT_SECRET_HERE');
}

// Integration mode: true for PayHere Sandbox testing, false for Live production
if (!defined('PAYHERE_SANDBOX_MODE')) {
    define('PAYHERE_SANDBOX_MODE', filter_var(getenv('PAYHERE_SANDBOX_MODE') ?: 'true', FILTER_VALIDATE_BOOLEAN));
}

// Store currency code
if (!defined('PAYHERE_CURRENCY')) {
    define('PAYHERE_CURRENCY', 'LKR');
}

/**
 * Generate PayHere checkout checksum hash.
 * Official Formula:
 *   hash = strtoupper(md5(merchant_id + order_id + amountFormatted + currency + strtoupper(md5(merchant_secret))))
 *
 * @param string $merchantId
 * @param string $orderId
 * @param float  $amount
 * @param string $currency
 * @param string $merchantSecret
 * @return string
 */
function payhere_generate_hash(string $merchantId, string $orderId, float $amount, string $currency, string $merchantSecret): string {
    $formattedAmount = number_format($amount, 2, '.', '');
    $hashedSecret = strtoupper(md5($merchantSecret));
    return strtoupper(md5($merchantId . $orderId . $formattedAmount . $currency . $hashedSecret));
}

/**
 * Verify PayHere server-to-server notification signature (md5sig).
 * Official Formula:
 *   local_md5sig = strtoupper(md5(merchant_id + order_id + payhere_amount + payhere_currency + status_code + strtoupper(md5(merchant_secret))))
 *
 * @param string $merchantId
 * @param string $orderId
 * @param string $payhereAmount
 * @param string $payhereCurrency
 * @param string $statusCode
 * @param string $receivedSignature
 * @param string $merchantSecret
 * @return bool
 */
function payhere_verify_signature(string $merchantId, string $orderId, string $payhereAmount, string $payhereCurrency, string $statusCode, string $receivedSignature, string $merchantSecret): bool {
    $hashedSecret = strtoupper(md5($merchantSecret));
    $expectedSignature = strtoupper(md5($merchantId . $orderId . $payhereAmount . $payhereCurrency . $statusCode . $hashedSecret));
    return hash_equals($expectedSignature, strtoupper(trim($receivedSignature)));
}
