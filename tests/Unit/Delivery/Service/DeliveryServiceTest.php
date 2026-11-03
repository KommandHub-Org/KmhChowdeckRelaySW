<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Delivery\Service;

use Doctrine\DBAL\Connection;
use Kommandhub\ChowdeckRelaySW\Client\ApiErrorReason;
use Kommandhub\ChowdeckRelaySW\Client\ChowdeckClient;
use Kommandhub\ChowdeckRelaySW\Client\Dto\ArrayReader;
use Kommandhub\ChowdeckRelaySW\Client\Dto\CancelledDelivery;
use Kommandhub\ChowdeckRelaySW\Client\Dto\Contact;
use Kommandhub\ChowdeckRelaySW\Client\Dto\CreateDeliveryRequest;
use Kommandhub\ChowdeckRelaySW\Client\Dto\Delivery;
use Kommandhub\ChowdeckRelaySW\Client\Dto\DeliveryFee;
use Kommandhub\ChowdeckRelaySW\Client\Dto\WalletBalance;
use Kommandhub\ChowdeckRelaySW\Client\Http\ChowdeckHttpClient;
use Kommandhub\ChowdeckRelaySW\Client\Resource\ApiResource;
use Kommandhub\ChowdeckRelaySW\Client\Resource\Deliveries;
use Kommandhub\ChowdeckRelaySW\Core\Content\Delivery\DeliveryCollection;
use Kommandhub\ChowdeckRelaySW\Core\Content\Delivery\DeliveryEntity;
use Kommandhub\ChowdeckRelaySW\Delivery\DeliveryException;
use Kommandhub\ChowdeckRelaySW\Delivery\DeliveryStatus;
use Kommandhub\ChowdeckRelaySW\Delivery\Service\DeliveryService;
use Kommandhub\ChowdeckRelaySW\Delivery\Service\DeliveryStateUpdater;
use Kommandhub\ChowdeckRelaySW\Delivery\Service\RequestResult;
use Kommandhub\ChowdeckRelaySW\Exception\ChowdeckApiException;
use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryCancelledEvent;
use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryDeliveredEvent;
use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryEvent;
use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryFailedEvent;
use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryPickedUpEvent;
use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryRequestedEvent;
use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryRiderAssignedEvent;
use Kommandhub\ChowdeckRelaySW\Quote\QuoteError;
use Kommandhub\ChowdeckRelaySW\Quote\QuoteService;
use Kommandhub\ChowdeckRelaySW\Setting\Service\Config;
use Kommandhub\ChowdeckRelaySW\Tests\Unit\Client\ClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Event\NestedEventCollection;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(DeliveryService::class)]
#[UsesClass(RequestResult::class)]
#[UsesClass(DeliveryException::class)]
#[UsesClass(DeliveryStatus::class)]
#[UsesClass(Config::class)]
#[UsesClass(ChowdeckClient::class)]
#[UsesClass(ChowdeckHttpClient::class)]
#[UsesClass(Deliveries::class)]
#[UsesClass(ApiResource::class)]
#[UsesClass(Delivery::class)]
#[UsesClass(DeliveryFee::class)]
#[UsesClass(CancelledDelivery::class)]
#[UsesClass(Contact::class)]
#[UsesClass(CreateDeliveryRequest::class)]
#[UsesClass(ArrayReader::class)]
#[UsesClass(WalletBalance::class)]
#[UsesClass(ChowdeckApiException::class)]
#[UsesClass(ApiErrorReason::class)]
#[UsesClass(QuoteError::class)]
#[UsesClass(DeliveryEvent::class)]
#[UsesClass(DeliveryRequestedEvent::class)]
#[UsesClass(DeliveryFailedEvent::class)]
#[UsesClass(DeliveryRiderAssignedEvent::class)]
#[UsesClass(DeliveryPickedUpEvent::class)]
#[UsesClass(DeliveryDeliveredEvent::class)]
#[UsesClass(DeliveryCancelledEvent::class)]
class DeliveryServiceTest extends TestCase
{
    private const DELIVERY_ID = '0190aaaa0000700080000000000000a1';

    private const ORDER_DELIVERY_ID = '0190aaaa0000700080000000000000d1';

