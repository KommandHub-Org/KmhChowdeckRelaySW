<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Quote;

use Kommandhub\ChowdeckRelaySW\Client\ChowdeckClient;
use Kommandhub\ChowdeckRelaySW\Client\Dto\DeliveryFee;
use Kommandhub\ChowdeckRelaySW\Client\Dto\FeeRequest;
use Kommandhub\ChowdeckRelaySW\Client\Dto\WalletBalance;
use Kommandhub\ChowdeckRelaySW\Exception\ChowdeckApiException;
use Kommandhub\ChowdeckRelaySW\Geocoding\Service\Geocoder;
use Kommandhub\ChowdeckRelaySW\Setting\Service\Config;
use Kommandhub\ChowdeckRelaySW\Shipping\Address\AddressLine;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Prices Chowdeck rider delivery for a cart (spec §13.3).
 *
 * Fees are cached per pickup, customer address and declared value, so
 * reloading checkout never calls Chowdeck (or Google) again, while changing
 * the address re-quotes. Failures are never cached.
 */
class QuoteService
{
    public function __construct(
        private readonly Config $config,
        private readonly ChowdeckClient $client,
        private readonly Geocoder $geocoder,
        private readonly FailureNotifier $failureNotifier,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function quote(Cart $cart, SalesChannelContext $context): QuoteStruct|QuoteError
    {
        $salesChannelId = $context->getSalesChannelId();

        try {
            $result = $this->fee($cart, $context, $salesChannelId);
        } catch (ChowdeckApiException $e) {
            $result = QuoteError::fromFeeFailure($e);
            $failure = ['httpStatus' => $e->getHttpStatus(), 'message' => $e->getMessage()];
        }

        if ($result instanceof QuoteError) {
            $this->logger->log($result->notifiesAdmin() ? 'error' : 'notice', 'Chowdeck delivery could not be quoted.', [
                'reason' => $result->value,
                'salesChannelId' => $salesChannelId,
            ] + ($failure ?? []));
            $this->failureNotifier->notify($result, $salesChannelId, $context->getContext());
        }

        return $result;
    }

    /**
     * A fresh fee for a placed order, never from the cache: the rider is
     * requested hours after checkout (spec §13.5). Needs the order's
     * `lineItems` and `currency` and the address's `country` / `countryState`.
     *
     * @throws ChowdeckApiException when Chowdeck refuses
     */
    public function feeForOrder(OrderEntity $order, OrderAddressEntity $address): DeliveryFee|QuoteError
    {
        $salesChannelId = $order->getSalesChannelId();

        if ($order->getCurrency()?->getIsoCode() !== WalletBalance::CURRENCY) {
            return QuoteError::CurrencyMismatch;
        }

        $pickupAddress = trim($this->config->getString('pickupAddress', $salesChannelId));

        if ($pickupAddress === '' || $this->config->getApiKey($salesChannelId) === '') {
            return QuoteError::NotConfigured;
        }

        $destination = self::destinationLine(
            $address->getStreet(),
            (string)$address->getAdditionalAddressLine1(),
            $address->getCity(),
            (string)$address->getCountryState()?->getName(),
            (string)$address->getZipcode(),
            (string)$address->getCountry()?->getIso(),
            (string)$address->getPhoneNumber(),
        );

        if ($destination === null) {
            return QuoteError::IncompleteAddress;
        }

        return $this->requestFee($pickupAddress, $destination, $this->declaredValue($order), $salesChannelId);
    }

    /**
     * The order's product total when the merchant declares order values
     * (Chowdeck's loss and damage cover), otherwise null.
     */
    public function declaredValue(OrderEntity $order): ?float
    {
        if (!$this->config->getBool('insureParcels', $order->getSalesChannelId())) {
            return null;
        }

        $total = 0.0;

        foreach ($order->getLineItems()?->filterByType(LineItem::PRODUCT_LINE_ITEM_TYPE) ?? [] as $lineItem) {
            $total += $lineItem->getTotalPrice();
        }

        return $total;
    }

    /**
     * @throws ChowdeckApiException
     */
    private function fee(Cart $cart, SalesChannelContext $context, string $salesChannelId): QuoteStruct|QuoteError
    {
        // Chowdeck prices in naira and returns no currency to convert from.
        if ($context->getCurrency()->getIsoCode() !== WalletBalance::CURRENCY) {
            return QuoteError::CurrencyMismatch;
        }

        $pickupAddress = trim($this->config->getString('pickupAddress', $salesChannelId));

        if ($pickupAddress === '' || $this->config->getApiKey($salesChannelId) === '') {
            return QuoteError::NotConfigured;
        }

        $destination = $this->destination($context);

        if ($destination === null) {
            return QuoteError::IncompleteAddress;
        }

        $declaredValue = $this->config->getBool('insureParcels', $salesChannelId)
            ? $cart->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE)->getPrices()->sum()->getTotalPrice()
            : null;

        $key = 'kmh_cd_fee_' . $salesChannelId . '_' . hash('sha256', implode('|', [
            $this->config->getApiKey($salesChannelId),
            $pickupAddress,
            (string)$this->config->getFloat('pickupLatitude', 0.0, $salesChannelId),
            (string)$this->config->getFloat('pickupLongitude', 0.0, $salesChannelId),
            $this->geocoder->isEnabled($salesChannelId) ? 'geo' : 'text',
            $destination,
            (string)$declaredValue,
        ]));

        /** @var array{fee: DeliveryFee, fetchedAt: \DateTimeImmutable} $cached */
        $cached = $this->cache->get($key, function (ItemInterface $item) use ($pickupAddress, $destination, $declaredValue, $salesChannelId): array {
            $item->expiresAfter($this->config->getInt('quoteCacheTtl', 600, $salesChannelId));

            return ['fee' => $this->requestFee($pickupAddress, $destination, $declaredValue, $salesChannelId), 'fetchedAt' => new \DateTimeImmutable()];
        });

        $fee = $cached['fee'];

        return new QuoteStruct($fee->id, $fee->totalAmount, $this->withMarkup($fee->totalAmount, $salesChannelId), WalletBalance::CURRENCY, $cached['fetchedAt']);
    }

