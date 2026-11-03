<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Geocoding\Service;

use Kommandhub\ChowdeckRelaySW\Client\Dto\GeoPoint;
use Kommandhub\ChowdeckRelaySW\Geocoding\Service\Geocoder;
use Kommandhub\ChowdeckRelaySW\Setting\Service\Config;
use Kommandhub\ChowdeckRelaySW\Tests\Unit\Client\ClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(Geocoder::class)]
#[UsesClass(GeoPoint::class)]
#[UsesClass(Config::class)]
class GeocoderTest extends TestCase
{
    private const KEY = 'AIza-test-google-key';

    /**
     * @var list<array{string, string, array<mixed>}>
     */
    private array $logs = [];

    public function testFoundAddressIsSentWithRegionAndCached(): void
    {
        $response = $this->google('OK', ['geometry' => ['location' => ['lat' => 6.5095, 'lng' => 3.3711], 'location_type' => 'ROOFTOP']]);
        $http = new MockHttpClient([$response]);
        $geocoder = $this->geocoder(['geocodingEnabled' => true, 'googleMapsApiKey' => self::KEY], $http);

        $point = $geocoder->geocode("  15 Herbert Macaulay Way,\n Yaba, Lagos ");
        $again = $geocoder->geocode('15 HERBERT MACAULAY WAY, YABA, LAGOS');

        static::assertNotNull($point);
        static::assertSame(6.5095, $point->latitude);
        static::assertSame(3.3711, $point->longitude);
        static::assertEquals($point, $again);
        static::assertSame(1, $http->getRequestsCount());
        static::assertStringStartsWith('https://maps.googleapis.com/maps/api/geocode/json?', $response->getRequestUrl());
        static::assertStringContainsString('address=15%20Herbert%20Macaulay%20Way%2C%20Yaba%2C%20Lagos', $response->getRequestUrl());
        static::assertStringContainsString('region=ng', $response->getRequestUrl());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function inactive(): iterable
    {
        yield 'switched off' => [['geocodingEnabled' => false, 'googleMapsApiKey' => self::KEY], 'Yaba'];
        yield 'no key' => [['geocodingEnabled' => true], 'Yaba'];
        yield 'empty address' => [['geocodingEnabled' => true, 'googleMapsApiKey' => self::KEY], '  '];
    }

    /**
     * Without the switch and a key, nothing is sent to Google and the address
     * goes to Chowdeck as text.
     *
     * @param array<string, mixed> $config
     */
    #[DataProvider('inactive')]
    public function testInactiveSendsNothing(array $config, string $address): void
    {
        $http = new MockHttpClient(static fn () => throw new \LogicException('must not be called'));

        static::assertNull($this->geocoder($config, $http)->geocode($address));
        static::assertSame(0, $http->getRequestsCount());
    }

    public function testIsEnabledNeedsSwitchAndKey(): void
    {
        $http = new MockHttpClient();

        static::assertTrue($this->geocoder(['geocodingEnabled' => true, 'googleMapsApiKey' => self::KEY], $http)->isEnabled());
        static::assertFalse($this->geocoder(['geocodingEnabled' => true], $http)->isEnabled());
        static::assertFalse($this->geocoder(['googleMapsApiKey' => self::KEY], $http)->isEnabled());
    }

    public function testNoResultAndApproximateResultAreMissesCachedForAnHour(): void
    {
        $http = new MockHttpClient([
            $this->google('ZERO_RESULTS', null),
            $this->google('OK', ['geometry' => ['location' => ['lat' => 6.5, 'lng' => 3.4], 'location_type' => 'APPROXIMATE']]),
        ]);
        $geocoder = $this->geocoder(['geocodingEnabled' => true, 'googleMapsApiKey' => self::KEY], $http);

        static::assertNull($geocoder->geocode('nowhere'));
        static::assertNull($geocoder->geocode('nowhere'));
        static::assertNull($geocoder->geocode('Lagos'));
        static::assertSame(2, $http->getRequestsCount());
    }

    public function testRefusedKeyIsLoggedAsErrorAndNotCached(): void
    {
        $http = new MockHttpClient([$this->google('REQUEST_DENIED', null), $this->google('OVER_QUERY_LIMIT', null)]);
        $geocoder = $this->geocoder(['geocodingEnabled' => true, 'googleMapsApiKey' => self::KEY], $http);

        static::assertNull($geocoder->geocode('Yaba'));
        static::assertNull($geocoder->geocode('Yaba'));

        static::assertSame(2, $http->getRequestsCount());
        static::assertSame(['error', 'warning', 'warning'], array_column($this->logs, 0));
        static::assertSame('REQUEST_DENIED', $this->logs[0][2]['status']);
    }

    public function testTransportFailureNeverLogsTheKey(): void
    {
        $http = new MockHttpClient([new MockResponse('', ['error' => 'DNS failure for https://maps.googleapis.com/?key=' . self::KEY])]);

        static::assertNull($this->geocoder(['geocodingEnabled' => true, 'googleMapsApiKey' => self::KEY], $http)->geocode('Yaba'));
        static::assertStringNotContainsString(self::KEY, (string)json_encode($this->logs));
        static::assertSame('warning', $this->logs[0][0]);
    }

    public function testAnswerWithoutCoordinatesIsAFailure(): void
    {
        $http = new MockHttpClient([$this->google('OK', ['geometry' => ['location' => ['lat' => 'x']]])]);

        static::assertNull($this->geocoder(['geocodingEnabled' => true, 'googleMapsApiKey' => self::KEY], $http)->geocode('Yaba'));
        static::assertSame('warning', $this->logs[0][0]);
    }

    public function testPickupPrefersTheMerchantsCoordinates(): void
    {
        $http = new MockHttpClient(static fn () => throw new \LogicException('must not be called'));
        $geocoder = $this->geocoder(['geocodingEnabled' => true, 'googleMapsApiKey' => self::KEY, 'pickupLatitude' => '6.4474', 'pickupLongitude' => 3.473, 'pickupAddress' => 'Lekki'], $http);

        static::assertEquals(new GeoPoint(6.4474, 3.473), $geocoder->pickup());
    }

    public function testPickupFallsBackToTheGeocodedAddress(): void
    {
        $response = $this->google('OK', ['geometry' => ['location' => ['lat' => 6.44, 'lng' => 3.47], 'location_type' => 'RANGE_INTERPOLATED']]);
        $geocoder = $this->geocoder(['geocodingEnabled' => true, 'googleMapsApiKey' => self::KEY, 'pickupLatitude' => 6.4, 'pickupAddress' => '1 Admiralty Way, Lekki'], new MockHttpClient([$response]));

        static::assertEquals(new GeoPoint(6.44, 3.47), $geocoder->pickup());
        static::assertStringContainsString('address=1%20Admiralty%20Way%2C%20Lekki', $response->getRequestUrl());
    }

    /**
     * @param array<mixed>|null $result
     */
    private function google(string $status, ?array $result): MockResponse
    {
        return new MockResponse((string)json_encode(['status' => $status, 'results' => $result === null ? [] : [$result]]));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function geocoder(array $config, MockHttpClient $http): Geocoder
    {
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

        return new Geocoder(ClientFactory::config($config), $http, new ArrayAdapter(), $logger);
    }
}
