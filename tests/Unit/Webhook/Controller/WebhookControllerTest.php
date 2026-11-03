<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Webhook\Controller;

use Kommandhub\ChowdeckRelaySW\Webhook\Controller\WebhookController;
use Kommandhub\ChowdeckRelaySW\Webhook\Service\WebhookReceiver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(WebhookController::class)]
class WebhookControllerTest extends TestCase
{
    public function testAnswersByResult(): void
    {
        $receiver = $this->createMock(WebhookReceiver::class);
        $receiver->method('receive')->willReturnOnConsecutiveCalls(
            WebhookReceiver::ACCEPTED,
            WebhookReceiver::DUPLICATE,
            WebhookReceiver::INVALID_SIGNATURE,
            WebhookReceiver::INVALID_PAYLOAD,
        );
        $controller = new WebhookController($receiver, new NullLogger());

        static::assertSame([200, 200, 401, 400], array_map(
            fn (): int => $controller->receive('default', $this->request())->getStatusCode(),
            range(1, 4),
        ));
    }

    public function testPassesBodySignatureAndSalesChannel(): void
    {
        $receiver = $this->createMock(WebhookReceiver::class);
        $receiver->expects(static::exactly(2))->method('receive')
            ->willReturnCallback(static function (string $body, ?string $signature, ?string $salesChannelId): string {
                static::assertSame('{"a":1}', $body);
                static::assertSame('sig', $signature);
                static::assertContains($salesChannelId, [null, '0190aaaa0000700080000000000000a1']);

                return WebhookReceiver::ACCEPTED;
            });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::never())->method('warning');
        $controller = new WebhookController($receiver, $logger);

        $controller->receive('default', $this->request());
        $controller->receive('0190aaaa0000700080000000000000a1', $this->request());
    }

    private function request(): Request
    {
        $request = new Request([], [], [], [], [], [], '{"a":1}');
        $request->headers->set('x-chowdeck-signature', 'sig');

        return $request;
    }
}
