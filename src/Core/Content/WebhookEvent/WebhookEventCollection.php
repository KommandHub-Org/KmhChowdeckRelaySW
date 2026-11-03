<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Core\Content\WebhookEvent;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<WebhookEventEntity>
 */
class WebhookEventCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return WebhookEventEntity::class;
    }
}
