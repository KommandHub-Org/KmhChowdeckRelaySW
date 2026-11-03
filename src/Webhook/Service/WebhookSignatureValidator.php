<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Webhook\Service;

use Kommandhub\ChowdeckRelaySW\Setting\Service\Config;

/**
 * Checks that a webhook really comes from Chowdeck (spec §13.6).
 *
 * Per the docs, `x-chowdeck-signature` is the HMAC-SHA256 of the raw body,
 * hex, keyed with the merchant's webhook secret (⚠ not yet seen live).
 */
class WebhookSignatureValidator
{
    public const SIGNATURE_HEADER = 'x-chowdeck-signature';

    private const ALGORITHM = 'sha256';

    public function __construct(private readonly Config $config)
    {
    }

    public function isValid(string $rawBody, ?string $signature, ?string $salesChannelId): bool
    {
        $secret = $this->config->getString('webhookSecret', $salesChannelId);

        if ($signature === null || $signature === '' || $secret === '') {
            return false;
        }

        return hash_equals(hash_hmac(self::ALGORITHM, $rawBody, $secret), strtolower($signature));
    }
}
