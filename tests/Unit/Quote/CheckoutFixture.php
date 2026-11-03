<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Quote;

use Kommandhub\ChowdeckRelaySW\Shipping\ChowdeckShippingMethod;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryInformation;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Defaults;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Test\Generator;

/**
 * A Lagos customer and cart for quote, processor and subscriber tests.
 */
final class CheckoutFixture
{
    public static function context(
        string $phone = '+2348031234567',
        string $currency = 'NGN',
        string $technicalName = ChowdeckShippingMethod::TECHNICAL_NAME,
        bool $withCustomer = true,
        string $street = '15 Awolowo Road',
    ): SalesChannelContext {
        $country = new CountryEntity();
        $country->setId('country-ng');
        $country->setIso('NG');

        $state = new CountryStateEntity();
        $state->setId('state-la');
        $state->setName('Lagos');

        $address = new CustomerAddressEntity();
        $address->setId('address-1');
        $address->setFirstName('Ada');
        $address->setLastName('Obi');
        $address->setStreet($street);
        $address->setCity('Lagos');
        $address->setZipcode('101233');
        $address->setPhoneNumber($phone);
        $address->setCountryId('country-ng');
        $address->setCountry($country);
        $address->setCountryState($state);

        $customer = new CustomerEntity();
        $customer->setId('customer-1');
        // Generator always adds a customer, so "no customer" is modelled as one without an email.
        $customer->setEmail($withCustomer ? 'ada@example.com' : '');

        $currencyEntity = new CurrencyEntity();
        $currencyEntity->setId(Defaults::CURRENCY);
        $currencyEntity->setIsoCode($currency);
        $currencyEntity->setFactor(1.0);

        $shippingMethod = new ShippingMethodEntity();
        $shippingMethod->setId('shipping-method-1');
        $shippingMethod->setTechnicalName($technicalName);
        $shippingMethod->setTaxType(ShippingMethodEntity::TAX_TYPE_AUTO);

        return Generator::generateSalesChannelContext(
            currency: $currencyEntity,
            shippingMethod: $shippingMethod,
            shippingLocation: ShippingLocation::createFromAddress($address),
            customer: $customer,
        );
    }

    /**
     * @param array<string, ?float> $weights product id => unit weight (null = none)
     */
    public static function cart(array $weights = ['p1' => 1.5], int $quantity = 2): Cart
    {
        $cart = new Cart('token');

        foreach ($weights as $id => $weight) {
            $lineItem = new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE, $id, $quantity);
            $lineItem->setLabel('Product ' . $id);
            $lineItem->setPayload(['productNumber' => 'SW-' . $id]);
            $lineItem->setPrice(new CalculatedPrice(5000, 5000 * $quantity, new CalculatedTaxCollection(), new TaxRuleCollection(), $quantity));
            $lineItem->setDeliveryInformation(new DeliveryInformation(100, $weight, false));
            $cart->add($lineItem);
        }

        return $cart;
    }
}
