<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Delivery\Subscriber;

use Kommandhub\ChowdeckRelaySW\Delivery\DeliveryStatus;
use Kommandhub\ChowdeckRelaySW\Delivery\Subscriber\DeliveryQuoteSubscriber;
use Kommandhub\ChowdeckRelaySW\Quote\QuoteStruct;
use Kommandhub\ChowdeckRelaySW\Tests\Unit\Quote\CheckoutFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Shopware\Core\Checkout\Cart\Order\OrderConversionContext;
use Shopware\Core\Test\Generator;

#[CoversClass(DeliveryQuoteSubscriber::class)]
#[UsesClass(QuoteStruct::class)]
#[UsesClass(DeliveryStatus::class)]
class DeliveryQuoteSubscriberTest extends TestCase
{
    public function testSubscribesToCartConversion(): void
    {
        static::assertArrayHasKey(CartConvertedEvent::class, DeliveryQuoteSubscriber::getSubscribedEvents());
    }

    public function testFeeIsWrittenWithTheChargedPrice(): void
    {
        $delivery = $this->convert(new QuoteStruct(39702, 4800.0, 5300.0, 'NGN', new \DateTimeImmutable('2026-11-03 10:00:00')))['deliveries'][0]['kmhChowdeckDelivery'];

        static::assertSame('order-1', $delivery['orderId']);
        static::assertSame(DeliveryStatus::Quoted->value, $delivery['status']);
        static::assertSame(39702, $delivery['feeId']);
        static::assertSame(4800.0, $delivery['quotedAmount']);
        // The cart delivery's own total: what the customer pays.
        static::assertSame(10.0, $delivery['chargedAmount']);
        static::assertSame('NGN', $delivery['currency']);
        static::assertSame('2026-11-03 10:00:00.000', $delivery['quotedAt']);
        static::assertIsString($delivery['salesChannelId']);
    }

    public function testFallbackOrdersNeedAQuote(): void
    {
        $delivery = $this->convert(QuoteStruct::fallback(2500.0, 'NGN', new \DateTimeImmutable()))['deliveries'][0]['kmhChowdeckDelivery'];

        static::assertSame(DeliveryStatus::NeedsQuote->value, $delivery['status']);
        static::assertNull($delivery['feeId']);
        static::assertNull($delivery['quotedAmount']);
    }

    public function testOtherDeliveriesAndPayloadsAreLeftAlone(): void
    {
        $cart = Generator::createCartWithDelivery();
        $converted = ['id' => 'order-1', 'deliveries' => [['id' => 'd1']]];
        static::assertSame($converted, $this->dispatch($cart, $converted));

        static::assertSame(['deliveries' => []], $this->dispatch($cart, ['deliveries' => []]));
        static::assertSame(['id' => 'order-1'], $this->dispatch($cart, ['id' => 'order-1']));
    }

    /**
     * @return array<string, mixed>
     */
    private function convert(QuoteStruct $quote): array
    {
        $cart = Generator::createCartWithDelivery();
        $cart->getDeliveries()->first()?->addExtension(QuoteStruct::EXTENSION_KEY, $quote);

        return $this->dispatch($cart, ['id' => 'order-1', 'deliveries' => [['id' => 'd1']]]);
    }

    /**
     * @param array<string, mixed> $converted
     *
     * @return array<string, mixed>
     */
    private function dispatch(Cart $cart, array $converted): array
    {
        $event = new CartConvertedEvent($cart, $converted, CheckoutFixture::context(), new OrderConversionContext());
        (new DeliveryQuoteSubscriber())->onCartConverted($event);

        return $event->getConvertedCart();
    }
}
