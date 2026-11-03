<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Webhook\Service;

use Doctrine\DBAL\Connection;
use Kommandhub\ChowdeckRelaySW\Setting\Service\Config;
use Kommandhub\ChowdeckRelaySW\Tests\Unit\Client\ClientFactory;
use Kommandhub\ChowdeckRelaySW\Webhook\Message\ChowdeckWebhookMessage;
use Kommandhub\ChowdeckRelaySW\Webhook\Service\WebhookReceiver;
use Kommandhub\ChowdeckRelaySW\Webhook\Service\WebhookSignatureValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(WebhookReceiver::class)]
#[CoversClass(ChowdeckWebhookMessage::class)]
#[UsesClass(WebhookSignatureValidator::class)]
#[UsesClass(Config::class)]
class WebhookReceiverTest extends TestCase
{
    private const SECRET = 'whsec_test';

    public function testStoresAndQueuesAValidWebhookOnce(): void
    {
        $body = '{"category":"ORDER_PICKED_UP","description":"A rider has picked up the order","payload":{"reference":"kmh-1","status":"picked"}}';
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::exactly(2))->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params) use ($body): int {
                static::assertStringContainsString('INSERT IGNORE', $sql);
                static::assertSame(hash('sha256', $body), $params['key']);
                static::assertSame('ORDER_PICKED_UP', $params['event']);
                static::assertSame('kmh-1', $params['reference']);

                static $calls = 0;

                return ++$calls === 1 ? 1 : 0;
            });
        $queued = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(static::once())->method('dispatch')->willReturnCallback(static function (object $m) use (&$queued): Envelope {
            $queued[] = $m;

            return new Envelope($m);
        });
        $receiver = $this->receiver($connection, $bus);

        static::assertSame(WebhookReceiver::ACCEPTED, $receiver->receive($body, hash_hmac('sha256', $body, self::SECRET), 'sc-1'));
        static::assertSame(WebhookReceiver::DUPLICATE, $receiver->receive($body, hash_hmac('sha256', $body, self::SECRET), 'sc-1'));
        static::assertInstanceOf(ChowdeckWebhookMessage::class, $queued[0]);
        static::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $queued[0]->eventId);
    }

    public function testInvalidSignatureStoresNothing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::never())->method('executeStatement');

        static::assertSame(WebhookReceiver::INVALID_SIGNATURE, $this->receiver($connection)->receive('{}', 'nope', null));
    }

    public function testSignedButUnreadableBodyIsRejected(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::never())->method('executeStatement');

        static::assertSame(WebhookReceiver::INVALID_PAYLOAD, $this->receiver($connection)->receive('not json', hash_hmac('sha256', 'not json', self::SECRET), null));
    }

    public function testBodyWithoutCategoryOrReferenceIsStoredAsUnknown(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::once())->method('executeStatement')->willReturnCallback(static function (string $sql, array $params): int {
            static::assertSame('unknown', $params['event']);
            static::assertNull($params['reference']);

            return 1;
        });
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));
        $body = '{"payload":{"reference":""}}';

        static::assertSame(WebhookReceiver::ACCEPTED, $this->receiver($connection, $bus)->receive($body, hash_hmac('sha256', $body, self::SECRET), null));
    }

    public function testLastReceivedAt(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls('2026-11-03 12:00:00.000', null);
        $receiver = $this->receiver($connection);

        static::assertSame('2026-11-03T12:00:00+00:00', $receiver->lastReceivedAt());
        static::assertNull($receiver->lastReceivedAt());
    }

    private function receiver(Connection $connection, ?MessageBusInterface $bus = null): WebhookReceiver
    {
        return new WebhookReceiver(
            new WebhookSignatureValidator(ClientFactory::config(['webhookSecret' => self::SECRET])),
            $connection,
            $bus ?? $this->createMock(MessageBusInterface::class),
        );
    }
}
