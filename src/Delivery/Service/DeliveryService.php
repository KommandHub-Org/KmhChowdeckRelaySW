<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Delivery\Service;

use Doctrine\DBAL\Connection;
use Kommandhub\ChowdeckRelaySW\Client\ChowdeckClient;
use Kommandhub\ChowdeckRelaySW\Client\Dto\Contact;
use Kommandhub\ChowdeckRelaySW\Client\Dto\CreateDeliveryRequest;
use Kommandhub\ChowdeckRelaySW\Client\Dto\Delivery;
use Kommandhub\ChowdeckRelaySW\Client\Dto\WalletBalance;
use Kommandhub\ChowdeckRelaySW\Core\Content\Delivery\DeliveryCollection;
use Kommandhub\ChowdeckRelaySW\Core\Content\Delivery\DeliveryEntity;
use Kommandhub\ChowdeckRelaySW\Delivery\DeliveryException;
use Kommandhub\ChowdeckRelaySW\Delivery\DeliveryStatus;
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
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Requests, refreshes and cancels the Chowdeck rider of an order delivery
 * (spec §13.5).
 *
 * Requesting always takes a fresh fee: the checkout fee is hours old and a
 * created delivery calls a rider at once. When the fresh price differs from
 * the stored one, it is stored and the merchant confirms it first. The
 * customer's charged price never changes.
 */
class DeliveryService
{
    /**
     * How long a changed price waits for the merchant's confirmation, in seconds.
     */
    private const CONFIRMATION_WINDOW = 900;

    private const CANCEL_REASON = 'Cancelled by the shop';

    private const ALLOWED_CHANNELS = ['sms', 'email', 'whatsapp'];

    /**
     * Flow event fired when a delivery reaches a status (spec §13.7).
     */
    private const STATUS_EVENTS = [
        'awaiting_pickup' => DeliveryRiderAssignedEvent::class,
        'picked_up' => DeliveryPickedUpEvent::class,
        'completed' => DeliveryDeliveredEvent::class,
        'cancelled' => DeliveryCancelledEvent::class,
    ];

    /**
     * @param EntityRepository<DeliveryCollection> $kmhChowdeckDeliveryRepository
     * @param EntityRepository<OrderCollection> $orderRepository
     * @param EntityRepository<OrderDeliveryCollection> $orderDeliveryRepository
     */
    public function __construct(
        private readonly EntityRepository $kmhChowdeckDeliveryRepository,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderDeliveryRepository,
        private readonly ChowdeckClient $client,
        private readonly QuoteService $quoteService,
        private readonly DeliveryStateUpdater $deliveryStateUpdater,
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws DeliveryException
     * @throws ChowdeckApiException with Chowdeck's reason when it refuses
     */
    public function request(string $deliveryId, bool $confirmed, Context $context): RequestResult
    {
        // The row lock keeps two clicks, or a click and the Flow action, from
        // calling two riders. A Chowdeck refusal is returned, not thrown, so
        // the transaction commits and the error can be stored afterwards.
        /** @var array{0: DeliveryEntity, 1: RequestResult|ChowdeckApiException} $outcome */
        $outcome = $this->connection->transactional(function () use ($deliveryId, $confirmed, $context): array {
            $status = $this->connection->fetchOne(
                'SELECT `status` FROM `kmh_chowdeck_delivery` WHERE `id` = :id FOR UPDATE',
                ['id' => Uuid::fromHexToBytes($deliveryId)],
            );

            if (!is_string($status)) {
                throw DeliveryException::notFound();
            }

            if (DeliveryStatus::tryFrom($status)?->isRequestable() !== true) {
                throw DeliveryException::notRequestable($status);
            }

            $delivery = $this->load($deliveryId, $context);

            try {
                return [$delivery, $this->attempt($delivery, $confirmed, $context)];
            } catch (ChowdeckApiException $e) {
                return [$delivery, $e];
            }
        });

        [$delivery, $result] = $outcome;

        if ($result instanceof ChowdeckApiException) {
            $this->recordError($delivery, $result, $context);
            $this->dispatch($deliveryId, DeliveryFailedEvent::class, $context, $result->getMessage());

            throw $result;
        }

        if ($result->requested) {
            $this->dispatch($deliveryId, DeliveryRequestedEvent::class, $context);
        }

        return $result;
    }

    /**
     * Reads the delivery back from Chowdeck and applies its status.
     *
     * @throws DeliveryException
     * @throws ChowdeckApiException
     */
    public function refresh(string $deliveryId, Context $context): void
    {
        $delivery = $this->load($deliveryId, $context);
        $reference = $delivery->getReference();

        if ($reference === null) {
            throw DeliveryException::notRequested();
        }

        $this->apply($delivery, $this->client->deliveries()->get($reference, $delivery->getSalesChannelId()), $context);
    }

    /**
     * Applies an update Chowdeck reported by webhook (spec §13.6).
     *
     * @return bool false when no delivery has this reference
     */
    public function applyWebhook(Delivery $update, Context $context): bool
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('reference', $update->reference))->setLimit(1);
        $delivery = $this->kmhChowdeckDeliveryRepository->search($criteria, $context)->getEntities()->first();

