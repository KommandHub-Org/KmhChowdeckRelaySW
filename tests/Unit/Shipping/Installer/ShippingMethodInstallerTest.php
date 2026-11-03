<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Shipping\Installer;

use Kommandhub\ChowdeckRelaySW\Shipping\Installer\ShippingMethodInstaller;
use Kommandhub\ChowdeckRelaySW\Shipping\ChowdeckShippingMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Uuid\Uuid;

#[CoversClass(ShippingMethodInstaller::class)]
class ShippingMethodInstallerTest extends TestCase
{
    public function testInstallCreatesInactiveMethodAndAssignsSalesChannels(): void
    {
        $deliveryTimeId = Uuid::randomHex();
        $salesChannelId = Uuid::randomHex();

        $shippingMethods = $this->createMock(EntityRepository::class);
        $shippingMethods->method('searchIds')->willReturn($this->ids([]));
        $shippingMethods->expects(static::once())->method('create')->with(static::callback(
            static function (array $payload) use ($deliveryTimeId): bool {
                $data = $payload[0];

                return $data['technicalName'] === ChowdeckShippingMethod::TECHNICAL_NAME
                    && $data['active'] === false
                    && $data['taxType'] === 'auto'
                    && $data['deliveryTimeId'] === $deliveryTimeId
                    && $data['translations']['de-DE']['name'] === ChowdeckShippingMethod::NAME_DE;
            }
        ));

        $deliveryTimes = $this->createStub(EntityRepository::class);
        $deliveryTimes->method('searchIds')->willReturn($this->ids([$deliveryTimeId]));

        $salesChannels = $this->createMock(EntityRepository::class);
        $salesChannels->method('searchIds')->willReturn($this->ids([$salesChannelId]));
        $salesChannels->expects(static::once())->method('update')->with(static::callback(
            static fn (array $payload): bool => $payload[0]['id'] === $salesChannelId && \count($payload[0]['shippingMethods']) === 1
        ));

        (new ShippingMethodInstaller($shippingMethods, $deliveryTimes, $salesChannels))->install(Context::createDefaultContext());
    }

    public function testInstallWithoutDeliveryTimesOrSalesChannels(): void
    {
        $shippingMethods = $this->createMock(EntityRepository::class);
        $shippingMethods->method('searchIds')->willReturn($this->ids([]));
        $shippingMethods->expects(static::once())->method('create')->with(static::callback(
            static fn (array $payload): bool => !\array_key_exists('deliveryTimeId', $payload[0])
        ));

        $empty = $this->createStub(EntityRepository::class);
        $empty->method('searchIds')->willReturn($this->ids([]));
        $salesChannels = $this->createMock(EntityRepository::class);
        $salesChannels->method('searchIds')->willReturn($this->ids([]));
        $salesChannels->expects(static::never())->method('update');

        (new ShippingMethodInstaller($shippingMethods, $empty, $salesChannels))->install(Context::createDefaultContext());
    }

    /**
     * An update must not undo the merchant's rename, activation or channel choice.
     */
    public function testInstallLeavesExistingMethodAlone(): void
    {
        $shippingMethods = $this->createMock(EntityRepository::class);
        $shippingMethods->method('searchIds')->willReturn($this->ids([Uuid::randomHex()]));
        $shippingMethods->expects(static::never())->method('create');
        $shippingMethods->expects(static::never())->method('update');
        $salesChannels = $this->createMock(EntityRepository::class);
        $salesChannels->expects(static::never())->method('update');

        (new ShippingMethodInstaller($shippingMethods, $this->createStub(EntityRepository::class), $salesChannels))->install(Context::createDefaultContext());
    }

    public function testNewMethodGetsTheDescription(): void
    {
        $shippingMethods = $this->createMock(EntityRepository::class);
        $shippingMethods->method('searchIds')->willReturn($this->ids([]));
        $shippingMethods->expects(static::once())->method('create')->with(static::callback(
            static fn (array $payload): bool => $payload[0]['description'] === ChowdeckShippingMethod::DESCRIPTION_EN
                && $payload[0]['translations']['de-DE']['description'] === ChowdeckShippingMethod::DESCRIPTION_DE
        ));
        $empty = $this->createStub(EntityRepository::class);
        $empty->method('searchIds')->willReturn($this->ids([]));

        (new ShippingMethodInstaller($shippingMethods, $empty, $empty))->install(Context::createDefaultContext());
    }

    public function testSetActive(): void
    {
        $id = Uuid::randomHex();
        $shippingMethods = $this->createMock(EntityRepository::class);
        $shippingMethods->method('searchIds')->willReturn($this->ids([$id]));
        $shippingMethods->expects(static::once())->method('update')->with([['id' => $id, 'active' => false]]);

        $installer = new ShippingMethodInstaller($shippingMethods, $this->createStub(EntityRepository::class), $this->createStub(EntityRepository::class));
        $installer->setActive(false, Context::createDefaultContext());
    }

    public function testSetActiveWithoutMethodDoesNothing(): void
    {
        $shippingMethods = $this->createMock(EntityRepository::class);
        $shippingMethods->method('searchIds')->willReturn($this->ids([]));
        $shippingMethods->expects(static::never())->method('update');

        $installer = new ShippingMethodInstaller($shippingMethods, $this->createStub(EntityRepository::class), $this->createStub(EntityRepository::class));
        $installer->setActive(true, Context::createDefaultContext());
    }

    /**
     * @param list<string> $ids
     */
    private function ids(array $ids): IdSearchResult
    {
        return new IdSearchResult(
            \count($ids),
            array_map(static fn (string $id): array => ['primaryKey' => $id, 'data' => []], $ids),
            new Criteria(),
            Context::createDefaultContext(),
        );
    }
}
