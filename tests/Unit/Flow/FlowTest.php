<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Flow;

use Kommandhub\ChowdeckRelaySW\Delivery\DeliveryStatus;
use Kommandhub\ChowdeckRelaySW\Delivery\Service\DeliveryService;
use Kommandhub\ChowdeckRelaySW\Delivery\Service\RequestResult;
use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryDeliveredEvent;
use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryFailedEvent;
use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryRequestedEvent;
use Kommandhub\ChowdeckRelaySW\Flow\FlowInstaller;
use Kommandhub\ChowdeckRelaySW\Flow\RequestRiderAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Flow\Dispatching\Action\SendMailAction;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Event\NestedEventCollection;
use Shopware\Core\Framework\Event\OrderAware;

#[CoversClass(RequestRiderAction::class)]
#[CoversClass(FlowInstaller::class)]
#[UsesClass(RequestResult::class)]
#[UsesClass(DeliveryStatus::class)]
class FlowTest extends TestCase
{
    public function testActionIdentity(): void
    {
        static::assertSame('action.kmh.chowdeck.request_rider', RequestRiderAction::getName());
        $action = new RequestRiderAction($this->createStub(EntityRepository::class), $this->createStub(DeliveryService::class), $this->createStub(LoggerInterface::class));
        static::assertSame([OrderAware::class], $action->requirements());
    }

    public function testRequestsEveryQuotedDeliveryAndKeepsGoingOnFailure(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('searchIds')->willReturnCallback(function (Criteria $criteria, Context $context): IdSearchResult {
            static::assertCount(2, $criteria->getFilters());

            return new IdSearchResult(3, [['primaryKey' => 'd1', 'data' => []], ['primaryKey' => 'd2', 'data' => []], ['primaryKey' => 'd3', 'data' => []]], $criteria, $context);
        });
        $service = $this->createMock(DeliveryService::class);
        $service->expects(static::exactly(3))->method('request')->willReturnCallback(static fn (string $id): RequestResult => match ($id) {
            'd1' => RequestResult::requested(),
            'd2' => RequestResult::confirmationNeeded(3900.0, 4200.0, 'NGN'),
            default => throw new \RuntimeException('wallet empty'),
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())->method('notice');
        $logger->expects(static::once())->method('error');

        (new RequestRiderAction($repository, $service, $logger))->handleFlow($this->flow('order-1'));
    }

    public function testWithoutOrderNothingHappens(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(static::never())->method('searchIds');

        (new RequestRiderAction($repository, $this->createStub(DeliveryService::class), $this->createStub(LoggerInterface::class)))->handleFlow($this->flow(null));
    }

    /**
     * Three active mail flows, and deliberately none that requests a rider.
     */
    public function testInstallsThreeActiveMailFlows(): void
    {
        $created = [];
        $flows = $this->createMock(EntityRepository::class);
        $flows->method('searchIds')->willReturn($this->ids([]));
        $flows->expects(static::exactly(3))->method('create')->willReturnCallback(function (array $payload) use (&$created): EntityWrittenContainerEvent {
            $created[] = $payload[0];

            return self::written();
        });
        $types = $this->createMock(EntityRepository::class);
        $types->method('searchIds')->willReturnOnConsecutiveCalls($this->ids([]), $this->ids(['existing-type']), $this->ids([]));
        $types->expects(static::exactly(2))->method('create')->willReturn(self::written());
        $templates = $this->createMock(EntityRepository::class);
        $templates->expects(static::exactly(3))->method('create')->willReturnCallback(static function (array $payload): EntityWrittenContainerEvent {
            static::assertSame('{{ salesChannel.translated.name }}', $payload[0]['translations']['en-GB']['senderName']);
            static::assertArrayHasKey('de-DE', $payload[0]['translations']);

            return self::written();
        });

        (new FlowInstaller($flows, $types, $templates))->install(Context::createDefaultContext());

        static::assertSame(FlowInstaller::flowIds(), array_column($created, 'id'));
        static::assertSame([true, true, true], array_column($created, 'active'));
        static::assertSame(
            [DeliveryRequestedEvent::EVENT_NAME, DeliveryFailedEvent::EVENT_NAME, DeliveryDeliveredEvent::EVENT_NAME],
            array_column($created, 'eventName'),
        );
        static::assertSame([SendMailAction::ACTION_NAME, SendMailAction::ACTION_NAME, SendMailAction::ACTION_NAME], array_map(static fn (array $flow): string => $flow['sequences'][0]['actionName'], $created));
        static::assertSame(['default', 'admin', 'default'], array_map(static fn (array $flow): string => $flow['sequences'][0]['config']['recipient']['type'], $created));
    }

    public function testExistingFlowsAreLeftAlone(): void
    {
        $flows = $this->createMock(EntityRepository::class);
        $flows->method('searchIds')->willReturn($this->ids(['exists']));
        $flows->expects(static::never())->method('create');
        $mail = $this->createMock(EntityRepository::class);
        $mail->expects(static::never())->method('create');

        (new FlowInstaller($flows, $mail, $mail))->install(Context::createDefaultContext());
    }

    private static function written(): EntityWrittenContainerEvent
    {
        return new EntityWrittenContainerEvent(Context::createDefaultContext(), new NestedEventCollection(), []);
    }

    private function flow(?string $orderId): StorableFlow
    {
        $flow = new StorableFlow('state_enter.order.state.in_progress', Context::createDefaultContext());

        if ($orderId !== null) {
            $flow->setData(OrderAware::ORDER_ID, $orderId);
        }

        return $flow;
    }

    /**
     * @param list<string> $ids
     */
    private function ids(array $ids): IdSearchResult
    {
        return new IdSearchResult(\count($ids), array_map(static fn (string $id): array => ['primaryKey' => $id, 'data' => []], $ids), new Criteria(), Context::createDefaultContext());
    }
}
