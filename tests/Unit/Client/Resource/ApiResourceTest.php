<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Client\Resource;

use Kommandhub\ChowdeckRelaySW\Client\ApiErrorReason;
use Kommandhub\ChowdeckRelaySW\Client\Dto\ArrayReader;
use Kommandhub\ChowdeckRelaySW\Client\Dto\WalletBalance;
use Kommandhub\ChowdeckRelaySW\Client\Http\HttpClientInterface;
use Kommandhub\ChowdeckRelaySW\Client\Http\ChowdeckHttpClient;
use Kommandhub\ChowdeckRelaySW\Client\Resource\ApiResource;
use Kommandhub\ChowdeckRelaySW\Client\Resource\Wallet;
use Kommandhub\ChowdeckRelaySW\Client\ChowdeckClient;
use Kommandhub\ChowdeckRelaySW\Exception\ChowdeckApiException;
use Kommandhub\ChowdeckRelaySW\Setting\Service\Config;
use Kommandhub\ChowdeckRelaySW\Tests\Unit\Client\ClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Error mapping for every resource, exercised through the wallet call.
 */
#[CoversClass(ApiResource::class)]
#[CoversClass(ChowdeckApiException::class)]
#[CoversClass(ApiErrorReason::class)]
#[CoversClass(Wallet::class)]
#[CoversClass(WalletBalance::class)]
#[UsesClass(ArrayReader::class)]
#[CoversClass(ChowdeckClient::class)]
#[UsesClass(ChowdeckHttpClient::class)]
#[UsesClass(Config::class)]
class ApiResourceTest extends TestCase
{
    public function testSuccessReturnsData(): void
    {
        $balance = ClientFactory::client(ClientFactory::ok(['balance' => '150050']))->wallet()->balance();

        static::assertSame(1500.5, $balance->balance);
    }

    public function testMissingDataIsEmpty(): void
    {
        $balance = ClientFactory::client(ClientFactory::json(['status' => 'success'], 200))->wallet()->balance();

        static::assertSame(0.0, $balance->balance);
    }

    /**
     * @return iterable<string, array{int, array<mixed>, ApiErrorReason}>
     */
    public static function errorProvider(): iterable
    {
        yield 'bad request' => [400, ['status' => 'failed', 'message' => 'Invalid payload'], ApiErrorReason::BadRequest];
        yield 'empty wallet' => [400, ['status' => 'failed', 'message' => 'Insufficient wallet balance'], ApiErrorReason::InsufficientBalance];
        yield 'unauthorized' => [401, ['status' => 'failed', 'message' => 'Unauthorized'], ApiErrorReason::Unauthorized];
        yield 'not found' => [404, ['status' => 'failed', 'message' => 'Not found'], ApiErrorReason::NotFound];
        yield 'validation' => [422, ['status' => 'failed', 'message' => 'Recipient address is required', 'errors' => ['address', 3]], ApiErrorReason::Validation];
        yield 'rate limited' => [429, ['status' => 'failed', 'message' => 'Too many requests'], ApiErrorReason::RateLimited];
        yield 'server error' => [500, ['status' => 'failed', 'message' => 'Oops'], ApiErrorReason::ServerError];
        yield 'unavailable' => [503, ['status' => 'failed', 'message' => 'Down'], ApiErrorReason::ServerError];
        yield 'failed with 200' => [200, ['status' => 'failed', 'message' => 'No'], ApiErrorReason::BadRequest];
    }

    /**
     * @param array<mixed> $body
     */
    #[DataProvider('errorProvider')]
    public function testErrorsAreMapped(int $status, array $body, ApiErrorReason $reason): void
    {
        try {
            ClientFactory::client(ClientFactory::json($body, $status))->wallet()->balance();
            static::fail('Expected exception');
        } catch (ChowdeckApiException $e) {
            static::assertSame($reason, $e->getReason());
            static::assertSame($status, $e->getHttpStatus());
            static::assertSame($body['message'], $e->getMessage());
        }
    }

    public function testValidationErrorsKeepStringErrors(): void
    {
        try {
            ClientFactory::client(ClientFactory::json(['status' => 'failed', 'message' => 'x', 'errors' => ['address', 3]], 422))->wallet()->balance();
            static::fail('Expected exception');
        } catch (ChowdeckApiException $e) {
            static::assertSame(['address'], $e->getErrors());
        }
    }

    public function testNonJsonErrorBodyIsMappedByStatus(): void
    {
        try {
            ClientFactory::client(new MockResponse('<html>Bad gateway</html>', ['http_code' => 502]))->wallet()->balance();
            static::fail('Expected exception');
        } catch (ChowdeckApiException $e) {
            static::assertSame(ApiErrorReason::ServerError, $e->getReason());
            static::assertSame('Chowdeck request failed with HTTP 502.', $e->getMessage());
        }
    }

    public function testNonJsonSuccessBodyIsInvalidResponse(): void
    {
        try {
            ClientFactory::client(new MockResponse('not json'))->wallet()->balance();
            static::fail('Expected exception');
        } catch (ChowdeckApiException $e) {
            static::assertSame(ApiErrorReason::InvalidResponse, $e->getReason());
            static::assertSame(200, $e->getHttpStatus());
        }
    }

    public function testNetworkFailure(): void
    {
        try {
            ClientFactory::client(new MockResponse('', ['error' => 'DNS failure']))->wallet()->balance();
            static::fail('Expected exception');
        } catch (ChowdeckApiException $e) {
            static::assertSame(ApiErrorReason::Network, $e->getReason());
            static::assertTrue($e->getReason()->isTransient());
        }
    }

    public function testTimeout(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willThrowException(new TimeoutException('Idle timeout reached'));
        $http = $this->createStub(HttpClientInterface::class);
        $http->method('get')->willReturn($response);

        try {
            (new Wallet($http))->balance();
            static::fail('Expected exception');
        } catch (ChowdeckApiException $e) {
            static::assertSame(ApiErrorReason::Timeout, $e->getReason());
            static::assertInstanceOf(TimeoutException::class, $e->getPrevious());
        }
    }

    /**
     * API keys must never end up in an exception (and so in logs or admin responses).
     */
    public function testExceptionNeverContainsTheApiKey(): void
    {
        try {
            ClientFactory::client(ClientFactory::json(['status' => 'failed', 'message' => 'Unauthorized'], 401))->wallet()->balance();
            static::fail('Expected exception');
        } catch (ChowdeckApiException $e) {
            static::assertStringNotContainsString(ClientFactory::API_KEY, $e->getMessage() . json_encode($e->getErrors()));
        }
    }
}
