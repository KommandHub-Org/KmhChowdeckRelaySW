import currentSalesChannelId, { systemConfig } from './sales-channel';

const { Criteria } = Shopware.Data;

/**
 * Shows the webhook URL to paste into Chowdeck (dashboard Settings >
 * Developer settings for live, the sandbox console's test webhook URLs for a
 * test key) and when the last webhook arrived (spec §13.6). The URL uses a
 * storefront domain of the selected sales channel; "All sales channels" gets
 * the `default` URL, which checks the global signing secret.
 */
Shopware.Component.register('kmh-chowdeck-webhook-info', {
    template: `
        <div class="kmh-chowdeck-webhook-info">
            <mt-text-field
                :label="$tc('kmh-chowdeck.webhook.url')"
                :model-value="url || $tc('kmh-chowdeck.webhook.noDomain')"
                :help-text="$tc('kmh-chowdeck.webhook.urlHelp')"
                disabled
                copyable
            />
            <p class="sw-field__hint">
                {{ lastReceivedAt
                    ? $t('kmh-chowdeck.webhook.lastReceived', { date: formatDate(lastReceivedAt) })
                    : $tc('kmh-chowdeck.webhook.neverReceived') }}
            </p>
        </div>
    `,

    inject: ['repositoryFactory', 'kmhChowdeckConfigService'],

    data() {
        return { url: null, lastReceivedAt: null };
    },

    computed: {
        salesChannelId() {
            return systemConfig(this)?.currentSalesChannelId ?? null;
        },
    },

    watch: {
        salesChannelId() {
            this.loadUrl();
        },
    },

    created() {
        this.loadUrl();
        this.kmhChowdeckConfigService.webhookStatus()
            .then((result) => { this.lastReceivedAt = result.lastReceivedAt; })
            .catch(() => { this.lastReceivedAt = null; });
    },

    methods: {
        async loadUrl() {
            const salesChannelId = currentSalesChannelId(this);
            // Only real web domains: headless channels carry placeholder "domains".
            const criteria = new Criteria(1, 1).addFilter(Criteria.prefix('url', 'http'));
            if (salesChannelId) {
                criteria.addFilter(Criteria.equals('salesChannelId', salesChannelId));
            }

            const domain = (await this.repositoryFactory.create('sales_channel_domain').search(criteria, Shopware.Context.api)).first();
            this.url = domain ? `${domain.url.replace(/\/$/, '')}/kmh-chowdeck/webhook/${salesChannelId || 'default'}` : null;
        },

        formatDate(value) {
            return Shopware.Utils.format.date(value);
        },
    },
});
