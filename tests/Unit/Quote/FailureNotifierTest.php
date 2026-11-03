<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Quote;

use Kommandhub\ChowdeckRelaySW\Quote\FailureNotifier;
use Kommandhub\ChowdeckRelaySW\Quote\QuoteError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Notification\NotificationService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(FailureNotifier::class)]
#[UsesClass(QuoteError::class)]
class FailureNotifierTest extends TestCase
{
    public function testOneAdminOnlyNotificationPerSalesChannelAndReasonPerHour(): void
    {
        $messages = [];
        $service = $this->createMock(NotificationService::class);
        $service->expects(static::exactly(3))->method('createNotification')
            ->willReturnCallback(function (array $data) use (&$messages): void {
                static::assertTrue($data['adminOnly']);
                static::assertSame('danger', $data['status']);
                $messages[] = $data['message'];
            });
        $notifier = new FailureNotifier($service, new ArrayAdapter());
        $context = Context::createDefaultContext();

        $notifier->notify(QuoteError::Unavailable, 'sc1', $context);
        $notifier->notify(QuoteError::Unavailable, 'sc1', $context);
        $notifier->notify(QuoteError::NotConfigured, 'sc1', $context);
        $notifier->notify(QuoteError::Unavailable, 'sc2', $context);

        static::assertStringContainsString('could not be reached', $messages[0]);
        static::assertStringContainsString('not set up', $messages[1]);
    }

    public function testCustomerSideErrorsDoNotNotify(): void
    {
        $service = $this->createMock(NotificationService::class);
        $service->expects(static::never())->method('createNotification');

        (new FailureNotifier($service, new ArrayAdapter()))->notify(QuoteError::IncompleteAddress, 'sc1', Context::createDefaultContext());
    }
}
