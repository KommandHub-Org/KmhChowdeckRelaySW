<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Client\Http;

use Kommandhub\ChowdeckRelaySW\Exception\ChowdeckApiException;
use Kommandhub\ChowdeckRelaySW\Setting\Service\Config;
use Symfony\Contracts\HttpClient\HttpClientInterface as SymfonyHttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Transport for the Chowdeck REST API.
 *
 * The only class in the plugin that knows the base URL and how the API key is
 * presented. The key decides the environment: a test key (`sk_test_…`) only
 * works against Chowdeck's sandbox host, so no separate mode setting is needed
 * (spec §13.2).
 *
 * Credentials are resolved per request rather than per instance: they are
 * sales-channel scoped settings, and this service is shared across sales
 * channels within one request.
 *
 * Symfony responses are lazy: transport faults (timeouts, DNS, TLS) surface
 * when the response is read, which happens in ApiResource::response().
 */
class ChowdeckHttpClient implements HttpClientInterface
{
    /**
     * The OpenAPI host. The Relay guides show api.relay.chowdeck.com, which
     * does not resolve (checked 3 Nov 2026, spec §13.2).
     */
    private const BASE_URL_LIVE = 'https://api.chowdeck.com';

    /**
     * Sandbox behind console.chowdeck.com; the live host answers a test key
     * with "Vendor not found" (checked 3 Nov 2026).
     */
    private const BASE_URL_SANDBOX = 'https://console-api.chowdeck.com';

    private const TEST_KEY_PREFIX = 'sk_test_';

    /**
     * Checkout waits on these calls, so they must give up quickly (spec §13.2).
     */
    private const TIMEOUT = 3;

    private const MAX_DURATION = 5;

    public function __construct(
        private readonly Config $config,
        private readonly SymfonyHttpClientInterface $client,
    ) {
    }

    public function get(string $endpoint, array $queryParams = [], ?string $salesChannelId = null): ResponseInterface
    {
        return $this->request('GET', $endpoint, ['query' => $queryParams], $salesChannelId);
    }

    public function post(string $endpoint, array $payload = [], ?string $salesChannelId = null): ResponseInterface
    {
        return $this->request('POST', $endpoint, ['json' => $payload], $salesChannelId);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws ChowdeckApiException
     */
    private function request(string $method, string $endpoint, array $options, ?string $salesChannelId): ResponseInterface
    {
        $apiKey = $this->config->getApiKey($salesChannelId);

        if ($apiKey === '') {
            throw ChowdeckApiException::notConfigured();
        }

        $options['headers'] = [
            'Authorization' => 'Bearer ' . $apiKey,
            'Accept' => 'application/json',
        ];
        $options['timeout'] = self::TIMEOUT;
        $options['max_duration'] = self::MAX_DURATION;

        $baseUrl = str_starts_with($apiKey, self::TEST_KEY_PREFIX) ? self::BASE_URL_SANDBOX : self::BASE_URL_LIVE;

        return $this->client->request($method, $baseUrl . $endpoint, $options);
    }
}
