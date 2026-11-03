<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Delivery;

use Kommandhub\ChowdeckRelaySW\Delivery\DeliveryException;
use Kommandhub\ChowdeckRelaySW\Delivery\DeliveryStatus;
use Kommandhub\ChowdeckRelaySW\Delivery\Service\RequestResult;
use Kommandhub\ChowdeckRelaySW\Quote\QuoteError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeliveryStatus::class)]
#[CoversClass(DeliveryException::class)]
#[CoversClass(RequestResult::class)]
#[UsesClass(QuoteError::class)]
class DeliveryStatusTest extends TestCase
{
    public function testBothChowdeckVocabulariesMap(): void
    {
        $map = [
            'preparing' => DeliveryStatus::Preparing, 'received' => DeliveryStatus::Preparing,
            'awaiting_pickup' => DeliveryStatus::AwaitingPickup,
            'in_transit' => DeliveryStatus::PickedUp, 'picked' => DeliveryStatus::PickedUp, 'picked_up' => DeliveryStatus::PickedUp,
            'arrived' => DeliveryStatus::Arrived,
            'success' => DeliveryStatus::Completed, 'Completed ' => DeliveryStatus::Completed, 'delivered' => DeliveryStatus::Completed,
            'rejected' => DeliveryStatus::Cancelled, 'cancelled' => DeliveryStatus::Cancelled, 'canceled' => DeliveryStatus::Cancelled,
        ];

        foreach ($map as $chowdeck => $expected) {
            static::assertSame($expected, DeliveryStatus::fromChowdeck($chowdeck), $chowdeck);
        }

        static::assertNull(DeliveryStatus::fromChowdeck('teleported'));
    }

    public function testGroups(): void
    {
        $requestable = array_filter(DeliveryStatus::cases(), static fn (DeliveryStatus $s): bool => $s->isRequestable());
        $cancellable = array_filter(DeliveryStatus::cases(), static fn (DeliveryStatus $s): bool => $s->isCancellable());
        $open = array_filter(DeliveryStatus::cases(), static fn (DeliveryStatus $s): bool => $s->isOpen());

        static::assertSame([DeliveryStatus::Quoted, DeliveryStatus::NeedsQuote], array_values($requestable));
        static::assertSame([DeliveryStatus::Preparing, DeliveryStatus::AwaitingPickup], array_values($cancellable));
        static::assertSame([DeliveryStatus::Preparing, DeliveryStatus::AwaitingPickup, DeliveryStatus::PickedUp, DeliveryStatus::Arrived], array_values($open));
    }

    public function testExceptionsCarryAReasonAndAShowableMessage(): void
    {
        static::assertSame('not_found', DeliveryException::notFound()->reason);
        static::assertStringContainsString('"preparing"', DeliveryException::notRequestable('preparing')->getMessage());
        static::assertSame('not_requested', DeliveryException::notRequested()->reason);
        static::assertStringContainsString('"completed"', DeliveryException::notCancellable('completed')->getMessage());
        static::assertSame('pickup_contact_missing', DeliveryException::pickupContactMissing()->reason);
        static::assertSame('quote_invalid_address', DeliveryException::quoteFailed(QuoteError::InvalidAddress)->reason);
    }

    public function testRequestResults(): void
    {
        static::assertSame(['requested' => true, 'oldPrice' => null, 'newPrice' => null, 'currency' => null], RequestResult::requested()->toArray());
        static::assertSame(
            ['requested' => false, 'oldPrice' => 3900.0, 'newPrice' => 4200.0, 'currency' => 'NGN'],
            RequestResult::confirmationNeeded(3900.0, 4200.0, 'NGN')->toArray(),
        );
    }
}
