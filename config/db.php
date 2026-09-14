<?php
/**
 * KúkiCakes — Database Connection
 *
 * Establishes a PDO connection to the kukicakes_db MySQL database.
 * Returns a $pdo instance ready for use in any PHP file that
 * requires this file.
 *
 * Usage:
 *   require_once __DIR__ . '/../config/db.php';
 *   // $pdo is now available
 */

// ---------------------------------------------------------------
// Database configuration — local XAMPP development settings
// ---------------------------------------------------------------
define('DB_HOST',    '127.0.0.1');
define('DB_PORT',    '3307');
define('DB_NAME',    'kukicakes_db');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_CHARSET', 'utf8mb4');

// ---------------------------------------------------------------
// Build the DSN (Data Source Name)
// ---------------------------------------------------------------
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    DB_HOST,
    DB_PORT,
    DB_NAME,
    DB_CHARSET
);

// ---------------------------------------------------------------
// PDO options
//   - ERRMODE_EXCEPTION  : throw exceptions on DB errors
//   - FETCH_ASSOC        : return rows as associative arrays
//   - EMULATE_PREPARES   : false — use native prepared statements
// ---------------------------------------------------------------
$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// ---------------------------------------------------------------
// Create the PDO connection
// ---------------------------------------------------------------
try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $pdoOptions);
} catch (PDOException $e) {
    // Stop execution and show a safe error message.
    // In production, never expose $e->getMessage() to end users.
    http_response_code(500);
    exit('Database connection failed: ' . $e->getMessage());
}
