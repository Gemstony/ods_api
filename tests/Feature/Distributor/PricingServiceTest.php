<?php

namespace Tests\Feature\Distributor;

use App\Models\PricingConfig;
use App\Services\Pricing\PricingService;
use Database\Seeders\PricingConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new PricingConfigSeeder())->run();
    }

    // ─── Haversine ────────────────────────────────────────────
    public function test_haversine_known_distance(): void
    {
        // Dar es Salaam (-6.7924, 39.2083) → Bagamoyo (-6.4443, 38.9015)
        // Expected: ~48–50 km
        $service = new PricingService();
        $distance = $service->haversineKm(-6.7924, 39.2083, -6.4443, 38.9015);
        $this->assertGreaterThan(45, $distance);
        $this->assertLessThan(55, $distance);
    }

    public function test_haversine_same_point_is_zero(): void
    {
        $service = new PricingService();
        $distance = $service->haversineKm(-6.7924, 39.2083, -6.7924, 39.2083);
        $this->assertSame(0.0, $distance);
    }

    // ─── Local pricing ────────────────────────────────────────
    public function test_local_preview_applies_minimum_fee(): void
    {
        // distance × 500 = 250 < min 1000 → ods_fee = 1000
        $service = new PricingService();
        $result = $service->previewLocal(0.5);

        $this->assertSame(0.5, $result['distance_km']);
        $this->assertSame(500.0, $result['rate_per_km']);
        $this->assertSame(1000.0, $result['minimum_fee']);
        $this->assertSame(500, $result['rounding_increment']);
        $this->assertSame(250.0, $result['calculated_fee']);
        $this->assertSame(1000.0, $result['ods_delivery_fee']);
        $this->assertNull($result['bus_cargo_fare']);
        $this->assertSame(1000.0, $result['total_amount']);
        $this->assertSame('TZS', $result['currency']);
    }

    public function test_local_preview_rounds_up_to_increment(): void
    {
        // distance × 500 = 1050, min 1000 → 1050 → round up to 1500
        $service = new PricingService();
        $result = $service->previewLocal(2.1);

        $this->assertSame(1050.0, $result['calculated_fee']);
        $this->assertSame(1500.0, $result['ods_delivery_fee']);
        $this->assertSame(1500.0, $result['total_amount']);
    }

    public function test_local_preview_handles_typical_distance(): void
    {
        // distance × 500 = 1600, min 1000 → 1600 → round up to 2000
        $service = new PricingService();
        $result = $service->previewLocal(3.2);

        $this->assertSame(1600.0, $result['calculated_fee']);
        $this->assertSame(2000.0, $result['ods_delivery_fee']);
        $this->assertSame(2000.0, $result['total_amount']);
    }

    public function test_local_preview_with_discount(): void
    {
        // distance × 500 = 2000, discount 500 → total 1500
        $service = new PricingService();
        $result = $service->previewLocal(4.0, 500);

        $this->assertSame(2000.0, $result['ods_delivery_fee']);
        $this->assertSame(500.0, $result['discount_amount']);
        $this->assertSame(1500.0, $result['total_amount']);
    }

    public function test_local_preview_discount_cannot_exceed_ods_fee(): void
    {
        // distance × 500 = 1000, discount 5000 → clamped to 1000 → total 0
        $service = new PricingService();
        $result = $service->previewLocal(2.0, 5000);

        $this->assertSame(1000.0, $result['ods_delivery_fee']);
        $this->assertSame(1000.0, $result['discount_amount']);
        $this->assertSame(0.0, $result['total_amount']);
    }

    // ─── Regional pricing ─────────────────────────────────────
    public function test_regional_preview_adds_bus_fare(): void
    {
        // Default: regional_base = 0, minimum = 0 → ods_fee = 0
        // Bus fare 3500 → total 3500
        $service = new PricingService();
        $result = $service->previewRegional(3500);

        $this->assertSame(0.0, $result['distance_km']);
        $this->assertSame(0.0, $result['rate_per_km']);
        $this->assertSame(0.0, $result['ods_delivery_fee']);
        $this->assertSame(3500.0, $result['bus_cargo_fare']);
        $this->assertSame(3500.0, $result['total_amount']);
    }

    public function test_regional_preview_uses_minimum(): void
    {
        // Override: minimum 2000, base 0 → ods_fee = 2000
        PricingConfig::current()->update([
            'regional_base_fee' => 0,
            'regional_minimum_fee' => 2000,
        ]);

        $service = new PricingService();
        $result = $service->previewRegional(3500);

        $this->assertSame(2000.0, $result['ods_delivery_fee']);
        $this->assertSame(3500.0, $result['bus_cargo_fare']);
        $this->assertSame(5500.0, $result['total_amount']);
    }

    public function test_regional_preview_with_discount(): void
    {
        PricingConfig::current()->update([
            'regional_base_fee' => 2000,
            'regional_minimum_fee' => 2000,
        ]);

        $service = new PricingService();
        $result = $service->previewRegional(3500, 1000);

        $this->assertSame(2000.0, $result['ods_delivery_fee']);
        $this->assertSame(1000.0, $result['discount_amount']);
        // (2000 - 1000) + 3500 = 4500
        $this->assertSame(4500.0, $result['total_amount']);
    }

    // ─── Config source ────────────────────────────────────────
    public function test_service_reads_current_config(): void
    {
        PricingConfig::current()->update([
            'local_rate_per_km' => 750,
            'local_minimum_fee' => 1500,
            'local_rounding_increment' => 100,
        ]);

        $service = new PricingService();
        $result = $service->previewLocal(2.0);

        // 2.0 × 750 = 1500 → min 1500 → round up 1500 → 1500
        $this->assertSame(750.0, $result['rate_per_km']);
        $this->assertSame(1500.0, $result['minimum_fee']);
        $this->assertSame(100, $result['rounding_increment']);
        $this->assertSame(1500.0, $result['total_amount']);
    }
}