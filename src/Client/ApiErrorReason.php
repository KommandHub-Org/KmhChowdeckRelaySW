<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Client;

/**
 * Why a Chowdeck call failed, in terms the plugin can act on.
 *
 * Callers branch on this (hide the method, show the merchant a reason, retry),
 * never on HTTP codes or message strings.
 */
enum ApiErrorReason: string
{
    case NotConfigured = 'not_configured';
    case Unauthorized = 'unauthorized';
    case BadRequest = 'bad_request';
    case InsufficientBalance = 'insufficient_balance';
    case NotFound = 'not_found';
    case Validation = 'validation';
    case RateLimited = 'rate_limited';
    case ServerError = 'server_error';
    case Timeout = 'timeout';
    case Network = 'network';
    case InvalidResponse = 'invalid_response';

    /**
     * Chowdeck answers 400 with `"status": "failed"` for every refusal; only the
     * message tells an empty wallet apart (⚠ exact text unknown, spec §13.9).
     */
    public static function fromHttpStatus(int $status, string $message = ''): self
    {
        return match (true) {
            $status === 400 && preg_match('/balance|insufficient|fund/i', $message) === 1 => self::InsufficientBalance,
            $status === 400 => self::BadRequest,
            $status === 401, $status === 403 => self::Unauthorized,
            $status === 404 => self::NotFound,
            $status === 422 => self::Validation,
            $status === 429 => self::RateLimited,
            $status >= 500 => self::ServerError,
            default => self::BadRequest,
        };
    }

    /**
     * Transient failures are worth retrying later and must not be cached as a
     * definitive answer.
     */
    public function isTransient(): bool
    {
        return match ($this) {
            self::RateLimited, self::ServerError, self::Timeout, self::Network => true,
            default => false,
        };
    }
}
