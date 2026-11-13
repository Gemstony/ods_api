<?php

namespace Tests\Feature\ClickPesa;

use App\Models\ClickPesaToken;
use App\Services\ClickPesa\ClickPesaTokenProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClickPesaTokenProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['clickpesa.client_id' => 'test-client']);
    }

    private function provider(): ClickPesaTokenProvider
    {
        return app(ClickPesaTokenProvider::class);
    }

    private function hash(): string
    {
        return hash('sha256', 'test-client');
    }

    // ─── findValid ──────────────────────────────────────────
    public function test_find_valid_returns_null_when_none_stored(): void
    {
        $this->assertNull($this->provider()->findValid());
    }

    public function test_find_valid_returns_active_token(): void
    {
        ClickPesaToken::create([
            'client_id_hash' => $this->hash(),
            'access_token'   => 'Bearer abc',
            'expires_at'     => now()->addMinutes(30),
        ]);

        $token = $this->provider()->findValid();

        $this->assertNotNull($token);
        $this->assertSame('Bearer abc', $token->access_token);
    }

    public function test_find_valid_returns_null_when_expired(): void
    {
        ClickPesaToken::create([
            'client_id_hash' => $this->hash(),
            'access_token'   => 'Bearer expired',
            'expires_at'     => now()->subMinute(),
        ]);

        $this->assertNull($this->provider()->findValid());
    }

    // ─── store ──────────────────────────────────────────────
    public function test_store_creates_row_when_none_exists(): void
    {
        $this->provider()->store('Bearer new', 55);

        $this->assertDatabaseHas('clickpesa_tokens', [
            'client_id_hash' => $this->hash(),
            'access_token'   => 'Bearer new',
        ]);
    }

    public function test_store_overwrites_existing_row(): void
    {
        ClickPesaToken::create([
            'client_id_hash' => $this->hash(),
            'access_token'   => 'Bearer old',
            'expires_at'     => now()->subMinute(),
        ]);

        $this->provider()->store('Bearer new', 55);

        $this->assertSame(1, ClickPesaToken::count());
        $this->assertSame('Bearer new', ClickPesaToken::first()->access_token);
    }

    public function test_store_sets_expires_at_from_ttl(): void
    {
        $before = now()->addMinutes(54);
        $this->provider()->store('Bearer t', 55);
        $after = now()->addMinutes(56);

        $expires = ClickPesaToken::first()->expires_at;

        $this->assertTrue($expires->greaterThanOrEqualTo($before));
        $this->assertTrue($expires->lessThanOrEqualTo($after));
    }

    // ─── clear ──────────────────────────────────────────────
    public function test_clear_removes_token(): void
    {
        ClickPesaToken::create([
            'client_id_hash' => $this->hash(),
            'access_token'   => 'Bearer t',
            'expires_at'     => now()->addMinutes(30),
        ]);

        $this->provider()->clear();

        $this->assertSame(0, ClickPesaToken::count());
    }

    public function test_clear_is_idempotent(): void
    {
        $this->provider()->clear();
        $this->provider()->clear();

        $this->assertSame(0, ClickPesaToken::count());
    }
}