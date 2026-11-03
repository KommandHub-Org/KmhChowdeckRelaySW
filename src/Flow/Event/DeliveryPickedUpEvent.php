<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Flow\Event;

/**
 * The rider collected the parcel.
 */
class DeliveryPickedUpEvent extends DeliveryEvent
{
    public const EVENT_NAME = 'kmh_chowdeck.delivery.picked_up';

    public function getName(): string
    {
        return self::EVENT_NAME;
    }
}
