<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Key-value settings store.
 *
 * Values are JSON-encoded so any PHP type survives the round-trip. Always
 * access via the static [get()] / [set()] helpers — they handle the encode/
 * decode transparently.
 */
class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'category',
        'description',
        'is_public',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Read a setting value. Returns $default if the key doesn't exist.
     *
     * The stored value is decoded from JSON. If the row exists but the
     * stored string isn't valid JSON (legacy data), the raw string is
     * returned so behaviour is never surprising.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::where('key', $key)->first();
        if (! $row) {
            return $default;
        }

        $decoded = json_decode($row->value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $row->value;
    }

    /**
     * Write a setting value. Encodes as JSON. Creates the row if missing.
     */
    public static function set(
        string $key,
        mixed $value,
        string $category = 'general',
        ?int $userId = null,
        ?string $description = null,
        bool $isPublic = false,
    ): self {
        return static::updateOrCreate(
            ['key' => $key],
            [
                'value'       => json_encode($value),
                'category'    => $category,
                'description' => $description,
                'is_public'   => $isPublic,
                'updated_by'  => $userId,
            ],
        );
    }
}