<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Quote;

use Kommandhub\ChowdeckRelaySW\Client\ApiErrorReason;
use Kommandhub\ChowdeckRelaySW\Client\ChowdeckClient;
use Kommandhub\ChowdeckRelaySW\Client\Dto\ArrayReader;
use Kommandhub\ChowdeckRelaySW\Client\Dto\DeliveryFee;
use Kommandhub\ChowdeckRelaySW\Client\Dto\FeeRequest;
use Kommandhub\ChowdeckRelaySW\Client\Dto\GeoPoint;
use Kommandhub\ChowdeckRelaySW\Client\Http\ChowdeckHttpClient;
use Kommandhub\ChowdeckRelaySW\Client\Resource\ApiResource;
use Kommandhub\ChowdeckRelaySW\Client\Resource\Deliveries;
use Kommandhub\ChowdeckRelaySW\Exception\ChowdeckApiException;
use Kommandhub\ChowdeckRelaySW\Geocoding\Service\Geocoder;
use Kommandhub\ChowdeckRelaySW\Quote\FailureNotifier;
use Kommandhub\ChowdeckRelaySW\Quote\QuoteError;
use Kommandhub\ChowdeckRelaySW\Quote\QuoteService;
use Kommandhub\ChowdeckRelaySW\Quote\QuoteStruct;
use Kommandhub\ChowdeckRelaySW\Setting\Service\Config;
use Kommandhub\ChowdeckRelaySW\Shipping\Address\AddressLine;
use Kommandhub\ChowdeckRelaySW\Tests\Unit\Client\ClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Shopware\Core\Framework\Notification\NotificationService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(QuoteService::class)]
#[UsesClass(Config::class)]
#[UsesClass(ChowdeckClient::class)]
#[UsesClass(ChowdeckHttpClient::class)]
#[UsesClass(Deliveries::class)]
#[UsesClass(ApiResource::class)]
#[UsesClass(DeliveryFee::class)]
#[UsesClass(FeeRequest::class)]
#[UsesClass(GeoPoint::class)]
#[UsesClass(ArrayReader::class)]
#[UsesClass(ChowdeckApiException::class)]
#[UsesClass(ApiErrorReason::class)]
#[UsesClass(Geocoder::class)]
#[UsesClass(AddressLine::class)]
#[UsesClass(QuoteError::class)]
#[UsesClass(QuoteStruct::class)]
#[UsesClass(FailureNotifier::class)]
class QuoteServiceTest extends TestCase
{
    private const CONFIG = ['apiKey' => 'sk_test_key', 'pickupAddress' => '1 Admiralty Way, Lekki Phase 1, Lagos'];

    private const FEE = ['id' => 39702, 'total_amount' => 480000, 'delivery_amount' => 480000, 'service_amount' => 0, 'safety_fee' => 0];

    /**
     * @var list<array<mixed>>
     */
    private array $feeBodies = [];

    /**
     * @var list<array{string, string, array<mixed>}>
     */
    private array $logs = [];

    private int $notifications = 0;

    public function testTextOnlyQuoteWithFixedMarkupIsCached(): void
    {
        $service = $this->service(self::CONFIG + ['markupType' => 'fixed', 'markupValue' => 200], [ClientFactory::ok(self::FEE)]);

        $quote = $service->quote(CheckoutFixture::cart(), CheckoutFixture::context());
        $again = $service->quote(CheckoutFixture::cart(), CheckoutFixture::context());

        static::assertInstanceOf(QuoteStruct::class, $quote);
        static::assertSame(39702, $quote->feeId);
        static::assertSame(4800.0, $quote->carrierTotal);
        static::assertSame(5000.0, $quote->price);
        static::assertSame('NGN', $quote->currency);
        static::assertFalse($quote->fallback);
        static::assertEquals($quote, $again);
        static::assertCount(1, $this->feeBodies);
        static::assertSame([
            'source_address_string' => '1 Admiralty Way, Lekki Phase 1, Lagos',
            'destination_address_string' => '15 Awolowo Road, Lagos, Lagos, 101233, Nigeria',
        ], $this->feeBodies[0]);
    }

    public function testChangedAddressQuotesAgain(): void
    {
        $service = $this->service(self::CONFIG, [ClientFactory::ok(self::FEE), ClientFactory::ok(self::FEE)]);

        $service->quote(CheckoutFixture::cart(), CheckoutFixture::context());
        $service->quote(CheckoutFixture::cart(), CheckoutFixture::context(street: '2 Ozumba Mbadiwe Avenue'));

        static::assertCount(2, $this->feeBodies);
    }

    public function testPercentMarkup(): void
    {
        $quote = $this->service(self::CONFIG + ['markupType' => 'percent', 'markupValue' => 10], [ClientFactory::ok(self::FEE)])
            ->quote(CheckoutFixture::cart(), CheckoutFixture::context());

        static::assertInstanceOf(QuoteStruct::class, $quote);
        static::assertEqualsWithDelta(5280.0, $quote->price, 0.001);
    }

