<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Exception;

use Kommandhub\ChowdeckRelaySW\Client\ApiErrorReason;

/**
 * A Chowdeck call that did not succeed, with the reason the plugin acts on.
 *
 * The message is either Chowdeck's own error message or a fixed text from
 * this plugin. It never contains request headers, so it can never contain an
 * API key, and is safe to log and to show to the merchant.
 */
class ChowdeckApiException extends ChowdeckException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        private readonly ApiErrorReason $reason,
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly array $errors = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    /**
     * @param array<mixed> $body decoded response body, possibly empty
     */
    public static function fromResponse(int $httpStatus, array $body): self
    {
        $message = is_string($body['message'] ?? null) && $body['message'] !== ''
            ? $body['message']
            : sprintf('Chowdeck request failed with HTTP %d.', $httpStatus);

        $errors = [];

        foreach (is_array($body['errors'] ?? null) ? $body['errors'] : [] as $error) {
            if (is_string($error)) {
                $errors[] = $error;
            }
        }

        // A 200 with "status": "failed" is still a failure; treat it as a bad request.
        $reason = ApiErrorReason::fromHttpStatus($httpStatus >= 400 ? $httpStatus : 400, $message);

        return new self($reason, $message, $httpStatus, $errors);
    }

    public static function notConfigured(): self
    {
        return new self(ApiErrorReason::NotConfigured, 'The Chowdeck API key is not configured.');
    }

    public function getReason(): ApiErrorReason
    {
        return $this->reason;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
