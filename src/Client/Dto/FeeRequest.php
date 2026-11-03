<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Client\Dto;

/**
 * Body of `POST /relay/delivery/fee`. Both addresses go as one-line strings,
 * so no geocoding is needed; pickup coordinates are added when the merchant
 * entered them (spec §13.3). `declaredValue` (naira) buys Chowdeck's loss and
 * damage cover and is only sent when set.
 */
final class FeeRequest
{
    public function __construct(
        public readonly string $pickupAddress,
        public readonly string $destinationAddress,
        public readonly ?float $pickupLatitude = null,
        public readonly ?float $pickupLongitude = null,
        public readonly ?float $declaredValue = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = [
            'source_address_string' => $this->pickupAddress,
            'destination_address_string' => $this->destinationAddress,
        ];

        if ($this->pickupLatitude !== null && $this->pickupLongitude !== null) {
            $body['source_address'] = ['latitude' => $this->pickupLatitude, 'longitude' => $this->pickupLongitude];
        }

        if ($this->declaredValue !== null) {
            $body['estimated_order_amount'] = (int)round($this->declaredValue * 100);
        }

        return $body;
    }
}
