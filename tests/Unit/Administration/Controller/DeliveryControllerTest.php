<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Administration\Controller;

use Kommandhub\ChowdeckRelaySW\Administration\Controller\DeliveryController;
use Kommandhub\ChowdeckRelaySW\Client\ApiErrorReason;
use Kommandhub\ChowdeckRelaySW\Delivery\DeliveryException;
use Kommandhub\ChowdeckRelaySW\Delivery\Service\DeliveryService;
use Kommandhub\ChowdeckRelaySW\Delivery\Service\RequestResult;
use Kommandhub\ChowdeckRelaySW\Exception\ChowdeckApiException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(DeliveryController::class)]
#[UsesClass(RequestResult::class)]
#[UsesClass(ChowdeckApiException::class)]
#[UsesClass(ApiErrorReason::class)]
#[UsesClass(DeliveryException::class)]
class DeliveryControllerTest extends TestCase
{
    public function testRequestPassesTheConfirmationAndReturnsTheResult(): void
    {
        $service = $this->createMock(DeliveryService::class);
        $service->expects(static::once())->method('request')->with('d1', true)->willReturn(RequestResult::confirmationNeeded(3900.0, 4200.0, 'NGN'));

        $body = $this->json((new DeliveryController($service))->request('d1', new Request([], ['confirmed' => true]), Context::createDefaultContext()));

        // JSON turns 3900.0 into 3900.
        static::assertEquals(['success' => true, 'requested' => false, 'oldPrice' => 3900, 'newPrice' => 4200, 'currency' => 'NGN'], $body);
    }

    public function testRefreshAndCancelSucceed(): void
    {
        $service = $this->createMock(DeliveryService::class);
        $service->expects(static::once())->method('refresh')->with('d1');
        $service->expects(static::once())->method('cancel')->with('d1');
        $controller = new DeliveryController($service);

        static::assertSame(['success' => true], $this->json($controller->refresh('d1', Context::createDefaultContext())));
        static::assertSame(['success' => true], $this->json($controller->cancel('d1', Context::createDefaultContext())));
    }

    public function testRefusalsCarryTheReasonAndMessage(): void
    {
        $service = $this->createMock(DeliveryService::class);
        $service->method('cancel')->willThrowException(new ChowdeckApiException(ApiErrorReason::BadRequest, 'Delivery can no longer be cancelled', 400));
        $service->method('refresh')->willThrowException(DeliveryException::notRequested());
        $controller = new DeliveryController($service);

        static::assertSame(['success' => false, 'reason' => 'bad_request', 'message' => 'Delivery can no longer be cancelled'], $this->json($controller->cancel('d1', Context::createDefaultContext())));
        static::assertSame('not_requested', $this->json($controller->refresh('d1', Context::createDefaultContext()))['reason']);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(JsonResponse $response): array
    {
        $data = json_decode((string)$response->getContent(), true);
        \assert(\is_array($data));

        return $data;
    }
}