    private const CONFIG = [
        'apiKey' => 'sk_test_key',
        'pickupContactName' => 'Kommandhub Store',
        'pickupContactPhone' => '+2348000000001',
        'itemType' => 'others',
        'useDeliveryPin' => false,
        'notificationChannels' => ['sms', 'pigeon', 'whatsapp'],
    ];

    private const CREATED = [
        'id' => 98765,
        'reference' => 'kmh-' . self::DELIVERY_ID,
        'delivery_price' => 390000,
        'delivery_pin' => 4821,
        'tracking_url' => 'https://track.chowdeck.com/x',
        'status' => 'preparing',
    ];

    /**
     * @var list<array<string, mixed>>
     */
    private array $deliveryUpdates = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $orderDeliveryUpdates = [];

    /**
     * @var list<array<mixed>>
     */
    private array $chowdeckBodies = [];

    /**
     * @var list<object>
     */
    private array $events = [];

    private QuoteService&MockObject $quoteService;

    private DeliveryStateUpdater&MockObject $stateUpdater;

    protected function setUp(): void
    {
        $this->quoteService = $this->createMock(QuoteService::class);
        $this->stateUpdater = $this->createMock(DeliveryStateUpdater::class);
    }

    public function testUnchangedFreshPriceRequestsTheRiderAtOnce(): void
    {
        $this->fee(3900.0);

        $result = $this->service($this->delivery(), [ClientFactory::ok(self::CREATED)], ['OLD'])->request(self::DELIVERY_ID, false, Context::createDefaultContext());

        static::assertTrue($result->requested);
        $body = $this->chowdeckBodies[0];
        static::assertSame(39710, $body['fee_id']);
        static::assertSame('kmh-' . self::DELIVERY_ID, $body['reference']);
        static::assertSame('others', $body['item_type']);
        static::assertSame(['name' => 'Kommandhub Store', 'phone' => '+2348000000001', 'country_code' => 'NG'], $body['source_contact']);
        static::assertSame(['name' => 'Ada Test', 'phone' => '+2348031234567', 'email' => 'ada@example.com', 'country_code' => 'NG'], $body['destination_contact']);
        static::assertSame("Order 10036\nRing twice", $body['customer_delivery_note']);
        static::assertSame(['sms', 'whatsapp'], $body['notification_channels']);
        static::assertArrayNotHasKey('delivery_pin', $body);
        $update = $this->deliveryUpdates[0];
        static::assertSame('preparing', $update['status']);
        static::assertSame(98765, $update['chowdeckId']);
        static::assertSame('https://track.chowdeck.com/x', $update['trackingUrl']);
        static::assertSame(4821, $update['deliveryPin']);
        static::assertNull($update['rider']);
        static::assertSame([['id' => self::ORDER_DELIVERY_ID, 'trackingCodes' => ['OLD', 'https://track.chowdeck.com/x']]], $this->orderDeliveryUpdates);
        static::assertInstanceOf(DeliveryRequestedEvent::class, $this->events[0]);
        static::assertSame('https://track.chowdeck.com/x', $this->events[0]->getValues()['trackingUrl']);
    }

    public function testPinIsGeneratedWhenSwitchedOn(): void
    {
        $this->fee(3900.0);
        $created = self::CREATED;
        unset($created['delivery_pin'], $created['reference'], $created['tracking_url']);

        $this->service($this->delivery(), [ClientFactory::ok($created)], [], array_merge(self::CONFIG, ['useDeliveryPin' => true]))->request(self::DELIVERY_ID, false, Context::createDefaultContext());

        $pin = $this->chowdeckBodies[0]['delivery_pin'];
        static::assertGreaterThanOrEqual(1000, $pin);
        static::assertLessThanOrEqual(9999, $pin);
        static::assertSame($pin, $this->deliveryUpdates[0]['deliveryPin']);
        static::assertSame('kmh-' . self::DELIVERY_ID, $this->deliveryUpdates[0]['reference']);
        static::assertSame([], $this->orderDeliveryUpdates);
    }

    public function testChangedPriceIsStoredAndAskedForBeforeAnyRider(): void
    {
        $this->fee(4200.0);

        $result = $this->service($this->delivery(), [])->request(self::DELIVERY_ID, false, Context::createDefaultContext());

        static::assertSame(['requested' => false, 'oldPrice' => 3900.0, 'newPrice' => 4200.0, 'currency' => 'NGN'], $result->toArray());
        static::assertSame('needs_quote', $this->deliveryUpdates[0]['status']);
        static::assertSame(39710, $this->deliveryUpdates[0]['feeId']);
        static::assertSame(4200.0, $this->deliveryUpdates[0]['quotedAmount']);
        static::assertSame([], $this->chowdeckBodies);
        static::assertSame([], $this->events);
    }

