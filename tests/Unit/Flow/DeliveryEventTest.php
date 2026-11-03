<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Flow;

use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryCancelledEvent;
use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryDeliveredEvent;
use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryEvent;
use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryFailedEvent;
use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryPickedUpEvent;
use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryRequestedEvent;
use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryRiderAssignedEvent;
use Kommandhub\ChowdeckRelaySW\Flow\FlowEventSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\BusinessEventCollector;
use Shopware\Core\Framework\Event\BusinessEventCollectorEvent;
use Shopware\Core\Framework\Event\BusinessEventCollectorResponse;
use Shopware\Core\Framework\Event\BusinessEventDefinition;

#[CoversClass(DeliveryEvent::class)]
#[CoversClass(DeliveryRequestedEvent::class)]
#[CoversClass(DeliveryFailedEvent::class)]
#[CoversClass(DeliveryRiderAssignedEvent::class)]
#[CoversClass(DeliveryPickedUpEvent::class)]
#[CoversClass(DeliveryDeliveredEvent::class)]
#[CoversClass(DeliveryCancelledEvent::class)]
#[CoversClass(FlowEventSubscriber::class)]
class DeliveryEventTest extends TestCase
{
    public function testEventsExposeTheOrderTheCustomerAndTheDeliveryValues(): void
    {
        $customer = new OrderCustomerEntity();
        $customer->setEmail('ada@example.com');
        $customer->setFirstName('Ada');
        $customer->setLastName('Test');
        $order = new OrderEntity();
        $order->setId('order-1');
        $order->setSalesChannelId('sc-1');
        $order->setOrderCustomer($customer);
        $context = Context::createDefaultContext();

        $requested = new DeliveryRequestedEvent($order, $context, ['trackingUrl' => 'https://t/1', 'deliveryPin' => '4821', 'status' => 'preparing']);

        static::assertSame('kmh_chowdeck.delivery.requested', $requested->getName());
        static::assertSame('kmh_chowdeck.delivery.failed', (new DeliveryFailedEvent($order, $context, []))->getName());
        static::assertSame('kmh_chowdeck.delivery.rider_assigned', (new DeliveryRiderAssignedEvent($order, $context, []))->getName());
        static::assertSame('kmh_chowdeck.delivery.picked_up', (new DeliveryPickedUpEvent($order, $context, []))->getName());
        static::assertSame('kmh_chowdeck.delivery.delivered', (new DeliveryDeliveredEvent($order, $context, []))->getName());
        static::assertSame('kmh_chowdeck.delivery.cancelled', (new DeliveryCancelledEvent($order, $context, []))->getName());
        static::assertSame($order, $requested->getOrder());
        static::assertSame('order-1', $requested->getOrderId());
        static::assertSame('sc-1', $requested->getSalesChannelId());
        static::assertSame($context, $requested->getContext());
        static::assertSame(['ada@example.com' => 'Ada Test'], $requested->getMailStruct()->getRecipients());
        static::assertSame(
            ['trackingUrl' => 'https://t/1', 'deliveryPin' => '4821', 'status' => 'preparing', 'riderName' => '', 'riderPhone' => '', 'errorMessage' => ''],
            $requested->getValues(),
        );
        static::assertSame(
            ['order', 'trackingUrl', 'deliveryPin', 'riderName', 'riderPhone', 'status', 'errorMessage'],
            array_keys(DeliveryEvent::getAvailableData()->toArray()),
        );
    }

    public function testEventsAreOfferedToFlowBuilder(): void
    {
        $collector = $this->createMock(BusinessEventCollector::class);
        $collector->method('define')->willReturnCallback(static fn (string $class): ?BusinessEventDefinition => $class === DeliveryRequestedEvent::class
            ? new BusinessEventDefinition('kmh_chowdeck.delivery.requested', $class, [])
            : null);
        $event = new BusinessEventCollectorEvent(new BusinessEventCollectorResponse(), Context::createDefaultContext());

        static::assertArrayHasKey(BusinessEventCollectorEvent::NAME, FlowEventSubscriber::getSubscribedEvents());
        (new FlowEventSubscriber($collector))->onCollect($event);

        static::assertSame(['kmh_chowdeck.delivery.requested'], array_keys($event->getCollection()->getElements()));
    }
}
