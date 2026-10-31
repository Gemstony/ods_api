<?php

namespace Tests\Feature\Public;

use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new SettingsSeeder())->run();
    }

    public function test_public_settings_are_readable_without_auth(): void
    {
        $response = $this->getJson('/api/v1/settings/public');

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $keys = array_column($response->json('data'), 'key');
        $this->assertContains('app.min_supported_version', $keys);
        $this->assertContains('app.maintenance_mode', $keys);
        $this->assertContains('app.app_name', $keys);
    }

    public function test_public_settings_excludes_private_ones(): void
    {
        $response = $this->getJson('/api/v1/settings/public');

        $keys = array_column($response->json('data'), 'key');
        $this->assertNotContains('batching.window_minutes', $keys);
        $this->assertNotContains('security.verification_required_delivery', $keys);
        $this->assertNotContains('payments.enabled_methods', $keys);
    }

    public function test_public_settings_values_are_decoded(): void
    {
        $response = $this->getJson('/api/v1/settings/public');

        $byKey = [];
        foreach ($response->json('data') as $s) {
            $byKey[$s['key']] = $s['value'];
        }

        $this->assertSame('0.1.0', $byKey['app.min_supported_version']);
        $this->assertFalse($byKey['app.maintenance_mode']);
        $this->assertSame('ODS', $byKey['app.app_name']);
    }
}