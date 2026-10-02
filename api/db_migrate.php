<?php
/**
 * KúkiCakes — Database Migration for Authentication (Week 6)
 *
 * Adds authentication columns to the existing `customer` table
 * and creates `user_addresses` table for shipping/billing addresses.
 *
 * Safe to run multiple times — uses IF NOT EXISTS / ALTER IF NOT EXISTS.
 * Does NOT delete any existing data or tables.
 */
require_once __DIR__ . '/../config/db.php';

$results = [];

// ---------------------------------------------------------------
// 1. Add authentication columns to the existing `customer` table
//    The customer table already has: customer_id, full_name, email,
//    phone_no, created_at
// ---------------------------------------------------------------

$authColumns = [
    'password_hash'  => "ALTER TABLE `customer` ADD COLUMN `password_hash` VARCHAR(255) DEFAULT NULL AFTER `phone_no`",
    'role'           => "ALTER TABLE `customer` ADD COLUMN `role` ENUM('customer','admin') NOT NULL DEFAULT 'customer' AFTER `password_hash`",
    'account_status' => "ALTER TABLE `customer` ADD COLUMN `account_status` ENUM('active','suspended') NOT NULL DEFAULT 'active' AFTER `role`",
];

foreach ($authColumns as $col => $sql) {
    // Check if column already exists
    $check = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer' AND COLUMN_NAME = ?");
    $check->execute([$col]);
    if ($check->fetchColumn() == 0) {
        $pdo->exec($sql);
        $results[] = "✓ Added column `customer`.`{$col}`";
    } else {
        $results[] = "→ Column `customer`.`{$col}` already exists, skipped.";
    }
}

// ---------------------------------------------------------------
// 2. Create `user_addresses` table if it doesn't exist
// ---------------------------------------------------------------
$createAddresses = "
CREATE TABLE IF NOT EXISTS `user_addresses` (
    `address_id`   INT(11) NOT NULL AUTO_INCREMENT,
    `customer_id`  INT(11) NOT NULL,
    `addr_type`    ENUM('shipping','billing') NOT NULL DEFAULT 'shipping',
    `full_name`    VARCHAR(100) NOT NULL,
    `line1`        VARCHAR(200) NOT NULL,
    `line2`        VARCHAR(200) DEFAULT NULL,
    `city`         VARCHAR(100) NOT NULL,
    `postal`       VARCHAR(20)  DEFAULT NULL,
    `phone`        VARCHAR(30)  DEFAULT NULL,
    `is_default`   TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`address_id`),
    CONSTRAINT `fk_addr_customer` FOREIGN KEY (`customer_id`)
        REFERENCES `customer` (`customer_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
$pdo->exec($createAddresses);
$results[] = "✓ Table `user_addresses` created (or already existed).";

// ---------------------------------------------------------------
// 3. Create `password_resets` table if it doesn't exist
// ---------------------------------------------------------------
$createResets = "
CREATE TABLE IF NOT EXISTS `password_resets` (
    `reset_id`     INT(11) NOT NULL AUTO_INCREMENT,
    `customer_id`  INT(11) NOT NULL,
    `token_hash`   VARCHAR(64) NOT NULL,
    `expires_at`   DATETIME NOT NULL,
    `used_at`      DATETIME DEFAULT NULL,
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`reset_id`),
    INDEX `idx_token_hash` (`token_hash`),
    INDEX `idx_customer_id` (`customer_id`),
    CONSTRAINT `fk_reset_customer` FOREIGN KEY (`customer_id`)
        REFERENCES `customer` (`customer_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
$pdo->exec($createResets);
$results[] = "✓ Table `password_resets` created (or already existed).";

// ---------------------------------------------------------------
// 4. Create `user_cart_items` table if it doesn't exist
// ---------------------------------------------------------------
$createCart = "
CREATE TABLE IF NOT EXISTS `user_cart_items` (
    `cart_item_id` INT(11) NOT NULL AUTO_INCREMENT,
    `customer_id`  INT(11) NOT NULL,
    `product_id`   INT(11) NOT NULL,
    `weight`       VARCHAR(50) NOT NULL DEFAULT '500g',
    `flavor`       VARCHAR(100) NOT NULL DEFAULT '',
    `note`         TEXT DEFAULT NULL,
    `quantity`     INT(11) NOT NULL DEFAULT 1,
    `item_data`    TEXT DEFAULT NULL,
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`cart_item_id`),
    INDEX `idx_cart_customer` (`customer_id`),
    CONSTRAINT `fk_cart_customer` FOREIGN KEY (`customer_id`)
        REFERENCES `customer` (`customer_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
$pdo->exec($createCart);

// Check if item_data column exists (for upgrade)
$checkCol = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_cart_items' AND COLUMN_NAME = 'item_data'");
$checkCol->execute();
if ($checkCol->fetchColumn() == 0) {
    $pdo->exec("ALTER TABLE `user_cart_items` ADD COLUMN `item_data` TEXT DEFAULT NULL AFTER `quantity`");
}
$results[] = "✓ Table `user_cart_items` created (or already existed).";

// ---------------------------------------------------------------
// 5. Done
// ---------------------------------------------------------------
echo "KúkiCakes — Auth DB Migration\n";
echo str_repeat('=', 40) . "\n";
foreach ($results as $r) {
    echo $r . "\n";
}
echo "\nMigration complete.\n";
