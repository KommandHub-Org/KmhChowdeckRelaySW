const { Classes } = Shopware;

/**
 * Admin API calls behind the plugin configuration page.
 * Every call answers 200 with `{ success, ... }`; failures carry `reason` and `message`.
 */
export default class ChowdeckConfigService extends Classes.ApiService {
    constructor(httpClient, loginService) {
        super(httpClient, loginService, 'kmh-chowdeck');
        this.name = 'kmhChowdeckConfigService';
    }

    testConnection(salesChannelId) {
        return this.httpClient
            .post('_action/kmh-chowdeck/test-connection', { salesChannelId }, { headers: this.getBasicHeaders() })
            .then((response) => Classes.ApiService.handleResponse(response));
    }

    webhookStatus() {
        return this.httpClient
            .get('_action/kmh-chowdeck/webhook-status', { headers: this.getBasicHeaders() })
            .then((response) => Classes.ApiService.handleResponse(response));
    }

    deliveryAction(deliveryId, action, payload = {}) {
        return this.httpClient
            .post(`_action/kmh-chowdeck/delivery/${deliveryId}/${action}`, payload, { headers: this.getBasicHeaders() })
            .then((response) => Classes.ApiService.handleResponse(response));
    }
}
