<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Quote;

use Shopware\Core\Framework\Struct\Struct;

/**
 * A Chowdeck price for one cart, attached to the cart delivery as
 * `kmhChowdeckQuote` and persisted on the order by the cart conversion.
 *
 * A fallback quote (Chowdeck unavailable, merchant fallback price) has no fee
 * id and no Chowdeck price.
 */
final class QuoteStruct extends Struct
{
    public const EXTENSION_KEY = 'kmhChowdeckQuote';

    public function __construct(
        public readonly ?int $feeId,
        public readonly ?float $carrierTotal,
        public readonly float $price,
        public readonly string $currency,
        public readonly \DateTimeImmutable $fetchedAt,
        public readonly bool $fallback = false,
    ) {
    }

    public static function fallback(float $price, string $currency, \DateTimeImmutable $now): self
    {
        return new self(null, null, $price, $currency, $now, true);
    }
}