    public function testConfirmedPriceWithinTheWindowUsesTheStoredFee(): void
    {
        $this->quoteService->expects(static::never())->method('feeForOrder');
        $delivery = $this->delivery(['status' => 'needs_quote', 'feeId' => 39711, 'quotedAt' => new \DateTimeImmutable('-5 minutes')]);

        $this->service($delivery, [ClientFactory::ok(self::CREATED)])->request(self::DELIVERY_ID, true, Context::createDefaultContext());

        static::assertSame(39711, $this->chowdeckBodies[0]['fee_id']);
    }

    public function testStaleConfirmationAndFallbackOrdersAskAgain(): void
    {
        $this->fee(3900.0);

        $stale = $this->service($this->delivery(['status' => 'needs_quote', 'feeId' => 1, 'quotedAt' => new \DateTimeImmutable('-1 hour')]), [])
            ->request(self::DELIVERY_ID, true, Context::createDefaultContext());
        $fallback = $this->service($this->delivery(['status' => 'needs_quote', 'quotedAmount' => null, 'feeId' => null]), [])
            ->request(self::DELIVERY_ID, false, Context::createDefaultContext());

        static::assertFalse($stale->requested);
        static::assertFalse($fallback->requested);
        static::assertNull($fallback->oldPrice);
    }

    public function testChowdeckRefusalIsStoredAndAlertsTheAdmin(): void
    {
        $this->fee(3900.0);
        $service = $this->service($this->delivery(), [ClientFactory::json(['status' => 'failed', 'message' => 'Insufficient wallet balance'], 400)]);

        try {
            $service->request(self::DELIVERY_ID, false, Context::createDefaultContext());
            static::fail('Expected exception');
        } catch (ChowdeckApiException $e) {
            static::assertSame(ApiErrorReason::InsufficientBalance, $e->getReason());
        }

        static::assertSame(['id' => self::DELIVERY_ID, 'errorCode' => 'insufficient_balance', 'errorMessage' => 'Insufficient wallet balance'], $this->deliveryUpdates[0]);
        static::assertInstanceOf(DeliveryFailedEvent::class, $this->events[0]);
        static::assertSame('Insufficient wallet balance', $this->events[0]->getValues()['errorMessage']);
    }

    public function testFeeThatCannotBeComputedIsReported(): void
    {
        $this->quoteService->method('feeForOrder')->willReturn(QuoteError::NotConfigured);

        $this->expectExceptionObject(DeliveryException::quoteFailed(QuoteError::NotConfigured));

        $this->service($this->delivery(), [])->request(self::DELIVERY_ID, false, Context::createDefaultContext());
    }

    public function testMissingPickupContactStopsBeforeChowdeck(): void
    {
        $this->fee(3900.0);

        try {
            $this->service($this->delivery(), [], [], ['apiKey' => 'k'])->request(self::DELIVERY_ID, false, Context::createDefaultContext());
            static::fail('Expected exception');
        } catch (DeliveryException $e) {
            static::assertSame('pickup_contact_missing', $e->reason);
        }

        static::assertSame([], $this->chowdeckBodies);
    }

    public function testDoubleRequestIsRefusedUnderTheLock(): void
    {
        $this->expectExceptionObject(DeliveryException::notRequestable('preparing'));

        $this->service($this->delivery(), [], [], self::CONFIG, 'preparing')->request(self::DELIVERY_ID, false, Context::createDefaultContext());
    }

    public function testRequestForAMissingDeliveryOrOrder(): void
    {
        try {
            $this->service($this->delivery(), [], [], self::CONFIG, false)->request(self::DELIVERY_ID, false, Context::createDefaultContext());
            static::fail('Expected exception');
        } catch (DeliveryException $e) {
            static::assertSame('not_found', $e->reason);
        }

        $this->expectExceptionObject(DeliveryException::notFound());
        $this->service($this->delivery(), [], [], self::CONFIG, null, false)->request(self::DELIVERY_ID, false, Context::createDefaultContext());
    }

