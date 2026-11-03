<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Delivery\Subscriber;

use Kommandhub\ChowdeckRelaySW\Core\Content\Delivery\OrderDeliveryExtension;
use Kommandhub\ChowdeckRelaySW\Delivery\DeliveryStatus;
use Kommandhub\ChowdeckRelaySW\Quote\QuoteStruct;
use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Shopware\Core\Defaults;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Writes the Chowdeck price onto the order as it is placed (spec §13.3): the
 * quote on each cart delivery becomes a `kmh_chowdeck_delivery` row in the
 * same write as the order, so an order never exists without it.
 *
 * Deliveries are matched by position: the order converter builds them in the
 * order of the cart's deliveries.
 */
class DeliveryQuoteSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [CartConvertedEvent::class => 'onCartConverted'];
    }

    public function onCartConverted(CartConvertedEvent $event): void
    {
        $converted = $event->getConvertedCart();
        $orderId = $converted['id'] ?? null;

        if (!is_string($orderId) || !is_array($converted['deliveries'] ?? null)) {
            return;
        }

        $cartDeliveries = array_values($event->getCart()->getDeliveries()->getElements());
        $changed = false;

        foreach ($converted['deliveries'] as $index => $delivery) {
            $quote = ($cartDeliveries[$index] ?? null)?->getExtension(QuoteStruct::EXTENSION_KEY);

            if (!$quote instanceof QuoteStruct || !is_array($delivery)) {
                continue;
            }

            $delivery[OrderDeliveryExtension::PROPERTY] = [
                'orderId' => $orderId,
                'salesChannelId' => $event->getSalesChannelContext()->getSalesChannelId(),
                'status' => ($quote->fallback ? DeliveryStatus::NeedsQuote : DeliveryStatus::Quoted)->value,
                'feeId' => $quote->feeId,
                'quotedAt' => $quote->fetchedAt->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'quotedAmount' => $quote->carrierTotal,
                'chargedAmount' => $cartDeliveries[$index]->getShippingCosts()->getTotalPrice(),
                'currency' => $quote->currency,
            ];
            $converted['deliveries'][$index] = $delivery;
            $changed = true;
        }

        if ($changed) {
            $event->setConvertedCart($converted);
        }
    }
}
