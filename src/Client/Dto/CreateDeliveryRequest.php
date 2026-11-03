<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Client\Dto;

/**
 * Body of `POST /relay/delivery` (spec §13.5). Sending it calls a rider at once.
 */
final class CreateDeliveryRequest
{
    /**
     * @param list<string> $notificationChannels any of sms, email, whatsapp
     */
    public function __construct(
        public readonly int $feeId,
        public readonly string $reference,
        public readonly string $itemType,
        public readonly Contact $source,
        public readonly Contact $destination,
        public readonly ?string $note = null,
        public readonly ?int $deliveryPin = null,
        public readonly array $notificationChannels = [],
        public readonly ?float $declaredValue = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = [
            'fee_id' => $this->feeId,
            'reference' => $this->reference,
            'item_type' => $this->itemType,
            'user_action' => 'sending',
            'source_contact' => $this->source->toArray(),
            'destination_contact' => $this->destination->toArray(),
        ];

        if ($this->note !== null && $this->note !== '') {
            $body['customer_delivery_note'] = $this->note;
        }

        if ($this->deliveryPin !== null) {
            $body['delivery_pin'] = $this->deliveryPin;
        }

        if ($this->notificationChannels !== []) {
            $body['notification_channels'] = $this->notificationChannels;
        }

        if ($this->declaredValue !== null) {
            $body['estimated_order_amount'] = (int)round($this->declaredValue * 100);
        }

        return $body;
    }
}
