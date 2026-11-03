<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Client\Resource;

use Kommandhub\ChowdeckRelaySW\Client\ApiErrorReason;
use Kommandhub\ChowdeckRelaySW\Client\ChowdeckClient;
use Kommandhub\ChowdeckRelaySW\Client\Dto\ArrayReader;
use Kommandhub\ChowdeckRelaySW\Client\Dto\CancelledDelivery;
use Kommandhub\ChowdeckRelaySW\Client\Dto\Contact;
use Kommandhub\ChowdeckRelaySW\Client\Dto\CreateDeliveryRequest;
use Kommandhub\ChowdeckRelaySW\Client\Dto\Delivery;
use Kommandhub\ChowdeckRelaySW\Client\Dto\DeliveryFee;
use Kommandhub\ChowdeckRelaySW\Client\Dto\FeeRequest;
use Kommandhub\ChowdeckRelaySW\Client\Dto\GeoPoint;
use Kommandhub\ChowdeckRelaySW\Client\Dto\WalletBalance;
use Kommandhub\ChowdeckRelaySW\Client\Http\ChowdeckHttpClient;
use Kommandhub\ChowdeckRelaySW\Client\Resource\ApiResource;
use Kommandhub\ChowdeckRelaySW\Client\Resource\Deliveries;
use Kommandhub\ChowdeckRelaySW\Exception\ChowdeckApiException;
use Kommandhub\ChowdeckRelaySW\Setting\Service\Config;
use Kommandhub\ChowdeckRelaySW\Tests\Unit\Client\ClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Request bodies and response mapping of the Relay delivery calls, with
 * payloads shaped like the examples in Chowdeck's API reference.
 */
#[CoversClass(Deliveries::class)]
#[CoversClass(ChowdeckClient::class)]
#[CoversClass(FeeRequest::class)]
#[CoversClass(GeoPoint::class)]
#[CoversClass(CreateDeliveryRequest::class)]
#[CoversClass(Contact::class)]
#[CoversClass(DeliveryFee::class)]
#[CoversClass(Delivery::class)]
#[CoversClass(CancelledDelivery::class)]
#[CoversClass(ArrayReader::class)]
#[UsesClass(WalletBalance::class)]
#[UsesClass(ApiResource::class)]
#[UsesClass(ChowdeckHttpClient::class)]
#[UsesClass(Config::class)]
#[UsesClass(ChowdeckApiException::class)]
#[UsesClass(ApiErrorReason::class)]
class ResourcesTest extends TestCase
{
    public function testFeeSendsAddressStringsAndReturnsNaira(): void
    {
        $response = ClientFactory::ok(['id' => 18987, 'total_amount' => 210000, 'delivery_amount' => 200000, 'service_amount' => 5000, 'safety_fee' => 5000]);

        $fee = ClientFactory::client($response)->deliveries()->fee(new FeeRequest('1 Shop St, Lekki, Lagos', '2 Home Rd, Yaba, Lagos'));

        static::assertSame('POST', $response->getRequestMethod());
        static::assertSame('https://api.chowdeck.com/relay/delivery/fee', $response->getRequestUrl());
        static::assertSame(
            ['source_address_string' => '1 Shop St, Lekki, Lagos', 'destination_address_string' => '2 Home Rd, Yaba, Lagos'],
            $this->body($response),
        );
        static::assertSame(18987, $fee->id);
        static::assertSame(2100.0, $fee->totalAmount);
        static::assertSame(2000.0, $fee->deliveryAmount);
        static::assertSame(50.0, $fee->serviceAmount);
        static::assertSame(50.0, $fee->safetyFee);
    }

    public function testFeeAddsCoordinatesNextToTheTextAndDeclaredValueInKobo(): void
    {
        $response = ClientFactory::ok([]);

        $fee = ClientFactory::client($response)->deliveries()->fee(new FeeRequest('a', 'b', new GeoPoint(6.43, 3.42), new GeoPoint(6.5, 3.37), 15000.5));

        static::assertSame([
            'source_address_string' => 'a',
            'destination_address_string' => 'b',
            'source_address' => ['latitude' => 6.43, 'longitude' => 3.42],
            'destination_address' => ['latitude' => 6.5, 'longitude' => 3.37],
            'estimated_order_amount' => 1500050,
        ], $this->body($response));
        static::assertSame(0, $fee->id);
    }

    public function testFeeWithOnlyDestinationCoordinates(): void
    {
        $response = ClientFactory::ok(['id' => 1]);

        ClientFactory::client($response)->deliveries()->fee(new FeeRequest('a', 'b', null, new GeoPoint(6.5, 3.37)));

        static::assertArrayNotHasKey('source_address', $this->body($response));
        static::assertArrayHasKey('destination_address', $this->body($response));
    }

