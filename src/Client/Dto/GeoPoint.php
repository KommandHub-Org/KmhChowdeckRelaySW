<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Client\Dto;

/**
 * A latitude/longitude pair, as Chowdeck takes it in `source_address` and
 * `destination_address`.
 */
final class GeoPoint
{
    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
    ) {
    }

    /**
     * @return array{latitude: float, longitude: float}
     */
    public function toArray(): array
    {
        return ['latitude' => $this->latitude, 'longitude' => $this->longitude];
    }
}
