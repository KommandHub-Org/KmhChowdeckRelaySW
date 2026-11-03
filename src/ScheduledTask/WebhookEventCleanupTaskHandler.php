<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\ScheduledTask;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs WebhookEventCleanupTask: one bounded DELETE per run, so a long backlog
 * is worked off over several days instead of one long lock.
 */
#[AsMessageHandler(handles: WebhookEventCleanupTask::class)]
class WebhookEventCleanupTaskHandler extends ScheduledTaskHandler
{
    public const RETENTION_DAYS = 90;

    private const BATCH_SIZE = 10000;

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        // The parent only logs task exceptions; reuse the plugin logger for that.
        parent::__construct($scheduledTaskRepository, $logger);
    }

    public function run(): void
    {
        $cutoff = $this->clock->now()->modify(sprintf('-%d days', self::RETENTION_DAYS));

        try {
            $deleted = $this->connection->executeStatement(
                'DELETE FROM `kmh_chowdeck_webhook_event` WHERE `received_at` < :cutoff LIMIT ' . self::BATCH_SIZE,
                ['cutoff' => $cutoff->format(Defaults::STORAGE_DATE_TIME_FORMAT)],
            );

            $this->logger->info('Removed old Chowdeck webhook events', ['deleted' => $deleted]);
        } catch (\Throwable $exception) {
            $this->logger->error('Chowdeck webhook event cleanup failed', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