    /**
     * With geocoding on, both sides get coordinates next to the text: the
     * merchant's own pickup coordinates and the geocoded customer address.
     */
    public function testGeocodedCoordinatesAreSentWithTheText(): void
    {
        $google = new MockResponse((string)json_encode(['status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => 6.4318, 'lng' => 3.4216], 'location_type' => 'ROOFTOP']]]]));
        $config = self::CONFIG + ['geocodingEnabled' => true, 'googleMapsApiKey' => 'g', 'pickupLatitude' => 6.4474, 'pickupLongitude' => 3.473];

        $this->service($config, [ClientFactory::ok(self::FEE)], new MockHttpClient([$google]))->quote(CheckoutFixture::cart(), CheckoutFixture::context());

        static::assertSame(['latitude' => 6.4474, 'longitude' => 3.473], $this->feeBodies[0]['source_address']);
        static::assertSame(['latitude' => 6.4318, 'longitude' => 3.4216], $this->feeBodies[0]['destination_address']);
        static::assertSame('15 Awolowo Road, Lagos, Lagos, 101233, Nigeria', $this->feeBodies[0]['destination_address_string']);
    }

    public function testDeclaredValueIsTheProductTotalInKobo(): void
    {
        $this->service(self::CONFIG + ['insureParcels' => true], [ClientFactory::ok(self::FEE)])
            ->quote(CheckoutFixture::cart(['p1' => 1.0, 'p2' => 1.0]), CheckoutFixture::context());

        // Two products at 5,000 × 2 each.
        static::assertSame(2000000, $this->feeBodies[0]['estimated_order_amount']);
    }

    public function testOtherCurrencyIsRefusedWithoutACall(): void
    {
        static::assertSame(QuoteError::CurrencyMismatch, $this->service(self::CONFIG, [])->quote(CheckoutFixture::cart(), CheckoutFixture::context(currency: 'EUR')));
        static::assertSame([], $this->feeBodies);
        static::assertSame('notice', $this->logs[0][0]);
    }

    public function testMissingPickupOrKeyIsNotConfiguredAndTellsTheAdmin(): void
    {
        static::assertSame(QuoteError::NotConfigured, $this->service(['apiKey' => 'k'], [])->quote(CheckoutFixture::cart(), CheckoutFixture::context()));
        static::assertSame(QuoteError::NotConfigured, $this->service(['pickupAddress' => 'Lekki'], [])->quote(CheckoutFixture::cart(), CheckoutFixture::context()));
        static::assertSame([], $this->feeBodies);
        static::assertSame('error', $this->logs[0][0]);
        static::assertSame(2, $this->notifications);
    }

    public function testRiderNeedsStreetCityAndPhone(): void
    {
        $service = $this->service(self::CONFIG, []);

        static::assertSame(QuoteError::IncompleteAddress, $service->quote(CheckoutFixture::cart(), CheckoutFixture::context(phone: '')));
        static::assertSame(QuoteError::IncompleteAddress, $service->quote(CheckoutFixture::cart(), CheckoutFixture::context(street: ' ')));
        static::assertSame(0, $this->notifications);
    }

    public function testAddressChowdeckCannotFindIsInvalidAndNotCached(): void
    {
        $refusal = static fn () => ClientFactory::json(['status' => 'failed', 'message' => 'Could not find coordinates for destination address'], 400);
        $service = $this->service(self::CONFIG, [$refusal(), $refusal()]);

        static::assertSame(QuoteError::InvalidAddress, $service->quote(CheckoutFixture::cart(), CheckoutFixture::context()));
        static::assertSame(QuoteError::InvalidAddress, $service->quote(CheckoutFixture::cart(), CheckoutFixture::context()));
        static::assertCount(2, $this->feeBodies);
        static::assertSame('notice', $this->logs[0][0]);
        static::assertSame(400, $this->logs[0][2]['httpStatus']);
        static::assertSame('Could not find coordinates for destination address', $this->logs[0][2]['message']);
    }

    public function testAccountWithoutRelayIsNotConfigured(): void
    {
        $service = $this->service(self::CONFIG, [ClientFactory::json(['status' => 'failed', 'message' => 'Vendor not found'], 400)]);

        static::assertSame(QuoteError::NotConfigured, $service->quote(CheckoutFixture::cart(), CheckoutFixture::context()));
        static::assertSame(1, $this->notifications);
    }

    public function testChowdeckDownIsUnavailable(): void
    {
        $service = $this->service(self::CONFIG, [new MockResponse('', ['http_code' => 503])]);

        static::assertSame(QuoteError::Unavailable, $service->quote(CheckoutFixture::cart(), CheckoutFixture::context()));
        static::assertSame('error', $this->logs[0][0]);
        static::assertStringNotContainsString('sk_test_key', (string)json_encode($this->logs));
    }

    /**
     * @param array<string, mixed> $config
     * @param list<MockResponse> $feeResponses
     */
    private function service(array $config, array $feeResponses, ?MockHttpClient $google = null): QuoteService
    {
        $config = ClientFactory::config($config);
        $bodies = &$this->feeBodies;
        $chowdeck = new MockHttpClient(static function (string $method, string $url, array $options) use (&$feeResponses, &$bodies): MockResponse {
            $body = json_decode((string)($options['body'] ?? ''), true);
            $bodies[] = \is_array($body) ? $body : [];

            return array_shift($feeResponses) ?? throw new \LogicException('Unexpected Chowdeck call');
        });

        $logs = &$this->logs;
        $logger = new class($logs) extends AbstractLogger {
            /**
             * @param list<array{string, string, array<mixed>}> $logs
             */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = [(string)$level, (string)$message, $context];
            }
        };

        $notifications = $this->createMock(NotificationService::class);
        $notifications->method('createNotification')->willReturnCallback(function (): void {
            ++$this->notifications;
        });

        return new QuoteService(
            $config,
            new ChowdeckClient(new ChowdeckHttpClient($config, $chowdeck)),
            new Geocoder($config, $google ?? new MockHttpClient(), new ArrayAdapter(), $logger),
            new FailureNotifier($notifications, new ArrayAdapter()),
            new ArrayAdapter(),
            $logger,
        );
    }
}
