<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Setting\Service;

use Kommandhub\ChowdeckRelaySW\Setting\Service\Config;
use Kommandhub\ChowdeckRelaySW\Tests\Unit\Client\ClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[CoversClass(Config::class)]
class ConfigTest extends TestCase
{
    private SystemConfigService&MockObject $systemConfigService;
    private Config $config;

    protected function setUp(): void
    {
        $this->systemConfigService = $this->createMock(SystemConfigService::class);
        $this->config = new Config($this->systemConfigService);
    }

    public function testGet(): void
    {
        $this->systemConfigService->expects($this->once())
            ->method('get')
            ->with(Config::KEY . 'someKey', 'sales-channel-id')
            ->willReturn('someValue');

        $result = $this->config->get('someKey', null, 'sales-channel-id');
        $this->assertSame('someValue', $result);
    }

    public function testGetWithDefault(): void
    {
        $this->systemConfigService->expects($this->once())
            ->method('get')
            ->with(Config::KEY . 'missingKey', null)
            ->willReturn(null);

        $result = $this->config->get('missingKey', 'defaultValue');
        $this->assertSame('defaultValue', $result);
    }

    public function testGetString(): void
    {
        $this->systemConfigService->expects($this->once())
            ->method('get')
            ->with(Config::KEY . 'stringKey', null)
            ->willReturn('stringValue');

        $result = $this->config->getString('stringKey');
        $this->assertSame('stringValue', $result);
    }

    public function testGetStringWithNonString(): void
    {
        $this->systemConfigService->expects($this->once())
            ->method('get')
            ->with(Config::KEY . 'nonStringKey', null)
            ->willReturn(123);

        $result = $this->config->getString('nonStringKey');
        $this->assertSame('', $result);
    }

    public function testGetBool(): void
    {
        $this->systemConfigService->expects($this->once())
            ->method('getBool')
            ->with(Config::KEY . 'boolKey', 'sales-channel-id')
            ->willReturn(true);

        $result = $this->config->getBool('boolKey', 'sales-channel-id');
        $this->assertTrue($result);
    }

    public function testGetArray(): void
    {
        $expectedArray = ['val1', 'val2'];
        $this->systemConfigService->expects($this->once())
            ->method('get')
            ->with(Config::KEY . 'arrayKey', null)
            ->willReturn($expectedArray);

        $result = $this->config->getArray('arrayKey');
        $this->assertSame($expectedArray, $result);
    }

    public function testGetArrayWithNonArray(): void
    {
        $this->systemConfigService->expects($this->once())
            ->method('get')
            ->with(Config::KEY . 'nonArrayKey', null)
            ->willReturn('not-an-array');

        $result = $this->config->getArray('nonArrayKey');
        $this->assertSame([], $result);
    }

    public function testApiKeyAndNumericReaders(): void
    {
        $config = ClientFactory::config(['apiKey' => 'k', 'markup' => '0.75', 'ttl' => 600, 'bad' => 'x']);

        $this->assertSame('k', $config->getApiKey());
        $this->assertSame(0.75, $config->getFloat('markup', 0.5));
        $this->assertSame(0.5, $config->getFloat('bad', 0.5));
        $this->assertSame(600, $config->getInt('ttl', 1800));
        $this->assertSame(1800, $config->getInt('missing', 1800));
    }
}
