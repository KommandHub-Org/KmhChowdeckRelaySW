import template from './sw-order-detail-general.html.twig';

/**
 * Mounts the Chowdeck card on the order's General tab, after the line items.
 * The card loads its own data and renders nothing for orders without a
 * Chowdeck delivery.
 */
Shopware.Component.override('sw-order-detail-general', {
    template,
});
