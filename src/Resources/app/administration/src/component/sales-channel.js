/**
 * The sw-system-config instance that renders the plugin configuration page.
 * Config components are rendered several levels below it.
 */
export function systemConfig(component) {
    let parent = component.$parent;

    while (parent) {
        // `in`, not hasOwnProperty: a production build keeps component data off
        // the proxy's own keys, so hasOwnProperty never matches there.
        if ('actualConfigData' in parent && 'currentSalesChannelId' in parent) {
            return parent;
        }
        parent = parent.$parent;
    }

    return null;
}

/**
 * The sales channel currently selected on the plugin configuration page
 * (null = all sales channels).
 */
export default function currentSalesChannelId(component) {
    return systemConfig(component)?.currentSalesChannelId ?? null;
}
