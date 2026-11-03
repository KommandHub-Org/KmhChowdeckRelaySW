<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Delivery;

/**
 * Lifecycle of one Chowdeck delivery (spec §13.4).
 *
 * `quoted` and `needs_quote` are ours (before a rider is requested); the rest
 * follow Chowdeck's statuses.
 */
enum DeliveryStatus: string
{
    case Quoted = 'quoted';
    case NeedsQuote = 'needs_quote';
    case Preparing = 'preparing';
    case AwaitingPickup = 'awaiting_pickup';
    case PickedUp = 'picked_up';
    case Arrived = 'arrived';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