    /**
     * Text plus the coordinates known for each side (spec §13.3).
     *
     * @throws ChowdeckApiException
     */
    private function requestFee(string $pickupAddress, string $destination, ?float $declaredValue, string $salesChannelId): DeliveryFee
    {
        return $this->client->deliveries()->fee(new FeeRequest(
            $pickupAddress,
            $destination,
            $this->geocoder->pickup($salesChannelId),
            $this->geocoder->geocode($destination, $salesChannelId),
            $declaredValue,
        ), $salesChannelId);
    }

    private function destination(SalesChannelContext $context): ?string
    {
        $address = $context->getShippingLocation()->getAddress();

        if ($address === null) {
            return null;
        }

        return self::destinationLine(
            $address->getStreet(),
            (string)$address->getAdditionalAddressLine1(),
            $address->getCity(),
            (string)$address->getCountryState()?->getName(),
            (string)$address->getZipcode(),
            (string)$address->getCountry()?->getIso(),
            (string)$address->getPhoneNumber(),
        );
    }

    /**
     * The customer's address as one line, or null when the rider could not
     * find or call the customer (street, city or phone missing).
     */
    private static function destinationLine(string $street, string $additional, string $city, string $state, string $zip, string $country, string $phone): ?string
    {
        if (trim($street) === '' || trim($city) === '' || trim($phone) === '') {
            return null;
        }

        return AddressLine::build([$street, $additional, $city, $state, $zip], $country);
    }

    private function withMarkup(float $total, string $salesChannelId): float
    {
        $value = $this->config->getFloat('markupValue', 0.0, $salesChannelId);

        return match ($this->config->getString('markupType', $salesChannelId)) {
            'fixed' => $total + $value,
            'percent' => $total * (1 + $value / 100),
            default => $total,
        };
    }
}
