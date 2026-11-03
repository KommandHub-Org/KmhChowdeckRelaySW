<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Client;

use Kommandhub\ChowdeckRelaySW\Client\Http\HttpClientInterface;
use Kommandhub\ChowdeckRelaySW\Client\Resource\Deliveries;
use Kommandhub\ChowdeckRelaySW\Client\Resource\Wallet;

/**
 * Facade over the Chowdeck Relay API resources.
 *
 * Services depend on this one class rather than on each resource class, which
 * keeps constructor signatures stable as the API surface grows. Tests fake the
 * transport (HttpClientInterface), not this facade.
 */
class ChowdeckClient
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function deliveries(): Deliveries
    {
        return new Deliveries($this->httpClient);
    }

    public function wallet(): Wallet
    {
        return new Wallet($this->httpClient);
    }
}
