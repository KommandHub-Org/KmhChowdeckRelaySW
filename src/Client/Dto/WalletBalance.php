<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Client\Dto;

/**
 * Result of `GET /relay/wallet/balance`, in naira. Rider deliveries are paid
 * from this wallet when they are created.
 */
final class WalletBalance
{
    public const CURRENCY = 'NGN';

    public function __construct(public readonly float $balance)
    {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(ArrayReader::naira($data, 'balance'));
    }
}
