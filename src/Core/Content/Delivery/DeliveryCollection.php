<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Core\Content\Delivery;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<DeliveryEntity>
 */
class DeliveryCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return DeliveryEntity::class;
    }
}
