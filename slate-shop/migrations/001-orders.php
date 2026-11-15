<?php
/**
 * Orders table (MySQL/MariaDB and SQLite).
 */
declare(strict_types=1);

use Slate\DB;

return static function (): void {
    $sqlite = DB::$driver === 'sqlite';
    $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    $text = $sqlite ? 'TEXT' : 'LONGTEXT';
    $big = $sqlite ? 'INTEGER' : 'BIGINT';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    DB::exec("CREATE TABLE IF NOT EXISTS {{shop_orders}} (
        id $pk,
        order_key VARCHAR(64) NOT NULL UNIQUE,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        customer_name VARCHAR(191) NOT NULL DEFAULT '',
        customer_email VARCHAR(191) NOT NULL DEFAULT '',
        customer_phone VARCHAR(60) NOT NULL DEFAULT '',
        address $text NULL,
        items $text NULL,
        subtotal_minor $big NOT NULL DEFAULT 0,
        shipping_minor $big NOT NULL DEFAULT 0,
        total_minor $big NOT NULL DEFAULT 0,
        currency VARCHAR(3) NOT NULL DEFAULT 'USD',
        provider VARCHAR(20) NOT NULL DEFAULT 'manual',
        provider_ref VARCHAR(191) NOT NULL DEFAULT '',
        payment_ref VARCHAR(191) NOT NULL DEFAULT '',
        meta $text NULL,
        notes TEXT NULL,
        admin_notes TEXT NULL,
        history $text NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        paid_at DATETIME NULL
    )$tail");

    $indexes = [
        'idx_shop_orders_status' => '(status, created_at)',
        'idx_shop_orders_email' => '(customer_email)',
        'idx_shop_orders_ref' => '(provider, provider_ref)',
        'idx_shop_orders_created' => '(created_at)',
    ];
    foreach ($indexes as $name => $cols) {
        try {
            DB::exec('CREATE INDEX ' . ($sqlite ? 'IF NOT EXISTS ' : '') . "$name ON {{shop_orders}} $cols");
        } catch (\PDOException) {
            // MySQL has no IF NOT EXISTS for indexes: it already exists.
        }
    }
};
