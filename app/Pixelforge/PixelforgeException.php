<?php

declare(strict_types=1);

namespace App\Pixelforge;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

class PixelforgeException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $retryAfter = $response->header('Retry-After');

        return new self(
            $response->json('error.code') ?? "http_{$response->status()}",
            $response->json('error.message') ?? "Pixelforge responded with HTTP {$response->status()}.",
            $response->status(),
            is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    /**
     * Whether trying again (with the same Idempotency-Key) can work: Pixelforge was
     * busy and created nothing, or the outcome is unknown (network error, 5xx).
     */
    public function isRetryable(): bool
    {
        return in_array($this->errorCode, ['rate_limit_exceeded', 'concurrency_limit_exceeded'], true)
            || $this->status === 0
            || $this->status >= 500;
    }
}
