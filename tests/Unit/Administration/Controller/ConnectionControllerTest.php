<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Administration\Controller;

use Kommandhub\ChowdeckRelaySW\Administration\Controller\ConnectionController;
use Kommandhub\ChowdeckRelaySW\Client\ApiErrorReason;
use Kommandhub\ChowdeckRelaySW\Client\ChowdeckClient;
use Kommandhub\ChowdeckRelaySW\Client\Dto\ArrayReader;
use Kommandhub\ChowdeckRelaySW\Client\Dto\WalletBalance;
use Kommandhub\ChowdeckRelaySW\Client\Http\ChowdeckHttpClient;
use Kommandhub\ChowdeckRelaySW\Client\Resource\ApiResource;
use Kommandhub\ChowdeckRelaySW\Client\Resource\Wallet;
use Kommandhub\ChowdeckRelaySW\Exception\ChowdeckApiException;
use Kommandhub\ChowdeckRelaySW\Setting\Service\Config;
use Kommandhub\ChowdeckRelaySW\Webhook\Service\WebhookReceiver;
use Kommandhub\ChowdeckRelaySW\Tests\Unit\Client\ClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(ConnectionController::class)]
#[UsesClass(Config::class)]
#[UsesClass(ChowdeckClient::class)]
#[UsesClass(ChowdeckHttpClient::class)]
#[UsesClass(ApiResource::class)]
#[UsesClass(Wallet::class)]
#[UsesClass(WalletBalance::class)]
#[UsesClass(ArrayReader::class)]
#[UsesClass(ChowdeckApiException::class)]
#[UsesClass(ApiErrorReason::class)]
class ConnectionControllerTest extends TestCase
{
    /**
     * @var list<array{string, string, array<mixed>}>
     */
    private array $logs = [];

    public function testConnectionSuccessReturnsBalance(): void
    {
        $response = ClientFactory::ok(['balance' => 250000]);

        $result = $this->decode($this->controller($response)->testConnection(new Request([], ['salesChannelId' => 'sc-1'])));

        static::assertSame(['success' => true, 'balance' => 2500, 'currency' => 'NGN'], $result);
    }

    /**
     * A failed test shows Chowdeck's reason, and neither the response nor the
     * log contains the API key.
     */
    public function testConnectionFailureReportsReasonWithoutKey(): void
    {
        $response = ClientFactory::json(['status' => 'failed', 'message' => 'Invalid API key'], 401);

        $raw = $this->controller($response)->testConnection(new Request());
        $result = $this->decode($raw);

        static::assertFalse($result['success']);
        static::assertSame('unauthorized', $result['reason']);
        static::assertSame('Invalid API key', $result['message']);
        static::assertStringNotContainsString(ClientFactory::API_KEY, (string)$raw->getContent());
        static::assertStringNotContainsString(ClientFactory::API_KEY, (string)json_encode($this->logs));
        static::assertSame('warning', $this->logs[0][0]);
        static::assertSame(401, $this->logs[0][2]['httpStatus']);
    }

    public function testUnconfiguredKey(): void
    {
        $controller = $this->controller(new MockResponse('{}'), '');

        static::assertSame('not_configured', $this->decode($controller->testConnection(new Request()))['reason']);
    }

    public function testWebhookStatus(): void
    {
        static::assertSame(['lastReceivedAt' => '2026-11-03T20:00:00+00:00'], $this->decode($this->controller(new MockResponse('{}'))->webhookStatus()));
    }

    private function controller(MockResponse $response, string $apiKey = ClientFactory::API_KEY): ConnectionController
    {
        $config = ClientFactory::config(['apiKey' => $apiKey]);
        $client = new ChowdeckClient(new ChowdeckHttpClient($config, new MockHttpClient($response)));
        $logs = &$this->logs;
        $logger = new class($logs) extends AbstractLogger {
            /**
             * @param list<array{string, string, array<mixed>}> $logs
             */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = [(string)$level, (string)$message, $context];
            }
        };

        $receiver = $this->createStub(WebhookReceiver::class);
        $receiver->method('lastReceivedAt')->willReturn('2026-11-03T20:00:00+00:00');

        return new ConnectionController($client, $receiver, $logger);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(JsonResponse $response): array
    {
        $data = json_decode((string)$response->getContent(), true);
        \assert(\is_array($data));

        return $data;
    }
}
