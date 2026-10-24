<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Convenience wrapper around [AuditLog::record()].
 *
 * Two things it adds over calling AuditLog::record() directly:
 *   1. Auto-fills IP and user-agent from the current request.
 *   2. Provides three entry points matching three real use cases:
 *        log()             explicit actor (admin acting on someone else)
 *        logCurrentUser()  self-service action by the signed-in user
 *        logSystem()       background job / CLI / scheduler
 *
 * Resolution via container:  app(AuditLogger::class)
 * Or inject:  public function __construct(private AuditLogger $auditLogger) {}
 */
class AuditLogger
{
    /**
     * Explicit-actor log. Use when the actor is known even if it isn't
     * the current request user (e.g., batch job impersonating the Owner,
     * or a controller logging about a target user).
     */
    public function log(
        ?User $actor,
        string $action,
        string $description,
        ?Model $subject = null,
        array $changes = [],
    ): AuditLog {
        $request = request();

        return AuditLog::record(
            actor: $actor,
            action: $action,
            description: $description,
            subject: $subject,
            changes: $changes,
            ipAddress: $request?->ip(),
            userAgent: $request?->userAgent(),
        );
    }

    /**
     * Log an action performed by the currently signed-in user.
     *
     * Uses `auth()->user()` rather than `request()->user()` because:
     *   - `auth()` reads from the configured guard, which is what
     *     `actingAs()` sets in tests.
     *   - `request()->user()` requires Laravel's `Authenticate` middleware
     *     to have populated the user resolver, which is true during an
     *     HTTP request but not in unit tests or queued jobs.
     *
     * Both forms resolve to the same user during real HTTP traffic.
     *
     * Returns null (and writes nothing) if no user is authenticated —
     * safe to call from controllers that mix public and private logic.
     */
    public function logCurrentUser(
        string $action,
        string $description,
        ?Model $subject = null,
        array $changes = [],
    ): ?AuditLog {
        $user = auth()->user();

        if (!$user instanceof User) {
            return null;
        }

        return $this->log($user, $action, $description, $subject, $changes);
    }
    /**
     * Log a system action (cron job, queue worker, artisan command).
     * Actor is null; `subject` carries the target (if any).
     */
    public function logSystem(
        string $action,
        string $description,
        ?Model $subject = null,
        array $changes = [],
    ): AuditLog {
        return $this->log(null, $action, $description, $subject, $changes);
    }

    // ─── Convenience methods for recurring events ───────────────
    // Used by Phases 5 and 6. Add more as needed.

    public function logRoleChange(
        User $actor,
        User $target,
        string $oldRole,
        string $newRole,
    ): AuditLog {
        return $this->log(
            actor: $actor,
            action: 'user.role_changed',
            description: "Changed {$target->name}'s role from {$oldRole} to {$newRole}",
            subject: $target,
            changes: ['role' => ['from' => $oldRole, 'to' => $newRole]],
        );
    }

    public function logUserCreated(User $actor, User $created): AuditLog
    {
        return $this->log(
            actor: $actor,
            action: 'user.created',
            description: "Created {$created->name} ({$created->role?->name})",
            subject: $created,
            changes: [
                'role' => $created->role?->name,
                'email' => $created->email,
                'branch_id' => $created->branch_id,
            ],
        );
    }

    public function logBranchAssignment(User $actor, Branch $branch, User $manager): AuditLog
    {
        return $this->log(
            actor: $actor,
            action: 'branch.manager_assigned',
            description: "Assigned {$manager->name} as manager of {$branch->name}",
            subject: $branch,
            changes: ['manager_id' => $manager->id, 'manager_name' => $manager->name],
        );
    }
}