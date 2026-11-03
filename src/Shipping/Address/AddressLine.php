<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Shipping\Address;

/**
 * Builds the one-line customer address sent to Chowdeck and to the optional
 * geocoder (spec §13.3).
 */
final class AddressLine
{
    /**
     * @param list<string> $parts street, city, state, … in that order; empty parts are skipped
     */
    public static function build(array $parts, string $country): string
    {
        $parts[] = self::countryName($country);

        return implode(', ', array_filter(array_map(trim(...), $parts), static fn (string $part): bool => $part !== ''));
    }

    /**
     * Geocoders read "Nigeria" more reliably than the ISO code "NG". Anything
     * that is not a two-letter code is passed on.
     */
    public static function countryName(string $country): string
    {
        if (preg_match('/^[A-Za-z]{2}$/', $country) !== 1) {
            return $country;
        }

        return \Locale::getDisplayRegion('-' . $country, 'en') ?: $country;
    }
}
