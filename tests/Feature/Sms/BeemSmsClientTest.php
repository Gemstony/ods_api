<?php

namespace Tests\Feature\Sms;

use App\Models\NotificationSmsLog;
use App\Services\Sms\BeemSmsClient;
use App\Services\Sms\BeemSmsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BeemSmsClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'beem.api_key' => 'test-api-key',
            'beem.secret_key' => 'test-secret',
            'beem.sender_id' => 'ODSTZ',
            'beem.base_url' => 'https://apisms.beem.africa/v1',
            'beem.timeout' => 5,
            'beem.connect_timeout' => 2,
        ]);
    }

    private function client(): BeemSmsClient
    {
        return app(BeemSmsClient::class);
    }

    // ─── Send ───────────────────────────────────────────────
    public function test_send_success_returns_request_id(): void
    {
        Http::fake([
            'apisms.beem.africa/v1/send' => Http::response([
                'successful' => true,
                'request_id' => 67,
                'code' => 100,
                'message' => 'Message Submitted Successfully',
                'valid' => 1,
                'invalid' => 0,
                'duplicates' => 0,
            ], 200),
        ]);

        $result = $this->client()->send('255712345678', 'Hello');

        $this->assertSame('67', $result['beem_request_id']);
        $this->assertSame(100, $result['beem_code']);

        Http::assertSent(function ($req) {
            return $req->url() === 'https://apisms.beem.africa/v1/send'
                && $req->method() === 'POST'
                && $req['source_addr'] === 'ODSTZ'
                && $req['message'] === 'Hello'
                && $req['recipients'][0]['dest_addr'] === '255712345678';
        });
    }

    public function test_send_rejects_invalid_phone_code_101(): void
    {
        Http::fake([
            'apisms.beem.africa/v1/send' => Http::response([
                'code' => 101,
                'message' => 'Invalid phone number',
            ], 200),
        ]);

        try {
            $this->client()->send('255712345678', 'Hello');
            $this->fail('Expected BeemSmsException');
        } catch (BeemSmsException $e) {
            $this->assertSame(101, $e->beemCode);
            $this->assertTrue($e->isInvalidRecipient());
        }
    }

    public function test_send_rejects_insufficient_balance_102(): void
    {
        Http::fake([
            'apisms.beem.africa/v1/send' => Http::response([
                'code' => 102,
                'message' => 'Insufficient balance',
            ], 200),
        ]);

        try {
            $this->client()->send('255712345678', 'Hello');
            $this->fail('Expected BeemSmsException');
        } catch (BeemSmsException $e) {
            $this->assertSame(102, $e->beemCode);
            $this->assertTrue($e->isInsufficientBalance());
        }
    }

    public function test_send_rejects_invalid_sender_111(): void
    {
        Http::fake([
            'apisms.beem.africa/v1/send' => Http::response([
                'code' => 111,
                'message' => 'Invalid Sender ID',
            ], 200),
        ]);

        try {
            $this->client()->send('255712345678', 'Hello');
            $this->fail('Expected BeemSmsException');
        } catch (BeemSmsException $e) {
            $this->assertSame(111, $e->beemCode);
            $this->assertTrue($e->isInvalidSender());
        }
    }

    public function test_send_rejects_auth_failure_120(): void
    {
        Http::fake([
            'apisms.beem.africa/v1/send' => Http::response([
                'code' => 120,
                'message' => 'Invalid Authentication Parameters',
            ], 401),
        ]);

        try {
            $this->client()->send('255712345678', 'Hello');
            $this->fail('Expected BeemSmsException');
        } catch (BeemSmsException $e) {
            $this->assertTrue($e->isAuthFailure());
        }
    }

    public function test_send_throws_when_credentials_missing(): void
    {
        config(['beem.api_key' => '', 'beem.secret_key' => '']);

        $this->expectException(BeemSmsException::class);
        $this->expectExceptionMessageMatches('/credentials are not configured/');

        $this->client()->send('255712345678', 'Hello');
    }

    public function test_send_throws_when_sender_id_is_info(): void
    {
        config(['beem.sender_id' => 'INFO']);

        $this->expectException(BeemSmsException::class);
        $this->expectExceptionMessageMatches('/INFO.*deactivated/');

        $this->client()->send('255712345678', 'Hello');
    }

    // ─── Logging + redaction ────────────────────────────────
    public function test_send_logs_call_with_redacted_auth_and_phone(): void
    {
        Http::fake([
            'apisms.beem.africa/v1/send' => Http::response([
                'code' => 100,
                'request_id' => 42,
                'message' => 'OK',
            ], 200),
        ]);

        $this->client()->send('255712345678', 'Hello there');

        $log = NotificationSmsLog::first();
        $this->assertNotNull($log);
        $this->assertSame('POST', $log->method);
        $this->assertSame(200, $log->response_status);
        $this->assertSame(100, $log->beem_code);
        $this->assertSame('42', $log->beem_request_id);

        // Auth redacted.
        $headers = $log->request_headers;
        $this->assertSame('Basic ***REDACTED***', $headers['Authorization']);

        // Phone masked, message preserved.
        $body = $log->request_body;
        $this->assertSame('255***678', $body['recipients'][0]['dest_addr']);
        $this->assertSame('Hello there', $body['message']);
    }

    // ─── Delivery reports ───────────────────────────────────
    public function test_delivery_report_returns_delivered(): void
    {
        Http::fake([
            'dlrapi.beem.africa/public/v1/delivery-reports*' => Http::response([
                ['dest_addr' => '255712345678', 'status' => 'DELIVERED', 'request_id' => '42'],
            ], 200),
        ]);

        $result = $this->client()->deliveryReport('255712345678', '42');

        $this->assertSame('DELIVERED', $result['status']);
    }

    public function test_delivery_report_404_returns_pending(): void
    {
        Http::fake([
            'dlrapi.beem.africa/public/v1/delivery-reports*' => Http::response([
                'error' => 'Invalid request_id or dest_addr',
            ], 404),
        ]);

        $result = $this->client()->deliveryReport('255712345678', '42');

        $this->assertSame('PENDING', $result['status']);
    }
}