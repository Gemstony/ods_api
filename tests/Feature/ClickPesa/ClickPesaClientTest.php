<?php

namespace Tests\Feature\ClickPesa;

use App\Models\ClickPesaApiLog;
use App\Models\ClickPesaToken;
use App\Services\ClickPesa\ClickPesaClient;
use App\Services\ClickPesa\ClickPesaException;
use App\Services\ClickPesa\PaymentStatus;
use App\Services\ClickPesa\UssdPushRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClickPesaClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'clickpesa.base_url' => 'https://sandbox.clickpesa.com',
            'clickpesa.client_id' => 'test-client',
            'clickpesa.client_secret' => 'test-secret',
            'clickpesa.currency' => 'TZS',
            'clickpesa.timeout' => 5,
            'clickpesa.connect_timeout' => 2,
            'clickpesa.token_ttl_minutes' => 55,
        ]);
    }

    private function client(): ClickPesaClient
    {
        return app(ClickPesaClient::class);
    }

    private function hash(): string
    {
        return hash('sha256', 'test-client');
    }

    private function fakeAuth(string $token = 'Bearer test.token'): void
    {
        Http::fake([
            '*/third-parties/generate-token' => Http::response(['token' => $token], 200),
        ]);
    }

    // ─── Construction ───────────────────────────────────────
    public function test_construction_succeeds_without_credentials(): void
    {
        config(['clickpesa.client_id' => '', 'clickpesa.client_secret' => '']);

        // Constructor must not throw — only the first provider call does.
        $client = app(ClickPesaClient::class);
        $this->assertInstanceOf(ClickPesaClient::class, $client);
    }

    public function test_ensure_fresh_token_throws_when_credentials_missing(): void
    {
        config(['clickpesa.client_id' => '', 'clickpesa.client_secret' => '']);

        $client = app(ClickPesaClient::class);

        $this->expectException(ClickPesaException::class);
        $this->expectExceptionMessageMatches('/credentials are not configured/');

        $client->ensureFreshToken();
    }

    // ─── Token generation + caching ─────────────────────────
    public function test_ensure_fresh_token_calls_auth_when_empty(): void
    {
        $this->fakeAuth('Bearer fresh-token');

        $token = $this->client()->ensureFreshToken();

        $this->assertSame('Bearer fresh-token', $token);
        $this->assertDatabaseHas('clickpesa_tokens', [
            'client_id_hash' => $this->hash(),
            'access_token' => 'Bearer fresh-token',
        ]);
    }

    public function test_ensure_fresh_token_uses_cache_on_second_call(): void
    {
        Http::fake([
            '*/third-parties/generate-token' => Http::sequence()
                ->push(['token' => 'Bearer token-1'], 200)
                ->push(['token' => 'Bearer token-2'], 200),
        ]);

        $this->client()->ensureFreshToken();
        $token = $this->client()->ensureFreshToken();

        $this->assertSame('Bearer token-1', $token);
        Http::assertSentCount(1);
    }

    public function test_ensure_fresh_token_refreshes_when_expired(): void
    {
        ClickPesaToken::create([
            'client_id_hash' => $this->hash(),
            'access_token' => 'Bearer old',
            'expires_at' => now()->subMinute(),
        ]);
        $this->fakeAuth('Bearer new');

        $token = $this->client()->ensureFreshToken();

        $this->assertSame('Bearer new', $token);
    }

    public function test_auth_response_missing_token_throws(): void
    {
        Http::fake([
            '*/third-parties/generate-token' => Http::response(['error' => 'bad'], 200),
        ]);

        $this->expectException(ClickPesaException::class);
        $this->expectExceptionMessageMatches('/missing "token"/');

        $this->client()->ensureFreshToken();
    }

    // ─── Preview ────────────────────────────────────────────
    public function test_preview_sends_correct_payload_and_headers(): void
    {
        Http::fake([
            '*/third-parties/generate-token' => Http::response(['token' => 'Bearer t'], 200),
            '*/third-parties/payments/preview-ussd-push-request' => Http::response([
                'channels' => [
                    ['name' => 'Airtel', 'available' => true, 'message' => 'OK'],
                ],
            ], 200),
        ]);

        $result = $this->client()->previewUssdPush(UssdPushRequest::create(
            orderReference: 'PAY-20261111-A3F2',
            amount: 5000,
            phoneNumber: '255712345678',
        ));

        $this->assertSame('Airtel', $result['channels'][0]['name']);

        Http::assertSent(function ($req) {
            return str_contains($req->url(), 'preview-ussd-push-request')
                && $req->method() === 'POST'
                && $req['orderReference'] === 'PAY-20261111-A3F2'
                && $req['amount'] === '5000'
                && $req['phoneNumber'] === '255712345678'
                && $req['currency'] === 'TZS'
                && $req->hasHeader('Authorization', 'Bearer t');
        });
    }

    // ─── Initiate ───────────────────────────────────────────
    public function test_initiate_returns_raw_response(): void
    {
        Http::fake([
            '*/third-parties/generate-token' => Http::response(['token' => 'Bearer t'], 200),
            '*/third-parties/payments/initiate-ussd-push-request' => Http::response([
                'id' => 'CP-123',
                'orderReference' => 'PAY-X',
                'status' => 'PENDING',
            ], 200),
        ]);

        $result = $this->client()->initiateUssdPush(UssdPushRequest::create(
            orderReference: 'PAY-X',
            amount: 1000,
            phoneNumber: '255712345678',
        ));

        $this->assertSame('CP-123', $result['id']);
        $this->assertSame('PENDING', $result['status']);
    }

    // ─── Query ──────────────────────────────────────────────
    public function test_query_payment_normalizes_status(): void
    {
        Http::fake([
            '*/third-parties/generate-token' => Http::response(['token' => 'Bearer t'], 200),
            '*/third-parties/payments/PAY-X' => Http::response([
                'status' => 'SUCCESS',
                'amount' => '1000',
                'currency' => 'TZS',
                'id' => 'CP-123',
            ], 200),
        ]);

        $result = $this->client()->queryPayment('PAY-X');

        $this->assertSame(PaymentStatus::SUCCESS, $result['status']);
        $this->assertSame(1000.0, $result['amount']);
        $this->assertSame('CP-123', $result['provider_id']);
    }

    public function test_query_payment_unknown_status_becomes_unknown(): void
    {
        Http::fake([
            '*/third-parties/generate-token' => Http::response(['token' => 'Bearer t'], 200),
            '*/third-parties/payments/PAY-X' => Http::response(['status' => 'WEIRD'], 200),
        ]);

        $result = $this->client()->queryPayment('PAY-X');

        $this->assertSame(PaymentStatus::UNKNOWN, $result['status']);
    }

    // ─── Error paths ────────────────────────────────────────
    public function test_4xx_throws_with_context(): void
    {
        Http::fake([
            '*/third-parties/generate-token' => Http::response(['token' => 'Bearer t'], 200),
            '*/third-parties/payments/initiate-ussd-push-request' => Http::response([
                'message' => 'Insufficient balance',
                'code' => 'INSUFFICIENT_FUNDS',
            ], 422),
        ]);

        try {
            $this->client()->initiateUssdPush(UssdPushRequest::create(
                orderReference: 'PAY-X',
                amount: 5000,
                phoneNumber: '255712345678',
            ));
            $this->fail('Expected ClickPesaException');
        } catch (ClickPesaException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertStringContainsString('Insufficient balance', $e->getMessage());
            $this->assertSame('INSUFFICIENT_FUNDS', $e->responseBody['code']);
            $this->assertNotNull($e->logId);
        }
    }

    public function test_401_triggers_refresh_and_retry(): void
    {
        // Pre-seed a locally-valid but server-revoked token
        ClickPesaToken::create([
            'client_id_hash' => $this->hash(),
            'access_token' => 'Bearer revoked',
            'expires_at' => now()->addMinutes(30),
        ]);

        Http::fake([
            '*/third-parties/generate-token' => Http::response(['token' => 'Bearer fresh'], 200),
            '*/third-parties/payments/preview-ussd-push-request' => Http::sequence()
                ->push(['message' => 'Unauthorized'], 401)
                ->push(['channels' => []], 200),
        ]);

        $result = $this->client()->previewUssdPush(UssdPushRequest::create(
            orderReference: 'PAY-X',
            amount: 100,
            phoneNumber: '255712345678',
        ));

        $this->assertSame([], $result['channels']);
        $this->assertSame(0, ClickPesaToken::where('access_token', 'Bearer revoked')->count());
    }

    public function test_401_twice_throws(): void
    {
        Http::fake([
            '*/third-parties/generate-token' => Http::response(['token' => 'Bearer t'], 200),
            '*/third-parties/payments/preview-ussd-push-request' => Http::response([], 401),
        ]);

        $this->expectException(ClickPesaException::class);

        $this->client()->previewUssdPush(UssdPushRequest::create(
            orderReference: 'PAY-X',
            amount: 100,
            phoneNumber: '255712345678',
        ));
    }

    // ─── Logging + redaction ────────────────────────────────
    public function test_logs_auth_call_with_redacted_headers(): void
    {
        $this->fakeAuth('Bearer eyJ.test.token');

        $this->client()->ensureFreshToken();

        $log = ClickPesaApiLog::where('endpoint', 'like', '%generate-token%')->first();
        $this->assertNotNull($log);

        $headers = $log->request_headers;
        $this->assertSame('***REDACTED***', $headers['api-key']);
        $this->assertStringStartsWith('***', $headers['client-id']);
        $this->assertStringNotContainsString('test-secret', json_encode($headers));
        $this->assertSame(200, $log->response_status);
        $this->assertGreaterThanOrEqual(0, $log->duration_ms);
    }

    public function test_logs_bearer_redacted_on_authenticated_call(): void
    {
        Http::fake([
            '*/third-parties/generate-token' => Http::response(['token' => 'Bearer eyJ.secret.jwt'], 200),
            '*/third-parties/payments/preview-ussd-push-request' => Http::response(['channels' => []], 200),
        ]);

        $this->client()->previewUssdPush(UssdPushRequest::create(
            orderReference: 'PAY-X',
            amount: 100,
            phoneNumber: '255712345678',
        ));

        $log = ClickPesaApiLog::where('endpoint', 'like', '%preview%')->first();
        $auth = $log->request_headers['Authorization'];

        $this->assertStringStartsWith('Bearer ***.', $auth);
        $this->assertStringNotContainsString('eyJ.secret.jwt', $auth);
    }

    public function test_logs_phone_redacted_but_order_reference_intact(): void
    {
        Http::fake([
            '*/third-parties/generate-token' => Http::response(['token' => 'Bearer t'], 200),
            '*/third-parties/payments/preview-ussd-push-request' => Http::response(['channels' => []], 200),
        ]);

        $this->client()->previewUssdPush(UssdPushRequest::create(
            orderReference: 'PAY-20261111-A3F2',
            amount: 5000,
            phoneNumber: '255712345678',
        ));

        $log = ClickPesaApiLog::where('endpoint', 'like', '%preview%')->first();
        $body = $log->request_body;

        $this->assertSame('255***678', $body['phoneNumber']);
        $this->assertSame('PAY-20261111-A3F2', $body['orderReference']);
        $this->assertSame('5000', $body['amount']);
    }

    public function test_network_error_logs_with_null_status(): void
    {
        Http::fake([
            '*/third-parties/generate-token' => Http::response(['token' => 'Bearer t'], 200),
            '*/third-parties/payments/preview-ussd-push-request' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
            },
        ]);

        try {
            $this->client()->previewUssdPush(UssdPushRequest::create(
                orderReference: 'PAY-X',
                amount: 100,
                phoneNumber: '255712345678',
            ));
            $this->fail('Expected ClickPesaException');
        } catch (ClickPesaException $e) {
            $this->assertNull($e->httpStatus);
        }

        $log = ClickPesaApiLog::where('endpoint', 'like', '%preview%')->first();
        $this->assertNull($log->response_status);
        $this->assertNotNull($log->error_message);
        $this->assertStringContainsString('Connection timed out', $log->error_message);
    }

    public function test_correlation_id_shared_across_calls(): void
    {
        Http::fake([
            '*/third-parties/generate-token' => Http::response(['token' => 'Bearer t'], 200),
            '*/third-parties/payments/preview-ussd-push-request' => Http::response(['channels' => []], 200),
        ]);

        $correlationId = 'test-correlation-uuid';

        $this->client()->previewUssdPush(
            UssdPushRequest::create(
                orderReference: 'PAY-X',
                amount: 100,
                phoneNumber: '255712345678',
            ),
            correlationId: $correlationId,
        );

        // Auth call is a separate correlation, but the preview call must
        // carry the caller-supplied one.
        $log = ClickPesaApiLog::where('endpoint', 'like', '%preview%')->first();
        $this->assertSame($correlationId, $log->correlation_id);
    }

    // ─── UssdPushRequest validation ─────────────────────────
    public function test_request_rejects_invalid_order_reference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/orderReference/');

        UssdPushRequest::create(
            orderReference: 'BAD REFERENCE WITH SPACES',
            amount: 100,
            phoneNumber: '255712345678',
        );
    }

    public function test_request_rejects_invalid_phone(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/phoneNumber/');

        UssdPushRequest::create(
            orderReference: 'PAY-X',
            amount: 100,
            phoneNumber: '12345',
        );
    }

    public function test_request_rejects_zero_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        UssdPushRequest::create(
            orderReference: 'PAY-X',
            amount: 0,
            phoneNumber: '255712345678',
        );
    }
}