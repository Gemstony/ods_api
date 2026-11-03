<?php

namespace Tests\Feature\Distributor;

use App\Models\Branch;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        (new PricingConfigSeeder())->run();
    }

    protected function distributor(): User
    {
        return User::factory()->role(Role::DISTRIBUTOR)->create();
    }

    protected function branch(): Branch
    {
        return Branch::factory()->create([
            'latitude' => -6.7924,
            'longitude' => 39.2083,
        ]);
    }

    // ─── Auth ─────────────────────────────────────────────────
    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/distributor/deliveries')->assertStatus(401);
    }

    public function test_index_rejects_other_roles(): void
    {
        $manager = User::factory()->role(Role::MANAGER)->create();
        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/distributor/deliveries')
            ->assertStatus(403);

        $rider = User::factory()->role(Role::RIDER)->create();
        $this->actingAs($rider, 'api')
            ->getJson('/api/v1/distributor/deliveries')
            ->assertStatus(403);

        $owner = User::factory()->role(Role::OWNER)->create();
        $this->actingAs($owner, 'api')
            ->getJson('/api/v1/distributor/deliveries')
            ->assertStatus(403);
    }

    // ─── index ────────────────────────────────────────────────
    public function test_index_returns_only_own_deliveries(): void
    {
        $distributor = $this->distributor();
        $other = $this->distributor();
        $branch = $this->branch();

        Delivery::factory()->create([
            'distributor_id' => $distributor->id,
            'pickup_branch_id' => $branch->id,
            'tracking_id' => 'ODS-MINE-1',
        ]);
        Delivery::factory()->create([
            'distributor_id' => $other->id,
            'pickup_branch_id' => $branch->id,
            'tracking_id' => 'ODS-OTHER-1',
        ]);

        $response = $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/distributor/deliveries');

        $response->assertStatus(200);
        $ids = array_column($response->json('data.data'), 'tracking_id');
        $this->assertContains('ODS-MINE-1', $ids);
        $this->assertNotContains('ODS-OTHER-1', $ids);
    }

    public function test_index_filters_by_status(): void
    {
        $distributor = $this->distributor();
        $branch = $this->branch();

        Delivery::factory()->create([
            'distributor_id' => $distributor->id,
            'pickup_branch_id' => $branch->id,
            'status' => Delivery::STATUS_PAID,
        ]);
        Delivery::factory()->create([
            'distributor_id' => $distributor->id,
            'pickup_branch_id' => $branch->id,
            'status' => Delivery::STATUS_DELIVERED,
        ]);

        $response = $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/distributor/deliveries?status=paid');

        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame('paid', $response->json('data.data.0.status'));
    }

    public function test_index_supports_limit_offset(): void
    {
        $distributor = $this->distributor();
        $branch = $this->branch();
        Delivery::factory()->count(5)->create([
            'distributor_id' => $distributor->id,
            'pickup_branch_id' => $branch->id,
        ]);

        $response = $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/distributor/deliveries?limit=2&offset=0');

        $this->assertCount(2, $response->json('data.data'));
    }

    // ─── show ─────────────────────────────────────────────────
    public function test_show_returns_own_delivery(): void
    {
        $distributor = $this->distributor();
        $delivery = Delivery::factory()->create([
            'distributor_id' => $distributor->id,
        ]);

        $response = $this->actingAs($distributor, 'api')
            ->getJson("/api/v1/distributor/deliveries/{$delivery->id}");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.id', $delivery->id);
    }

    public function test_show_returns_404_for_other_distributor(): void
    {
        $distributor = $this->distributor();
        $other = $this->distributor();
        $delivery = Delivery::factory()->create([
            'distributor_id' => $other->id,
        ]);

        $this->actingAs($distributor, 'api')
            ->getJson("/api/v1/distributor/deliveries/{$delivery->id}")
            ->assertStatus(404);
    }

    // ─── pricePreview ─────────────────────────────────────────
    public function test_price_preview_for_receive(): void
    {
        $distributor = $this->distributor();
        $branch = $this->branch();

        // ~3.2 km between the branch and destination (Upanga, DSM)
        $response = $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/deliveries/price-preview', [
                'service_type' => 'receive',
                'pickup_branch_id' => $branch->id,
                'destination_latitude' => -6.7890,
                'destination_longitude' => 39.2200,
            ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.service_type', 'receive')
            ->assertJsonPath('data.rate_per_km', 500)
            ->assertJsonPath('data.minimum_fee', 1000)
            ->assertJsonPath('data.rounding_increment', 500)
            ->assertJsonStructure([
                'data' => [
                    'service_type',
                    'distance_km',
                    'rate_per_km',
                    'minimum_fee',
                    'rounding_increment',
                    'calculated_fee',
                    'ods_delivery_fee',
                    'bus_cargo_fare',
                    'discount_amount',
                    'total_amount',
                    'currency',
                ],
            ]);

        // Sanity: ODS fee should be at least the minimum
        $this->assertGreaterThanOrEqual(1000, $response->json('data.ods_delivery_fee'));
    }

    public function test_price_preview_for_send(): void
    {
        $distributor = $this->distributor();

        $response = $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/deliveries/price-preview', [
                'service_type' => 'send',
                'pickup_latitude' => -6.7924,
                'pickup_longitude' => 39.2083,
                'destination_latitude' => -6.7890,
                'destination_longitude' => 39.2200,
            ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.service_type', 'send');
    }

    public function test_price_preview_for_regional(): void
    {
        $distributor = $this->distributor();
        $branch = $this->branch();

        $response = $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/deliveries/price-preview', [
                'service_type' => 'regional',
                'pickup_branch_id' => $branch->id,
                'bus_cargo_fare' => 3500,
            ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.service_type', 'regional')
            ->assertJsonPath('data.bus_cargo_fare', 3500)
            ->assertJsonPath('data.total_amount', 3500); // 0 ODS + 3500 fare
    }

    public function test_price_preview_validates_service_specific_fields(): void
    {
        $distributor = $this->distributor();

        // Receive without branch → 422
        $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/deliveries/price-preview', [
                'service_type' => 'receive',
                'destination_latitude' => -6.78,
                'destination_longitude' => 39.22,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['pickup_branch_id']]);

        // Send without pickup → 422
        $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/deliveries/price-preview', [
                'service_type' => 'send',
                'destination_latitude' => -6.78,
                'destination_longitude' => 39.22,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['pickup_latitude']]);
    }

    // ─── store: Receive ───────────────────────────────────────
    public function test_store_creates_receive_delivery(): void
    {
        $distributor = $this->distributor();
        $branch = $this->branch();

        $response = $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/deliveries', [
                'service_type' => 'receive',
                'pickup_branch_id' => $branch->id,
                'pickup_contact_phone' => '0712345678',
                'destination_address' => '456 Upanga Road',
                'destination_latitude' => -6.7890,
                'destination_longitude' => 39.2200,
                'items' => [
                    [
                        'product_name' => 'Salmon Oil',
                        'product_code' => 'SLM-001',
                        'quantity' => 2,
                    ],
                ],
            ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.service_type', 'receive')
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.distributor_id', $distributor->id)
            ->assertJsonPath('data.currency', 'TZS');

        $this->assertDatabaseHas('deliveries', [
            'distributor_id' => $distributor->id,
            'service_type' => 'receive',
            'status' => 'pending_payment',
            'pickup_branch_id' => $branch->id,
        ]);

        $this->assertDatabaseHas('delivery_items', [
            'product_name' => 'Salmon Oil',
            'product_code' => 'SLM-001',
            'quantity' => 2,
        ]);
    }

    public function test_store_snapshots_pricing_at_creation(): void
    {
        $distributor = $this->distributor();
        $branch = $this->branch();

        $response = $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/deliveries', [
                'service_type' => 'receive',
                'pickup_branch_id' => $branch->id,
                'destination_address' => '456 Upanga',
                'destination_latitude' => -6.7890,
                'destination_longitude' => 39.2200,
                'items' => [
                    ['product_name' => 'X', 'quantity' => 1],
                ],
            ]);

        $response->assertStatus(201);

        $delivery = Delivery::latest('id')->first();
        $this->assertNotNull($delivery->distance_km);
        $this->assertSame(500.0, (float) $delivery->rate_per_km);
        $this->assertSame(1000.0, (float) $delivery->minimum_fee);
        $this->assertSame(500, $delivery->rounding_increment);
        $this->assertNotNull($delivery->ods_delivery_fee);
        $this->assertSame(0.0, (float) $delivery->discount_amount);
    }

    // ─── store: Send ──────────────────────────────────────────
    public function test_store_creates_send_delivery(): void
    {
        $distributor = $this->distributor();

        $response = $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/deliveries', [
                'service_type' => 'send',
                'pickup_address' => '123 Kariakoo',
                'pickup_latitude' => -6.7924,
                'pickup_longitude' => 39.2083,
                'recipient_name' => 'Jane Customer',
                'recipient_phone' => '0755123456',
                'destination_address' => '456 Upanga',
                'destination_latitude' => -6.7890,
                'destination_longitude' => 39.2200,
                'items' => [
                    ['product_name' => 'Cal-Mag', 'quantity' => 1],
                ],
            ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('data.service_type', 'send')
            ->assertJsonPath('data.recipient_name', 'Jane Customer')
            ->assertJsonPath('data.recipient_phone', '0755123456');
    }

    // ─── store: Regional ──────────────────────────────────────
    public function test_store_creates_regional_delivery(): void
    {
        $distributor = $this->distributor();
        $branch = $this->branch();

        $response = $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/deliveries', [
                'service_type' => 'regional',
                'pickup_branch_id' => $branch->id,
                'recipient_name' => 'John Arusha',
                'recipient_phone' => '0762345678',
                'destination_region' => 'Arusha',
                'destination_district' => 'Arusha City',
                'destination_bus_stop' => 'Nairobi Road Station',
                'bus_cargo_fare' => 3500,
                'items' => [
                    ['product_name' => 'Full Motion', 'quantity' => 1],
                ],
            ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('data.service_type', 'regional')
            ->assertJsonPath('data.destination_region', 'Arusha')
            ->assertJsonPath('data.destination_bus_stop', 'Nairobi Road Station')
            ->assertJsonPath('data.bus_cargo_fare', 3500);

        $delivery = Delivery::latest('id')->first();
        $this->assertSame(3500.0, (float) $delivery->bus_cargo_fare);
        // Default regional_minimum_fee = 0, so ods_fee = 0, total = 3500
        $this->assertSame(3500.0, (float) $delivery->total_amount);
    }

    // ─── store: validation ────────────────────────────────────
    public function test_store_requires_items(): void
    {
        $distributor = $this->distributor();
        $branch = $this->branch();

        $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/deliveries', [
                'service_type' => 'receive',
                'pickup_branch_id' => $branch->id,
                'destination_address' => 'X',
                'destination_latitude' => -6.78,
                'destination_longitude' => 39.22,
                'items' => [],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['items']]);
    }

    public function test_store_item_requires_exgo_or_product(): void
    {
        $distributor = $this->distributor();
        $branch = $this->branch();

        $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/deliveries', [
                'service_type' => 'receive',
                'pickup_branch_id' => $branch->id,
                'destination_address' => 'X',
                'destination_latitude' => -6.78,
                'destination_longitude' => 39.22,
                'items' => [
                    [], // empty — no exgo, no product
                ],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['items.0']]);
    }

    public function test_store_accepts_exgo_only_item(): void
    {
        $distributor = $this->distributor();
        $branch = $this->branch();

        $response = $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/deliveries', [
                'service_type' => 'receive',
                'pickup_branch_id' => $branch->id,
                'destination_address' => 'X',
                'destination_latitude' => -6.78,
                'destination_longitude' => 39.22,
                'items' => [
                    ['exgo_order_id' => 'EXGO-12345'],
                ],
            ]);

        $response->assertStatus(201);
        $item = DeliveryItem::latest('id')->first();
        $this->assertSame('EXGO-12345', $item->exgo_order_id);
        $this->assertNull($item->product_name);
    }

    public function test_store_requires_bus_cargo_fare_for_regional(): void
    {
        $distributor = $this->distributor();
        $branch = $this->branch();

        $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/deliveries', [
                'service_type' => 'regional',
                'pickup_branch_id' => $branch->id,
                'recipient_name' => 'X',
                'recipient_phone' => '0755123456',
                'destination_region' => 'Arusha',
                'destination_district' => 'Arusha City',
                'destination_bus_stop' => 'Nairobi Road',
                'items' => [['product_name' => 'X', 'quantity' => 1]],
                // bus_cargo_fare missing
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['bus_cargo_fare']]);
    }

    public function test_store_requires_branch_for_receive(): void
    {
        $distributor = $this->distributor();

        $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/deliveries', [
                'service_type' => 'receive',
                'destination_address' => 'X',
                'destination_latitude' => -6.78,
                'destination_longitude' => 39.22,
                'items' => [['product_name' => 'X', 'quantity' => 1]],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['pickup_branch_id']]);
    }

    public function test_store_requires_recipient_for_send(): void
    {
        $distributor = $this->distributor();

        $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/deliveries', [
                'service_type' => 'send',
                'pickup_address' => 'X',
                'pickup_latitude' => 0,
                'pickup_longitude' => 0,
                'destination_address' => 'Y',
                'destination_latitude' => 0,
                'destination_longitude' => 0,
                'items' => [['product_name' => 'X', 'quantity' => 1]],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['recipient_name', 'recipient_phone']]);
    }

    // ─── Audit ────────────────────────────────────────────────
    public function test_store_writes_audit_log(): void
    {
        $distributor = $this->distributor();
        $branch = $this->branch();

        $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/deliveries', [
                'service_type' => 'receive',
                'pickup_branch_id' => $branch->id,
                'destination_address' => 'X',
                'destination_latitude' => -6.78,
                'destination_longitude' => 39.22,
                'items' => [['product_name' => 'X', 'quantity' => 1]],
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delivery.created',
            'actor_id' => $distributor->id,
        ]);
    }
}