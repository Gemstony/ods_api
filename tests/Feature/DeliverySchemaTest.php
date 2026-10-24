<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\DispatcherTicket;
use App\Models\Role;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Schema + behaviour tests for Sub-phase 2.3 — deliveries, delivery items,
 * verification codes, dispatcher tickets.
 */
class DeliverySchemaTest extends TestCase
{
    use RefreshDatabase;

    // ─── Tables ────────────────────────────────────────────────
    public function test_all_delivery_tables_exist(): void
    {
        foreach ([
            'deliveries',
            'delivery_items',
            'verification_codes',
            'dispatcher_tickets',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: $table");
        }
    }

    public function test_deliveries_has_all_expected_columns(): void
    {
        $cols = Schema::getColumnListing('deliveries');
        $expected = [
            'id', 'tracking_id', 'distributor_id', 'pickup_branch_id',
            'service_type', 'status',
            'pickup_address', 'pickup_latitude', 'pickup_longitude', 'pickup_contact_phone',
            'destination_address', 'destination_latitude', 'destination_longitude',
            'destination_region', 'destination_district', 'destination_bus_stop',
            'recipient_name', 'recipient_phone',
            'distance_km', 'rate_per_km', 'minimum_fee', 'rounding_increment',
            'calculated_fee', 'ods_delivery_fee', 'bus_cargo_fare',
            'discount_amount', 'discount_reason', 'total_amount', 'currency',
            'rider_id', 'assigned_at', 'assignment_expires_at', 'accepted_at',
            'picked_up_at', 'delivered_at',
            'dispatcher_id', 'dispatched_at',
            'batch_id', 'batch_window_start', 'batch_window_end', 'assignment_group_id',
            'cancelled_at', 'cancelled_by', 'cancellation_reason',
            'notes', 'created_at', 'updated_at', 'deleted_at',
        ];
        foreach ($expected as $col) {
            $this->assertContains($col, $cols, "Missing deliveries column: $col");
        }
    }

    // ─── Tracking ID ───────────────────────────────────────────
    public function test_tracking_id_is_auto_generated(): void
    {
        $d = Delivery::factory()->create();
        $this->assertMatchesRegularExpression('/^ODS-\d{8}-[A-F0-9]{6}$/', $d->tracking_id);
    }

    // ─── Service type helpers ──────────────────────────────────
    public function test_service_type_helpers(): void
    {
        $receive  = Delivery::factory()->receive()->create();
        $send     = Delivery::factory()->send()->create();
        $regional = Delivery::factory()->regional()->create();

        $this->assertTrue($receive->isReceive());
        $this->assertTrue($receive->isLocal());
        $this->assertFalse($receive->isRegional());

        $this->assertTrue($send->isSend());
        $this->assertTrue($send->isLocal());

        $this->assertTrue($regional->isRegional());
        $this->assertFalse($regional->isLocal());
    }

    // ─── Relations ─────────────────────────────────────────────
    public function test_delivery_distributor_rider_branch_relations(): void
    {
        $distributor = User::factory()->role(Role::DISTRIBUTOR)->create();
        $rider = User::factory()->role(Role::RIDER)->create();
        $delivery = Delivery::factory()->create([
            'distributor_id' => $distributor->id,
            'rider_id'       => $rider->id,
        ]);

        $this->assertSame($distributor->id, $delivery->distributor->id);
        $this->assertSame($rider->id, $delivery->rider->id);
        $this->assertNotNull($delivery->pickupBranch);
    }

    public function test_delivery_items_order_by_sort_order(): void
    {
        $delivery = Delivery::factory()->create();
        DeliveryItem::factory()->for($delivery)->create(['sort_order' => 2, 'product_name' => 'C']);
        DeliveryItem::factory()->for($delivery)->create(['sort_order' => 0, 'product_name' => 'A']);
        DeliveryItem::factory()->for($delivery)->create(['sort_order' => 1, 'product_name' => 'B']);

        $names = $delivery->items()->pluck('product_name')->toArray();
        $this->assertSame(['A', 'B', 'C'], $names);
    }

    public function test_delivery_item_exgo_and_manual_helpers(): void
    {
        $delivery = Delivery::factory()->create();
        $exgo = DeliveryItem::factory()->for($delivery)->exgoOrder('EXGO-777')->create();
        $manual = DeliveryItem::factory()->for($delivery)->create([
            'product_name' => 'Salmon Oil',
        ]);

        $this->assertTrue($exgo->isExgoReference());
        $this->assertFalse($exgo->isManualProduct());

        $this->assertFalse($manual->isExgoReference());
        $this->assertTrue($manual->isManualProduct());
    }

    // ─── Cascade deletes ───────────────────────────────────────
    public function test_deleting_delivery_cascades_to_items_codes_tickets(): void
    {
        $delivery = Delivery::factory()->regional()->create();
        DeliveryItem::factory()->for($delivery)->create();
        VerificationCode::factory()->for($delivery)->create();
        DispatcherTicket::factory()->for($delivery)->create();

        $delivery->forceDelete();

        $this->assertSame(0, DeliveryItem::where('delivery_id', $delivery->id)->count());
        $this->assertSame(0, VerificationCode::where('delivery_id', $delivery->id)->count());
        $this->assertSame(0, DispatcherTicket::where('delivery_id', $delivery->id)->count());
    }

    // ─── Verification codes ────────────────────────────────────
    public function test_verification_code_issue_and_verify(): void
    {
        $delivery = Delivery::factory()->create();
        $distributor = $delivery->distributor;

        [$code, $raw] = VerificationCode::issueFor(
            $delivery,
            VerificationCode::TYPE_DISTRIBUTOR,
            $distributor,
        );

        $this->assertSame(6, strlen($raw));
        $this->assertTrue($code->isUsable());
        $this->assertTrue($code->hashMatches($raw));
        $this->assertFalse($code->hashMatches('000000' === $raw ? '111111' : '000000'));

        $code->update(['verified_at' => now()]);
        $this->assertFalse($code->fresh()->isUsable());
        $this->assertTrue($code->fresh()->isVerified());
    }

    // ─── Dispatcher tickets ────────────────────────────────────
    public function test_dispatcher_ticket_records_transport_fare_separately(): void
    {
        $ticket = DispatcherTicket::factory()->create([
            'transport_fare' => 3500.00,
        ]);

        // The transport fare exists in its own field — never conflated with
        // any dispatcher earning. Phase 11 will enforce this by reading
        // only from `dispatcher_tickets.transport_fare` for provider
        // liability, never for compensation.
        $this->assertSame(3500.00, $ticket->transport_fare);
        $this->assertFalse($ticket->isConfirmed());

        $ticket->update(['provider_confirmed_at' => now()]);
        $this->assertTrue($ticket->fresh()->isConfirmed());
    }

    // ─── Status helpers ────────────────────────────────────────
    public function test_status_helpers(): void
    {
        $paid = Delivery::factory()->paid()->create();
        $delivered = Delivery::factory()->delivered()->create();

        $this->assertTrue($paid->isPaid());
        $this->assertTrue($paid->isActive());
        $this->assertFalse($paid->isDelivered());

        $this->assertTrue($delivered->isDelivered());
        $this->assertFalse($delivered->isActive());
    }
}