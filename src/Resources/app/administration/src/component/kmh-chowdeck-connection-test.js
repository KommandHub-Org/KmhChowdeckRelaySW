import currentSalesChannelId from './sales-channel';

/**
 * "Test connection" button: checks the saved API key with a wallet balance call.
 */
Shopware.Component.register('kmh-chowdeck-connection-test', {
    template: `
        <div class="kmh-chowdeck-connection-test">
            <mt-button variant="secondary" :is-loading="isLoading" @click="run">
                {{ $tc('kmh-chowdeck.config.testConnection') }}
            </mt-button>
            <p class="sw-field__hint">{{ $tc('kmh-chowdeck.config.saveFirst') }}</p>
        </div>
    `,

    inject: ['kmhChowdeckConfigService'],

    mixins: [Shopware.Mixin.getByName('notification')],

    data() {
        return { isLoading: false };
    },

    methods: {
        async run() {
            this.isLoading = true;

            try {
                const result = await this.kmhChowdeckConfigService.testConnection(currentSalesChannelId(this));

                if (result.success) {
                    this.createNotificationSuccess({
                        message: this.$t('kmh-chowdeck.config.connectionOk', {
                            balance: result.balance,
                            currency: result.currency,
                        }),
                    });
                } else {
                    this.createNotificationError({ message: result.message });
                }
            } catch {
                this.createNotificationError({ message: this.$tc('kmh-chowdeck.config.requestFailed') });
            } finally {
                this.isLoading = false;
            }
        },
    },
});
