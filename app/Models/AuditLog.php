<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only audit log entry.
 *
 * Do NOT use `update()` or `delete()` on these records — they're
 * immutable. Use [record()] to create new entries.
 */
class AuditLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null; // append-only

    protected $fillable = [
        'actor_id',
        'actor_role',
        'actor_label',
        'action',
        'subject_type',
        'subject_id',
        'subject_label',
        'description',
        'changes',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'changes'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Convenience factory. Snapshots actor + subject fields so the log
     * remains readable even if those users are later deleted.
     */
    public static function record(
        ?User $actor,
        string $action,
        string $description,
        ?Model $subject = null,
        array $changes = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): self {
        return static::create([
            'actor_id'      => $actor?->id,
            'actor_role'    => $actor?->role?->name,
            'actor_label'   => $actor?->name,
            'action'        => $action,
            'subject_type'  => $subject ? $subject->getMorphClass() : null,
            'subject_id'    => $subject?->getKey(),
            'subject_label' => static::labelFor($subject),
            'description'   => $description,
            'changes'       => empty($changes) ? null : $changes,
            'ip_address'    => $ipAddress,
            'user_agent'    => $userAgent,
        ]);
    }

    private static function labelFor(?Model $subject): ?string
    {
        if (! $subject) {
            return null;
        }
        if (method_exists($subject, 'auditLabel')) {
            return $subject->auditLabel();
        }
        return $subject->getAttribute('name')
            ?? $subject->getAttribute('title')
            ?? $subject->getAttribute('reference')
            ?? (string) $subject->getKey();
    }
}