    public function testOrderWithoutTheDeliveryAddressCannotBePriced(): void
    {
        $this->expectExceptionObject(DeliveryException::quoteFailed(QuoteError::IncompleteAddress));

        $this->service($this->delivery(['orderDeliveryId' => 'other']), [])->request(self::DELIVERY_ID, false, Context::createDefaultContext());
    }

    public function testRefreshAppliesStatusRiderAndEvents(): void
    {
        $this->stateUpdater->expects(static::once())->method('apply')->with(DeliveryStatus::PickedUp, self::ORDER_DELIVERY_ID);
        $response = ClientFactory::ok(['reference' => 'kmh-1', 'status' => 'picked', 'driver' => ['name' => 'Tunde', 'phone' => '+2348011111111']]);

        $this->service($this->requested(), [$response])->refresh(self::DELIVERY_ID, Context::createDefaultContext());

        static::assertSame('https://console-api.chowdeck.com/relay/delivery/kmh-1', $response->getRequestUrl());
        static::assertSame('picked_up', $this->deliveryUpdates[0]['status']);
        static::assertSame(['name' => 'Tunde', 'phone' => '+2348011111111'], $this->deliveryUpdates[0]['rider']);
        static::assertInstanceOf(DeliveryPickedUpEvent::class, $this->events[0]);
        static::assertSame('Tunde', $this->events[0]->getValues()['riderName']);
    }

    public function testTrackingCodeAlreadyOnTheDeliveryIsNotAddedTwice(): void
    {
        $this->fee(3900.0);

        $this->service($this->delivery(), [ClientFactory::ok(self::CREATED)], ['https://track.chowdeck.com/x'])->request(self::DELIVERY_ID, false, Context::createDefaultContext());

        static::assertSame([], $this->orderDeliveryUpdates);
    }

    public function testRefreshOfAMissingDelivery(): void
    {
        $this->expectExceptionObject(DeliveryException::notFound());

        $this->service(null, [])->refresh(self::DELIVERY_ID, Context::createDefaultContext());
    }

    public function testRefreshBeforeAnyRiderIsRefused(): void
    {
        $this->expectExceptionObject(DeliveryException::notRequested());

        $this->service($this->delivery(), [])->refresh(self::DELIVERY_ID, Context::createDefaultContext());
    }

    public function testWebhookCompletesTheDeliveryOnce(): void
    {
        $this->stateUpdater->expects(static::once())->method('apply')->with(DeliveryStatus::Completed);
        $service = $this->service($this->requested(['status' => 'picked_up']), []);

        static::assertTrue($service->applyWebhook(Delivery::fromArray(['reference' => 'kmh-1', 'status' => 'success', 'tracking_url' => 'https://track.chowdeck.com/new']), Context::createDefaultContext()));

        static::assertSame('completed', $this->deliveryUpdates[0]['status']);
        static::assertArrayHasKey('deliveredAt', $this->deliveryUpdates[0]);
        static::assertSame('https://track.chowdeck.com/new', $this->deliveryUpdates[0]['trackingUrl']);
        static::assertSame(['https://track.chowdeck.com/new'], $this->orderDeliveryUpdates[0]['trackingCodes']);
        static::assertInstanceOf(DeliveryDeliveredEvent::class, $this->events[0]);
    }

    public function testWebhookWithTheSameOrAnUnknownStatusFiresNothing(): void
    {
        $this->stateUpdater->expects(static::never())->method('apply');
        $service = $this->service($this->requested(['status' => 'awaiting_pickup', 'deliveredAt' => new \DateTimeImmutable(), 'rider' => ['name' => 'Kemi']]), []);

        $service->applyWebhook(Delivery::fromArray(['reference' => 'kmh-1', 'status' => 'awaiting_pickup']), Context::createDefaultContext());
        $service->applyWebhook(Delivery::fromArray(['reference' => 'kmh-1', 'status' => 'teleported']), Context::createDefaultContext());

        static::assertSame(['name' => 'Kemi'], $this->deliveryUpdates[0]['rider']);
        static::assertArrayNotHasKey('status', $this->deliveryUpdates[1]);
        static::assertSame([], $this->events);
    }

