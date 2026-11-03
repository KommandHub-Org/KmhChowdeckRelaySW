<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Flow;

use Kommandhub\ChowdeckRelaySW\Core\Content\Delivery\DeliveryCollection;
use Kommandhub\ChowdeckRelaySW\Delivery\DeliveryStatus;
use Kommandhub\ChowdeckRelaySW\Delivery\Service\DeliveryService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Flow\Dispatching\Action\FlowAction;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Event\OrderAware;

/**
 * Flow Builder action "Request Chowdeck rider" (spec §13.5): requests a rider
 * for every `quoted` Chowdeck delivery of the order. The plugin installs no
 * flow with it — merchants pick their own trigger (e.g. order "ready"). A
 * delivery whose price changed is left for the merchant to confirm in the
 * order; a failure is logged and never stops the rest of the flow.
 */
class RequestRiderAction extends FlowAction
{
    public const ACTION_NAME = 'action.kmh.chowdeck.request_rider';

    /**
     * @param EntityRepository<DeliveryCollection> $kmhChowdeckDeliveryRepository
     */
    public function __construct(
        private readonly EntityRepository $kmhChowdeckDeliveryRepository,
        private readonly DeliveryService $deliveryService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getName(): string
    {
        return self::ACTION_NAME;
    }

    /**
     * @return list<string>
     */
    public function requirements(): array
    {
        return [OrderAware::class];
    }

    public function handleFlow(StorableFlow $flow): void
    {
        $orderId = $flow->getData(OrderAware::ORDER_ID);

        if (!is_string($orderId)) {
            return;
        }

        $context = $flow->getContext();
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('orderId', $orderId))
            ->addFilter(new EqualsFilter('status', DeliveryStatus::Quoted->value));

        /** @var list<string> $deliveryIds */
        $deliveryIds = $this->kmhChowdeckDeliveryRepository->searchIds($criteria, $context)->getIds();

        foreach ($deliveryIds as $deliveryId) {
            try {
                if (!$this->deliveryService->request($deliveryId, false, $context)->requested) {
                    $this->logger->notice('Chowdeck price changed; confirm it in the order to request the rider.', ['deliveryId' => $deliveryId]);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Chowdeck rider could not be requested by the flow.', [
                    'deliveryId' => $deliveryId,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }
}
