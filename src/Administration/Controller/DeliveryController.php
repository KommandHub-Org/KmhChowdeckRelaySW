<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Administration\Controller;

use Kommandhub\ChowdeckRelaySW\Delivery\DeliveryException;
use Kommandhub\ChowdeckRelaySW\Delivery\Service\DeliveryService;
use Kommandhub\ChowdeckRelaySW\Exception\ChowdeckApiException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin API behind the order's Chowdeck card (spec §13.5). Every route
 * answers 200; a refusal carries `success: false`, a reason and a message the
 * card shows as is.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
class DeliveryController extends AbstractController
{
    public function __construct(private readonly DeliveryService $deliveryService)
    {
    }

    #[Route(
        path: '/api/_action/kmh-chowdeck/delivery/{deliveryId}/request',
        name: 'api.action.kmh_chowdeck.delivery.request',
        requirements: ['deliveryId' => '[0-9a-f]{32}'],
        defaults: ['_acl' => ['kmh_chowdeck_delivery:update']],
        methods: ['POST'],
    )]
    public function request(string $deliveryId, Request $request, Context $context): JsonResponse
    {
        return $this->run(fn (): array => $this->deliveryService->request($deliveryId, $request->request->getBoolean('confirmed'), $context)->toArray());
    }

    #[Route(
        path: '/api/_action/kmh-chowdeck/delivery/{deliveryId}/refresh',
        name: 'api.action.kmh_chowdeck.delivery.refresh',
        requirements: ['deliveryId' => '[0-9a-f]{32}'],
        defaults: ['_acl' => ['kmh_chowdeck_delivery:update']],
        methods: ['POST'],
    )]
    public function refresh(string $deliveryId, Context $context): JsonResponse
    {
        return $this->run(function () use ($deliveryId, $context): array {
            $this->deliveryService->refresh($deliveryId, $context);

            return [];
        });
    }

    #[Route(
        path: '/api/_action/kmh-chowdeck/delivery/{deliveryId}/cancel',
        name: 'api.action.kmh_chowdeck.delivery.cancel',
        requirements: ['deliveryId' => '[0-9a-f]{32}'],
        defaults: ['_acl' => ['kmh_chowdeck_delivery:update']],
        methods: ['POST'],
    )]
    public function cancel(string $deliveryId, Context $context): JsonResponse
    {
        return $this->run(function () use ($deliveryId, $context): array {
            $this->deliveryService->cancel($deliveryId, $context);

            return [];
        });
    }

    /**
     * @param \Closure(): array<string, mixed> $action
     */
    private function run(\Closure $action): JsonResponse
    {
        try {
            return new JsonResponse(['success' => true] + $action());
        } catch (ChowdeckApiException $e) {
            return new JsonResponse(['success' => false, 'reason' => $e->getReason()->value, 'message' => $e->getMessage()]);
        } catch (DeliveryException $e) {
            return new JsonResponse(['success' => false, 'reason' => $e->reason, 'message' => $e->getMessage()]);
        }
    }
}
