<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Flow\Event;

use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Flow\Dispatching\Aware\ScalarValuesAware;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\EventData\EntityType;
use Shopware\Core\Framework\Event\EventData\EventDataCollection;
use Shopware\Core\Framework\Event\EventData\MailRecipientStruct;
use Shopware\Core\Framework\Event\EventData\ScalarValueType;
use Shopware\Core\Framework\Event\FlowEventAware;
use Shopware\Core\Framework\Event\MailAware;
use Shopware\Core\Framework\Event\OrderAware;
use Shopware\Core\Framework\Event\SalesChannelAware;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * A Flow Builder trigger about one Chowdeck delivery (spec §13.7). Exposes the
 * order and, as template variables, `trackingUrl`, `deliveryPin`,
 * `riderName`, `riderPhone`, `status` and `errorMessage`. The default mail
 * recipient is the order's customer.
 */
abstract class DeliveryEvent extends Event implements FlowEventAware, OrderAware, MailAware, SalesChannelAware, ScalarValuesAware
{
    private const VARIABLES = ['trackingUrl', 'deliveryPin', 'riderName', 'riderPhone', 'status', 'errorMessage'];

    /**
     * @param array{trackingUrl?: string, deliveryPin?: string, riderName?: string, riderPhone?: string, status?: string, errorMessage?: string} $values
     */
    public function __construct(
        private readonly OrderEntity $order,
        private readonly Context $context,
        private readonly array $values,
    ) {
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    public function getOrder(): OrderEntity
    {
        return $this->order;
    }

    public function getOrderId(): string
    {
        return $this->order->getId();
    }

    public function getSalesChannelId(): string
    {
        return $this->order->getSalesChannelId();
    }

    public function getMailStruct(): MailRecipientStruct
    {
        $customer = $this->order->getOrderCustomer();

        return new MailRecipientStruct([
            (string)$customer?->getEmail() => trim($customer?->getFirstName() . ' ' . $customer?->getLastName()),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function getValues(): array
    {
        return $this->values + array_fill_keys(self::VARIABLES, '');
    }

    public static function getAvailableData(): EventDataCollection
    {
        $data = (new EventDataCollection())->add('order', new EntityType(OrderDefinition::class));

        foreach (self::VARIABLES as $name) {
            $data->add($name, new ScalarValueType(ScalarValueType::TYPE_STRING));
        }

        return $data;
    }
}
