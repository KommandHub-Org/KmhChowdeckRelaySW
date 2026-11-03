<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Shipping;

use Kommandhub\ChowdeckRelaySW\Quote\QuoteError;
use Kommandhub\ChowdeckRelaySW\Quote\QuoteService;
use Kommandhub\ChowdeckRelaySW\Quote\QuoteStruct;
use Kommandhub\ChowdeckRelaySW\Setting\Service\Config;
use Kommandhub\ChowdeckRelaySW\Shipping\Error\ChowdeckDeliveryError;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\PercentageTaxRuleBuilder;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Sets the Chowdeck price as the shipping cost, only when the Chowdeck method
 * is selected (spec §13.3).
 *
 * Runs after the core delivery processor (-5000), so the deliveries already
 * exist and only their cost is replaced. Every cart change re-runs it; the
 * fee cache keeps that from calling Chowdeck again for an unchanged address.
 */
class ChowdeckDeliveryProcessor implements CartProcessorInterface
{
    /**
     * Set when the Administration recalculates a placed order: the charged
     * shipping price must not change then.
     */
    public const SKIP_DELIVERY_PRICE_RECALCULATION = 'skipDeliveryPriceRecalculation';

    public function __construct(
        private readonly QuoteService $quoteService,
        private readonly Config $config,
        private readonly QuantityPriceCalculator $priceCalculator,
        private readonly PercentageTaxRuleBuilder $taxRuleBuilder,
    ) {
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $shippingMethod = $context->getShippingMethod();

        if ($shippingMethod->getTechnicalName() !== ChowdeckShippingMethod::TECHNICAL_NAME
            || $behavior->hasPermission(self::SKIP_DELIVERY_PRICE_RECALCULATION)
            || $toCalculate->getDeliveries()->count() === 0
        ) {
            return;
        }

        $quote = $this->quoteService->quote($toCalculate, $context);

        if ($quote instanceof QuoteError) {
            $fallbackPrice = $this->config->getFloat('fallbackPrice', 0.0, $context->getSalesChannelId());

            if ($quote !== QuoteError::Unavailable || $fallbackPrice <= 0) {
                $toCalculate->addErrors(new ChowdeckDeliveryError($quote));

                return;
            }

            $quote = QuoteStruct::fallback($fallbackPrice, $context->getCurrency()->getIsoCode(), new \DateTimeImmutable());
        }

        $shippingCosts = $this->buildPrice($quote->price, $shippingMethod, $toCalculate, $context);

        foreach ($toCalculate->getDeliveries() as $delivery) {
            $delivery->setShippingCosts($shippingCosts);
            $delivery->addExtension(QuoteStruct::EXTENSION_KEY, $quote);
        }
    }

    /**
     * The Chowdeck total is what the merchant pays, so it is used as the
     * gross price and taxed by the shipping method's tax type, like the core
     * delivery processor does.
     */
    private function buildPrice(float $price, ShippingMethodEntity $shippingMethod, Cart $cart, SalesChannelContext $context): CalculatedPrice
    {
        $lineItemPrices = $cart->getLineItems()->getPrices();

        $rules = match ($shippingMethod->getTaxType()) {
            ShippingMethodEntity::TAX_TYPE_HIGHEST => $lineItemPrices->getHighestTaxRule(),
            ShippingMethodEntity::TAX_TYPE_FIXED => $shippingMethod->getTaxId() !== null
                ? $context->buildTaxRules($shippingMethod->getTaxId())
                : $this->taxRuleBuilder->buildCollectionRules($lineItemPrices->getCalculatedTaxes(), $lineItemPrices->getTotalPriceAmount()),
            default => $this->taxRuleBuilder->buildCollectionRules($lineItemPrices->getCalculatedTaxes(), $lineItemPrices->getTotalPriceAmount()),
        };

        return $this->priceCalculator->calculate(new QuantityPriceDefinition($price, $rules, 1), $context);
    }
}
