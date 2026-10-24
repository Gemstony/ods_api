<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A role in the RBAC system.
 *
 * Constants avoid magic strings throughout the codebase — use
 * Role::OWNER instead of 'owner'.
 */
class Role extends Model
{
    public const OWNER       = 'owner';
    public const MANAGER     = 'manager';
    public const DISTRIBUTOR = 'distributor';
    public const RIDER       = 'rider';
    public const RECEIVER    = 'receiver';

    protected $fillable = [
        'name',
        'display_name',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
            ->withTimestamps();
    }
}   