        if ($delivery === null) {
            return false;
        }

        $this->apply($delivery, $update, $context);

        return true;
    }

    /**
     * @throws DeliveryException
     * @throws ChowdeckApiException with Chowdeck's reason when it refuses
     */
    public function cancel(string $deliveryId, Context $context): void
    {
        $delivery = $this->load($deliveryId, $context);
        $reference = $delivery->getReference();

        if ($reference === null || DeliveryStatus::tryFrom($delivery->getStatus())?->isCancellable() !== true) {
            throw DeliveryException::notCancellable($delivery->getStatus());
        }

        try {
            $result = $this->client->deliveries()->cancel($reference, self::CANCEL_REASON, $delivery->getSalesChannelId());
        } catch (ChowdeckApiException $e) {
            $this->recordError($delivery, $e, $context);

            throw $e;
        }

        $this->update($delivery, [
            'status' => DeliveryStatus::Cancelled->value,
            'cancelledAt' => self::now(),
            'refundAmount' => $result->refundAmount,
            'errorCode' => null,
            'errorMessage' => null,
        ], $context);
        $this->deliveryStateUpdater->apply(DeliveryStatus::Cancelled, $delivery->getOrderDeliveryId(), $context);
        $this->dispatch($delivery->getId(), DeliveryCancelledEvent::class, $context);
    }

    /**
     * @throws DeliveryException
     * @throws ChowdeckApiException
     */
    private function attempt(DeliveryEntity $delivery, bool $confirmed, Context $context): RequestResult
    {
        $order = $this->loadOrder($delivery, $context);

        if ($confirmed && $this->hasConfirmableFee($delivery)) {
            $this->create($delivery, $order, (int)$delivery->getFeeId(), $context);

            return RequestResult::requested();
        }

        $address = $order->getDeliveries()?->get($delivery->getOrderDeliveryId())?->getShippingOrderAddress();

        if ($address === null) {
            throw DeliveryException::quoteFailed(QuoteError::IncompleteAddress);
        }

        $fee = $this->quoteService->feeForOrder($order, $address);

        if ($fee instanceof QuoteError) {
            throw DeliveryException::quoteFailed($fee);
        }

        $oldPrice = $delivery->getQuotedAmount();

        if ($delivery->getStatus() === DeliveryStatus::Quoted->value && $oldPrice !== null && abs($fee->totalAmount - $oldPrice) < 0.005) {
            $this->create($delivery, $order, $fee->id, $context);

            return RequestResult::requested();
        }

        $this->update($delivery, [
            'status' => DeliveryStatus::NeedsQuote->value,
            'feeId' => $fee->id,
            'quotedAmount' => $fee->totalAmount,
            'quotedAt' => self::now(),
        ], $context);

        return RequestResult::confirmationNeeded($oldPrice, $fee->totalAmount, WalletBalance::CURRENCY);
    }

    /**
     * A changed price the merchant was shown a moment ago.
     */
    private function hasConfirmableFee(DeliveryEntity $delivery): bool
    {
        $quotedAt = $delivery->getQuotedAt();

        return $delivery->getStatus() === DeliveryStatus::NeedsQuote->value
            && $delivery->getFeeId() !== null
            && $quotedAt !== null
            && $quotedAt >= new \DateTimeImmutable(sprintf('-%d seconds', self::CONFIRMATION_WINDOW));
    }

    /**
     * Creates the Chowdeck delivery, which calls a rider at once.
     *
     * @throws DeliveryException
     * @throws ChowdeckApiException
     */
    private function create(DeliveryEntity $delivery, OrderEntity $order, int $feeId, Context $context): void
    {
        $salesChannelId = $delivery->getSalesChannelId();
        $source = new Contact(
            $this->config->getString('pickupContactName', $salesChannelId),
            $this->config->getString('pickupContactPhone', $salesChannelId),
            $this->config->getString('pickupContactEmail', $salesChannelId),
        );

        if (trim($source->name) === '' || trim($source->phone) === '') {
            throw DeliveryException::pickupContactMissing();
        }

        $address = $order->getDeliveries()?->get($delivery->getOrderDeliveryId())?->getShippingOrderAddress();
        $destination = new Contact(
            trim($address?->getFirstName() . ' ' . $address?->getLastName()),
            (string)$address?->getPhoneNumber(),
            $order->getOrderCustomer()?->getEmail(),
        );

        $pin = $this->config->getBool('useDeliveryPin', $salesChannelId) ? random_int(1000, 9999) : null;
        $note = trim('Order ' . $order->getOrderNumber() . "\n" . (string)$order->getCustomerComment());

        $created = $this->client->deliveries()->create(new CreateDeliveryRequest(
            $feeId,
            'kmh-' . $delivery->getId(),
            $this->config->getString('itemType', $salesChannelId) ?: 'others',
            $source,
            $destination,
            $note,
            $pin,
            $this->notificationChannels($salesChannelId),
            $this->quoteService->declaredValue($order),
        ), $salesChannelId);

        $this->update($delivery, [
            'status' => (DeliveryStatus::fromChowdeck($created->status) ?? DeliveryStatus::Preparing)->value,
            'feeId' => $feeId,
            'reference' => $created->reference !== '' ? $created->reference : 'kmh-' . $delivery->getId(),
            'chowdeckId' => $created->id,
            'trackingUrl' => $created->trackingUrl,
            'deliveryPin' => $created->deliveryPin ?? $pin,
            'rider' => $created->rider !== [] ? $created->rider : null,
            'requestedAt' => self::now(),
            'errorCode' => null,
            'errorMessage' => null,
        ], $context);

        if ($created->trackingUrl !== null) {
            $this->addTrackingCode($delivery->getOrderDeliveryId(), $created->trackingUrl, $context);
        }
    }

    private function apply(DeliveryEntity $delivery, Delivery $update, Context $context): void
    {
        $status = DeliveryStatus::fromChowdeck($update->status);
        $previousStatus = $delivery->getStatus();
        $previousTrackingUrl = $delivery->getTrackingUrl();
        $data = [
            'trackingUrl' => $update->trackingUrl ?? $delivery->getTrackingUrl(),
            'rider' => $update->rider !== [] ? $update->rider : $delivery->getRider(),
            'errorCode' => null,
            'errorMessage' => null,
        ];

        if ($status === null) {
            $this->logger->info('Unknown Chowdeck status; delivery status left unchanged.', ['status' => $update->status, 'deliveryId' => $delivery->getId()]);
        } else {
            $data['status'] = $status->value;

            if ($status === DeliveryStatus::Completed && $delivery->getDeliveredAt() === null) {
                $data['deliveredAt'] = self::now();
            }

            if ($status === DeliveryStatus::Cancelled && $delivery->getCancelledAt() === null) {
                $data['cancelledAt'] = self::now();
            }
        }

        $this->update($delivery, $data, $context);

        if ($update->trackingUrl !== null && $update->trackingUrl !== $previousTrackingUrl) {
            $this->addTrackingCode($delivery->getOrderDeliveryId(), $update->trackingUrl, $context);
        }

        if ($status === null || $status->value === $previousStatus) {
            return;
        }

        $this->deliveryStateUpdater->apply($status, $delivery->getOrderDeliveryId(), $context);

        if (isset(self::STATUS_EVENTS[$status->value])) {
            $this->dispatch($delivery->getId(), self::STATUS_EVENTS[$status->value], $context);
        }
    }

    /**
     * Fires a delivery Flow event with the delivery's current data.
     *
     * @param class-string<DeliveryEvent> $eventClass
     */
    private function dispatch(string $deliveryId, string $eventClass, Context $context, string $errorMessage = ''): void
    {
        $delivery = $this->load($deliveryId, $context);
        $criteria = (new Criteria([$delivery->getOrderId()]))->addAssociation('orderCustomer');
        $order = $this->orderRepository->search($criteria, $context)->getEntities()->first();

        if ($order === null) {
            return;
        }

        $rider = $delivery->getRider() ?? [];

        $this->eventDispatcher->dispatch(new $eventClass($order, $context, [
            'trackingUrl' => (string)$delivery->getTrackingUrl(),
            'deliveryPin' => (string)$delivery->getDeliveryPin(),
            'riderName' => is_string($rider['name'] ?? null) ? $rider['name'] : '',
            'riderPhone' => is_string($rider['phone'] ?? null) ? $rider['phone'] : '',
            'status' => $delivery->getStatus(),
            'errorMessage' => $errorMessage,
        ]));
    }

    /**
     * The tracking link itself becomes the delivery's tracking code; the
     * shipping method's tracking URL is `%s`, so Shopware's account page and
     * mails link straight to Chowdeck (whose URL pattern is undocumented).
     */
    private function addTrackingCode(string $orderDeliveryId, string $trackingUrl, Context $context): void
    {
        $orderDelivery = $this->orderDeliveryRepository->search(new Criteria([$orderDeliveryId]), $context)->getEntities()->first();

        if ($orderDelivery === null || \in_array($trackingUrl, $orderDelivery->getTrackingCodes(), true)) {
            return;
        }

        $this->orderDeliveryRepository->update([['id' => $orderDeliveryId, 'trackingCodes' => [...$orderDelivery->getTrackingCodes(), $trackingUrl]]], $context);
    }

    /**
     * @return list<string>
     */
    private function notificationChannels(string $salesChannelId): array
    {
        return array_values(array_intersect(self::ALLOWED_CHANNELS, array_filter($this->config->getArray('notificationChannels', $salesChannelId), is_string(...))));
    }

    private function recordError(DeliveryEntity $delivery, ChowdeckApiException $e, Context $context): void
    {
        $this->logger->warning('Chowdeck delivery action failed.', [
            'deliveryId' => $delivery->getId(),
            'reason' => $e->getReason()->value,
            'httpStatus' => $e->getHttpStatus(),
        ]);

        $this->update($delivery, [
            'errorCode' => $e->getReason()->value,
            'errorMessage' => mb_substr($e->getMessage(), 0, 1024),
        ], $context);
    }

    /**
     * @throws DeliveryException
     */
    private function loadOrder(DeliveryEntity $delivery, Context $context): OrderEntity
    {
        $criteria = new Criteria([$delivery->getOrderId()]);
        $criteria->addAssociations(['lineItems', 'orderCustomer', 'currency', 'deliveries.shippingOrderAddress.country', 'deliveries.shippingOrderAddress.countryState']);
        $order = $this->orderRepository->search($criteria, $context)->getEntities()->first();

        if ($order === null) {
            throw DeliveryException::notFound();
        }

        return $order;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function update(DeliveryEntity $delivery, array $data, Context $context): void
    {
        $this->kmhChowdeckDeliveryRepository->update([['id' => $delivery->getId()] + $data], $context);
    }

    /**
     * @throws DeliveryException
     */
    private function load(string $deliveryId, Context $context): DeliveryEntity
    {
        $delivery = $this->kmhChowdeckDeliveryRepository->search(new Criteria([$deliveryId]), $context)->getEntities()->first();

        if ($delivery === null) {
            throw DeliveryException::notFound();
        }

        return $delivery;
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }
}
