<?php

namespace App\Services\ClickPesa;

use App\Models\ClickPesaApiLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The single entry point for all ClickPesa HTTP calls (Phase 11.1).
 *
 * Responsibilities:
 *   - Manage the access token via ClickPesaTokenProvider + auth endpoint
 *   - Make HTTP calls with consistent headers and timeouts
 *   - Log EVERY call (request + response, redacted) to clickpesa_api_logs
 *   - Retry once on 401 after refreshing the token
 *   - Surface failures as ClickPesaException
 *
 * Deliberately NOT responsible for:
 *   - Creating / updating Payment rows (11.2)
 *   - Processing webhooks (11.2)
 *   - Retrying on 5xx or business errors (11.2 decides retry policy)
 *
 * Concurrency note: two simultaneous processes could race on
 * ensureFreshToken() and both hit the auth endpoint. ClickPesa's token
 * limit is 1 per 3 min per IP, so the second call gets a 429 that
 * surfaces as a normal ClickPesaException — rare, recoverable. Phase 15
 * (multi-instance) will add a cache mutex around the auth call.
 */
class ClickPesaClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $defaultCurrency,
        private readonly int $timeout,
        private readonly int $connectTimeout,
        private readonly int $tokenTtlMinutes,
        private readonly ClickPesaTokenProvider $tokenProvider,
    ) {
        if ($clientId === '' || $clientSecret === '') {
            throw new ClickPesaException(
                'ClickPesa credentials are not configured. '
                . 'Set CLICKPESA_CLIENT_ID and CLICKPESA_CLIENT_SECRET in .env.'
            );
        }
    }

    // ═════════════════════════════════════════════════════════
    // Public API
    // ═════════════════════════════════════════════════════════

    /**
     * Ensure a valid token exists in the cache, calling the auth
     * endpoint if necessary. Returns the full "Bearer ..." string.
     */
    public function ensureFreshToken(?string $correlationId = null): string
    {
        $cached = $this->tokenProvider->findValid();
        if ($cached !== null) {
            return $cached->access_token;
        }

        $correlationId ??= (string) Str::uuid();

        $response = $this->httpCall(
            method: 'POST',
            path: '/third-parties/generate-token',
            headers: [
                'client-id' => $this->clientId,
                'api-key'   => $this->clientSecret,
                'Accept'    => 'application/json',
            ],
            body: null,
            correlationId: $correlationId,
            paymentId: null,
            triggeredBy: 'system',
        );

        $token = $response['token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new ClickPesaException(
                'ClickPesa auth response missing "token" field.',
                httpStatus: 200,
                responseBody: $response,
            );
        }

        $this->tokenProvider->store($token, $this->tokenTtlMinutes);

        return $token;
    }

    public function previewUssdPush(
        UssdPushRequest $request,
        ?int $paymentId = null,
        ?string $correlationId = null,
    ): array {
        return $this->callAuthenticated(
            method: 'POST',
            path: '/third-parties/payments/preview-ussd-push-request',
            body: $request->toPayload(),
            paymentId: $paymentId,
            triggeredBy: 'api',
            correlationId: $correlationId,
        );
    }

    public function initiateUssdPush(
        UssdPushRequest $request,
        ?int $paymentId = null,
        ?string $correlationId = null,
    ): array {
        return $this->callAuthenticated(
            method: 'POST',
            path: '/third-parties/payments/initiate-ussd-push-request',
            body: $request->toPayload(),
            paymentId: $paymentId,
            triggeredBy: 'api',
            correlationId: $correlationId,
        );
    }

    /**
     * Query a single payment by orderReference.
     *
     * Returns a normalized payload — the `status` field is a PaymentStatus
     * enum instance, not a raw string. Callers (11.2) can pattern-match
     * on it directly.
     */
    public function queryPayment(
        string $orderReference,
        ?int $paymentId = null,
        ?string $correlationId = null,
    ): array {
        $response = $this->callAuthenticated(
            method: 'GET',
            path: '/third-parties/payments/' . rawurlencode($orderReference),
            body: null,
            paymentId: $paymentId,
            triggeredBy: 'api',
            correlationId: $correlationId,
        );

        return [
            'order_reference' => $orderReference,
            'status'          => PaymentStatus::fromString($response['status'] ?? null),
            'amount'          => isset($response['amount']) ? (float) $response['amount'] : null,
            'currency'        => $response['currency'] ?? null,
            'provider_id'     => $response['id'] ?? null,
            'raw'             => $response,
        ];
    }

    /**
     * Query all payments, paginated. Used by reconciliation and Owner
     * finance reporting (Phase 11.4).
     */
    public function queryAllPayments(
        array $filters = [],
        ?string $correlationId = null,
    ): array {
        return $this->callAuthenticated(
            method: 'GET',
            path: '/third-parties/payments/all',
            body: null,
            paymentId: null,
            triggeredBy: 'api',
            correlationId: $correlationId,
            query: $filters,
        );
    }

    // ═════════════════════════════════════════════════════════
    // Internal
    // ═════════════════════════════════════════════════════════

    /**
     * Authenticated call. On 401, clears the cached token, refreshes,
     * and retries ONCE. Further 401s are propagated as-is.
     */
    private function callAuthenticated(
        string $method,
        string $path,
        ?array $body,
        ?int $paymentId,
        string $triggeredBy,
        ?string $correlationId,
        array $query = [],
    ): array {
        $correlationId ??= (string) Str::uuid();

        $token = $this->ensureFreshToken($correlationId);

        try {
            return $this->httpCall(
                method: $method,
                path: $path,
                headers: [
                    'Authorization' => $token,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                body: $body,
                correlationId: $correlationId,
                paymentId: $paymentId,
                triggeredBy: $triggeredBy,
                query: $query,
            );
        } catch (ClickPesaException $e) {
            if ($e->httpStatus !== 401) {
                throw $e;
            }

            // Token rejected server-side — clear cache, refresh, retry once.
            $this->tokenProvider->clear();
            $token = $this->ensureFreshToken($correlationId);

            return $this->httpCall(
                method: $method,
                path: $path,
                headers: [
                    'Authorization' => $token,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                body: $body,
                correlationId: $correlationId,
                paymentId: $paymentId,
                triggeredBy: $triggeredBy,
                query: $query,
            );
        }
    }

    /**
     * The ONLY place in the client that talks HTTP.
     * Always logs — success or failure — before returning or throwing.
     */
    private function httpCall(
        string $method,
        string $path,
        array $headers,
        ?array $body,
        string $correlationId,
        ?int $paymentId,
        string $triggeredBy,
        array $query = [],
    ): array {
        $url = rtrim($this->baseUrl, '/') . $path;
        if (! empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $start = microtime(true);
        $response = null;
        $errorMessage = null;
        $thrown = null;

        try {
            $request = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout);

            $response = strtoupper($method) === 'GET'
                ? $request->get($url)
                : $request->post($url, $body ?? []);
        } catch (ConnectionException $e) {
            $errorMessage = 'Connection error: ' . $e->getMessage();
            $thrown = new ClickPesaException(
                message: $errorMessage,
                endpoint: $path,
                previous: $e,
            );
        } catch (Throwable $e) {
            $errorMessage = 'Unexpected HTTP error: ' . $e->getMessage();
            $thrown = new ClickPesaException(
                message: $errorMessage,
                endpoint: $path,
                previous: $e,
            );
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);

        $log = $this->writeLog(
            correlationId: $correlationId,
            method: strtoupper($method),
            path: $path,
            fullUrl: $url,
            requestHeaders: $headers,
            requestBody: $body,
            responseStatus: $response?->status(),
            responseBody: $response?->json(),
            responseHeaders: $response?->headers(),
            errorMessage: $errorMessage,
            durationMs: $durationMs,
            paymentId: $paymentId,
            triggeredBy: $triggeredBy,
        );

        if ($thrown !== null) {
            // Re-throw with the log id attached so callers can surface it.
            throw new ClickPesaException(
                message: $thrown->getMessage(),
                httpStatus: null,
                responseBody: null,
                logId: $log->id,
                endpoint: $path,
                previous: $thrown->getPrevious(),
            );
        }

        if (! $response->successful()) {
            $errorMsg = $this->extractErrorMessage($response->json(), $response->status());
            throw new ClickPesaException(
                message: $errorMsg,
                httpStatus: $response->status(),
                responseBody: $response->json(),
                logId: $log->id,
                endpoint: $path,
            );
        }

        return $response->json() ?? [];
    }

    // ═════════════════════════════════════════════════════════
    // Logging + redaction
    // ═════════════════════════════════════════════════════════

    private function writeLog(
        string $correlationId,
        string $method,
        string $path,
        string $fullUrl,
        array $requestHeaders,
        ?array $requestBody,
        ?int $responseStatus,
        ?array $responseBody,
        ?array $responseHeaders,
        ?string $errorMessage,
        int $durationMs,
        ?int $paymentId,
        string $triggeredBy,
    ): ClickPesaApiLog {
        return ClickPesaApiLog::create([
            'correlation_id'   => $correlationId,
            'method'           => $method,
            'endpoint'         => $path,
            'full_url'         => $fullUrl,
            'request_headers'  => $this->redactHeaders($requestHeaders),
            'request_body'     => $this->redactBody($requestBody),
            'response_status'  => $responseStatus,
            'response_body'    => $responseBody,
            'response_headers' => $responseHeaders,
            'error_message'    => $errorMessage,
            'duration_ms'      => $durationMs,
            'payment_id'       => $paymentId,
            'triggered_by'     => $triggeredBy,
        ]);
    }

    private function redactHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $key => $value) {
            $lower = strtolower((string) $key);

            if ($lower === 'api-key') {
                $out[$key] = '***REDACTED***';
            } elseif ($lower === 'client-id') {
                $out[$key] = $this->maskSuffix((string) $value, 4);
            } elseif ($lower === 'authorization') {
                $out[$key] = $this->maskBearer((string) $value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    private function redactBody(?array $body): ?array
    {
        if ($body === null) {
            return null;
        }
        $out = $body;
        if (isset($out['phoneNumber']) && is_string($out['phoneNumber'])) {
            $out['phoneNumber'] = $this->maskPhone($out['phoneNumber']);
        }
        // orderReference intentionally NOT redacted (decision confirmed).
        return $out;
    }

    private function maskPhone(string $phone): string
    {
        if (strlen($phone) < 6) {
            return '***';
        }
        return substr($phone, 0, 3) . '***' . substr($phone, -3);
    }

    private function maskBearer(string $header): string
    {
        if (stripos($header, 'Bearer ') !== 0) {
            return '***';
        }
        $token = substr($header, 7);
        if (strlen($token) <= 8) {
            return 'Bearer ***';
        }
        return 'Bearer ***.' . substr($token, -8);
    }

    private function maskSuffix(string $value, int $visibleChars): string
    {
        if (strlen($value) <= $visibleChars) {
            return '***';
        }
        return '***' . substr($value, -$visibleChars);
    }

    private function extractErrorMessage(?array $body, int $status): string
    {
        if ($body === null) {
            return "ClickPesa HTTP {$status}";
        }
        $message = $body['message'] ?? $body['error'] ?? null;
        if (is_string($message) && $message !== '') {
            return "ClickPesa {$status}: {$message}";
        }
        return "ClickPesa HTTP {$status}";
    }
}