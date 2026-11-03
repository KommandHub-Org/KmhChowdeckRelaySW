<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Webhook\Message;

use Doctrine\DBAL\Connection;
use Kommandhub\ChowdeckRelaySW\Client\Dto\Delivery;
use Kommandhub\ChowdeckRelaySW\Delivery\Service\DeliveryService;
use Kommandhub\ChowdeckRelaySW\Webhook\Service\WebhookReceiver;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Processes one stored webhook (spec §13.6): finds the delivery by its
 * reference, applies the reported status and marks the event processed.
 * Anything that cannot be applied is noted on the event, never retried in a
 * loop.
 */
#[AsMessageHandler]
class ChowdeckWebhookHandler
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DeliveryService $deliveryService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ChowdeckWebhookMessage $message): void
    {
        $id = Uuid::fromHexToBytes($message->eventId);
        $payload = $this->connection->fetchOne(
            'SELECT `payload` FROM `kmh_chowdeck_webhook_event` WHERE `id` = :id AND `processed_at` IS NULL',
            ['id' => $id],
        );

        if (!is_string($payload)) {
            return;
        }

        try {
            $note = $this->process(json_decode($payload, true));
        } catch (\Throwable $e) {
            $this->logger->error('Chowdeck webhook could not be processed.', ['eventId' => $message->eventId, 'reason' => $e->getMessage()]);
            $note = 'Failed: ' . $e->getMessage();
        }

        $this->connection->executeStatement(
            'UPDATE `kmh_chowdeck_webhook_event` SET `processed_at` = :now, `error` = :note, `updated_at` = :now WHERE `id` = :id',
            ['now' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT), 'note' => $note, 'id' => $id],
        );
    }

    /**
     * @return string|null a note when the event was not applied
     */
    private function process(mixed $body): ?string
    {
        if (!is_array($body) || WebhookReceiver::reference($body) === null || !is_array($body['payload'])) {
            return 'Webhook without a delivery reference.';
        }

        $applied = $this->deliveryService->applyWebhook(Delivery::fromArray($body['payload']), Context::createCLIContext());

        return $applied ? null : 'No delivery with this reference.';
    }
}
