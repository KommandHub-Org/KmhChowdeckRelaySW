<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Creates `kmh_chowdeck_delivery` (one per order delivery, keyed to the
 * versioned `order_delivery`, cascade-deleted with it) and
 * `kmh_chowdeck_webhook_event` (received webhooks, unique per body hash).
 * Spec §13.4.
 */
class Migration1793760000CreateDeliveryTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1793760000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `kmh_chowdeck_delivery` (
                `id`                        BINARY(16)    NOT NULL,
                `order_id`                  BINARY(16)    NOT NULL,
                `order_version_id`          BINARY(16)    NOT NULL,
                `order_delivery_id`         BINARY(16)    NOT NULL,
                `order_delivery_version_id` BINARY(16)    NOT NULL,
                `sales_channel_id`          BINARY(16)    NOT NULL,
                `status`                    VARCHAR(24)   NOT NULL,
                `fee_id`                    INT           NULL,
                `quoted_at`                 DATETIME(3)   NULL,
                `quoted_amount`             DOUBLE        NULL,
                `charged_amount`            DOUBLE        NULL,
                `currency`                  CHAR(3)       NULL,
                `reference`                 VARCHAR(64)   NULL,
                `chowdeck_id`               INT           NULL,
                `tracking_url`              VARCHAR(1024) NULL,
                `delivery_pin`              SMALLINT      NULL,
                `rider`                     JSON          NULL,
                `refund_amount`             DOUBLE        NULL,
                `error_code`                VARCHAR(64)   NULL,
                `error_message`             VARCHAR(1024) NULL,
                `requested_at`              DATETIME(3)   NULL,
                `delivered_at`              DATETIME(3)   NULL,
                `cancelled_at`              DATETIME(3)   NULL,
                `created_at`                DATETIME(3)   NOT NULL,
                `updated_at`                DATETIME(3)   NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.kmh_cd_delivery.delivery` (`order_delivery_id`, `order_delivery_version_id`),
                UNIQUE KEY `uniq.kmh_cd_delivery.reference` (`reference`),
                KEY `idx.kmh_cd_delivery.status` (`status`),
                CONSTRAINT `fk.kmh_cd_delivery.order` FOREIGN KEY (`order_id`, `order_version_id`)
                    REFERENCES `order` (`id`, `version_id`)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.kmh_cd_delivery.order_delivery` FOREIGN KEY (`order_delivery_id`, `order_delivery_version_id`)
                    REFERENCES `order_delivery` (`id`, `version_id`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
        SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `kmh_chowdeck_webhook_event` (
                `id`              BINARY(16)  NOT NULL,
                `idempotency_key` CHAR(64)    NOT NULL,
                `event`           VARCHAR(64) NOT NULL,
                `reference`       VARCHAR(64) NULL,
                `payload`         JSON        NOT NULL,
                `received_at`     DATETIME(3) NOT NULL,
                `processed_at`    DATETIME(3) NULL,
                `error`           LONGTEXT    NULL,
                `created_at`      DATETIME(3) NOT NULL,
                `updated_at`      DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.kmh_cd_webhook_event.key` (`idempotency_key`),
                KEY `idx.kmh_cd_webhook_event.received_at` (`received_at`)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // Intentionally empty — nothing destructive to do.
    }
}
