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
}
