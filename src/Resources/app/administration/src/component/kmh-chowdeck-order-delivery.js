import './kmh-chowdeck-order-delivery.scss';
const { Criteria } = Shopware.Data;

/**
 * "Chowdeck" card on the order: per Chowdeck delivery the prices, status,
 * rider, delivery PIN and tracking link, with Request rider, Refresh and
 * Cancel (spec §13.5). Renders nothing for orders without a Chowdeck delivery.
 */
Shopware.Component.register('kmh-chowdeck-order-delivery', {
    template: `
        <mt-card v-if="deliveries.length" :title="$tc('kmh-chowdeck.delivery.title')" :is-loading="isLoading" class="kmh-chowdeck-order-delivery">
            <div v-for="delivery in deliveries" :key="delivery.id" class="kmh-chowdeck-order-delivery__item">
                <sw-description-list grid="1fr 2fr">
                    <dt>{{ $tc('kmh-chowdeck.delivery.status') }}</dt>
                    <dd>{{ statusLabel(delivery.status) }}</dd>
                    <dt>{{ $tc('kmh-chowdeck.delivery.charged') }}</dt>
                    <dd>{{ money(delivery.chargedAmount) }}</dd>
                    <dt>{{ $tc('kmh-chowdeck.delivery.chowdeckPrice') }}</dt>
                    <dd>{{ money(delivery.quotedAmount) }}</dd>
                    <template v-if="delivery.rider && (delivery.rider.name || delivery.rider.phone)">
                        <dt>{{ $tc('kmh-chowdeck.delivery.rider') }}</dt>
                        <dd>{{ [delivery.rider.name, delivery.rider.phone].filter(Boolean).join(', ') }}</dd>
                    </template>
                    <template v-if="delivery.deliveryPin">
                        <dt>{{ $tc('kmh-chowdeck.delivery.pin') }}</dt>
                        <dd>{{ delivery.deliveryPin }}</dd>
                    </template>
                    <template v-if="delivery.trackingUrl">
                        <dt>{{ $tc('kmh-chowdeck.delivery.tracking') }}</dt>
                        <dd><a :href="delivery.trackingUrl" target="_blank" rel="noopener">{{ $tc('kmh-chowdeck.delivery.openTracking') }}</a></dd>
                    </template>
                    <template v-if="delivery.refundAmount !== null && delivery.refundAmount !== undefined">
                        <dt>{{ $tc('kmh-chowdeck.delivery.refund') }}</dt>
                        <dd>{{ money(delivery.refundAmount) }}</dd>
                    </template>
                </sw-description-list>

                <mt-banner v-if="delivery.errorMessage" variant="critical" class="kmh-chowdeck-order-delivery__error">
                    {{ delivery.errorMessage }}
                </mt-banner>

                <div v-if="canEdit" class="kmh-chowdeck-order-delivery__actions">
                    <mt-button v-if="isRequestable(delivery)" variant="primary" size="small" :is-loading="busyId === delivery.id" @click="request(delivery, false)">
                        {{ $tc('kmh-chowdeck.delivery.request') }}
                    </mt-button>
                    <mt-button v-if="delivery.reference" variant="secondary" size="small" :is-loading="busyId === delivery.id" @click="refresh(delivery)">
                        {{ $tc('kmh-chowdeck.delivery.refresh') }}
                    </mt-button>
                    <mt-button v-if="isCancellable(delivery)" variant="critical" size="small" :disabled="busyId === delivery.id" @click="cancelTarget = delivery">
                        {{ $tc('kmh-chowdeck.delivery.cancel') }}
                    </mt-button>
                </div>
                <p v-if="canEdit && isRequestable(delivery)" class="sw-field__hint">{{ $tc('kmh-chowdeck.delivery.requestHint') }}</p>
            </div>

            <sw-modal v-if="priceChange" :title="$tc('kmh-chowdeck.delivery.priceTitle')" variant="small" @modal-close="priceChange = null">
                <p>{{ $t('kmh-chowdeck.delivery.priceText', { old: money(priceChange.oldPrice), new: money(priceChange.newPrice) }) }}</p>
                <p>{{ $tc('kmh-chowdeck.delivery.priceCharged') }}</p>
                <template #modal-footer>
                    <mt-button size="small" variant="secondary" @click="priceChange = null">{{ $tc('global.default.cancel') }}</mt-button>
                    <mt-button size="small" variant="primary" :is-loading="busyId !== null" @click="confirmPrice">{{ $tc('kmh-chowdeck.delivery.priceConfirm') }}</mt-button>
                </template>
            </sw-modal>

            <sw-modal v-if="cancelTarget" :title="$tc('kmh-chowdeck.delivery.cancelTitle')" variant="small" @modal-close="cancelTarget = null">
                <p>{{ $tc('kmh-chowdeck.delivery.cancelText') }}</p>
                <template #modal-footer>
                    <mt-button size="small" variant="secondary" @click="cancelTarget = null">{{ $tc('global.default.cancel') }}</mt-button>
                    <mt-button size="small" variant="critical" :is-loading="busyId !== null" @click="cancel">{{ $tc('kmh-chowdeck.delivery.cancelConfirm') }}</mt-button>
                </template>
            </sw-modal>
        </mt-card>
    `,

    inject: ['repositoryFactory', 'acl', 'kmhChowdeckConfigService'],

    mixins: [Shopware.Mixin.getByName('notification')],

    props: {
        orderId: { type: String, required: true },
    },

    data() {
        return { deliveries: [], isLoading: false, busyId: null, priceChange: null, cancelTarget: null };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('kmh_chowdeck_delivery');
        },

        canEdit() {
            return this.acl.can('kmh_chowdeck_delivery.editor');
        },
    },

    watch: {
        // The order page is reused when moving between orders.
        orderId() {
            this.load();
        },
    },

    created() {
        this.load();
    },

    methods: {
        async load() {
            this.isLoading = true;

            try {
                const criteria = new Criteria(1, 25).addFilter(Criteria.equals('orderId', this.orderId));
                this.deliveries = Array.from(await this.repository.search(criteria, Shopware.Context.api));
            } finally {
                this.isLoading = false;
            }
        },

        isRequestable(delivery) {
            return ['quoted', 'needs_quote'].includes(delivery.status);
        },

        isCancellable(delivery) {
            return ['preparing', 'awaiting_pickup'].includes(delivery.status);
        },

        statusLabel(status) {
            return this.$tc(`kmh-chowdeck.delivery.statuses.${status}`);
        },

        money(value) {
            if (value === null || value === undefined) {
                return '–';
            }

            return Shopware.Utils.format.currency(Number(value), 'NGN');
        },

        async request(delivery, confirmed) {
            const result = await this.act(delivery, 'request', { confirmed });

            if (result?.success && !result.requested) {
                this.priceChange = { ...result, deliveryId: delivery.id };
            }
        },

        async confirmPrice() {
            const delivery = this.deliveries.find((item) => item.id === this.priceChange.deliveryId);
            this.priceChange = null;

            await this.request(delivery, true);
        },

        async refresh(delivery) {
            await this.act(delivery, 'refresh');
        },

        async cancel() {
            const delivery = this.cancelTarget;
            this.cancelTarget = null;

            await this.act(delivery, 'cancel');
        },

        /**
         * Runs an action, shows Chowdeck's own message on a refusal and
         * reloads the card either way (a refusal stores its error on the row).
         */
        async act(delivery, action, payload = {}) {
            this.busyId = delivery.id;

            try {
                const result = await this.kmhChowdeckConfigService.deliveryAction(delivery.id, action, payload);

                if (!result.success) {
                    this.createNotificationError({ message: result.message });
                } else if (action !== 'request' || result.requested) {
                    this.createNotificationSuccess({ message: this.$tc(`kmh-chowdeck.delivery.done.${action}`) });
                }

                return result;
            } catch {
                this.createNotificationError({ message: this.$tc('kmh-chowdeck.config.requestFailed') });

                return null;
            } finally {
                this.busyId = null;
                await this.load();
            }
        },
    },
});
