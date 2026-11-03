<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Webhook\Service;

use Doctrine\DBAL\Connection;
use Kommandhub\ChowdeckRelaySW\Webhook\Message\ChowdeckWebhookMessage;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Verifies, stores and queues one webhook (spec §13.6). The body hash is the
 * idempotency key: a redelivered webhook is acknowledged and not processed
 * again.
 */
class WebhookReceiver
{
    public const ACCEPTED = 'accepted';

    public const DUPLICATE = 'duplicate';

    public const INVALID_SIGNATURE = 'invalid_signature';

    public const INVALID_PAYLOAD = 'invalid_payload';

    public function __construct(
        private readonly WebhookSignatureValidator $signatureValidator,
        private readonly Connection $connection,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function receive(string $rawBody, ?string $signature, ?string $salesChannelId): string
    {
        if (!$this->signatureValidator->isValid($rawBody, $signature, $salesChannelId)) {
            return self::INVALID_SIGNATURE;
        }

        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            return self::INVALID_PAYLOAD;
        }

        $id = Uuid::randomHex();
        $now = (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);

        $inserted = $this->connection->executeStatement(
            'INSERT IGNORE INTO `kmh_chowdeck_webhook_event`
                (`id`, `idempotency_key`, `event`, `reference`, `payload`, `received_at`, `created_at`)
             VALUES (:id, :key, :event, :reference, :payload, :now, :now)',
            [
                'id' => Uuid::fromHexToBytes($id),
                'key' => hash('sha256', $rawBody),
                'event' => mb_substr(is_string($payload['category'] ?? null) ? $payload['category'] : 'unknown', 0, 64),
                'reference' => self::reference($payload),
                'payload' => $rawBody,
                'now' => $now,
            ],
        );

        if ($inserted === 0) {
            return self::DUPLICATE;
        }

        $this->messageBus->dispatch(new ChowdeckWebhookMessage($id));

        return self::ACCEPTED;
    }

    /**
     * Chowdeck sends `{category, description, payload}`; the delivery's
     * reference is inside `payload`.
     *
     * @param array<mixed> $body
     */
    public static function reference(array $body): ?string
    {
        $inner = is_array($body['payload'] ?? null) ? $body['payload'] : [];
        $reference = $inner['reference'] ?? null;

        return is_string($reference) && $reference !== '' ? mb_substr($reference, 0, 64) : null;
    }

    /**
     * When the last webhook arrived, for the configuration page.
     */
    public function lastReceivedAt(): ?string
    {
        $value = $this->connection->fetchOne('SELECT MAX(`received_at`) FROM `kmh_chowdeck_webhook_event`');

        // Stored in UTC; returned with its offset so the browser shows local time.
        return is_string($value) ? (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format(\DATE_ATOM) : null;
    }
}
