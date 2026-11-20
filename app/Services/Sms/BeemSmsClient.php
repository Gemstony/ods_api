<?php

namespace App\Services\Sms;

use App\Models\NotificationSmsLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Beem Africa SMS HTTP client (Phase 13.2).
 *
 * Two endpoints:
 *   - POST {v1}/send                                        (send)
 *   - GET  https://dlrapi.beem.africa/public/v1/delivery-reports?dest_addr=&request_id=
 *
 * The v1 base is normalized: whether `beem.base_url` ends with `/v1` or
 * not, requests go to `<root>/v1/send`. This tolerates both
 * `BEEM_BASE_URL=https://apisms.beem.africa` and
 * `BEEM_BASE_URL=https://apisms.beem.africa/v1` in `.env`.
 *
 * Every HTTP call writes to `notification_sms_logs` with redacted auth +
 * recipient. Response body, Beem platform code, and request_id are
 * preserved in full for reconciliation.
 */
class BeemSmsClient
{
    private const DELIVERY_REPORT_BASE = 'https://dlrapi.beem.africa/public/v1';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $secretKey,
        private readonly string $senderId,
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly int $connectTimeout,
    ) {}

    // ═════════════════════════════════════════════════════════
    // Send
    // ═════════════════════════════════════════════════════════

    public function send(
        string $destAddr,
        string $message,
        ?int $notificationId = null,
        string $triggeredBy = 'scheduler',
        ?string $correlationId = null,
    ): array {
        $this->assertCredentialsConfigured();
        $this->assertSenderIdUsable();

        $correlationId ??= (string) Str::uuid();
        $payload = [
            'source_addr' => $this->senderId,
            'encoding' => 0,
            'schedule_time' => '',
            'message' => $message,
            'recipients' => [
                [
                    'recipient_id' => '1',
                    'dest_addr' => $destAddr,
                ],
            ],
        ];

        $response = $this->httpCall(
            method: 'POST',
            path: '/v1/send',
            headers: [
                'Authorization' => $this->basicAuth(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            body: $payload,
            fullUrl: $this->v1Base().'/send',
            correlationId: $correlationId,
            notificationId: $notificationId,
            triggeredBy: $triggeredBy,
        );

        $body = $response['body'];
        $code = $response['beem_code'];
        $requestId = isset($body['request_id']) ? (string) $body['request_id'] : null;

        if ($code !== 100) {
            $msg = $body['message'] ?? "Beem code {$code}";
            throw new BeemSmsException(
                message: 'Beem rejected SMS: '.$msg,
                beemCode: $code,
                httpStatus: $response['http_status'],
                responseBody: $body,
                logId: $response['log_id'],
                endpoint: '/v1/send',
            );
        }

        return [
            'beem_request_id' => $requestId,
            'beem_code' => $code,
            'response' => $body,
            'log_id' => $response['log_id'],
        ];
    }

    // ═════════════════════════════════════════════════════════
    // Delivery report
    // ═════════════════════════════════════════════════════════

    public function deliveryReport(
        string $destAddr,
        string $beemRequestId,
        ?int $notificationId = null,
        string $triggeredBy = 'scheduler',
        ?string $correlationId = null,
    ): array {
        $this->assertCredentialsConfigured();

        $correlationId ??= (string) Str::uuid();
        $url = self::DELIVERY_REPORT_BASE.'/delivery-reports'
            .'?'.http_build_query([
                'dest_addr' => $destAddr,
                'request_id' => $beemRequestId,
            ]);

        try {
            $response = $this->httpCall(
                method: 'GET',
                path: '/public/v1/delivery-reports',
                headers: [
                    'Authorization' => $this->basicAuth(),
                    'Accept' => 'application/json',
                ],
                body: null,
                fullUrl: $url,
                correlationId: $correlationId,
                notificationId: $notificationId,
                triggeredBy: $triggeredBy,
            );
        } catch (BeemSmsException $e) {
            if ($e->httpStatus === 404) {
                return ['status' => 'PENDING', 'raw' => null];
            }
            throw $e;
        }

        $raw = $response['body'];
        $first = is_array($raw) ? ($raw[0] ?? []) : [];
        $status = strtoupper((string) ($first['status'] ?? 'PENDING'));

        return [
            'status' => in_array($status, ['PENDING', 'DELIVERED', 'UNDELIVERED'], true)
                ? $status
                : 'PENDING',
            'raw' => $first ?: null,
        ];
    }

    // ═════════════════════════════════════════════════════════
    // Internals
    // ═════════════════════════════════════════════════════════

    /**
     * Normalize the configured base URL into the v1 base.
     * Accepts both `…/beem.africa` and `…/beem.africa/v1`.
     */
    private function v1Base(): string
    {
        $base = rtrim($this->baseUrl, '/');

        return str_ends_with($base, '/v1') ? $base : $base.'/v1';
    }

    private function httpCall(
        string $method,
        string $path,
        array $headers,
        ?array $body,
        string $fullUrl,
        string $correlationId,
        ?int $notificationId,
        string $triggeredBy,
    ): array {
        $start = microtime(true);
        $response = null;
        $errorMessage = null;
        $beemCode = null;
        $beemMessage = null;
        $beemRequestId = null;
        $thrown = null;

        try {
            $request = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout);

            $response = strtoupper($method) === 'GET'
                ? $request->get($fullUrl)
                : $request->post($fullUrl, $body ?? []);
        } catch (ConnectionException $e) {
            $errorMessage = 'Connection error: '.$e->getMessage();
            $thrown = new BeemSmsException(
                message: $errorMessage,
                endpoint: $path,
                previous: $e,
            );
        } catch (Throwable $e) {
            $errorMessage = 'Unexpected HTTP error: '.$e->getMessage();
            $thrown = new BeemSmsException(
                message: $errorMessage,
                endpoint: $path,
                previous: $e,
            );
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $bodyArray = $response?->json();

        if (is_array($bodyArray)) {
            if (isset($bodyArray['code']) && is_numeric($bodyArray['code'])) {
                $beemCode = (int) $bodyArray['code'];
            }
            if (isset($bodyArray['message']) && is_string($bodyArray['message'])) {
                $beemMessage = substr($bodyArray['message'], 0, 255);
            }
            if (isset($bodyArray['request_id'])) {
                $beemRequestId = (string) $bodyArray['request_id'];
            }
        }

        $log = $this->writeLog(
            correlationId: $correlationId,
            method: strtoupper($method),
            path: $path,
            fullUrl: $fullUrl,
            headers: $headers,
            body: $body,
            responseStatus: $response?->status(),
            responseBody: is_array($bodyArray) ? $bodyArray : null,
            responseHeaders: $response?->headers(),
            errorMessage: $errorMessage,
            durationMs: $durationMs,
            beemCode: $beemCode,
            beemMessage: $beemMessage,
            beemRequestId: $beemRequestId,
            notificationId: $notificationId,
            triggeredBy: $triggeredBy,
        );

        if ($thrown !== null) {
            throw new BeemSmsException(
                message: $thrown->getMessage(),
                logId: $log->id,
                endpoint: $path,
                previous: $thrown->getPrevious(),
            );
        }

        if (! $response->successful()) {
            $msg = is_string($beemMessage) && $beemMessage !== ''
                ? $beemMessage
                : "Beem HTTP {$response->status()}";

            if ($response->status() === 404) {
                throw new BeemSmsException(
                    message: $msg,
                    beemCode: $beemCode,
                    httpStatus: 404,
                    responseBody: is_array($bodyArray) ? $bodyArray : null,
                    logId: $log->id,
                    endpoint: $path,
                );
            }

            throw new BeemSmsException(
                message: $msg,
                beemCode: $beemCode,
                httpStatus: $response->status(),
                responseBody: is_array($bodyArray) ? $bodyArray : null,
                logId: $log->id,
                endpoint: $path,
            );
        }

        if (strtoupper($method) === 'POST'
            && ($beemCode !== 100 || ($bodyArray['successful'] ?? true) === false)) {
            $message = $beemMessage
                ?? ($beemCode === null
                    ? 'Beem response did not include a success code.'
                    : "Beem code {$beemCode}");

            throw new BeemSmsException(
                message: $message,
                beemCode: $beemCode,
                httpStatus: $response->status(),
                responseBody: is_array($bodyArray) ? $bodyArray : null,
                logId: $log->id,
                endpoint: $path,
            );
        }

        return [
            'body' => is_array($bodyArray) ? $bodyArray : [],
            'beem_code' => $beemCode ?? 100,
            'http_status' => $response->status(),
            'log_id' => $log->id,
        ];
    }

    private function writeLog(
        string $correlationId,
        string $method,
        string $path,
        string $fullUrl,
        array $headers,
        ?array $body,
        ?int $responseStatus,
        ?array $responseBody,
        ?array $responseHeaders,
        ?string $errorMessage,
        int $durationMs,
        ?int $beemCode,
        ?string $beemMessage,
        ?string $beemRequestId,
        ?int $notificationId,
        string $triggeredBy,
    ): NotificationSmsLog {
        return NotificationSmsLog::create([
            'correlation_id' => $correlationId,
            'method' => $method,
            'endpoint' => $path,
            'full_url' => $fullUrl,
            'request_headers' => $this->redactHeaders($headers),
            'request_body' => $this->redactBody($body),
            'response_status' => $responseStatus,
            'response_body' => $responseBody,
            'response_headers' => $responseHeaders,
            'error_message' => $errorMessage,
            'duration_ms' => $durationMs,
            'beem_code' => $beemCode,
            'beem_message' => $beemMessage,
            'beem_request_id' => $beemRequestId,
            'notification_id' => $notificationId,
            'triggered_by' => $triggeredBy,
        ]);
    }

    private function redactHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === 'authorization') {
                $out[$key] = 'Basic ***REDACTED***';
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
        if (isset($out['recipients']) && is_array($out['recipients'])) {
            foreach ($out['recipients'] as $i => $recipient) {
                if (isset($recipient['dest_addr']) && is_string($recipient['dest_addr'])) {
                    $out['recipients'][$i]['dest_addr'] = $this->maskPhone($recipient['dest_addr']);
                }
            }
        }

        return $out;
    }

    private function maskPhone(string $phone): string
    {
        if (strlen($phone) < 6) {
            return '***';
        }

        return substr($phone, 0, 3).'***'.substr($phone, -3);
    }

    private function basicAuth(): string
    {
        return 'Basic '.base64_encode($this->apiKey.':'.$this->secretKey);
    }

    private function assertCredentialsConfigured(): void
    {
        if ($this->apiKey === '' || $this->secretKey === '') {
            throw new BeemSmsException(
                'Beem credentials are not configured. Set BEEM_API_KEY and BEEM_SECRET_KEY in .env.'
            );
        }
    }

    private function assertSenderIdUsable(): void
    {
        if ($this->senderId === '' || $this->senderId === 'INFO') {
            throw new BeemSmsException(
                'Beem sender ID is not usable. The default INFO sender is deactivated; '
                .'register an approved Sender ID in the Beem dashboard and set BEEM_SENDER_ID.'
            );
        }
    }
}
