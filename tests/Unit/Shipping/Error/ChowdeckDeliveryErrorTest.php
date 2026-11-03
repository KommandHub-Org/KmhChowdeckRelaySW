<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Shipping\Error;

use Kommandhub\ChowdeckRelaySW\Quote\QuoteError;
use Kommandhub\ChowdeckRelaySW\Shipping\Error\ChowdeckDeliveryError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChowdeckDeliveryError::class)]
#[UsesClass(QuoteError::class)]
class ChowdeckDeliveryErrorTest extends TestCase
{
    public function testEveryReasonIsABlockingErrorWithItsOwnKey(): void
    {
        foreach (QuoteError::cases() as $reason) {
            $error = new ChowdeckDeliveryError($reason);

            static::assertSame('kmh-chowdeck-' . $reason->value, $error->getId());
            static::assertSame($error->getId(), $error->getMessageKey());
            static::assertSame(ChowdeckDeliveryError::LEVEL_ERROR, $error->getLevel());
            static::assertTrue($error->blockOrder());
            static::assertSame([], $error->getParameters());
            static::assertSame($reason, $error->getReason());
            static::assertNotSame('', $error->getMessage());
        }
    }

    /**
     * The API fallback text must say the same as the storefront snippet.
     */
    public function testMessagesMatchTheEnglishSnippets(): void
    {
        $snippets = json_decode((string)file_get_contents(__DIR__ . '/../../../../src/Resources/snippet/en_GB/storefront.en-GB.json'), true);
        \assert(\is_array($snippets) && \is_array($snippets['checkout']));

        foreach (QuoteError::cases() as $reason) {
            static::assertSame($snippets['checkout']['kmh-chowdeck-' . $reason->value], (new ChowdeckDeliveryError($reason))->getMessage());
        }
    }
}
