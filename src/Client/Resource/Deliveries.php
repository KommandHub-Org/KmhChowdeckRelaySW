<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Client\Resource;

use Kommandhub\ChowdeckRelaySW\Client\Dto\CancelledDelivery;
use Kommandhub\ChowdeckRelaySW\Client\Dto\CreateDeliveryRequest;
use Kommandhub\ChowdeckRelaySW\Client\Dto\Delivery;
use Kommandhub\ChowdeckRelaySW\Client\Dto\DeliveryFee;
use Kommandhub\ChowdeckRelaySW\Client\Dto\FeeRequest;
use Kommandhub\ChowdeckRelaySW\Exception\ChowdeckApiException;

/**
 * Relay deliveries: fee quote, create (calls a rider), track and cancel
 * (spec §13.2).
 */
class Deliveries extends ApiResource
{
    /**
     * @throws ChowdeckApiException
     */
    public function fee(FeeRequest $request, ?string $salesChannelId = null): DeliveryFee
    {
        return DeliveryFee::fromArray($this->data($this->httpClient->post('/relay/delivery/fee', $request->toArray(), $salesChannelId)));
    }

    /**
     * @throws ChowdeckApiException
     */
    public function create(CreateDeliveryRequest $request, ?string $salesChannelId = null): Delivery
    {
        return Delivery::fromArray($this->data($this->httpClient->post('/relay/delivery', $request->toArray(), $salesChannelId)));
    }

    /**
     * @throws ChowdeckApiException
     */
    public function get(string $reference, ?string $salesChannelId = null): Delivery
    {
        return Delivery::fromArray($this->data($this->httpClient->get('/relay/delivery/' . rawurlencode($reference), [], $salesChannelId)));
    }

    /**
     * @throws ChowdeckApiException
     */
    public function cancel(string $reference, string $reason, ?string $salesChannelId = null): CancelledDelivery
    {
        $data = $this->data($this->httpClient->post('/relay/delivery/' . rawurlencode($reference) . '/cancel', ['reason' => $reason], $salesChannelId));

        return CancelledDelivery::fromArray($data);
    }
}
