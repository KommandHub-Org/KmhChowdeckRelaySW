<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Shipping;

/**
 * Identity of the plugin's single shipping method (spec §13). The method is
 * found by technical name everywhere; its id is random per installation.
 */
final class ChowdeckShippingMethod
{
    public const TECHNICAL_NAME = 'kmh_chowdeck_relay';

    public const NAME_EN = 'Same-day rider delivery (Chowdeck)';

    public const NAME_DE = 'Kurierlieferung am selben Tag (Chowdeck)';

    public const DESCRIPTION_EN = 'Same-day delivery by a Chowdeck rider.';

    public const DESCRIPTION_DE = 'Lieferung am selben Tag durch einen Chowdeck-Fahrer.';
}
