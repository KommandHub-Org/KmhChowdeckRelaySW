<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Core\Content\Delivery;

use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Extension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Adds `order_delivery.kmhChowdeckDelivery` (spec §13.4), loaded only when
 * explicitly associated.
 */
class OrderDeliveryExtension extends EntityExtension
{
    public const PROPERTY = 'kmhChowdeckDelivery';

    public function getEntityName(): string
    {
        return OrderDeliveryDefinition::ENTITY_NAME;
    }

    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new OneToOneAssociationField(self::PROPERTY, 'id', 'order_delivery_id', DeliveryDefinition::class, false))
                // cloneRelevant: false — otherwise Shopware copies the row (same
                // id) into every order version, so opening the order in the
                // Administration moves the delivery row onto the draft version and
                // saving or discarding the draft deletes it (same pitfall as
                // KmhLastMileDeliverySW). Deleting the order still cascades via
                // the database foreign key.
                ->addFlags(new Extension(), new CascadeDelete(false)),
        );
    }
}
