<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * A physical NeoLife pickup branch operating under ODS.
 */
class Branch extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'name',
        'code',
        'address',
        'latitude',
        'longitude',
        'operating_hours',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'operating_hours' => 'array',
        ];
    }

    // ─── Relations ────────────────────────────────────────────
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** All users bound to this branch (manager, riders, receivers). */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Full assignment history (active + past). */
    public function managerAssignments(): HasMany
    {
        return $this->hasMany(BranchManager::class);
    }

    // ─── Manager helpers ──────────────────────────────────────
    /**
     * The currently assigned manager (or null if none is assigned).
     *
     * Reads through the active assignment row and returns the associated
     * user. This is the single source of truth — do NOT rely on
     * `users.branch_id` alone (that column reflects the *last* update
     * without preserving history).
     */
    public function currentManager(): ?User
    {
        return $this->managerAssignments()
            ->active()
            ->with('user')
            ->first()
                ?->user;
    }

    /**
     * Assign a manager to this branch.
     *
     * In a single transaction:
     *   1. Closes any currently active assignment.
     *   2. Points the new manager user at this branch (users.branch_id).
     *   3. Creates a new active assignment row.
     *
     * Returns the newly created BranchManager record.
     */
    public function assignManager(User $manager, ?User $assignedBy = null): BranchManager
    {
        return DB::transaction(function () use ($manager, $assignedBy) {
            // Close the currently active assignment (if any) AND detach the
            // displaced manager's branch_id. Without this, both the old and
            // new managers would still have users.branch_id pointing here,
            // leaving the branch with two "managers" from a permissions view.
            $previous = $this->managerAssignments()->active()->with('user')->first();
            if ($previous) {
                $previous->update([
                    'unassigned_at' => now(),
                    'unassigned_by' => $assignedBy?->id,
                ]);

                if ($previous->user && $previous->user->branch_id === $this->id) {
                    $previous->user->update(['branch_id' => null]);
                }
            }

            // Bind the new manager to this branch.
            $manager->update(['branch_id' => $this->id]);

            // Open a fresh active assignment for the new manager.
            return $this->managerAssignments()->create([
                'user_id' => $manager->id,
                'assigned_by' => $assignedBy?->id,
                'assigned_at' => now(),
            ]);
        });
    }

    /**
     * Remove the current manager from this branch.
     *
     * Closes the active assignment and detaches the manager's branch_id.
     */
    public function unassignManager(?User $unassignedBy = null): void
    {
        DB::transaction(function () use ($unassignedBy) {
            $active = $this->managerAssignments()->active()->first();
            if (!$active) {
                return;
            }

            $active->update([
                'unassigned_at' => now(),
                'unassigned_by' => $unassignedBy?->id,
            ]);

            $manager = $active->user;
            if ($manager && $manager->branch_id === $this->id) {
                $manager->update(['branch_id' => null]);
            }
        });
    }

    // ─── Scopes ───────────────────────────────────────────────
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}