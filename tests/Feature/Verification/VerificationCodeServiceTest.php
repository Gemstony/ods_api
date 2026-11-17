<?php

namespace Tests\Feature\Verification;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Delivery;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\VerificationCode;
use App\Services\Verification\VerificationCodeService;
use App\Services\Verification\VerificationException;
use App\Services\Verification\VerificationOutcome;
use App\Services\Verification\VerificationReadiness;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerificationCodeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder)->run();
        (new PricingConfigSeeder)->run();
        (new SettingsSeeder)->run();
    }

    protected function service(): VerificationCodeService
    {
        return app(VerificationCodeService::class);
    }

    protected function makeDelivery(string $status = Delivery::STATUS_PAID): Delivery
    {
        $distributor = User::factory()->role(Role::DISTRIBUTOR)->create();
        $branch = Branch::factory()->create();

        return Delivery::factory()->create([
            'distributor_id' => $distributor->id,
            'pickup_branch_id' => $branch->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => $status,
        ]);
    }

    protected function verifier(): User
    {
        return User::factory()->role(Role::RIDER)->create();
    }

    // ─── issueForDelivery() ─────────────────────────────────
    public function test_issue_creates_hashed_code_and_returns_raw(): void
    {
        $delivery = $this->makeDelivery();
        $distributor = User::find($delivery->distributor_id);

        [$code, $rawCode] = $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $distributor,
        );

        $this->assertSame(6, strlen($rawCode));
        $this->assertTrue(ctype_digit($rawCode));
        $this->assertNotSame($rawCode, $code->code_hash);
        $this->assertSame(hash('sha256', $rawCode), $code->code_hash);
        $this->assertNull($code->verified_at);
        $this->assertSame(0, (int) $code->attempt_count);
    }

    public function test_issue_respects_default_ttl_setting(): void
    {
        Setting::set('security.verification_code_ttl_hours', 24, 'security');
        $delivery = $this->makeDelivery();

        [$code] = $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            User::find($delivery->distributor_id),
        );

        $deltaHours = now()->diffInHours($code->expires_at, absolute: false);
        $this->assertGreaterThanOrEqual(23, $deltaHours);
        $this->assertLessThanOrEqual(25, $deltaHours);
    }

    public function test_issue_honors_explicit_ttl_override(): void
    {
        $delivery = $this->makeDelivery();

        [$code] = $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            User::find($delivery->distributor_id),
            ttlHours: 2,
        );

        $deltaHours = now()->diffInHours($code->expires_at, absolute: false);
        $this->assertGreaterThanOrEqual(1, $deltaHours);
        $this->assertLessThanOrEqual(3, $deltaHours);
    }

    public function test_issue_revokes_prior_unverified_code_for_same_type(): void
    {
        $delivery = $this->makeDelivery();
        $distributor = User::find($delivery->distributor_id);

        [$first] = $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $distributor,
        );
        [$second] = $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $distributor,
        );

        $first->refresh();
        $this->assertTrue($first->isExpired());
        $this->assertFalse($second->isExpired());
    }

    public function test_issue_does_not_touch_other_code_type(): void
    {
        $delivery = $this->makeDelivery();
        $distributor = User::find($delivery->distributor_id);

        [$distCode] = $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_DISTRIBUTOR,
            $distributor,
        );
        $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $distributor,
        );

        $distCode->refresh();
        $this->assertFalse($distCode->isExpired());
    }

    public function test_issue_does_not_touch_verified_code(): void
    {
        $delivery = $this->makeDelivery();
        $distributor = User::find($delivery->distributor_id);

        [$first] = $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $distributor,
        );
        $first->update(['verified_at' => now()]);

        $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $distributor,
        );

        $first->refresh();
        $this->assertNotNull($first->verified_at);
    }

    public function test_verification_uses_newest_code_id_not_timestamp_order(): void
    {
        $delivery = $this->makeDelivery();
        $distributor = User::find($delivery->distributor_id);
        $rider = $this->verifier();

        [$oldCode] = $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $distributor,
        );
        [, $newRawCode] = $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $distributor,
        );

        // Simulate timestamp conversion between the PHP process and MySQL.
        $oldCode->update(['issued_at' => now()->addDay()]);

        $result = $this->service()->verify(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $newRawCode,
            $rider,
        );

        $this->assertSame(VerificationOutcome::Success, $result->outcome);
    }

    public function test_issue_throws_on_terminal_delivery(): void
    {
        $delivery = $this->makeDelivery(Delivery::STATUS_DELIVERED);
        $distributor = User::find($delivery->distributor_id);

        $this->expectException(VerificationException::class);
        $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $distributor,
        );
    }

    public function test_issue_throws_on_invalid_code_type(): void
    {
        $delivery = $this->makeDelivery();

        $this->expectException(VerificationException::class);
        $this->service()->issueForDelivery(
            $delivery,
            'bogus',
            User::find($delivery->distributor_id),
        );
    }

    // ─── verify() ───────────────────────────────────────────
    public function test_verify_success_marks_code_verified(): void
    {
        $delivery = $this->makeDelivery();
        $distributor = User::find($delivery->distributor_id);
        $rider = $this->verifier();

        [, $rawCode] = $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $distributor,
        );

        $result = $this->service()->verify(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $rawCode,
            $rider,
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame(VerificationOutcome::Success, $result->outcome);

        $code = $result->code;
        $this->assertNotNull($code);
        $this->assertNotNull($code->verified_at);
        $this->assertSame($rider->id, $code->verified_by_user_id);
        $this->assertSame(1, $code->attempt_count);
    }

    public function test_verify_is_idempotent_after_success(): void
    {
        $delivery = $this->makeDelivery();
        $distributor = User::find($delivery->distributor_id);
        $rider = $this->verifier();

        [, $rawCode] = $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $distributor,
        );

        $this->service()->verify($delivery, VerificationCode::TYPE_RECIPIENT, $rawCode, $rider);
        $second = $this->service()->verify($delivery, VerificationCode::TYPE_RECIPIENT, $rawCode, $rider);

        $this->assertSame(VerificationOutcome::AlreadyVerified, $second->outcome);
        $this->assertTrue($second->isSuccess());
    }

    public function test_verify_wrong_code_returns_invalid_and_increments_attempt(): void
    {
        $delivery = $this->makeDelivery();
        $distributor = User::find($delivery->distributor_id);
        $rider = $this->verifier();

        [$code, $rawCode] = $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $distributor,
        );

        // Guarantee a wrong code by flipping one digit of the actual one.
        $wrong = $this->flipDigit($rawCode);

        $result = $this->service()->verify(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $wrong,
            $rider,
        );

        $this->assertSame(VerificationOutcome::Invalid, $result->outcome);
        $this->assertFalse($result->isSuccess());

        $code->refresh();
        $this->assertSame(1, $code->attempt_count);
        $this->assertSame($this->service()->maxAttempts() - 1, $result->remainingAttempts);
    }

    public function test_verify_locks_out_after_max_attempts(): void
    {
        Setting::set('security.max_verification_attempts', 2, 'security');

        $delivery = $this->makeDelivery();
        $distributor = User::find($delivery->distributor_id);
        $rider = $this->verifier();

        [$code, $rawCode] = $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $distributor,
        );

        // Two wrong attempts.
        $this->service()->verify($delivery, VerificationCode::TYPE_RECIPIENT, $this->flipDigit($rawCode), $rider);
        $this->service()->verify($delivery, VerificationCode::TYPE_RECIPIENT, $this->flipDigit($rawCode), $rider);

        // Third attempt — even with the CORRECT code — must be locked out.
        $result = $this->service()->verify(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $rawCode,
            $rider,
        );

        $this->assertSame(VerificationOutcome::LockedOut, $result->outcome);
        $this->assertSame(0, $result->remainingAttempts);
    }

    public function test_verify_expired_code_returns_expired(): void
    {
        $delivery = $this->makeDelivery();
        $distributor = User::find($delivery->distributor_id);
        $rider = $this->verifier();

        [$code, $rawCode] = $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $distributor,
        );
        $code->update(['expires_at' => now()->subMinute()]);

        $result = $this->service()->verify(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $rawCode,
            $rider,
        );

        $this->assertSame(VerificationOutcome::Expired, $result->outcome);
    }

    public function test_verify_no_code_returns_no_code(): void
    {
        $delivery = $this->makeDelivery();

        $result = $this->service()->verify(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            '123456',
            $this->verifier(),
        );

        $this->assertSame(VerificationOutcome::NoCode, $result->outcome);
        $this->assertNull($result->code);
    }

    public function test_verify_different_code_types_are_independent(): void
    {
        $delivery = $this->makeDelivery();
        $distributor = User::find($delivery->distributor_id);
        $rider = $this->verifier();

        [, $distRaw] = $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_DISTRIBUTOR,
            $distributor,
        );
        [, $recipRaw] = $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $distributor,
        );

        $result = $this->service()->verify(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $recipRaw,
            $rider,
        );
        $this->assertTrue($result->isSuccess());

        $distCode = VerificationCode::where('delivery_id', $delivery->id)
            ->where('code_type', VerificationCode::TYPE_DISTRIBUTOR)
            ->first();
        $this->assertNull($distCode->verified_at);
        $this->assertSame(0, (int) $distCode->attempt_count);

        $distResult = $this->service()->verify(
            $delivery,
            VerificationCode::TYPE_DISTRIBUTOR,
            $distRaw,
            $rider,
        );
        $this->assertTrue($distResult->isSuccess());
    }

    // ─── readinessFor() ─────────────────────────────────────
    public function test_readiness_ready_when_code_is_fresh(): void
    {
        $delivery = $this->makeDelivery();
        $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            User::find($delivery->distributor_id),
        );

        $this->assertSame(
            VerificationReadiness::Ready,
            $this->service()->readinessFor($delivery, VerificationCode::TYPE_RECIPIENT),
        );
    }

    public function test_readiness_no_code_when_none(): void
    {
        $delivery = $this->makeDelivery();

        $this->assertSame(
            VerificationReadiness::NoCode,
            $this->service()->readinessFor($delivery, VerificationCode::TYPE_RECIPIENT),
        );
    }

    public function test_readiness_expired(): void
    {
        $delivery = $this->makeDelivery();
        [$code] = $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            User::find($delivery->distributor_id),
        );
        $code->update(['expires_at' => now()->subMinute()]);

        $this->assertSame(
            VerificationReadiness::Expired,
            $this->service()->readinessFor($delivery, VerificationCode::TYPE_RECIPIENT),
        );
    }

    public function test_readiness_locked_out(): void
    {
        Setting::set('security.max_verification_attempts', 1, 'security');
        $delivery = $this->makeDelivery();
        $distributor = User::find($delivery->distributor_id);
        $rider = $this->verifier();

        [, $rawCode] = $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $distributor,
        );
        $this->service()->verify(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $this->flipDigit($rawCode),
            $rider,
        );

        $this->assertSame(
            VerificationReadiness::LockedOut,
            $this->service()->readinessFor($delivery, VerificationCode::TYPE_RECIPIENT),
        );
    }

    public function test_readiness_already_verified(): void
    {
        $delivery = $this->makeDelivery();
        $distributor = User::find($delivery->distributor_id);
        $rider = $this->verifier();

        [, $raw] = $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $distributor,
        );
        $this->service()->verify($delivery, VerificationCode::TYPE_RECIPIENT, $raw, $rider);

        $this->assertSame(
            VerificationReadiness::AlreadyVerified,
            $this->service()->readinessFor($delivery, VerificationCode::TYPE_RECIPIENT),
        );
    }

    // ─── revokeForDelivery() ────────────────────────────────
    public function test_revoke_expires_all_unverified_codes(): void
    {
        $delivery = $this->makeDelivery();
        $distributor = User::find($delivery->distributor_id);

        $this->service()->issueForDelivery($delivery, VerificationCode::TYPE_DISTRIBUTOR, $distributor);
        $this->service()->issueForDelivery($delivery, VerificationCode::TYPE_RECIPIENT, $distributor);

        $count = $this->service()->revokeForDelivery($delivery, 'cancelled_by_distributor');
        $this->assertSame(2, $count);

        $this->assertSame(0, VerificationCode::where('delivery_id', $delivery->id)
            ->whereNull('verified_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->count());
    }

    public function test_revoke_skips_verified_codes(): void
    {
        $delivery = $this->makeDelivery();
        $distributor = User::find($delivery->distributor_id);
        $rider = $this->verifier();

        [, $raw] = $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $distributor,
        );
        $this->service()->verify($delivery, VerificationCode::TYPE_RECIPIENT, $raw, $rider);

        $count = $this->service()->revokeForDelivery($delivery);
        $this->assertSame(0, $count);

        $verified = VerificationCode::where('delivery_id', $delivery->id)->first();
        $this->assertNotNull($verified->verified_at);
    }

    // ─── Settings helpers ───────────────────────────────────
    public function test_is_required_reads_pickup_setting(): void
    {
        Setting::set('security.verification_required_pickup', false, 'security');
        $this->assertFalse($this->service()->isRequired(VerificationCode::TYPE_DISTRIBUTOR));

        Setting::set('security.verification_required_pickup', true, 'security');
        $this->assertTrue($this->service()->isRequired(VerificationCode::TYPE_DISTRIBUTOR));
    }

    public function test_is_required_reads_delivery_setting(): void
    {
        Setting::set('security.verification_required_delivery', false, 'security');
        $this->assertFalse($this->service()->isRequired(VerificationCode::TYPE_RECIPIENT));

        Setting::set('security.verification_required_delivery', true, 'security');
        $this->assertTrue($this->service()->isRequired(VerificationCode::TYPE_RECIPIENT));
    }

    // ─── Audit ──────────────────────────────────────────────
    public function test_issue_writes_audit_log(): void
    {
        $delivery = $this->makeDelivery();
        $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            User::find($delivery->distributor_id),
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'verification.issued',
        ]);
    }

    public function test_successful_verify_writes_audit_log(): void
    {
        $delivery = $this->makeDelivery();
        $distributor = User::find($delivery->distributor_id);
        $rider = $this->verifier();

        [, $raw] = $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $distributor,
        );
        $this->service()->verify($delivery, VerificationCode::TYPE_RECIPIENT, $raw, $rider);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'verification.succeeded',
            'actor_id' => $rider->id,
        ]);
    }

    public function test_failed_verify_writes_audit_log(): void
    {
        $delivery = $this->makeDelivery();
        $distributor = User::find($delivery->distributor_id);
        $rider = $this->verifier();

        [, $rawCode] = $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $distributor,
        );
        $this->service()->verify(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $this->flipDigit($rawCode),
            $rider,
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'verification.failed',
            'actor_id' => $rider->id,
        ]);
    }

    public function test_raw_code_is_never_written_to_audit_changes(): void
    {
        $delivery = $this->makeDelivery();
        $distributor = User::find($delivery->distributor_id);
        $rider = $this->verifier();

        [, $raw] = $this->service()->issueForDelivery(
            $delivery,
            VerificationCode::TYPE_RECIPIENT,
            $distributor,
        );
        $this->service()->verify($delivery, VerificationCode::TYPE_RECIPIENT, $raw, $rider);

        $logs = AuditLog::all();
        foreach ($logs as $log) {
            $serialized = json_encode($log->changes);
            $this->assertStringNotContainsString($raw, (string) $serialized);
        }
    }

    // ═════════════════════════════════════════════════════════
    // Helpers
    // ═════════════════════════════════════════════════════════

    /**
     * Guarantee a wrong code by flipping one digit. This removes the
     * (rare) collision risk where the "wrong" code we test with could
     * by chance equal the actual random code.
     */
    private function flipDigit(string $sixDigitCode): string
    {
        $first = (int) $sixDigitCode[0];
        $flipped = ($first + 1) % 10;

        return $flipped.substr($sixDigitCode, 1);
    }
}
