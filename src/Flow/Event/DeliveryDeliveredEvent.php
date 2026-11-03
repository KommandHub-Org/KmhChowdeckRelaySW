<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Flow\Event;

/**
 * Chowdeck reports the parcel as delivered.
 */
class DeliveryDeliveredEvent extends DeliveryEvent
{
    public const EVENT_NAME = 'kmh_chowdeck.delivery.delivered';

    public function getName(): string
    {
        return self::EVENT_NAME;
    }
}
