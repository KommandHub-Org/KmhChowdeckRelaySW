<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Geocoding\Service;

use Kommandhub\ChowdeckRelaySW\Client\Dto\GeoPoint;
use Kommandhub\ChowdeckRelaySW\Setting\Service\Config;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Optional address → coordinates lookup with the Google Geocoding API
 * (spec §13.3, decision 3 Nov 2026).
 *
 * Only active when the merchant switched it on **and** entered a Google Maps
 * key; otherwise every lookup returns null and the plugin sends the address
 * to Chowdeck as text. A lookup never breaks checkout: no result, an
 * imprecise result or a Google failure all return null (failures are logged).
 *
 * Results are cached per address for 30 days and misses for one hour, because
 * every Google call costs the merchant money.
 */
class Geocoder
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    private const HIT_TTL = 2592000;

    private const MISS_TTL = 3600;

    /**
     * Runs inside cart calculation, so it must give up quickly.
     */
    private const HTTP_OPTIONS = ['timeout' => 3, 'max_duration' => 5];

    /**
     * Statuses that keep failing until the merchant fixes the key or billing.
     */
    private const FATAL_STATUSES = ['REQUEST_DENIED', 'OVER_DAILY_LIMIT', 'INVALID_REQUEST'];

    public function __construct(
        private readonly Config $config,
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isEnabled(?string $salesChannelId = null): bool
    {
        return $this->config->getBool('geocodingEnabled', $salesChannelId) && $this->apiKey($salesChannelId) !== '';
    }

    /**
     * The pickup point: the merchant's coordinates when both are entered,
     * otherwise the geocoded pickup address (or null).
     */
    public function pickup(?string $salesChannelId = null): ?GeoPoint
    {
        $latitude = $this->config->get('pickupLatitude', null, $salesChannelId);
        $longitude = $this->config->get('pickupLongitude', null, $salesChannelId);

        if (is_numeric($latitude) && is_numeric($longitude)) {
            return new GeoPoint((float)$latitude, (float)$longitude);
        }

        return $this->geocode($this->config->getString('pickupAddress', $salesChannelId), $salesChannelId);
    }

    public function geocode(string $address, ?string $salesChannelId = null): ?GeoPoint
    {
        $address = trim((string)preg_replace('/\s+/', ' ', $address));

        if ($address === '' || !$this->isEnabled($salesChannelId)) {
            return null;
        }

        $cached = $this->cache->get(
            'kmh_cd_geo_' . hash('sha256', mb_strtolower($address)),
            function (ItemInterface $item) use ($address, $salesChannelId): ?array {
                try {
                    $point = $this->lookup($address, $salesChannelId);
                } catch (\RuntimeException $e) {
                    // Not cached: a transient failure must not hide the address for an hour.
                    $item->expiresAfter(0);
                    $this->logger->warning('Google geocoding failed; the address is sent to Chowdeck as text', ['error' => $e->getMessage()]);

                    return null;
                }

                $item->expiresAfter($point === null ? self::MISS_TTL : self::HIT_TTL);

                return $point?->toArray();
            },
        );

        return \is_array($cached) ? new GeoPoint((float)$cached['latitude'], (float)$cached['longitude']) : null;
    }

    /**
     * @throws \RuntimeException on transport failure or a Google error status
     */
    private function lookup(string $address, ?string $salesChannelId): ?GeoPoint
    {
        try {
            $data = $this->httpClient->request('GET', self::ENDPOINT, self::HTTP_OPTIONS + ['query' => [
                'address' => $address,
                'region' => 'ng',
                'key' => $this->apiKey($salesChannelId),
            ]])->toArray();
        } catch (\Throwable $e) {
            // Never pass the message on: Symfony puts the full URL, key included, into it.
            throw new \RuntimeException('Google Geocoding could not be reached (' . $e::class . ').', 0);
        }

        $status = \is_string($data['status'] ?? null) ? $data['status'] : 'UNKNOWN';

        if ($status === 'ZERO_RESULTS') {
            return null;
        }

        if ($status !== 'OK') {
            if (\in_array($status, self::FATAL_STATUSES, true)) {
                $this->logger->error('Google Geocoding refused the request; check the Google Maps key and billing', ['status' => $status]);
            }

            throw new \RuntimeException('Google Geocoding status ' . $status);
        }

        $result = \is_array($data['results'] ?? null) && \is_array($data['results'][0] ?? null) ? $data['results'][0] : [];
        $geometry = \is_array($result['geometry'] ?? null) ? $result['geometry'] : [];

        // An APPROXIMATE result is the centre of an area (city, state), which
        // would price the delivery to the wrong place; let Chowdeck read the text.
        if (($geometry['location_type'] ?? null) === 'APPROXIMATE') {
            return null;
        }

        $location = \is_array($geometry['location'] ?? null) ? $geometry['location'] : [];

        if (!is_numeric($location['lat'] ?? null) || !is_numeric($location['lng'] ?? null)) {
            throw new \RuntimeException('Google Geocoding answered without coordinates');
        }

        return new GeoPoint((float)$location['lat'], (float)$location['lng']);
    }

    private function apiKey(?string $salesChannelId): string
    {
        return $this->config->getString('googleMapsApiKey', $salesChannelId);
    }
}
