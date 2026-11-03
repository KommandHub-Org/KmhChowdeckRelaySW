<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Flow\Event;

/**
 * Requesting the rider failed (empty wallet, address, …). Meant for an admin
 * alert; `errorMessage` carries Chowdeck's reason.
 */
class DeliveryFailedEvent extends DeliveryEvent
{
    public const EVENT_NAME = 'kmh_chowdeck.delivery.failed';

    public function getName(): string
    {
        return self::EVENT_NAME;
    }
}
