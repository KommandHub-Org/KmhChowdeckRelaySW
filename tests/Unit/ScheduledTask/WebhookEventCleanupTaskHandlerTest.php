<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\ScheduledTask;

use Doctrine\DBAL\Connection;
use Kommandhub\ChowdeckRelaySW\ScheduledTask\WebhookEventCleanupTask;
use Kommandhub\ChowdeckRelaySW\ScheduledTask\WebhookEventCleanupTaskHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\Clock\MockClock;

#[CoversClass(WebhookEventCleanupTaskHandler::class)]
#[CoversClass(WebhookEventCleanupTask::class)]
class WebhookEventCleanupTaskHandlerTest extends TestCase
{
    public function testDeletesEventsOlderThanRetention(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::once())
            ->method('executeStatement')
            ->with(static::stringContains('DELETE FROM `kmh_chowdeck_webhook_event`'), ['cutoff' => '2026-08-05 12:00:00.000'])
            ->willReturn(3);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())->method('info')->with(static::anything(), ['deleted' => 3]);

        $this->handler($connection, $logger)->run();
    }

    public function testFailureIsLoggedNotThrown(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willThrowException(new \RuntimeException('db gone'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())->method('error')->with(static::anything(), ['exception' => 'db gone']);

        $this->handler($connection, $logger)->run();
    }

    public function testTaskDefinition(): void
    {
        static::assertSame('kmh_chowdeck.webhook_event_cleanup', WebhookEventCleanupTask::getTaskName());
        static::assertSame(86400, WebhookEventCleanupTask::getDefaultInterval());
    }

    private function handler(Connection $connection, LoggerInterface $logger): WebhookEventCleanupTaskHandler
    {
        return new WebhookEventCleanupTaskHandler(
            $this->createStub(EntityRepository::class),
            $connection,
            new MockClock('2026-11-03 12:00:00'),
            $logger,
        );
    }
}