    public function testWebhookEventsForAssignmentAndCancellation(): void
    {
        $service = $this->service($this->requested(['status' => 'preparing']), []);

        $service->applyWebhook(Delivery::fromArray(['reference' => 'kmh-1', 'status' => 'awaiting_pickup']), Context::createDefaultContext());
        $service->applyWebhook(Delivery::fromArray(['reference' => 'kmh-1', 'status' => 'rejected']), Context::createDefaultContext());

        static::assertInstanceOf(DeliveryRiderAssignedEvent::class, $this->events[0]);
        static::assertInstanceOf(DeliveryCancelledEvent::class, $this->events[1]);
        static::assertArrayHasKey('cancelledAt', $this->deliveryUpdates[1]);
    }

    public function testWebhookForAnUnknownReference(): void
    {
        static::assertFalse($this->service(null, [])->applyWebhook(Delivery::fromArray(['reference' => 'x', 'status' => 'success']), Context::createDefaultContext()));
    }

    public function testCancelStoresTheRefundAndCancelsTheDelivery(): void
    {
        $this->stateUpdater->expects(static::once())->method('apply')->with(DeliveryStatus::Cancelled, self::ORDER_DELIVERY_ID);

        $this->service($this->requested(), [ClientFactory::ok(['reference' => 'kmh-1', 'status' => 'cancelled', 'refund_amount' => 390000, 'currency' => 'NGN'])])
            ->cancel(self::DELIVERY_ID, Context::createDefaultContext());

        static::assertSame(['reason' => 'Cancelled by the shop'], $this->chowdeckBodies[0]);
        static::assertSame('cancelled', $this->deliveryUpdates[0]['status']);
        static::assertSame(3900.0, $this->deliveryUpdates[0]['refundAmount']);
        static::assertInstanceOf(DeliveryCancelledEvent::class, $this->events[0]);
    }

    public function testCancelRefusedByChowdeckKeepsTheStatusAndShowsTheReason(): void
    {
        $service = $this->service($this->requested(), [ClientFactory::json(['status' => 'failed', 'message' => 'Delivery can no longer be cancelled'], 400)]);

        try {
            $service->cancel(self::DELIVERY_ID, Context::createDefaultContext());
            static::fail('Expected exception');
        } catch (ChowdeckApiException $e) {
            static::assertSame('Delivery can no longer be cancelled', $e->getMessage());
        }

        static::assertSame('Delivery can no longer be cancelled', $this->deliveryUpdates[0]['errorMessage']);
        static::assertArrayNotHasKey('status', $this->deliveryUpdates[0]);
    }

    public function testCancelAfterPickupIsRefusedLocally(): void
    {
        $this->expectExceptionObject(DeliveryException::notCancellable('picked_up'));

        $this->service($this->requested(['status' => 'picked_up']), [])->cancel(self::DELIVERY_ID, Context::createDefaultContext());
    }

    public function testEventWithoutTheOrderIsSkipped(): void
    {
        $service = $this->service($this->requested(['status' => 'preparing']), [], [], self::CONFIG, null, false);

        $service->applyWebhook(Delivery::fromArray(['reference' => 'kmh-1', 'status' => 'awaiting_pickup']), Context::createDefaultContext());

        static::assertSame([], $this->events);
    }

