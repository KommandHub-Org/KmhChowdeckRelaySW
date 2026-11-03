/**
 * Order permissions for the Chowdeck card (spec §13.5), under Settings >
 * Users & permissions > Roles > Orders. The server enforces the same
 * privileges: the entity read for viewing, `kmh_chowdeck_delivery:update`
 * on the request, refresh and cancel routes.
 */
Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'orders',
    key: 'kmh_chowdeck_delivery',
    roles: {
        viewer: {
            privileges: ['kmh_chowdeck_delivery:read'],
            dependencies: ['order.viewer'],
        },
        editor: {
            privileges: ['kmh_chowdeck_delivery:update'],
            dependencies: ['kmh_chowdeck_delivery.viewer'],
        },
    },
});
