<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Webhook\Controller;

use Kommandhub\ChowdeckRelaySW\Webhook\Service\WebhookReceiver;
use Kommandhub\ChowdeckRelaySW\Webhook\Service\WebhookSignatureValidator;
use Psr\Log\LoggerInterface;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Chowdeck's webhook endpoint (spec §13.6). Storefront scope, because
 * Chowdeck cannot authenticate against the Admin API; the signature is the
 * authentication. Answers at once — processing happens in the queue.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['storefront']])]
class WebhookController extends AbstractController
{
    public function __construct(
        private readonly WebhookReceiver $receiver,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/kmh-chowdeck/webhook/{salesChannelId}',
        name: 'frontend.kmh_chowdeck.webhook',
        requirements: ['salesChannelId' => '[0-9a-f]{32}|default'],
        defaults: ['auth_required' => false, 'XmlHttpRequest' => true],
        methods: ['POST'],
    )]
    public function receive(string $salesChannelId, Request $request): Response
    {
        $result = $this->receiver->receive(
            $request->getContent(),
            $request->headers->get(WebhookSignatureValidator::SIGNATURE_HEADER),
            $salesChannelId === 'default' ? null : $salesChannelId,
        );

        if ($result === WebhookReceiver::INVALID_SIGNATURE || $result === WebhookReceiver::INVALID_PAYLOAD) {
            $this->logger->warning('Chowdeck webhook rejected.', ['reason' => $result, 'ip' => $request->getClientIp()]);

            return new JsonResponse(['status' => $result], $result === WebhookReceiver::INVALID_SIGNATURE ? Response::HTTP_UNAUTHORIZED : Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['status' => $result]);
    }
}
