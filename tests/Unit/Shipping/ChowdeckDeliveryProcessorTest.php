<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Shipping;

use Kommandhub\ChowdeckRelaySW\Quote\QuoteError;
use Kommandhub\ChowdeckRelaySW\Quote\QuoteService;
use Kommandhub\ChowdeckRelaySW\Quote\QuoteStruct;
use Kommandhub\ChowdeckRelaySW\Setting\Service\Config;
use Kommandhub\ChowdeckRelaySW\Shipping\ChowdeckDeliveryProcessor;
use Kommandhub\ChowdeckRelaySW\Shipping\Error\ChowdeckDeliveryError;
use Kommandhub\ChowdeckRelaySW\Tests\Unit\Client\ClientFactory;
use Kommandhub\ChowdeckRelaySW\Tests\Unit\Quote\CheckoutFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\Price\CashRounding;
use Shopware\Core\Checkout\Cart\Price\GrossPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\NetPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Tax\PercentageTaxRuleBuilder;
use Shopware\Core\Checkout\Cart\Tax\TaxCalculator;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\Tax\Aggregate\TaxRule\TaxRuleCollection;
use Shopware\Core\Test\Generator;

#[CoversClass(ChowdeckDeliveryProcessor::class)]
#[UsesClass(Config::class)]
#[UsesClass(QuoteStruct::class)]
#[UsesClass(QuoteError::class)]
#[UsesClass(ChowdeckDeliveryError::class)]
class ChowdeckDeliveryProcessorTest extends TestCase
{
    public function testQuotedPriceBecomesTheShippingCost(): void
    {
        $cart = $this->process($this->quote());
        $delivery = $cart->getDeliveries()->first();

        static::assertNotNull($delivery);
        static::assertSame(5300.0, $delivery->getShippingCosts()->getTotalPrice());
        $quote = $delivery->getExtension(QuoteStruct::EXTENSION_KEY);
        static::assertInstanceOf(QuoteStruct::class, $quote);
        static::assertSame(39702, $quote->feeId);
        static::assertCount(0, $cart->getErrors());
    }

    public function testErrorBlocksTheOrder(): void
    {
        $cart = $this->process(QuoteError::InvalidAddress);

        static::assertTrue($cart->getErrors()->blockOrder());
        static::assertSame('kmh-chowdeck-invalid_address', $cart->getErrors()->first()?->getId());
        static::assertNull($cart->getDeliveries()->first()?->getExtension(QuoteStruct::EXTENSION_KEY));
    }

    public function testUnavailableWithoutFallbackPriceBlocks(): void
    {
        static::assertTrue($this->process(QuoteError::Unavailable)->getErrors()->blockOrder());
    }

    public function testUnavailableWithFallbackPriceChargesIt(): void
    {
        $cart = $this->process(QuoteError::Unavailable, ['fallbackPrice' => 2500.0]);
        $delivery = $cart->getDeliveries()->first();

        static::assertCount(0, $cart->getErrors());
        static::assertSame(2500.0, $delivery?->getShippingCosts()->getTotalPrice());
        $quote = $delivery->getExtension(QuoteStruct::EXTENSION_KEY);
        static::assertInstanceOf(QuoteStruct::class, $quote);
        static::assertTrue($quote->fallback);
        static::assertNull($quote->feeId);
    }

    public function testFallbackPriceDoesNotHideConfigurationErrors(): void
    {
        static::assertTrue($this->process(QuoteError::NotConfigured, ['fallbackPrice' => 2500.0])->getErrors()->blockOrder());
    }

    public function testOtherShippingMethodsAreLeftAlone(): void
    {
        $quoteService = $this->createMock(QuoteService::class);
        $quoteService->expects(static::never())->method('quote');
        $cart = Generator::createCartWithDelivery();

        $this->processor($quoteService)->process(new CartDataCollection(), $cart, $cart, CheckoutFixture::context(technicalName: 'other'), new CartBehavior());

        static::assertSame(10.0, $cart->getDeliveries()->first()?->getShippingCosts()->getTotalPrice());
    }

    public function testAdminRecalculationAndEmptyCartsAreLeftAlone(): void
    {
        $quoteService = $this->createMock(QuoteService::class);
        $quoteService->expects(static::never())->method('quote');
        $processor = $this->processor($quoteService);

        $cart = Generator::createCartWithDelivery();
        $processor->process(new CartDataCollection(), $cart, $cart, CheckoutFixture::context(), new CartBehavior([ChowdeckDeliveryProcessor::SKIP_DELIVERY_PRICE_RECALCULATION => true]));

        $empty = new Cart('empty');
        $processor->process(new CartDataCollection(), $empty, $empty, CheckoutFixture::context(), new CartBehavior());

        static::assertSame(10.0, $cart->getDeliveries()->first()?->getShippingCosts()->getTotalPrice());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function taxTypes(): iterable
    {
        yield 'auto' => [ShippingMethodEntity::TAX_TYPE_AUTO, false];
        yield 'highest' => [ShippingMethodEntity::TAX_TYPE_HIGHEST, false];
        yield 'fixed without tax' => [ShippingMethodEntity::TAX_TYPE_FIXED, false];
        yield 'fixed with tax' => [ShippingMethodEntity::TAX_TYPE_FIXED, true];
    }

    #[DataProvider('taxTypes')]
    public function testEveryTaxTypeKeepsTheGrossPrice(string $taxType, bool $withTax): void
    {
        $context = CheckoutFixture::context();
        $context->getShippingMethod()->setTaxType($taxType);

        if ($withTax) {
            $tax = $context->getTaxRules()->first();
            static::assertNotNull($tax);
            $tax->setRules(new TaxRuleCollection());
            $context->getShippingMethod()->setTaxId($tax->getId());
        }

        static::assertSame(5300.0, $this->process($this->quote(), [], $context)->getDeliveries()->first()?->getShippingCosts()->getTotalPrice());
    }

    private function quote(): QuoteStruct
    {
        return new QuoteStruct(39702, 5300.0, 5300.0, 'NGN', new \DateTimeImmutable());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function process(QuoteStruct|QuoteError $result, array $config = [], ?SalesChannelContext $context = null): Cart
    {
        $quoteService = $this->createMock(QuoteService::class);
        $quoteService->method('quote')->willReturn($result);
        $cart = Generator::createCartWithDelivery();

        $this->processor($quoteService, $config)
            ->process(new CartDataCollection(), $cart, $cart, $context ?? CheckoutFixture::context(), new CartBehavior());

        return $cart;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function processor(QuoteService $quoteService, array $config = []): ChowdeckDeliveryProcessor
    {
        $rounding = new CashRounding();

        return new ChowdeckDeliveryProcessor(
            $quoteService,
            ClientFactory::config($config),
            new QuantityPriceCalculator(new GrossPriceCalculator(new TaxCalculator(), $rounding), new NetPriceCalculator(new TaxCalculator(), $rounding)),
            new PercentageTaxRuleBuilder(),
        );
    }
}
