<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single branch-manager assignment record.
 *
 * One row is active per branch at a time (`unassigned_at = null`); past
 * rows record the full history. Do NOT create these directly from UI code
 * — use `Branch::assignManager()` / `Branch::unassignManager()`, which
 * handle the transaction (close old, open new, sync `users.branch_id`).
 */
class BranchManager extends Model
{
    protected $fillable = [
        'branch_id',
        'user_id',
        'assigned_by',
        'assigned_at',
        'unassigned_at',
        'unassigned_by',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at'   => 'datetime',
            'unassigned_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function unassigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unassigned_by');
    }

    public function isActive(): bool
    {
        return $this->unassigned_at === null;
    }

    public function scopeActive($query)
    {
        return $query->whereNull('unassigned_at');
    }
}