<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Client\Dto;

/**
 * Defensive reads from decoded Chowdeck JSON.
 *
 * The API reference leaves many fields untyped (spec §13.2), so every DTO
 * reads through these instead of trusting a shape.
 */
final class ArrayReader
{
    /**
     * @param array<mixed> $data
     */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) || is_int($value) || is_float($value) ? (string)$value : '';
    }

    /**
     * @param array<mixed> $data
     */
    public static function nullableString(array $data, string $key): ?string
    {
        $value = self::string($data, $key);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<mixed> $data
     */
    public static function float(array $data, string $key): float
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (float)$value : 0.0;
    }

    /**
     * @param array<mixed> $data
     */
    public static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int)$value : null;
    }

    /**
     * Chowdeck sends money in kobo; the plugin works in naira.
     *
     * @param array<mixed> $data
     */
    public static function naira(array $data, string $key): float
    {
        return self::float($data, $key) / 100;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    public static function array(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }
}
