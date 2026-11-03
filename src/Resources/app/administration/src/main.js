import enGB from './snippet/en-GB.json';
import deDE from './snippet/de-DE.json';
import frFR from './snippet/fr-FR.json';
import ChowdeckConfigService from './service/chowdeck-config.service';

Shopware.Locale.extend('en-GB', enGB);
Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('fr-FR', frFR);

Shopware.Service().register('kmhChowdeckConfigService', (container) => {
    const initContainer = Shopware.Application.getContainer('init');
    return new ChowdeckConfigService(initContainer.httpClient, container.loginService);
});

import './acl';
import './component/kmh-chowdeck-connection-test';
