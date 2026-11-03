/**
 * Label, icon and (empty) settings modal for the Flow Builder action
 * `action.kmh.chowdeck.request_rider`. Flow Builder derives the lookup key
 * `kmhChowdeckRequest_rider` and the modal name from the action name.
 */
Shopware.Application.addServiceProviderDecorator('flowBuilderService', (flowBuilderService) => {
    flowBuilderService.addLabels({ kmhChowdeckRequest_rider: 'kmh-chowdeck.flow.requestRider' });
    flowBuilderService.addIcons({ kmhChowdeckRequest_rider: 'regular-truck' });

    return flowBuilderService;
});

Shopware.Component.register('sw-flow-kmh-chowdeck-request_rider-modal', {
    template: `
        <sw-modal :title="$tc('kmh-chowdeck.flow.requestRider')" variant="small" @modal-close="$emit('modal-close')">
            <p>{{ $tc('kmh-chowdeck.flow.requestRiderHelp') }}</p>
            <template #modal-footer>
                <mt-button size="small" variant="secondary" @click="$emit('modal-close')">{{ $tc('global.default.cancel') }}</mt-button>
                <mt-button size="small" variant="primary" @click="save">{{ $tc('global.default.save') }}</mt-button>
            </template>
        </sw-modal>
    `,

    props: {
        sequence: { type: Object, required: true },
    },

    emits: ['modal-close', 'process-finish'],

    methods: {
        save() {
            this.$emit('process-finish', { ...this.sequence, config: {} });
        },
    },
});
