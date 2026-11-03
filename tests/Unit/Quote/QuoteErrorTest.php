<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Quote;

use Kommandhub\ChowdeckRelaySW\Client\ApiErrorReason;
use Kommandhub\ChowdeckRelaySW\Exception\ChowdeckApiException;
use Kommandhub\ChowdeckRelaySW\Quote\QuoteError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuoteError::class)]
#[UsesClass(ChowdeckApiException::class)]
#[UsesClass(ApiErrorReason::class)]
class QuoteErrorTest extends TestCase
{
    /**
     * @return iterable<string, array{ApiErrorReason, string, QuoteError}>
     */
    public static function failures(): iterable
    {
        yield 'no key' => [ApiErrorReason::NotConfigured, 'x', QuoteError::NotConfigured];
        yield 'rejected key' => [ApiErrorReason::Unauthorized, 'Invalid Key', QuoteError::NotConfigured];
        yield 'account cannot use relay' => [ApiErrorReason::BadRequest, 'Vendor not found', QuoteError::NotConfigured];
        yield 'address not found' => [ApiErrorReason::BadRequest, 'Could not find coordinates for destination address', QuoteError::InvalidAddress];
        yield 'not found' => [ApiErrorReason::NotFound, 'Not found', QuoteError::InvalidAddress];
        yield 'validation' => [ApiErrorReason::Validation, 'Bad field', QuoteError::InvalidAddress];
        yield 'timeout' => [ApiErrorReason::Timeout, 'x', QuoteError::Unavailable];
        yield 'server error' => [ApiErrorReason::ServerError, 'x', QuoteError::Unavailable];
        yield 'empty wallet' => [ApiErrorReason::InsufficientBalance, 'Insufficient balance', QuoteError::Unavailable];
    }

    #[DataProvider('failures')]
    public function testFeeFailuresMap(ApiErrorReason $reason, string $message, QuoteError $expected): void
    {
        static::assertSame($expected, QuoteError::fromFeeFailure(new ChowdeckApiException($reason, $message, 400)));
    }

    public function testOnlyMerchantProblemsNotifyTheAdmin(): void
    {
        static::assertSame(
            [QuoteError::Unavailable, QuoteError::NotConfigured],
            array_values(array_filter(QuoteError::cases(), static fn (QuoteError $e): bool => $e->notifiesAdmin())),
        );
    }
}
