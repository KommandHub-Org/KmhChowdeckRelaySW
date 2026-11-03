<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Flow\Event;

/**
 * A rider is assigned and on the way to the pickup.
 */
class DeliveryRiderAssignedEvent extends DeliveryEvent
{
    public const EVENT_NAME = 'kmh_chowdeck.delivery.rider_assigned';

    public function getName(): string
    {
        return self::EVENT_NAME;
    }
}
