<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Client\Dto;

/**
 * Result of `POST /relay/delivery/fee`, amounts in naira. `id` is passed as
 * `fee_id` when the rider is requested; its lifetime is undocumented (spec §13.3).
 */
final class DeliveryFee
{
    public function __construct(
        public readonly int $id,
        public readonly float $totalAmount,
        public readonly float $deliveryAmount,
        public readonly float $serviceAmount,
        public readonly float $safetyFee,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ArrayReader::nullableInt($data, 'id') ?? 0,
            ArrayReader::naira($data, 'total_amount'),
            ArrayReader::naira($data, 'delivery_amount'),
            ArrayReader::naira($data, 'service_amount'),
            ArrayReader::naira($data, 'safety_fee'),
        );
    }
}
