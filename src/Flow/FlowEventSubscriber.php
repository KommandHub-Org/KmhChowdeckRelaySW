<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Flow;

use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryCancelledEvent;
use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryDeliveredEvent;
use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryFailedEvent;
use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryPickedUpEvent;
use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryRequestedEvent;
use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryRiderAssignedEvent;
use Shopware\Core\Framework\Event\BusinessEventCollector;
use Shopware\Core\Framework\Event\BusinessEventCollectorEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Makes the plugin's events selectable as Flow Builder triggers.
 */
class FlowEventSubscriber implements EventSubscriberInterface
{
    private const EVENTS = [
        DeliveryRequestedEvent::class,
        DeliveryFailedEvent::class,
        DeliveryRiderAssignedEvent::class,
        DeliveryPickedUpEvent::class,
        DeliveryDeliveredEvent::class,
        DeliveryCancelledEvent::class,
    ];

    public function __construct(private readonly BusinessEventCollector $collector)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [BusinessEventCollectorEvent::NAME => 'onCollect'];
    }

    public function onCollect(BusinessEventCollectorEvent $event): void
    {
        foreach (self::EVENTS as $eventClass) {
            $definition = $this->collector->define($eventClass);

            if ($definition !== null) {
                $event->getCollection()->set($definition->getName(), $definition);
            }
        }
    }
}
