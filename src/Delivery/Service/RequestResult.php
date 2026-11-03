<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Delivery\Service;

/**
 * Outcome of "Request rider": requested, or a changed Chowdeck price the
 * merchant has to confirm first (spec §13.5). The customer's charged price
 * never changes.
 */
final class RequestResult
{
    private function __construct(
        public readonly bool $requested,
        public readonly ?float $oldPrice = null,
        public readonly ?float $newPrice = null,
        public readonly ?string $currency = null,
    ) {
    }

    public static function requested(): self
    {
        return new self(true);
    }

    public static function confirmationNeeded(?float $oldPrice, float $newPrice, string $currency): self
    {
        return new self(false, $oldPrice, $newPrice, $currency);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'requested' => $this->requested,
            'oldPrice' => $this->oldPrice,
            'newPrice' => $this->newPrice,
            'currency' => $this->currency,
        ];
    }
}
