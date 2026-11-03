<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Quote;

use Kommandhub\ChowdeckRelaySW\Quote\QuoteStruct;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuoteStruct::class)]
class QuoteStructTest extends TestCase
{
    public function testQuoteCarriesFeeAndPrices(): void
    {
        $quote = new QuoteStruct(39702, 4800.0, 5300.0, 'NGN', new \DateTimeImmutable('2026-11-03'));

        static::assertSame(39702, $quote->feeId);
        static::assertSame(4800.0, $quote->carrierTotal);
        static::assertSame(5300.0, $quote->price);
        static::assertFalse($quote->fallback);
    }

    public function testFallbackHasNoFee(): void
    {
        $now = new \DateTimeImmutable();
        $quote = QuoteStruct::fallback(2500.0, 'NGN', $now);

        static::assertNull($quote->feeId);
        static::assertNull($quote->carrierTotal);
        static::assertSame(2500.0, $quote->price);
        static::assertSame($now, $quote->fetchedAt);
        static::assertTrue($quote->fallback);
    }
}
