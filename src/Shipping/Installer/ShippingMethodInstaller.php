<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Shipping\Installer;

use Kommandhub\ChowdeckRelaySW\Shipping\ChowdeckShippingMethod;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\DeliveryTime\DeliveryTimeCollection;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;

/**
 * Creates the Chowdeck shipping method through the DAL (pattern:
 * KmhLastMileDeliverySW), never through a migration.
 *
 * Created **inactive**: it cannot quote until the merchant has entered the API
 * key and a pickup address, so the merchant activates it. Once it exists it is
 * left alone — the merchant may rename it, change its sales channels or
 * activate it, and a plugin update must not undo that.
 *
 * Never deleted (orders reference it); deactivate and uninstall only switch it
 * off. Base price 0: the real price is set per cart from the Chowdeck fee.
 */
class ShippingMethodInstaller
{
    /**
     * @param EntityRepository<ShippingMethodCollection> $shippingMethodRepository
     * @param EntityRepository<DeliveryTimeCollection> $deliveryTimeRepository
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private readonly EntityRepository $shippingMethodRepository,
        private readonly EntityRepository $deliveryTimeRepository,
        private readonly EntityRepository $salesChannelRepository,
    ) {
    }

    public function install(Context $context): void
    {
        $existingId = $this->findId($context);

        if ($existingId !== null) {
            return;
        }

        $id = Uuid::randomHex();

        $data = [
            'id' => $id,
            'technicalName' => ChowdeckShippingMethod::TECHNICAL_NAME,
            'active' => false,
            'taxType' => 'auto',
            'name' => ChowdeckShippingMethod::NAME_EN,
            'description' => ChowdeckShippingMethod::DESCRIPTION_EN,
            'translations' => [
                'en-GB' => ['name' => ChowdeckShippingMethod::NAME_EN, 'description' => ChowdeckShippingMethod::DESCRIPTION_EN],
                'de-DE' => ['name' => ChowdeckShippingMethod::NAME_DE, 'description' => ChowdeckShippingMethod::DESCRIPTION_DE],
            ],
            'prices' => [[
                'calculation' => 1,
                'quantityStart' => 1,
                'currencyPrice' => [[
                    'currencyId' => Defaults::CURRENCY,
                    'gross' => 0,
                    'net' => 0,
                    'linked' => false,
                ]],
            ]],
        ];

        $deliveryTimeId = $this->deliveryTimeRepository->searchIds((new Criteria())->setLimit(1), $context)->firstId();

        if ($deliveryTimeId !== null) {
            $data['deliveryTimeId'] = $deliveryTimeId;
        }

        $this->shippingMethodRepository->create([$data], $context);
        $this->assignToAllSalesChannels($id, $context);
    }

    public function setActive(bool $active, Context $context): void
    {
        $id = $this->findId($context);

        if ($id === null) {
            return;
        }

        $this->shippingMethodRepository->update([['id' => $id, 'active' => $active]], $context);
    }

    private function findId(Context $context): ?string
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('technicalName', ChowdeckShippingMethod::TECHNICAL_NAME))
            ->setLimit(1);

        return $this->shippingMethodRepository->searchIds($criteria, $context)->firstId();
    }

    private function assignToAllSalesChannels(string $shippingMethodId, Context $context): void
    {
        $payload = array_map(
            static fn (string $salesChannelId): array => [
                'id' => $salesChannelId,
                'shippingMethods' => [['id' => $shippingMethodId]],
            ],
            $this->salesChannelRepository->searchIds(new Criteria(), $context)->getIds(),
        );

        if ($payload !== []) {
            $this->salesChannelRepository->update($payload, $context);
        }
    }
}
