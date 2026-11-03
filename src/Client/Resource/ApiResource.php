<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Client\Resource;

use Kommandhub\ChowdeckRelaySW\Client\ApiErrorReason;
use Kommandhub\ChowdeckRelaySW\Client\Http\HttpClientInterface;
use Kommandhub\ChowdeckRelaySW\Exception\ChowdeckApiException;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Base class for one Chowdeck API endpoint group.
 *
 * One subclass per endpoint group, each method mapping to a single documented
 * call. Nothing outside Client/ should build a URL by hand.
 */
abstract class ApiResource
{
    public function __construct(protected HttpClientInterface $httpClient)
    {
    }

    /**
     * Reads a response and returns its `data` member.
     *
     * Chowdeck wraps everything in `{status, message, data}`. Any
     * failure (transport fault, timeout, HTTP error, `"status": "failed"`,
     * undecodable body) becomes a ChowdeckApiException with a reason; a
     * success returns `data` as an array (empty when absent or not an array).
     *
     * @return array<mixed>
     *
     * @throws ChowdeckApiException
     */
    protected function data(ResponseInterface $response): array
    {
        try {
            $status = $response->getStatusCode();
        } catch (TimeoutExceptionInterface $e) {
            throw new ChowdeckApiException(ApiErrorReason::Timeout, 'Chowdeck did not answer in time.', null, [], $e);
        } catch (TransportExceptionInterface $e) {
            throw new ChowdeckApiException(ApiErrorReason::Network, 'Chowdeck could not be reached.', null, [], $e);
        }

        try {
            $body = $response->toArray(false);
        } catch (\Throwable $e) {
            if ($status >= 400) {
                throw ChowdeckApiException::fromResponse($status, []);
            }

            throw new ChowdeckApiException(ApiErrorReason::InvalidResponse, 'Chowdeck returned an unreadable response.', $status, [], $e);
        }

        if ($status >= 400 || ($body['status'] ?? null) === 'failed') {
            throw ChowdeckApiException::fromResponse($status, $body);
        }

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }
}
