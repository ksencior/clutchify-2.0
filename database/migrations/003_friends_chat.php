<?php

return function (PDO $pdo, array $db): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `friendships` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `requester_id` BIGINT UNSIGNED NOT NULL,
            `addressee_id` BIGINT UNSIGNED NOT NULL,
            `status` ENUM('pending','accepted','rejected','blocked') NOT NULL DEFAULT 'pending',
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            KEY `idx_friendships_requester` (`requester_id`),
            KEY `idx_friendships_addressee` (`addressee_id`),
            KEY `idx_friendships_status` (`status`)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `private_messages` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `sender_id` BIGINT UNSIGNED NOT NULL,
            `receiver_id` BIGINT UNSIGNED NOT NULL,
            `body` TEXT NOT NULL,
            `is_read` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            KEY `idx_private_messages_pair` (`sender_id`, `receiver_id`, `created_at`),
            KEY `idx_private_messages_unread` (`receiver_id`, `is_read`, `created_at`)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");
};