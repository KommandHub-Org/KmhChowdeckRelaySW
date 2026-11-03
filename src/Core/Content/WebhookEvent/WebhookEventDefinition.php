<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Core\Content\WebhookEvent;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * DAL definition for `kmh_chowdeck_webhook_event` — every authentic webhook
 * Chowdeck sent, keyed by the SHA-256 of its raw body so a redelivery is
 * recognised and processed once (spec §13.6).
 */
class WebhookEventDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'kmh_chowdeck_webhook_event';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return WebhookEventEntity::class;
    }

    public function getCollectionClass(): string
    {
        return WebhookEventCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('idempotency_key', 'idempotencyKey', 64))->addFlags(new Required(), new ApiAware()),
            (new StringField('event', 'event', 64))->addFlags(new Required(), new ApiAware()),
            (new StringField('reference', 'reference', 64))->addFlags(new ApiAware()),
            (new JsonField('payload', 'payload'))->addFlags(new Required(), new ApiAware()),
            (new DateTimeField('received_at', 'receivedAt'))->addFlags(new Required(), new ApiAware()),
            (new DateTimeField('processed_at', 'processedAt'))->addFlags(new ApiAware()),
            (new LongTextField('error', 'error'))->addFlags(new ApiAware()),
        ]);
    }
}
