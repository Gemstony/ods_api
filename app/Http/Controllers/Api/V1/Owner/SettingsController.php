<?php

namespace App\Http\Controllers\Api\V1\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Owner\BulkUpdateSettingsRequest;
use App\Http\Requests\Api\V1\Owner\UpdateSettingRequest;
use App\Models\Setting;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsSchema;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Owner-facing settings management.
 *
 * Endpoints:
 *   GET /owner/settings        → all settings grouped by category
 *   GET /owner/settings/{key}  → single setting
 *   PUT /owner/settings/{key}  → update one
 *   PUT /owner/settings        → bulk update (atomic)
 *
 * Values are validated against [SettingsSchema] — unknown keys and
 * invalid values both produce 422s.
 */
class SettingsController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    // ─── index ────────────────────────────────────────────────
    public function index(): JsonResponse
    {
        $grouped = Setting::query()
            ->orderBy('category')
            ->orderBy('key')
            ->get()
            ->groupBy('category')
            ->map(fn ($items) => $items->map(fn ($s) => $this->serialize($s))->all())
            ->all();

        return $this->success($grouped, 'OK');
    }

    // ─── show ─────────────────────────────────────────────────
    public function show(string $key): JsonResponse
    {
        $setting = Setting::where('key', $key)->first();
        if (! $setting) {
            return $this->error('Setting not found.', null, 404);
        }

        return $this->success($this->serialize($setting), 'OK');
    }

    // ─── update (single) ──────────────────────────────────────
    public function update(UpdateSettingRequest $request, string $key): JsonResponse
    {
        $setting = Setting::where('key', $key)->first();
        if (! $setting) {
            return $this->error('Setting not found.', null, 404);
        }

        $oldValue = json_decode($setting->value, true);
        $newValue = $request->input('value');

        Setting::set(
            key: $key,
            value: $newValue,
            category: $setting->category,
            userId: $request->user()->id,
            description: $setting->description,
            isPublic: $setting->is_public,
        );

        $setting->refresh();

        $this->auditLogger->logCurrentUser(
            action: 'settings.updated',
            description: "Updated {$key}",
            subject: $setting,
            changes: ['old' => $oldValue, 'new' => $newValue],
        );

        return $this->success($this->serialize($setting), 'Setting updated.');
    }

    // ─── bulkUpdate ───────────────────────────────────────────
    public function bulkUpdate(BulkUpdateSettingsRequest $request): JsonResponse
    {
        $pairs = $request->input('settings');
        $keys = array_column($pairs, 'key');

        // All keys must exist in the DB — reject with 404 if any is missing.
        $existing = Setting::whereIn('key', $keys)->get()->keyBy('key');
        foreach ($keys as $key) {
            if (! $existing->has($key)) {
                return $this->error("Setting not found: {$key}", null, 404);
            }
        }

        $changes = [];
        $updatedSettings = [];

        DB::transaction(function () use ($pairs, $existing, $request, &$changes, &$updatedSettings) {
            foreach ($pairs as $pair) {
                $key = $pair['key'];
                $value = $pair['value'];
                $setting = $existing->get($key);
                $oldValue = json_decode($setting->value, true);

                Setting::set(
                    key: $key,
                    value: $value,
                    category: $setting->category,
                    userId: $request->user()->id,
                    description: $setting->description,
                    isPublic: $setting->is_public,
                );

                $changes[] = [
                    'key' => $key,
                    'old' => $oldValue,
                    'new' => $value,
                ];
            }

            // Reload for response payload
            $updatedSettings = Setting::whereIn('key', array_column($pairs, 'key'))
                ->orderBy('key')
                ->get()
                ->map(fn ($s) => $this->serialize($s))
                ->all();
        });

        $this->auditLogger->logCurrentUser(
            action: 'settings.bulk_updated',
            description: 'Bulk-updated ' . count($pairs) . ' settings',
            changes: ['changes' => $changes],
        );

        return $this->success($updatedSettings, 'Settings updated.');
    }

    // ─── Serializer ───────────────────────────────────────────
    private function serialize(Setting $setting): array
    {
        return [
            'key' => $setting->key,
            'value' => json_decode($setting->value, true),
            'category' => $setting->category,
            'description' => $setting->description,
            'is_public' => (bool) $setting->is_public,
            'updated_by' => $setting->updated_by,
            'updated_at' => $setting->updated_at?->toIso8601String(),
        ];
    }
}