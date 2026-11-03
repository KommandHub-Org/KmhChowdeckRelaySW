<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Core;

use Kommandhub\ChowdeckRelaySW\Core\Content\Delivery\DeliveryEntity;
use Kommandhub\ChowdeckRelaySW\Core\Content\WebhookEvent\WebhookEventEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;

#[CoversClass(DeliveryEntity::class)]
#[CoversClass(WebhookEventEntity::class)]
class EntitySettersTest extends TestCase
{
    /**
     * @return iterable<string, array{object, string, mixed}>
     */
    public static function fields(): iterable
    {
        $date = new \DateTimeImmutable('2026-11-03 12:00:00');
        $delivery = [
            'OrderId' => 'o1', 'OrderVersionId' => 'v1', 'OrderDeliveryId' => 'd1', 'OrderDeliveryVersionId' => 'v2',
            'SalesChannelId' => 'sc', 'Status' => 'preparing', 'FeeId' => 18987, 'QuotedAt' => $date, 'QuotedAmount' => 1.5,
            'ChargedAmount' => 2.5, 'Currency' => 'NGN', 'Reference' => 'kmh-1', 'ChowdeckId' => 98765,
            'TrackingUrl' => 'https://t', 'DeliveryPin' => 4821, 'Rider' => ['name' => 'Tunde'], 'RefundAmount' => 800.0,
            'ErrorCode' => 'e', 'ErrorMessage' => 'msg', 'RequestedAt' => $date, 'DeliveredAt' => $date, 'CancelledAt' => $date,
            'Order' => new OrderEntity(), 'OrderDelivery' => new OrderDeliveryEntity(),
        ];

        foreach ($delivery as $field => $value) {
            yield 'delivery ' . $field => [new DeliveryEntity(), $field, $value];
        }

        $event = [
            'IdempotencyKey' => str_repeat('a', 64), 'Event' => 'ORDER_COMPLETE', 'Reference' => 'kmh-1',
            'Payload' => ['reference' => 'kmh-1'], 'ReceivedAt' => $date, 'ProcessedAt' => $date, 'Error' => 'note',
        ];

        foreach ($event as $field => $value) {
            yield 'webhook event ' . $field => [new WebhookEventEntity(), $field, $value];
        }
    }

    #[DataProvider('fields')]
    public function testSetterAndGetterRoundTrip(object $entity, string $field, mixed $value): void
    {
        $entity->{'set' . $field}($value);

        static::assertSame($value, $entity->{'get' . $field}());
    }
}
