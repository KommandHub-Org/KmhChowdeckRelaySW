<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Shipping\Address;

use Kommandhub\ChowdeckRelaySW\Shipping\Address\AddressLine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddressLine::class)]
class AddressLineTest extends TestCase
{
    public function testJoinsPartsAndNamesTheCountry(): void
    {
        static::assertSame('1 Allen Ave, Ikeja, Lagos, Nigeria', AddressLine::build([' 1 Allen Ave ', '', 'Ikeja', 'Lagos'], 'ng'));
    }

    public function testCountryNameIsPassedThrough(): void
    {
        static::assertSame('Ghana', AddressLine::countryName('Ghana'));
    }

    public function testUnknownCodeIsPassedThrough(): void
    {
        static::assertSame('XX', AddressLine::countryName('XX'));
    }
}
