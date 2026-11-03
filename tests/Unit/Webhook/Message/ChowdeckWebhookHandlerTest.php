<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Webhook\Message;

use Doctrine\DBAL\Connection;
use Kommandhub\ChowdeckRelaySW\Client\Dto\ArrayReader;
use Kommandhub\ChowdeckRelaySW\Client\Dto\Delivery;
use Kommandhub\ChowdeckRelaySW\Client\Dto\WalletBalance;
use Kommandhub\ChowdeckRelaySW\Delivery\Service\DeliveryService;
use Kommandhub\ChowdeckRelaySW\Webhook\Message\ChowdeckWebhookHandler;
use Kommandhub\ChowdeckRelaySW\Webhook\Message\ChowdeckWebhookMessage;
use Kommandhub\ChowdeckRelaySW\Webhook\Service\WebhookReceiver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

#[CoversClass(ChowdeckWebhookHandler::class)]
#[UsesClass(ChowdeckWebhookMessage::class)]
#[UsesClass(WebhookReceiver::class)]
#[UsesClass(Delivery::class)]
#[UsesClass(ArrayReader::class)]
#[UsesClass(WalletBalance::class)]
class ChowdeckWebhookHandlerTest extends TestCase
{
    private const EVENT_ID = '0190aaaa0000700080000000000000e1';

    private ?string $note = 'unset';

    public function testAppliesThePayloadToTheDelivery(): void
    {
        $service = $this->createMock(DeliveryService::class);
        $service->expects(static::once())->method('applyWebhook')
            ->willReturnCallback(static function (Delivery $update): bool {
                static::assertSame('kmh-1', $update->reference);
                static::assertSame('picked', $update->status);
                static::assertSame(['name' => 'Tunde'], $update->rider);

                return true;
            });

        $this->handle($service, ['category' => 'ORDER_PICKED_UP', 'payload' => ['reference' => 'kmh-1', 'status' => 'picked', 'driver' => ['name' => 'Tunde']]]);

        static::assertNull($this->note);
    }

    public function testUnknownReferenceAndMissingReferenceAreNoted(): void
    {
        $service = $this->createMock(DeliveryService::class);
        $service->expects(static::once())->method('applyWebhook')->willReturn(false);

        $this->handle($service, ['category' => 'ORDER_COMPLETE', 'payload' => ['reference' => 'other', 'status' => 'completed']]);
        static::assertSame('No delivery with this reference.', $this->note);

        $this->handle($service, ['category' => 'ORDER_COMPLETE', 'payload' => []]);
        static::assertSame('Webhook without a delivery reference.', $this->note);

        $this->handle($service, 'not json');
        static::assertSame('Webhook without a delivery reference.', $this->note);
    }

    public function testAFailureIsLoggedAndNotedNotRetried(): void
    {
        $service = $this->createMock(DeliveryService::class);
        $service->method('applyWebhook')->willThrowException(new \RuntimeException('database gone'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())->method('error');

        $this->handle($service, ['payload' => ['reference' => 'kmh-1', 'status' => 'picked']], $logger);

        static::assertSame('Failed: database gone', $this->note);
    }

    public function testAlreadyProcessedEventsAreSkipped(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $connection->expects(static::never())->method('executeStatement');
        $service = $this->createMock(DeliveryService::class);
        $service->expects(static::never())->method('applyWebhook');

        (new ChowdeckWebhookHandler($connection, $service, new NullLogger()))(new ChowdeckWebhookMessage(self::EVENT_ID));
    }

    /**
     * @param array<mixed>|string $body
     */
    private function handle(DeliveryService $service, array|string $body, ?LoggerInterface $logger = null): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(\is_string($body) ? $body : (string)json_encode($body));
        $connection->expects(static::once())->method('executeStatement')->willReturnCallback(function (string $sql, array $params): int {
            static::assertStringContainsString('UPDATE `kmh_chowdeck_webhook_event`', $sql);
            $this->note = $params['note'];

            return 1;
        });

        (new ChowdeckWebhookHandler($connection, $service, $logger ?? new NullLogger()))(new ChowdeckWebhookMessage(self::EVENT_ID));
    }
}
