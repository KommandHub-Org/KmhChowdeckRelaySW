<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Tests\Unit\Logging;

use Kommandhub\ChowdeckRelaySW\Logging\ConfigurableLogger;
use Kommandhub\ChowdeckRelaySW\Setting\Service\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

#[CoversClass(ConfigurableLogger::class)]
class ConfigurableLoggerTest extends TestCase
{
    private LoggerInterface&MockObject $inner;
    private Config&MockObject $config;
    private ConfigurableLogger $logger;

    protected function setUp(): void
    {
        $this->inner = $this->createMock(LoggerInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->logger = new ConfigurableLogger($this->inner, $this->config);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function alwaysLoggedLevelProvider(): array
    {
        return [
            'emergency' => [LogLevel::EMERGENCY],
            'alert' => [LogLevel::ALERT],
            'critical' => [LogLevel::CRITICAL],
            'error' => [LogLevel::ERROR],
        ];
    }

    /**
     * Regression guard for the behaviour this class was written to fix: the old
     * handler gated logError() behind the debug toggle, so production lost every
     * webhook rejection and refund failure — exactly the entries worth keeping.
     */
    #[DataProvider('alwaysLoggedLevelProvider')]
    public function testSeverityIsLoggedEvenWhenDebuggingIsDisabled(string $level): void
    {
        $this->config->method('getBool')->with('enableDebugging', null)->willReturn(false);
        $this->config->expects(static::never())->method('getArray');

        $this->inner->expects(static::once())
            ->method('log')
            ->with($level, 'boom', ['a' => 1]);

        $this->logger->log($level, 'boom', ['a' => 1]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function gatedLevelProvider(): array
    {
        return [
            'warning' => [LogLevel::WARNING],
            'notice' => [LogLevel::NOTICE],
            'info' => [LogLevel::INFO],
            'debug' => [LogLevel::DEBUG],
        ];
    }

    #[DataProvider('gatedLevelProvider')]
    public function testNonSeverityIsSuppressedWhenDebuggingIsDisabled(string $level): void
    {
        $this->config->method('getBool')->with('enableDebugging', null)->willReturn(false);

        $this->inner->expects(static::never())->method('log');

        $this->logger->log($level, 'chatter');
    }

    #[DataProvider('gatedLevelProvider')]
    public function testNonSeverityIsLoggedWhenDebuggingIsEnabledAndNoLevelsConfigured(string $level): void
    {
        $this->config->method('getBool')->willReturn(true);
        $this->config->method('getArray')->with('logLevels', null)->willReturn([]);

        $this->inner->expects(static::once())->method('log')->with($level, 'chatter', []);

        $this->logger->log($level, 'chatter');
    }

    public function testConfiguredLevelIsAllowed(): void
    {
        $this->config->method('getBool')->willReturn(true);
        $this->config->method('getArray')->willReturn([LogLevel::INFO]);

        $this->inner->expects(static::once())->method('log')->with(LogLevel::INFO, 'kept', []);

        $this->logger->log(LogLevel::INFO, 'kept');
    }

    public function testUnconfiguredLevelIsFilteredOut(): void
    {
        $this->config->method('getBool')->willReturn(true);
        $this->config->method('getArray')->willReturn([LogLevel::INFO]);

        $this->inner->expects(static::never())->method('log');

        $this->logger->log(LogLevel::DEBUG, 'dropped');
    }

    public function testStringableMessageIsForwarded(): void
    {
        $this->config->method('getBool')->willReturn(false);

        $message = new class() implements \Stringable {
            public function __toString(): string
            {
                return 'stringable';
            }
        };

        $this->inner->expects(static::once())->method('log')->with(LogLevel::ERROR, $message, []);

        $this->logger->log(LogLevel::ERROR, $message);
    }

    /**
     * PSR-3 types $level as mixed, so a non-scalar must not fatal here. It cannot
     * match a known level, so it falls through the debug gate like any other
     * unrecognised level.
     */
    public function testNonScalarLevelIsNormalisedAndDoesNotFatal(): void
    {
        $this->config->method('getBool')->willReturn(true);
        $this->config->method('getArray')->willReturn([]);

        $this->inner->expects(static::once())->method('log')->with('unknown', 'weird', []);

        $this->logger->log(['not', 'a', 'level'], 'weird');
    }

    /**
     * AbstractLogger's helpers must route through log() and respect the gate.
     */
    public function testAbstractLoggerHelpersRouteThroughTheGate(): void
    {
        $this->config->method('getBool')->willReturn(false);

        $this->inner->expects(static::once())->method('log')->with(LogLevel::ERROR, 'via helper', []);

        $this->logger->error('via helper');
        $this->logger->info('suppressed');
    }

    /**
     * enableDebugging is a sales-channel-scopable setting, so the logger must
     * resolve it against the channel the entry belongs to. Chowdeck's logger
     * reads only the global scope, which means a merchant who enables debugging
     * on a single sales channel there gets nothing; this is the deliberate
     * deviation from that reference.
     */
    public function testDebuggingIsResolvedAgainstTheSalesChannelInContext(): void
    {
        $this->config->expects(static::once())
            ->method('getBool')
            ->with('enableDebugging', 'sales-channel-id')
            ->willReturn(true);
        $this->config->method('getArray')
            ->with('logLevels', 'sales-channel-id')
            ->willReturn([]);

        $this->inner->expects(static::once())->method('log');

        $this->logger->info('scoped', [ConfigurableLogger::CONTEXT_SALES_CHANNEL_ID => 'sales-channel-id']);
    }

    public function testDebuggingDisabledForThatSalesChannelSuppressesTheEntry(): void
    {
        $this->config->method('getBool')
            ->with('enableDebugging', 'sales-channel-id')
            ->willReturn(false);

        $this->inner->expects(static::never())->method('log');

        $this->logger->info('scoped', [ConfigurableLogger::CONTEXT_SALES_CHANNEL_ID => 'sales-channel-id']);
    }

    public function testLogLevelsAreResolvedAgainstTheSalesChannelInContext(): void
    {
        $this->config->method('getBool')->willReturn(true);
        $this->config->expects(static::once())
            ->method('getArray')
            ->with('logLevels', 'sales-channel-id')
            ->willReturn([LogLevel::INFO]);

        $this->inner->expects(static::never())->method('log');

        $this->logger->debug('scoped', [ConfigurableLogger::CONTEXT_SALES_CHANNEL_ID => 'sales-channel-id']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableSalesChannelIdProvider(): array
    {
        return [
            'absent' => [null],
            'empty string' => [''],
            'not a string' => [123],
        ];
    }

    /**
     * An unusable id must fall back to the global scope rather than be forwarded
     * to SystemConfigService, which would either fatal or silently miss.
     */
    #[DataProvider('unusableSalesChannelIdProvider')]
    public function testUnusableSalesChannelIdFallsBackToGlobalScope(mixed $salesChannelId): void
    {
        $this->config->expects(static::once())
            ->method('getBool')
            ->with('enableDebugging', null)
            ->willReturn(true);
        $this->config->method('getArray')->with('logLevels', null)->willReturn([]);

        $this->inner->expects(static::once())->method('log');

        $this->logger->info('global', [ConfigurableLogger::CONTEXT_SALES_CHANNEL_ID => $salesChannelId]);
    }

    /**
     * The id is deliberately left in the forwarded context: it is useful
     * structured data on the entry itself, not just a routing hint.
     */
    public function testSalesChannelIdRemainsInTheForwardedContext(): void
    {
        $this->config->method('getBool')->willReturn(true);
        $this->config->method('getArray')->willReturn([]);

        $this->inner->expects(static::once())
            ->method('log')
            ->with(LogLevel::INFO, 'kept', [
                ConfigurableLogger::CONTEXT_SALES_CHANNEL_ID => 'sales-channel-id',
                'orderTransactionId' => 'abc',
            ]);

        $this->logger->info('kept', [
            ConfigurableLogger::CONTEXT_SALES_CHANNEL_ID => 'sales-channel-id',
            'orderTransactionId' => 'abc',
        ]);
    }

    /**
     * A failure on a channel with debugging off must still be recorded.
     */
    public function testSeverityIgnoresScopedDebuggingToo(): void
    {
        $this->config->expects(static::never())->method('getBool');

        $this->inner->expects(static::once())->method('log');

        $this->logger->error('boom', [ConfigurableLogger::CONTEXT_SALES_CHANNEL_ID => 'sales-channel-id']);
    }
}