    private function fee(float $total): void
    {
        $this->quoteService->method('feeForOrder')->willReturn(new DeliveryFee(39710, $total, $total, 0.0, 0.0));
        $this->quoteService->method('declaredValue')->willReturn(null);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function delivery(array $data = []): DeliveryEntity
    {
        $delivery = new DeliveryEntity();
        $delivery->setId(self::DELIVERY_ID);
        $delivery->assign(array_merge([
            'orderId' => 'order-1',
            'orderDeliveryId' => self::ORDER_DELIVERY_ID,
            'salesChannelId' => 'sc-1',
            'status' => 'quoted',
            'feeId' => 39707,
            'quotedAt' => new \DateTimeImmutable('-1 hour'),
            'quotedAmount' => 3900.0,
            'chargedAmount' => 3900.0,
            'currency' => 'NGN',
        ], $data));

        return $delivery;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requested(array $data = []): DeliveryEntity
    {
        return $this->delivery(array_merge(['status' => 'preparing', 'reference' => 'kmh-1', 'trackingUrl' => 'https://track.chowdeck.com/x'], $data));
    }

    /**
     * @param list<MockResponse> $responses
     * @param list<string> $trackingCodes
     * @param array<string, mixed> $config
     */
    private function service(
        ?DeliveryEntity $delivery,
        array $responses,
        array $trackingCodes = [],
        array $config = self::CONFIG,
        string|false|null $lockedStatus = null,
        bool $withOrder = true,
    ): DeliveryService {
        $deliveries = $this->createMock(EntityRepository::class);
        $deliveries->method('search')->willReturnCallback(fn (): EntitySearchResult => $this->searchResult(new DeliveryCollection($delivery !== null ? [$delivery] : [])));
        $deliveries->method('update')->willReturnCallback(function (array $payload) use ($delivery): EntityWrittenContainerEvent {
            $this->deliveryUpdates[] = $payload[0];
            // Later reads (the Flow event) see the written values, as in the database.
            $delivery?->assign(array_diff_key($payload[0], ['id' => true, 'quotedAt' => true, 'requestedAt' => true, 'deliveredAt' => true, 'cancelledAt' => true]));

            return self::written();
        });

        $orders = $this->createMock(EntityRepository::class);
        $orders->method('search')->willReturnCallback(fn (): EntitySearchResult => $this->searchResult(new OrderCollection($withOrder ? [$this->order()] : [])));

        $orderDeliveries = $this->createMock(EntityRepository::class);
        $orderDeliveries->method('search')->willReturnCallback(function () use ($trackingCodes): EntitySearchResult {
            $orderDelivery = new OrderDeliveryEntity();
            $orderDelivery->setId(self::ORDER_DELIVERY_ID);
            $orderDelivery->setTrackingCodes($trackingCodes);

            return $this->searchResult(new OrderDeliveryCollection([$orderDelivery]));
        });
        $orderDeliveries->method('update')->willReturnCallback(function (array $payload): EntityWrittenContainerEvent {
            $this->orderDeliveryUpdates[] = $payload[0];

            return self::written();
        });

        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $callback): mixed => $callback());
        $connection->method('fetchOne')->willReturn($lockedStatus ?? $delivery?->getStatus() ?? false);

        $bodies = &$this->chowdeckBodies;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$responses, &$bodies): MockResponse {
            $body = json_decode((string)($options['body'] ?? ''), true);

            if (\is_array($body)) {
                $bodies[] = $body;
            }

            return array_shift($responses) ?? throw new \LogicException('Unexpected Chowdeck call');
        });
        $configService = ClientFactory::config($config);

        $events = &$this->events;
        $dispatcher = new class($events) implements EventDispatcherInterface {
            /**
             * @param list<object> $events
             */
            public function __construct(private array &$events)
            {
            }

            public function dispatch(object $event): object
            {
                $this->events[] = $event;

                return $event;
            }
        };

        return new DeliveryService(
            $deliveries,
            $orders,
            $orderDeliveries,
            new ChowdeckClient(new ChowdeckHttpClient($configService, $http)),
            $this->quoteService,
            $this->stateUpdater,
            $configService,
            $connection,
            $dispatcher,
            new NullLogger(),
        );
    }

    private function order(): OrderEntity
    {
        $address = new OrderAddressEntity();
        $address->setId('address-1');
        $address->setFirstName('Ada');
        $address->setLastName('Test');
        $address->setPhoneNumber('+2348031234567');
        $orderDelivery = new OrderDeliveryEntity();
        $orderDelivery->setId(self::ORDER_DELIVERY_ID);
        $orderDelivery->setShippingOrderAddress($address);
        $customer = new OrderCustomerEntity();
        $customer->setEmail('ada@example.com');
        $order = new OrderEntity();
        $order->setId('order-1');
        $order->setSalesChannelId('sc-1');
        $order->setOrderNumber('10036');
        $order->setCustomerComment('Ring twice');
        $order->setOrderCustomer($customer);
        $order->setDeliveries(new OrderDeliveryCollection([$orderDelivery]));

        return $order;
    }

    private static function written(): EntityWrittenContainerEvent
    {
        return new EntityWrittenContainerEvent(Context::createDefaultContext(), new NestedEventCollection(), []);
    }

    /**
     * @template T of EntityCollection
     *
     * @param T $collection
     *
     * @return EntitySearchResult<T>
     */
    private function searchResult(EntityCollection $collection): EntitySearchResult
    {
        return new EntitySearchResult('entity', $collection->count(), $collection, null, new Criteria(), Context::createDefaultContext());
    }
}
