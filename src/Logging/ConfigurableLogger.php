<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Logging;

use Kommandhub\ChowdeckRelaySW\Setting\Service\Config;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Configurable logger wrapper.
 *
 * This logger acts as a gatekeeper around the underlying logger and allows
 * log output to be controlled through plugin configuration.
 *
 * Features:
 * - Enables/disables logging entirely via configuration.
 * - Filters log entries by configured PSR-3 log levels.
 * - Resolves both against the sales channel an entry belongs to.
 * - Falls back to logging all levels when no specific levels are configured
 *   to maintain backwards compatibility.
 */
class ConfigurableLogger extends AbstractLogger
{
    /**
     * Context key carrying the sales channel a log entry belongs to.
     *
     * `enableDebugging` and `logLevels` are ordinary plugin settings, so Shopware
     * lets a merchant scope them to a single sales channel. This class used to
     * read them without a sales channel id, i.e. from the global scope only,
     * which meant a merchant who enabled debugging on one sales channel got
     * nothing at all. PSR-3 has no argument for the scope, so callers pass the
     * id in the log context and it is resolved from there. Entries without the
     * key keep the previous behaviour and resolve against the global scope.
     *
     * The key is left in the forwarded context on purpose: it is useful
     * structured data on the entry itself.
     */
    public const CONTEXT_SALES_CHANNEL_ID = 'salesChannelId';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Config $config
    ) {
    }

    /**
     * Logs a message at the specified level.
     *
     * The message is forwarded to the underlying logger only if:
     * - Logging is enabled for the entry's sales channel.
     * - The log level is allowed by configuration for that sales channel.
     *
     * @param mixed $level PSR-3 log level
     * @param string|Stringable $message Log message
     * @param array<string, mixed> $context Additional context data
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $levelString = is_scalar($level) || $level instanceof Stringable ? (string)$level : 'unknown';

        if (!$this->shouldLog($levelString, $this->resolveSalesChannelId($context))) {
            return;
        }

        $this->logger->log($levelString, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveSalesChannelId(array $context): ?string
    {
        $salesChannelId = $context[self::CONTEXT_SALES_CHANNEL_ID] ?? null;

        return is_string($salesChannelId) && $salesChannelId !== '' ? $salesChannelId : null;
    }

    /**
     * Severity levels always written, regardless of the debug toggle, so
     * production keeps a trail of failures (webhook signature rejections,
     * verification/refund errors).
     */
    private const ALWAYS_LOGGED = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
    ];

    /**
     * Determines whether a log entry should be written.
     */
    private function shouldLog(string $level, ?string $salesChannelId): bool
    {
        if (in_array($level, self::ALWAYS_LOGGED, true)) {
            return true;
        }

        return $this->isLoggingEnabled($salesChannelId)
            && $this->isLevelAllowed($level, $salesChannelId);
    }

    /**
     * Determines whether logging is enabled for the entry's sales channel.
     */
    private function isLoggingEnabled(?string $salesChannelId): bool
    {
        return $this->config->getBool('enableDebugging', $salesChannelId);
    }

    /**
     * Determines whether the given log level is allowed for the entry's sales channel.
     *
     * If no log levels are configured, all levels are considered allowed.
     * This preserves backwards compatibility for installations that have
     * enabled debugging but have not explicitly selected any log levels.
     */
    private function isLevelAllowed(string $level, ?string $salesChannelId): bool
    {
        $allowedLevels = $this->config->getArray('logLevels', $salesChannelId);

        return empty($allowedLevels)
            || in_array($level, $allowedLevels, true);
    }
}
