<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Client;

use Kommandhub\ChowdeckRelaySW\Client\Http\ChowdeckHttpClient;
use Kommandhub\ChowdeckRelaySW\Client\ChowdeckClient;
use Kommandhub\ChowdeckRelaySW\Setting\Service\Config;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Builds the real client stack over Symfony's MockHttpClient, so tests cover
 * transport, resource and DTO together and can inspect the request sent.
 */
final class ClientFactory
{
    public const API_KEY = 'cd_test_secret_key_123';

    /**
     * @param list<MockResponse>|MockResponse|callable $responses
     */
    public static function client(MockResponse|array|callable $responses, string $apiKey = self::API_KEY): ChowdeckClient
    {
        return new ChowdeckClient(self::http($responses, $apiKey));
    }

    /**
     * @param list<MockResponse>|MockResponse|callable $responses
     */
    public static function http(MockResponse|array|callable $responses, string $apiKey = self::API_KEY): ChowdeckHttpClient
    {
        return new ChowdeckHttpClient(self::config(['apiKey' => $apiKey]), new MockHttpClient($responses));
    }

    /**
     * A real Config over an in-memory system config (keys without prefix).
     *
     * @param array<string, mixed> $values
     */
    public static function config(array $values): Config
    {
        $prefixed = [];

        foreach ($values as $key => $value) {
            $prefixed[Config::KEY . $key] = $value;
        }

        return new Config(new class($prefixed) extends SystemConfigService {
            /**
             * @param array<string, mixed> $values
             */
            public function __construct(public array $values)
            {
            }

            public function get(string $key, ?string $salesChannelId = null): mixed
            {
                return $this->values[$key] ?? null;
            }

            public function getBool(string $key, ?string $salesChannelId = null): bool
            {
                return (bool)($this->values[$key] ?? false);
            }

            public function set(string $key, mixed $value, ?string $salesChannelId = null, bool $silent = false): void
            {
                $this->values[$key] = $value;
            }
        });
    }

    /**
     * @param array<mixed> $data
     */
    public static function ok(array $data, int $status = 200): MockResponse
    {
        return self::json(['status' => 'success', 'message' => 'ok', 'data' => $data], $status);
    }

    /**
     * @param array<mixed> $body
     */
    public static function json(array $body, int $status): MockResponse
    {
        return new MockResponse((string)json_encode($body), [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }
}
