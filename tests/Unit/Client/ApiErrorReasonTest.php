<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Client;

use Kommandhub\ChowdeckRelaySW\Client\ApiErrorReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiErrorReason::class)]
class ApiErrorReasonTest extends TestCase
{
    public function testUnusualStatuses(): void
    {
        static::assertSame(ApiErrorReason::Unauthorized, ApiErrorReason::fromHttpStatus(403));
        static::assertSame(ApiErrorReason::BadRequest, ApiErrorReason::fromHttpStatus(409));
        static::assertSame(ApiErrorReason::InsufficientBalance, ApiErrorReason::fromHttpStatus(400, 'Insufficient funds in wallet'));
    }

    public function testTransientReasons(): void
    {
        foreach (ApiErrorReason::cases() as $reason) {
            $expected = \in_array($reason, [
                ApiErrorReason::RateLimited,
                ApiErrorReason::ServerError,
                ApiErrorReason::Timeout,
                ApiErrorReason::Network,
            ], true);

            static::assertSame($expected, $reason->isTransient(), $reason->value);
        }
    }
}
