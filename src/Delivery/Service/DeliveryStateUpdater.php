<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Delivery\Service;

use Kommandhub\ChowdeckRelaySW\Delivery\DeliveryStatus;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

/**
 * Moves the Shopware order delivery along with the Chowdeck status, using
 * core states only (spec §4, §13.4). A transition the delivery's current state does
 * not allow is logged and skipped; order and payment state are never touched.
 */
class DeliveryStateUpdater
{
    private const ACTIONS = [
        'picked_up' => 'ship',
        'cancelled' => 'cancel',
    ];

    public function __construct(
        private readonly StateMachineRegistry $stateMachineRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function apply(DeliveryStatus $status, string $orderDeliveryId, Context $context): void
    {
        $action = self::ACTIONS[$status->value] ?? null;

        if ($action === null) {
            return;
        }

        try {
            $this->stateMachineRegistry->transition(
                new Transition(OrderDeliveryDefinition::ENTITY_NAME, $orderDeliveryId, $action, 'stateId'),
                $context,
            );
        } catch (\Throwable $e) {
            // Usually the delivery is already past this state (shipped twice,
            // cancelled after shipping); not worth failing the sync over.
            $this->logger->info('Order delivery state not changed.', [
                'orderDeliveryId' => $orderDeliveryId,
                'action' => $action,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
