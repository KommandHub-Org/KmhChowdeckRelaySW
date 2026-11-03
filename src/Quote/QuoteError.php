<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Quote;

use Kommandhub\ChowdeckRelaySW\Client\ApiErrorReason;
use Kommandhub\ChowdeckRelaySW\Exception\ChowdeckApiException;

/**
 * Why a cart could not be priced for Chowdeck delivery (spec §3, §13.3). Each
 * case is a distinct cart error and storefront message.
 */
enum QuoteError: string
{
    case IncompleteAddress = 'incomplete_address';
    case InvalidAddress = 'invalid_address';
    case Unavailable = 'unavailable';
    case CurrencyMismatch = 'currency_mismatch';
    case NotConfigured = 'not_configured';

    /**
     * A failed fee request. Chowdeck refuses with 400 and a message:
     * "Vendor not found" means the key's account cannot use Relay (the
     * merchant's job); any other refusal (address not found, outside the
     * area) is about the address. Transient faults are Chowdeck being
     * unavailable, the case the fallback price exists for.
     */
    public static function fromFeeFailure(ChowdeckApiException $e): self
    {
        return match ($e->getReason()) {
            ApiErrorReason::NotConfigured, ApiErrorReason::Unauthorized => self::NotConfigured,
            ApiErrorReason::BadRequest, ApiErrorReason::NotFound, ApiErrorReason::Validation => stripos($e->getMessage(), 'vendor') !== false
                ? self::NotConfigured
                : self::InvalidAddress,
            default => self::Unavailable,
        };
    }

    /**
     * Problems only the merchant can fix; they get an admin notification.
     */
    public function notifiesAdmin(): bool
    {
        return $this === self::Unavailable || $this === self::NotConfigured;
    }
}
