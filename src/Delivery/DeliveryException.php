<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Delivery;

use Kommandhub\ChowdeckRelaySW\Exception\ChowdeckException;
use Kommandhub\ChowdeckRelaySW\Quote\QuoteError;

/**
 * A delivery action that cannot run in the delivery's current state, or a
 * fresh fee that failed. The message is safe to show in the Administration.
 */
class DeliveryException extends ChowdeckException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('not_found', 'The Chowdeck delivery does not exist.');
    }

    public static function notRequestable(string $status): self
    {
        return new self('not_requestable', sprintf('A rider has already been requested for this delivery (status "%s").', $status));
    }

    public static function notRequested(): self
    {
        return new self('not_requested', 'No rider has been requested for this delivery yet.');
    }

    public static function notCancellable(string $status): self
    {
        return new self('not_cancellable', sprintf('A delivery with status "%s" cannot be cancelled.', $status));
    }

    public static function pickupContactMissing(): self
    {
        return new self('pickup_contact_missing', 'Enter the pickup contact name and phone in the Chowdeck settings first.');
    }

    public static function quoteFailed(QuoteError $error): self
    {
        return new self('quote_' . $error->value, sprintf('Chowdeck could not price this delivery again (%s).', $error->value));
    }
}
