<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, SoftDeletes;

    public const STATUS_PENDING     = 'pending';
    public const STATUS_ACTIVE      = 'active';
    public const STATUS_SUSPENDED   = 'suspended';
    public const STATUS_DEACTIVATED = 'deactivated';

    protected $fillable = [
        'role_id', 'name', 'email', 'phone', 'password', 'status', 'branch_id',
        'distributor_id', 'nida', 'sex', 'date_of_birth',
        'vehicle_type', 'vehicle_color', 'vehicle_ownership', 'plate_number',
        'dispatcher_enabled',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at'  => 'datetime',
            'password'           => 'hashed',
            'date_of_birth'      => 'date',
            'dispatcher_enabled' => 'boolean',
        ];
    }

    // ─── JWT ──────────────────────────────────────────────────
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'role'      => $this->role?->name,
            'branch_id' => $this->branch_id,
            'status'    => $this->status,
        ];
    }

    // ─── Relations ────────────────────────────────────────────
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function managerAssignments(): HasMany
    {
        return $this->hasMany(BranchManager::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function reward(): HasOne
    {
        return $this->hasOne(Reward::class, 'distributor_id');
    }

    public function openedDisputes(): HasMany
    {
        return $this->hasMany(Dispute::class, 'opened_by');
    }

    public function disputesAgainst(): HasMany
    {
        return $this->hasMany(Dispute::class, 'against_user_id');
    }

    public function auditLogsAsActor(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_id');
    }

    // ─── Scopes ───────────────────────────────────────────────
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeOfRole($query, string $roleName)
    {
        return $query->whereHas('role', fn ($q) => $q->where('name', $roleName));
    }

    // ─── Helpers ──────────────────────────────────────────────
    public function hasRole(string $roleName): bool
    {
        return $this->role?->name === $roleName;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hasPermission(string $permissionName): bool
    {
        return $this->role?->permissions->contains('name', $permissionName) ?? false;
    }
}