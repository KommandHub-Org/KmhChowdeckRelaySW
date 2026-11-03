<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Client\Dto;

/**
 * Body of `POST /relay/delivery/fee`.
 *
 * Both addresses always go as one-line strings; coordinates are added for a
 * side when they are known (merchant-entered pickup, or the plugin's own
 * geocoding). Chowdeck prices from the coordinates when both are sent, and
 * its sandbox cannot geocode text at all (spec §13.3). `declaredValue` (naira)
 * buys Chowdeck's loss and damage cover and is only sent when set.
 */
final class FeeRequest
{
    public function __construct(
        public readonly string $pickupAddress,
        public readonly string $destinationAddress,
        public readonly ?GeoPoint $pickup = null,
        public readonly ?GeoPoint $destination = null,
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

        if ($this->pickup !== null) {
            $body['source_address'] = $this->pickup->toArray();
        }

        if ($this->destination !== null) {
            $body['destination_address'] = $this->destination->toArray();
        }

        if ($this->declaredValue !== null) {
            $body['estimated_order_amount'] = (int)round($this->declaredValue * 100);
        }

        return $body;
    }
}
