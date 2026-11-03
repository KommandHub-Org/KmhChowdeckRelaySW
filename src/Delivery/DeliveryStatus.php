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

    /**
     * Chowdeck documents two vocabularies (Relay guide, webhook guide) plus
     * `received` in its Postman collection; all map onto ours. Unknown values
     * return null and leave the status unchanged.
     */
    public static function fromChowdeck(string $status): ?self
    {
        return match (strtolower(trim($status))) {
            'preparing', 'received' => self::Preparing,
            'awaiting_pickup' => self::AwaitingPickup,
            'in_transit', 'picked', 'picked_up' => self::PickedUp,
            'arrived' => self::Arrived,
            'success', 'completed', 'delivered' => self::Completed,
            'rejected', 'cancelled', 'canceled' => self::Cancelled,
            default => null,
        };
    }

    /**
     * Before a rider is requested.
     */
    public function isRequestable(): bool
    {
        return $this === self::Quoted || $this === self::NeedsQuote;
    }

    /**
     * A rider is on the way but has not collected the parcel; Chowdeck may
     * still cancel (⚠ spec §13.4).
     */
    public function isCancellable(): bool
    {
        return $this === self::Preparing || $this === self::AwaitingPickup;
    }

    /**
     * Requested deliveries Chowdeck may still change.
     */
    public function isOpen(): bool
    {
        return \in_array($this, [self::Preparing, self::AwaitingPickup, self::PickedUp, self::Arrived], true);
    }
}
