<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Flow\Event;

/**
 * The delivery was cancelled (by the merchant or by Chowdeck).
 */
class DeliveryCancelledEvent extends DeliveryEvent
{
    public const EVENT_NAME = 'kmh_chowdeck.delivery.cancelled';

    public function getName(): string
    {
        return self::EVENT_NAME;
    }
}
