<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Webhook\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * A stored webhook to process in the background, so Chowdeck gets its 200
 * at once (spec §13.6).
 */
final class ChowdeckWebhookMessage implements AsyncMessageInterface
{
    public function __construct(public readonly string $eventId)
    {
    }
}
