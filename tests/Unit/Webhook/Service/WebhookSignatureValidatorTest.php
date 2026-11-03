<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Webhook\Service;

use Kommandhub\ChowdeckRelaySW\Setting\Service\Config;
use Kommandhub\ChowdeckRelaySW\Tests\Unit\Client\ClientFactory;
use Kommandhub\ChowdeckRelaySW\Webhook\Service\WebhookSignatureValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebhookSignatureValidator::class)]
#[UsesClass(Config::class)]
class WebhookSignatureValidatorTest extends TestCase
{
    private const BODY = '{"category":"ORDER_COMPLETE"}';

    public function testSignedWithTheWebhookSecretPasses(): void
    {
        $validator = $this->validator(['webhookSecret' => 'whsec']);

        static::assertTrue($validator->isValid(self::BODY, hash_hmac('sha256', self::BODY, 'whsec'), null));
        static::assertTrue($validator->isValid(self::BODY, strtoupper(hash_hmac('sha256', self::BODY, 'whsec')), null));
    }

    public function testWrongMissingOrUnconfiguredSignaturesFail(): void
    {
        $validator = $this->validator(['webhookSecret' => 'whsec', 'apiKey' => 'key']);

        static::assertFalse($validator->isValid(self::BODY, hash_hmac('sha512', self::BODY, 'whsec'), null));
        static::assertFalse($validator->isValid(self::BODY, hash_hmac('sha256', self::BODY, 'key'), null));
        static::assertFalse($validator->isValid(self::BODY . ' ', hash_hmac('sha256', self::BODY, 'whsec'), null));
        static::assertFalse($validator->isValid(self::BODY, null, null));
        static::assertFalse($validator->isValid(self::BODY, '', null));
        static::assertFalse($this->validator([])->isValid(self::BODY, hash_hmac('sha256', self::BODY, ''), null));
    }

    /**
     * @param array<string, string> $values
     */
    private function validator(array $values): WebhookSignatureValidator
    {
        return new WebhookSignatureValidator(ClientFactory::config($values));
    }
}
