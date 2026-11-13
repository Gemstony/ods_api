<?php

namespace App\Services\ClickPesa;

use RuntimeException;
use Throwable;

/**
 * Every failure from the ClickPesa client is wrapped in this exception.
 *
 * Context fields let callers (Phase 11.2 payment service, webhook handlers)
 * make decisions without re-parsing strings:
 *   - $httpStatus   → null on network errors, otherwise the HTTP code
 *   - $responseBody → raw JSON from ClickPesa (already logged to
 *                     clickpesa_api_logs; included here for convenience)
 *   - $logId        → id of the clickpesa_api_logs row so callers can
 *                     surface "see log #N" in error UIs
 *   - $endpoint     → relative path, for telemetry
 *
 * Phase 11.2 will pattern-match on $httpStatus (e.g. 422 = duplicate
 * orderReference → query instead of retry).
 */
class ClickPesaException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?array $responseBody = null,
        public readonly ?int $logId = null,
        public readonly ?string $endpoint = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}