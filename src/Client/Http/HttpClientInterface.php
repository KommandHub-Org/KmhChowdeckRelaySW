<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Client\Http;

use Kommandhub\ChowdeckRelaySW\Exception\ChowdeckApiException;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Transport for the Chowdeck API. Chowdeck only uses GET and POST for the
 * calls this plugin makes.
 */
interface HttpClientInterface
{
    /**
     * @param array<string, mixed> $queryParams
     *
     * @throws ChowdeckApiException when no API key is configured
     */
    public function get(string $endpoint, array $queryParams = [], ?string $salesChannelId = null): ResponseInterface;

    /**
     * @param array<string, mixed> $payload
     *
     * @throws ChowdeckApiException when no API key is configured
     */
    public function post(string $endpoint, array $payload = [], ?string $salesChannelId = null): ResponseInterface;
}
