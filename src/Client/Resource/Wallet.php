<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Client\Resource;

use Kommandhub\ChowdeckRelaySW\Client\Dto\WalletBalance;
use Kommandhub\ChowdeckRelaySW\Exception\ChowdeckApiException;

/**
 * The merchant's Relay wallet. The balance call doubles as the cheapest way to
 * check that an API key works.
 */
class Wallet extends ApiResource
{
    /**
     * @throws ChowdeckApiException
     */
    public function balance(?string $salesChannelId = null): WalletBalance
    {
        return WalletBalance::fromArray($this->data($this->httpClient->get('/relay/wallet/balance', [], $salesChannelId)));
    }
}
