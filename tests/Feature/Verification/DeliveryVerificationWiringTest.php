<?php

namespace Tests\Feature\Verification;

use App\Models\Branch;
use App\Models\Delivery;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\VerificationCode;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryVerificationWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        (new PricingConfigSeeder())->run();
        (new SettingsSeeder())->run();
    }

    // ─── Fixtures ────────────────────────────────────────────
    protected function distributor(): User
    {
        return User::factory()->role(Role::DISTRIBUTOR)->create();
    }

    protected function rider(): User
    {
        return User::factory()->role(Role::RIDER)->create();
    }

    protected function receiver(Branch $branch): User
    {
        return User::factory()->role(Role::RECEIVER)->create([
            'branch_id' => $branch->id,
        ]);
    }

    protected function branch(): Branch
    {
        return Branch::factory()->create();
    }

    protected function receiveDelivery(
        User $distributor,
        Branch $branch,
        string $status,
        ?User $rider = null,
    ): Delivery {
        return Delivery::factory()->create([
            'distributor_id' => $distributor->id,
            'pickup_branch_id' => $branch->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => $status,
            'rider_id' => $rider?->id,
        ]);
    }

    protected function issueCode(Delivery $delivery, string $type, User $issuer): string
    {
        [, $rawCode] = app(\App\Services\Verification\VerificationCodeService::class)
            ->issueForDelivery($delivery, $type, $issuer);

        return $rawCode;
    }

    // ═════════════════════════════════════════════════════════
    // Distributor verification/issue endpoint
    // ═════════════════════════════════════════════════════════

    public function test_issue_returns_both_raw_codes_once(): void
    {
        $distributor = $this->distributor();
        $delivery = $this->receiveDelivery(
            $distributor, $this->branch(), Delivery::STATUS_PAID,
        );

        $response = $this->actingAs($distributor, 'api')
            ->postJson("/api/v1/distributor/deliveries/{$delivery->id}/verification/issue");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.delivery_id', $delivery->id)
            ->assertJsonStructure([
                'data' => [
                    'tracking_id',
                    'codes' => [
                        'distributor' => ['code', 'expires_at'],
                        'recipient' => ['code', 'expires_at'],
                    ],
                    'warning',
                ],
            ]);

        $distCode = $response->json('data.codes.distributor.code');
        $recipCode = $response->json('data.codes.recipient.code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $distCode);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $recipCode);

        // Two codes issued in DB.
        $this->assertSame(2, VerificationCode::where('delivery_id', $delivery->id)->count());
    }

    public function test_issue_requires_distributor_to_own_delivery(): void
    {
        $me = $this->distributor();
        $other = $this->distributor();
        $delivery = $this->receiveDelivery(
            $other, $this->branch(), Delivery::STATUS_PAID,
        );

        $this->actingAs($me, 'api')
            ->postJson("/api/v1/distributor/deliveries/{$delivery->id}/verification/issue")
            ->assertStatus(404);
    }

    public function test_issue_revokes_prior_codes(): void
    {
        $distributor = $this->distributor();
        $delivery = $this->receiveDelivery(
            $distributor, $this->branch(), Delivery::STATUS_PAID,
        );

        $this->actingAs($distributor, 'api')
            ->postJson("/api/v1/distributor/deliveries/{$delivery->id}/verification/issue")
            ->assertStatus(200);

        $firstIssued = VerificationCode::where('delivery_id', $delivery->id)
            ->whereNull('verified_at')->count();
        $this->assertSame(2, $firstIssued);

        $this->actingAs($distributor, 'api')
            ->postJson("/api/v1/distributor/deliveries/{$delivery->id}/verification/issue")
            ->assertStatus(200);

        // Only 2 outstanding (old ones expired).
        $this->assertSame(2, VerificationCode::where('delivery_id', $delivery->id)
            ->whereNull('verified_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->count());
    }

    // ═════════════════════════════════════════════════════════
    // Rider pickup — DISTRIBUTOR_CODE gate
    // ═════════════════════════════════════════════════════════

    public function test_pickup_passes_without_code_when_gate_not_armed(): void
    {
        // No codes issued → gate off → pickup works as before.
        $rider = $this->rider();
        $delivery = Delivery::factory()->create([
            'rider_id' => $rider->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_ACCEPTED,
        ]);

        $this->actingAs($rider, 'api')
            ->postJson("/api/v1/rider/deliveries/{$delivery->id}/pickup")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'picked_up');
    }

    public function test_pickup_requires_code_when_gate_armed(): void
    {
        $distributor = $this->distributor();
        $rider = $this->rider();
        $delivery = Delivery::factory()->create([
            'distributor_id' => $distributor->id,
            'rider_id' => $rider->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_ACCEPTED,
        ]);
        $this->issueCode($delivery, VerificationCode::TYPE_DISTRIBUTOR, $distributor);

        // No code sent → 422.
        $this->actingAs($rider, 'api')
            ->postJson("/api/v1/rider/deliveries/{$delivery->id}/pickup")
            ->assertStatus(422)
            ->assertJsonPath('errors.outcome', 'no_code_provided')
            ->assertJsonPath('errors.code_type', 'distributor');
    }

    public function test_pickup_rejects_wrong_code(): void
    {
        $distributor = $this->distributor();
        $rider = $this->rider();
        $delivery = Delivery::factory()->create([
            'distributor_id' => $distributor->id,
            'rider_id' => $rider->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_ACCEPTED,
        ]);
        $rawCode = $this->issueCode($delivery, VerificationCode::TYPE_DISTRIBUTOR, $distributor);
        $wrongCode = ((int) $rawCode[0] + 1) % 10 . substr($rawCode, 1);

        $this->actingAs($rider, 'api')
            ->postJson("/api/v1/rider/deliveries/{$delivery->id}/pickup", [
                'verification_code' => $wrongCode,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.outcome', 'invalid')
            ->assertJsonPath('errors.code_type', 'distributor');
    }

    public function test_pickup_succeeds_with_correct_code(): void
    {
        $distributor = $this->distributor();
        $rider = $this->rider();
        $delivery = Delivery::factory()->create([
            'distributor_id' => $distributor->id,
            'rider_id' => $rider->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_ACCEPTED,
        ]);
        $rawCode = $this->issueCode($delivery, VerificationCode::TYPE_DISTRIBUTOR, $distributor);

        $this->actingAs($rider, 'api')
            ->postJson("/api/v1/rider/deliveries/{$delivery->id}/pickup", [
                'verification_code' => $rawCode,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'picked_up');

        // Code is marked verified.
        $code = VerificationCode::where('delivery_id', $delivery->id)
            ->where('code_type', VerificationCode::TYPE_DISTRIBUTOR)
            ->first();
        $this->assertNotNull($code->verified_at);
    }

    // ═════════════════════════════════════════════════════════
    // Rider deliver — RECIPIENT_CODE gate
    // ═════════════════════════════════════════════════════════

    public function test_deliver_passes_without_code_when_gate_not_armed(): void
    {
        $rider = $this->rider();
        $delivery = Delivery::factory()->create([
            'rider_id' => $rider->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_PICKED_UP,
            'picked_up_at' => now()->subMinutes(10),
        ]);

        $this->actingAs($rider, 'api')
            ->postJson("/api/v1/rider/deliveries/{$delivery->id}/deliver")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'delivered');
    }

    public function test_deliver_requires_code_when_gate_armed(): void
    {
        $distributor = $this->distributor();
        $rider = $this->rider();
        $delivery = Delivery::factory()->create([
            'distributor_id' => $distributor->id,
            'rider_id' => $rider->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_PICKED_UP,
            'picked_up_at' => now()->subMinutes(10),
        ]);
        $this->issueCode($delivery, VerificationCode::TYPE_RECIPIENT, $distributor);

        $this->actingAs($rider, 'api')
            ->postJson("/api/v1/rider/deliveries/{$delivery->id}/deliver")
            ->assertStatus(422)
            ->assertJsonPath('errors.outcome', 'no_code_provided')
            ->assertJsonPath('errors.code_type', 'recipient');
    }

    public function test_deliver_rejects_wrong_code(): void
    {
        $distributor = $this->distributor();
        $rider = $this->rider();
        $delivery = Delivery::factory()->create([
            'distributor_id' => $distributor->id,
            'rider_id' => $rider->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_PICKED_UP,
            'picked_up_at' => now()->subMinutes(10),
        ]);
        $rawCode = $this->issueCode($delivery, VerificationCode::TYPE_RECIPIENT, $distributor);
        $wrongCode = ((int) $rawCode[0] + 1) % 10 . substr($rawCode, 1);

        $this->actingAs($rider, 'api')
            ->postJson("/api/v1/rider/deliveries/{$delivery->id}/deliver", [
                'verification_code' => $wrongCode,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.outcome', 'invalid')
            ->assertJsonPath('errors.code_type', 'recipient');
    }

    public function test_deliver_succeeds_with_correct_code(): void
    {
        $distributor = $this->distributor();
        $rider = $this->rider();
        $delivery = Delivery::factory()->create([
            'distributor_id' => $distributor->id,
            'rider_id' => $rider->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_PICKED_UP,
            'picked_up_at' => now()->subMinutes(10),
        ]);
        $rawCode = $this->issueCode($delivery, VerificationCode::TYPE_RECIPIENT, $distributor);

        $this->actingAs($rider, 'api')
            ->postJson("/api/v1/rider/deliveries/{$delivery->id}/deliver", [
                'verification_code' => $rawCode,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'delivered');
    }

    // ═════════════════════════════════════════════════════════
    // Receiver release — DISTRIBUTOR_CODE gate
    // ═════════════════════════════════════════════════════════

    public function test_release_passes_without_code_when_gate_not_armed(): void
    {
        $branch = $this->branch();
        $distributor = $this->distributor();
        $rider = $this->rider();
        $receiver = $this->receiver($branch);
        $delivery = $this->receiveDelivery($distributor, $branch, Delivery::STATUS_ACCEPTED, $rider);

        $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/packages/{$delivery->id}/release")
            ->assertStatus(200);
    }

    public function test_release_requires_code_when_gate_armed(): void
    {
        $branch = $this->branch();
        $distributor = $this->distributor();
        $rider = $this->rider();
        $receiver = $this->receiver($branch);
        $delivery = $this->receiveDelivery($distributor, $branch, Delivery::STATUS_ACCEPTED, $rider);
        $this->issueCode($delivery, VerificationCode::TYPE_DISTRIBUTOR, $distributor);

        $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/packages/{$delivery->id}/release")
            ->assertStatus(422)
            ->assertJsonPath('errors.outcome', 'no_code_provided')
            ->assertJsonPath('errors.code_type', 'distributor');
    }

    public function test_release_rejects_wrong_code(): void
    {
        $branch = $this->branch();
        $distributor = $this->distributor();
        $rider = $this->rider();
        $receiver = $this->receiver($branch);
        $delivery = $this->receiveDelivery($distributor, $branch, Delivery::STATUS_ACCEPTED, $rider);
        $rawCode = $this->issueCode($delivery, VerificationCode::TYPE_DISTRIBUTOR, $distributor);
        $wrongCode = ((int) $rawCode[0] + 1) % 10 . substr($rawCode, 1);

        $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/packages/{$delivery->id}/release", [
                'verification_code' => $wrongCode,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.outcome', 'invalid');
    }

    public function test_release_succeeds_with_correct_code(): void
    {
        $branch = $this->branch();
        $distributor = $this->distributor();
        $rider = $this->rider();
        $receiver = $this->receiver($branch);
        $delivery = $this->receiveDelivery($distributor, $branch, Delivery::STATUS_ACCEPTED, $rider);
        $rawCode = $this->issueCode($delivery, VerificationCode::TYPE_DISTRIBUTOR, $distributor);

        $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/packages/{$delivery->id}/release", [
                'verification_code' => $rawCode,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'picked_up');
    }

    // ═════════════════════════════════════════════════════════
    // Setting override: gate can be disabled entirely
    // ═════════════════════════════════════════════════════════

    public function test_gate_off_when_setting_disabled(): void
    {
        Setting::set('security.verification_required_pickup', false, 'security');

        $distributor = $this->distributor();
        $rider = $this->rider();
        $delivery = Delivery::factory()->create([
            'distributor_id' => $distributor->id,
            'rider_id' => $rider->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_ACCEPTED,
        ]);
        $this->issueCode($delivery, VerificationCode::TYPE_DISTRIBUTOR, $distributor);

        // Even with code issued, gate is off because setting is off.
        $this->actingAs($rider, 'api')
            ->postJson("/api/v1/rider/deliveries/{$delivery->id}/pickup")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'picked_up');
    }
}