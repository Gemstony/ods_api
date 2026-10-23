<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Feature tests for the health endpoint and the JSON envelope.
 *
 * Runs on Laravel 12's default PHPUnit setup — no extra packages.
 */
class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_success_envelope(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['app', 'version', 'env', 'database', 'time'],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'ODS API is healthy')
            ->assertJsonPath('data.version', 'v1');
    }

    public function test_unknown_route_returns_404_envelope(): void
    {
        $response = $this->getJson('/api/v1/this-route-does-not-exist');

        $response
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Endpoint not found.');
    }
}