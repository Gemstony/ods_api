<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A single capability (e.g., 'manage_users').
 *
 * Permissions are granted to roles via the role_permissions pivot. User
 * permission checks go through `$user->hasPermission('name')` (defined on
 * User).
 */
class Permission extends Model
{
    protected $fillable = [
        'name',
        'description',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions')
            ->withTimestamps();
    }
}