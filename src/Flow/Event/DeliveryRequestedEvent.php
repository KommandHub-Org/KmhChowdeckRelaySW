<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Flow\Event;

/**
 * A rider was requested from Chowdeck; the tracking link (and PIN) are live.
 */
class DeliveryRequestedEvent extends DeliveryEvent
{
    public const EVENT_NAME = 'kmh_chowdeck.delivery.requested';

    public function getName(): string
    {
        return self::EVENT_NAME;
    }
}
