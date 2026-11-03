<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Client\Dto;

/**
 * Result of `POST /relay/delivery/{reference}/cancel`. `refundAmount` (naira)
 * goes back to the merchant's wallet.
 */
final class CancelledDelivery
{
    public function __construct(
        public readonly string $reference,
        public readonly string $status,
        public readonly float $refundAmount,
        public readonly string $currency,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ArrayReader::string($data, 'reference'),
            ArrayReader::string($data, 'status'),
            ArrayReader::naira($data, 'refund_amount'),
            ArrayReader::nullableString($data, 'currency') ?? WalletBalance::CURRENCY,
        );
    }
}
