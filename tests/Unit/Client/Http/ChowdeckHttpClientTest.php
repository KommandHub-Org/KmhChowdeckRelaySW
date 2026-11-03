<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Client\Http;

use Kommandhub\ChowdeckRelaySW\Client\ApiErrorReason;
use Kommandhub\ChowdeckRelaySW\Client\Http\ChowdeckHttpClient;
use Kommandhub\ChowdeckRelaySW\Exception\ChowdeckApiException;
use Kommandhub\ChowdeckRelaySW\Setting\Service\Config;
use Kommandhub\ChowdeckRelaySW\Tests\Unit\Client\ClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(ChowdeckHttpClient::class)]
#[UsesClass(Config::class)]
#[CoversClass(ChowdeckApiException::class)]
#[UsesClass(ApiErrorReason::class)]
class ChowdeckHttpClientTest extends TestCase
{
    public function testGetSendsBearerKeyAndShortTimeout(): void
    {
        $response = new MockResponse('{}');
        $http = ClientFactory::http($response);

        $http->get('/relay/wallet/balance', ['a' => 'b'], 'sc-1');

        static::assertSame('GET', $response->getRequestMethod());
        static::assertSame('https://api.chowdeck.com/relay/wallet/balance?a=b', $response->getRequestUrl());
        $headers = $response->getRequestOptions()['headers'];
        static::assertContains('Authorization: Bearer ' . ClientFactory::API_KEY, $headers);
        static::assertSame(3.0, (float)$response->getRequestOptions()['timeout']);
        static::assertSame(5.0, (float)$response->getRequestOptions()['max_duration']);
    }

    public function testPostSendsJsonBody(): void
    {
        $response = new MockResponse('{}');

        ClientFactory::http($response)->post('/relay/delivery', ['fee_id' => 1]);

        static::assertSame('POST', $response->getRequestMethod());
        static::assertSame('{"fee_id":1}', $response->getRequestOptions()['body']);
    }

    public function testTestKeyGoesToTheSandboxHost(): void
    {
        $response = new MockResponse('{}');

        ClientFactory::http($response, 'sk_test_abc')->get('/relay/wallet/balance');

        static::assertSame('https://console-api.chowdeck.com/relay/wallet/balance', $response->getRequestUrl());
    }

    public function testMissingKeyFailsBeforeAnyRequest(): void
    {
        $client = new MockHttpClient(static fn () => throw new \LogicException('must not be called'));
        $config = ClientFactory::config([]);

        try {
            (new ChowdeckHttpClient($config, $client))->get('/x');
            static::fail('Expected exception');
        } catch (ChowdeckApiException $e) {
            static::assertSame(ApiErrorReason::NotConfigured, $e->getReason());
            static::assertSame(0, $client->getRequestsCount());
        }
    }
}
