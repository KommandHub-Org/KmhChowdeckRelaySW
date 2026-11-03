<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Administration\Controller;

use Kommandhub\ChowdeckRelaySW\Client\ChowdeckClient;
use Kommandhub\ChowdeckRelaySW\Client\Dto\WalletBalance;
use Kommandhub\ChowdeckRelaySW\Exception\ChowdeckApiException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin API behind the plugin configuration page (spec §13.8): test the saved
 * API key by reading the Relay wallet balance.
 *
 * Answers 200 with `success: false` and a reason on a Chowdeck failure, so the
 * config page can show it inline. The response never contains the API key:
 * only the balance or a mapped error.
 *
 * Uses the *saved* configuration of the given sales channel (or the global
 * one), so the merchant saves before testing.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
class ConnectionController extends AbstractController
{
    public function __construct(
        private readonly ChowdeckClient $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/api/_action/kmh-chowdeck/test-connection',
        name: 'api.action.kmh_chowdeck.test_connection',
        defaults: ['_acl' => ['system_config:update']],
        methods: ['POST'],
    )]
    public function testConnection(Request $request): JsonResponse
    {
        $value = $request->get('salesChannelId');
        $salesChannelId = is_string($value) && $value !== '' ? $value : null;

        try {
            $balance = $this->client->wallet()->balance($salesChannelId);
        } catch (ChowdeckApiException $e) {
            $this->logger->warning('Chowdeck connection test failed', [
                'reason' => $e->getReason()->value,
                'httpStatus' => $e->getHttpStatus(),
                'salesChannelId' => $salesChannelId,
            ]);

            return new JsonResponse(['success' => false, 'reason' => $e->getReason()->value, 'message' => $e->getMessage()]);
        }

        return new JsonResponse(['success' => true, 'balance' => $balance->balance, 'currency' => WalletBalance::CURRENCY]);
    }
}
