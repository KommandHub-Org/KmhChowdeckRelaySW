<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Shipping\Error;

use Kommandhub\ChowdeckRelaySW\Quote\QuoteError;
use Shopware\Core\Checkout\Cart\Error\Error;

/**
 * Blocking cart error when Chowdeck delivery cannot be priced (spec §3, §13.3).
 * The order must not go through with a wrong or zero shipping price; the
 * customer can still pick another delivery method.
 *
 * The storefront shows `checkout.kmh-chowdeck-<reason>`; the messages below
 * are the API fallback and must match the en-GB snippets.
 */
class ChowdeckDeliveryError extends Error
{
    private const MESSAGES = [
        'incomplete_address' => 'Please complete your delivery address, including a phone number, to use same-day rider delivery.',
        'invalid_address' => 'Chowdeck does not deliver to this address. Please check it or choose another delivery method.',
        'unavailable' => 'Same-day rider delivery is temporarily unavailable. Please choose another delivery method.',
        'currency_mismatch' => 'Same-day rider delivery is only available in naira. Please choose another delivery method.',
        'not_configured' => 'Same-day rider delivery is currently unavailable. Please choose another delivery method.',
    ];

    public function __construct(private readonly QuoteError $reason)
    {
        parent::__construct(self::MESSAGES[$reason->value]);
    }

    public function getId(): string
    {
        return $this->getMessageKey();
    }

    public function getMessageKey(): string
    {
        return 'kmh-chowdeck-' . $this->reason->value;
    }

    public function getLevel(): int
    {
        return self::LEVEL_ERROR;
    }

    public function blockOrder(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return [];
    }

    public function getReason(): QuoteError
    {
        return $this->reason;
    }
}
