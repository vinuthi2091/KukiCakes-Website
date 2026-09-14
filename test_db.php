<?php
/**
 * KúkiCakes — Database Connection Test
 *
 * Temporary diagnostic page. Verifies that the PDO connection
 * to kukicakes_db is working correctly.
 *
 * REMOVE or RESTRICT ACCESS to this file before going live.
 */

require_once __DIR__ . '/config/db.php';

// ---------------------------------------------------------------
// If we reach this point, the connection succeeded.
// Run a lightweight query to confirm the database is reachable.
// ---------------------------------------------------------------
try {
    $stmt = $pdo->query('SELECT DATABASE() AS db_name, VERSION() AS db_version');
    $info = $stmt->fetch();
} catch (PDOException $e) {
    $info = null;
    $queryError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KúkiCakes — DB Connection Test</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #fff9f5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 4px 32px rgba(0,0,0,0.08);
            padding: 2.5rem 3rem;
            max-width: 520px;
            width: 100%;
            text-align: center;
        }

        .logo {
            font-size: 2rem;
            margin-bottom: 0.25rem;
        }

        h1 {
            font-size: 1.15rem;
            color: #6b3a5e;
            font-weight: 600;
            margin-bottom: 1.75rem;
            letter-spacing: 0.02em;
        }

        .status {
            border-radius: 10px;
            padding: 1.25rem 1.5rem;
            font-size: 1.05rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
        }

        .status.success {
            background: #f0fdf4;
            border: 2px solid #22c55e;
            color: #15803d;
        }

        .status.error {
            background: #fef2f2;
            border: 2px solid #ef4444;
            color: #b91c1c;
        }

        .icon {
            font-size: 2rem;
            display: block;
            margin-bottom: 0.4rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
            text-align: left;
            margin-top: 0.5rem;
        }

        th, td {
            padding: 0.55rem 0.75rem;
            border-bottom: 1px solid #f0e8e8;
        }

        th {
            color: #9c6b8a;
            font-weight: 600;
            width: 45%;
        }

        td {
            color: #3d1a2e;
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
        }

        .detail-box {
            background: #fdf8fb;
            border-radius: 10px;
            padding: 1rem;
            margin-top: 1.25rem;
            text-align: left;
        }

        .detail-box h2 {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #9c6b8a;
            margin-bottom: 0.75rem;
        }

        .warning {
            margin-top: 1.5rem;
            font-size: 0.78rem;
            color: #b45309;
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            padding: 0.65rem 1rem;
        }

        .error-detail {
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
            color: #b91c1c;
            margin-top: 0.75rem;
            background: #fff1f1;
            border-radius: 6px;
            padding: 0.75rem;
            text-align: left;
            word-break: break-word;
        }
    </style>
</head>
<body>
<div class="card">
    <div class="logo">🎂</div>
    <h1>KúkiCakes — Database Connection Test</h1>

    <?php if (isset($info) && $info): ?>

        <div class="status success">
            <span class="icon">✅</span>
            Database connection successful!
        </div>

        <div class="detail-box">
            <h2>Connection Details</h2>
            <table>
                <tr>
                    <th>Host</th>
                    <td><?= htmlspecialchars(DB_HOST) ?>:<?= htmlspecialchars(DB_PORT) ?></td>
                </tr>
                <tr>
                    <th>Database</th>
                    <td><?= htmlspecialchars($info['db_name']) ?></td>
                </tr>
                <tr>
                    <th>MySQL Version</th>
                    <td><?= htmlspecialchars($info['db_version']) ?></td>
                </tr>
                <tr>
                    <th>PHP Version</th>
                    <td><?= htmlspecialchars(PHP_VERSION) ?></td>
                </tr>
                <tr>
                    <th>Charset</th>
                    <td><?= htmlspecialchars(DB_CHARSET) ?></td>
                </tr>
                <tr>
                    <th>PDO Driver</th>
                    <td>mysql</td>
                </tr>
            </table>
        </div>

    <?php else: ?>

        <div class="status error">
            <span class="icon">❌</span>
            Database connection failed.
        </div>

        <?php if (isset($queryError)): ?>
            <p style="color:#6b3a5e; font-size:0.9rem;">
                The PDO connection was established but the test query failed.
            </p>
            <div class="error-detail"><?= htmlspecialchars($queryError) ?></div>
        <?php endif; ?>

        <div class="detail-box">
            <h2>Check the Following</h2>
            <table>
                <tr><th>XAMPP MySQL</th><td>Is it running in the XAMPP Control Panel?</td></tr>
                <tr><th>Port</th><td>Is MySQL on port <?= htmlspecialchars(DB_PORT) ?>?</td></tr>
                <tr><th>Database</th><td>Does <code>kukicakes_db</code> exist in phpMyAdmin?</td></tr>
                <tr><th>Username</th><td><?= htmlspecialchars(DB_USER) ?></td></tr>
            </table>
        </div>

    <?php endif; ?>

    <div class="warning">
        ⚠️ <strong>Remove or restrict access to this file before going live.</strong>
        It is for local development use only.
    </div>
</div>
</body>
</html>
