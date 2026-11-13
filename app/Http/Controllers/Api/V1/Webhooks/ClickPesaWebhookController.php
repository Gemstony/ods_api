<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\ClickPesaCallback;
use App\Services\Payments\PaymentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public webhook receiver for ClickPesa events (Phase 11.2).
 *
 * Endpoint:
 *   POST /api/v1/webhooks/clickpesa
 *
 * Security model:
 *   - If CLICKPESA_WEBHOOK_SECRET is set → require a valid HMAC-SHA256
 *     signature in X-ClickPesa-Signature OR X-Checksum header.
 *     Invalid signature → 401, payload logged with signature_valid=false.
 *   - If secret is empty (dev) → accept unsigned requests but always
 *     store signature_valid=false so production readiness is visible.
 *
 * Idempotency: every callback is stored in clickpesa_callbacks, then
 * processing runs inside a try/catch. Even on processing failure we
 * return 200 so ClickPesa doesn't retry a bad-but-well-formed payload
 * (the failure is preserved in the DB for manual replay).
 */
class ClickPesaWebhookController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PaymentService $payments) {}

    public function receive(Request $request): JsonResponse
    {
        $rawBody = $request->getContent() ?: '';
        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return $this->error('Invalid JSON payload.', null, 422);
        }

        $signatureValid = $this->verifySignature($request, $rawBody);
        $secret = (string) config('clickpesa.webhook_secret');

        if ($secret !== '' && ! $signatureValid) {
            // Log the rejection so we retain visibility, then 401.
            $this->persistCallback($request, $payload, signatureValid: false);
            return $this->error('Invalid webhook signature.', null, 401);
        }

        $callback = $this->persistCallback($request, $payload, $signatureValid);

        try {
            $this->payments->processWebhook($callback);
        } catch (\Throwable $e) {
            // Already logged to clickpesa_callbacks with processing_error.
            // Return 200 so ClickPesa stops retrying — the operator can
            // replay from the DB.
            Log::error('ClickPesa webhook processing failed', [
                'callback_id' => $callback->id,
                'error'       => $e->getMessage(),
            ]);
        }

        return $this->success(null, 'OK');
    }

    // ─── Helpers ──────────────────────────────────────────────
    private function verifySignature(Request $request, string $rawBody): bool
    {
        $secret = (string) config('clickpesa.webhook_secret');
        if ($secret === '') {
            return false; // dev mode — no secret, cannot verify
        }

        $provided = $request->header('X-ClickPesa-Signature')
            ?? $request->header('X-Checksum')
            ?? '';

        if (! is_string($provided) || $provided === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $provided);
    }

    private function persistCallback(Request $request, array $payload, bool $signatureValid): ClickPesaCallback
    {
        $data = $payload['data'] ?? $payload;

        return ClickPesaCallback::create([
            'provider_event_id' => $data['id'] ?? ($data['eventId'] ?? null),
            'event_type'        => $data['event'] ?? ($data['status'] ?? 'unknown'),
            'payment_id'        => null,
            'signature_valid'   => $signatureValid,
            'payload'           => $payload,
            'ip_address'        => $request->ip(),
            'user_agent'        => $request->userAgent(),
            'received_at'       => now(),
        ]);
    }
}