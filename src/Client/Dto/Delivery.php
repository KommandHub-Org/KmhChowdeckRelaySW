<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Client\Dto;

/**
 * A Chowdeck rider delivery, as returned by `POST /relay/delivery` and
 * `GET /relay/delivery/{reference}`. Price in naira.
 *
 * Chowdeck documents two status vocabularies; the raw value is kept and
 * mapped by the plugin (spec §13.4).
 */
final class Delivery
{
    /**
     * @param array{name?: string, phone?: string} $rider
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $reference,
        public readonly string $status,
        public readonly float $price,
        public readonly string $currency,
        public readonly ?string $trackingUrl,
        public readonly ?int $deliveryPin,
        public readonly array $rider,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ArrayReader::nullableInt($data, 'id'),
            ArrayReader::string($data, 'reference'),
            ArrayReader::string($data, 'status'),
            ArrayReader::naira($data, 'delivery_price'),
            ArrayReader::nullableString($data, 'currency') ?? WalletBalance::CURRENCY,
            ArrayReader::nullableString($data, 'tracking_url'),
            ArrayReader::nullableInt($data, 'delivery_pin'),
            self::rider(ArrayReader::array($data, 'driver')),
        );
    }

    /**
     * The `driver` object has no documented shape (⚠ spec §13.9); take a name
     * and phone when they are there under the usual keys.
     *
     * @param array<mixed> $driver
     *
     * @return array{name?: string, phone?: string}
     */
    private static function rider(array $driver): array
    {
        $name = ArrayReader::nullableString($driver, 'name')
            ?? (trim(ArrayReader::string($driver, 'first_name') . ' ' . ArrayReader::string($driver, 'last_name')) ?: null);
        $phone = ArrayReader::nullableString($driver, 'phone');

        return array_filter(['name' => $name, 'phone' => $phone], static fn (?string $value): bool => $value !== null);
    }
}
