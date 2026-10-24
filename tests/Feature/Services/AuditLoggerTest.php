<?php

namespace Tests\Feature\Services;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    private AuditLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = app(AuditLogger::class);
    }

    public function test_log_writes_record_with_explicit_actor(): void
    {
        $actor = User::factory()->role(Role::OWNER)->create();
        $target = User::factory()->role(Role::MANAGER)->create();

        $log = $this->logger->log(
            actor: $actor,
            action: 'user.suspended',
            description: 'Suspended a manager',
            subject: $target,
            changes: ['status' => ['from' => 'active', 'to' => 'suspended']],
        );

        $this->assertSame($actor->id, $log->actor_id);
        $this->assertSame('owner', $log->actor_role);
        $this->assertSame($actor->name, $log->actor_label);
        $this->assertSame('user.suspended', $log->action);
        $this->assertSame($target->getMorphClass(), $log->subject_type);
        $this->assertSame($target->id, $log->subject_id);
        $this->assertSame(['status' => ['from' => 'active', 'to' => 'suspended']], $log->changes);
    }

    public function test_log_accepts_null_actor(): void
    {
        $log = $this->logger->log(
            actor: null,
            action: 'system.job_ran',
            description: 'Nightly reconciliation job',
        );

        $this->assertNull($log->actor_id);
        $this->assertSame('system.job_ran', $log->action);
    }

    public function test_log_current_user_uses_authenticated_user(): void
    {
        $user = User::factory()->role(Role::DISTRIBUTOR)->create();
        $this->actingAs($user, 'api');

        $log = $this->logger->logCurrentUser(
            action: 'delivery.created',
            description: 'Created a delivery request',
        );

        $this->assertNotNull($log);
        $this->assertSame($user->id, $log->actor_id);
    }

    public function test_log_current_user_returns_null_when_unauthenticated(): void
    {
        // No actingAs
        $log = $this->logger->logCurrentUser(
            action: 'test.action',
            description: 'Nobody should see this',
        );

        $this->assertNull($log);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_log_system_writes_with_null_actor(): void
    {
        $log = $this->logger->logSystem(
            action: 'scheduler.batch_timeout',
            description: 'Expired 5 batching windows',
        );

        $this->assertNull($log->actor_id);
        $this->assertSame('scheduler.batch_timeout', $log->action);
    }

    public function test_log_role_change_convenience(): void
    {
        $owner = User::factory()->role(Role::OWNER)->create();
        $target = User::factory()->role(Role::DISTRIBUTOR)->create();

        $log = $this->logger->logRoleChange($owner, $target, 'distributor', 'manager');

        $this->assertSame('user.role_changed', $log->action);
        $this->assertSame('distributor', $log->changes['role']['from']);
        $this->assertSame('manager', $log->changes['role']['to']);
    }

    public function test_log_branch_assignment_convenience(): void
    {
        $owner = User::factory()->role(Role::OWNER)->create();
        $branch = Branch::factory()->create();
        $manager = User::factory()->role(Role::MANAGER)->create();

        $log = $this->logger->logBranchAssignment($owner, $branch, $manager);

        $this->assertSame('branch.manager_assigned', $log->action);
        $this->assertSame($manager->id, $log->changes['manager_id']);
        $this->assertSame($branch->getMorphClass(), $log->subject_type);
    }

    public function test_audit_log_is_append_only(): void
    {
        $this->assertNull(AuditLog::UPDATED_AT);
    }
}