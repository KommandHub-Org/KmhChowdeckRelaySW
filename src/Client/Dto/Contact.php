<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Client\Dto;

/**
 * Sender or recipient of a rider delivery (`source_contact` /
 * `destination_contact`). The rider calls this phone number.
 */
final class Contact
{
    public function __construct(
        public readonly string $name,
        public readonly string $phone,
        public readonly ?string $email = null,
        public readonly string $countryCode = 'NG',
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'country_code' => $this->countryCode,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }
}