    public function testCreateSendsTheFullBody(): void
    {
        $response = ClientFactory::ok([
            'id' => 98765,
            'reference' => 'kmh-abc',
            'delivery_price' => 80000,
            'delivery_pin' => 4821,
            'tracking_url' => 'https://track.chowdeck.com/x',
            'status' => 'preparing',
        ]);

        $delivery = ClientFactory::client($response)->deliveries()->create(new CreateDeliveryRequest(
            18987,
            'kmh-abc',
            'others',
            new Contact('Shop', '+2348000000001', 'shop@example.com'),
            new Contact('Ada', '+2348000000002'),
            'Order 10001',
            4821,
            ['sms', 'whatsapp'],
            5000.0,
        ));

        static::assertSame('https://api.chowdeck.com/relay/delivery', $response->getRequestUrl());
        static::assertSame([
            'fee_id' => 18987,
            'reference' => 'kmh-abc',
            'item_type' => 'others',
            'user_action' => 'sending',
            'source_contact' => ['name' => 'Shop', 'phone' => '+2348000000001', 'email' => 'shop@example.com', 'country_code' => 'NG'],
            'destination_contact' => ['name' => 'Ada', 'phone' => '+2348000000002', 'country_code' => 'NG'],
            'customer_delivery_note' => 'Order 10001',
            'delivery_pin' => 4821,
            'notification_channels' => ['sms', 'whatsapp'],
            'estimated_order_amount' => 500000,
        ], $this->body($response));
        static::assertSame(98765, $delivery->id);
        static::assertSame('kmh-abc', $delivery->reference);
        static::assertSame('preparing', $delivery->status);
        static::assertSame(800.0, $delivery->price);
        static::assertSame('NGN', $delivery->currency);
        static::assertSame('https://track.chowdeck.com/x', $delivery->trackingUrl);
        static::assertSame(4821, $delivery->deliveryPin);
        static::assertSame([], $delivery->rider);
    }

    public function testCreateLeavesOutOptionalFields(): void
    {
        $response = ClientFactory::ok(['reference' => 'r']);

        ClientFactory::client($response)->deliveries()->create(new CreateDeliveryRequest(1, 'r', 'documents', new Contact('S', '1'), new Contact('D', '2'), ''));

        static::assertSame(
            ['fee_id', 'reference', 'item_type', 'user_action', 'source_contact', 'destination_contact'],
            array_keys($this->body($response)),
        );
    }

    public function testGetReadsStatusPriceAndRider(): void
    {
        $response = ClientFactory::ok([
            'id' => 3122,
            'reference' => 'kmh/1',
            'status' => 'awaiting_pickup',
            'delivery_price' => '210000',
            'currency' => 'NGN',
            'driver' => ['first_name' => 'Tunde', 'last_name' => 'Bello', 'phone' => '+2348011111111'],
        ]);

        $delivery = ClientFactory::client($response)->deliveries()->get('kmh/1');

        static::assertSame('GET', $response->getRequestMethod());
        static::assertSame('https://api.chowdeck.com/relay/delivery/kmh%2F1', $response->getRequestUrl());
        static::assertSame('awaiting_pickup', $delivery->status);
        static::assertSame(2100.0, $delivery->price);
        static::assertSame(['name' => 'Tunde Bello', 'phone' => '+2348011111111'], $delivery->rider);
        static::assertNull($delivery->trackingUrl);
        static::assertNull($delivery->deliveryPin);
    }

    public function testRiderNameFieldWins(): void
    {
        $delivery = ClientFactory::client(ClientFactory::ok(['driver' => ['name' => 'Kemi', 'first_name' => 'X']]))->deliveries()->get('r');

        static::assertSame(['name' => 'Kemi'], $delivery->rider);
    }

    public function testCancelSendsReasonAndReturnsRefund(): void
    {
        $response = ClientFactory::ok(['id' => 98765, 'reference' => 'kmh-abc', 'status' => 'cancelled', 'refund_amount' => 80000, 'currency' => 'NGN']);

        $result = ClientFactory::client($response)->deliveries()->cancel('kmh-abc', 'Order cancelled by the shop');

        static::assertSame('https://api.chowdeck.com/relay/delivery/kmh-abc/cancel', $response->getRequestUrl());
        static::assertSame(['reason' => 'Order cancelled by the shop'], $this->body($response));
        static::assertSame('kmh-abc', $result->reference);
        static::assertSame('cancelled', $result->status);
        static::assertSame(800.0, $result->refundAmount);
        static::assertSame('NGN', $result->currency);
    }

    public function testCancelRefusedOncePickedUp(): void
    {
        $response = ClientFactory::json(['status' => 'failed', 'message' => 'Delivery can no longer be cancelled'], 400);

        try {
            ClientFactory::client($response)->deliveries()->cancel('kmh-abc', 'x');
            static::fail('Expected exception');
        } catch (ChowdeckApiException $e) {
            static::assertSame(ApiErrorReason::BadRequest, $e->getReason());
            static::assertSame('Delivery can no longer be cancelled', $e->getMessage());
        }
    }

    public function testMissingCurrencyDefaultsToNaira(): void
    {
        $result = ClientFactory::client(ClientFactory::ok(['refund_amount' => 'x']))->deliveries()->cancel('r', 'x');

        static::assertSame('NGN', $result->currency);
        static::assertSame(0.0, $result->refundAmount);
    }

    /**
     * @return array<mixed>
     */
    private function body(MockResponse $response): array
    {
        $body = json_decode((string)$response->getRequestOptions()['body'], true);
        \assert(\is_array($body));

        return $body;
    }
}
