<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Delivery\Service;

use Kommandhub\ChowdeckRelaySW\Delivery\Service\DeliveryStateUpdater;
use Kommandhub\ChowdeckRelaySW\Delivery\DeliveryStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateCollection;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

#[CoversClass(DeliveryStateUpdater::class)]
#[UsesClass(DeliveryStatus::class)]
class DeliveryStateUpdaterTest extends TestCase
{
    public function testMapsChowdeckStatusesToCoreDeliveryActions(): void
    {
        $actions = [];
        $registry = $this->createMock(StateMachineRegistry::class);
        $registry->method('transition')->willReturnCallback(function (Transition $transition) use (&$actions): StateMachineStateCollection {
            static::assertSame('order_delivery', $transition->getEntityName());
            static::assertSame('d1', $transition->getEntityId());
            $actions[] = $transition->getTransitionName();

            return new StateMachineStateCollection();
        });
        $updater = new DeliveryStateUpdater($registry, new NullLogger());

        foreach (DeliveryStatus::cases() as $status) {
            $updater->apply($status, 'd1', Context::createDefaultContext());
        }

        static::assertSame(['ship', 'cancel'], $actions);
    }

    public function testATransitionTheDeliveryDoesNotAllowIsLoggedAndSkipped(): void
    {
        $registry = $this->createMock(StateMachineRegistry::class);
        $registry->method('transition')->willThrowException(new \RuntimeException('Illegal transition'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())->method('info');

        (new DeliveryStateUpdater($registry, $logger))->apply(DeliveryStatus::PickedUp, 'd1', Context::createDefaultContext());
    }
}
