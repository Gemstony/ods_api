<?php

namespace App\Http\Requests\Api\V1\Owner;

use App\Services\Settings\SettingsSchema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;

/**
 * Validation for a bulk settings update.
 *
 * Payload:
 *   {
 *     "settings": [
 *       {"key": "batching.window_minutes", "value": 25},
 *       {"key": "location.pickup_radius_km", "value": 6.0}
 *     ]
 *   }
 *
 * Each setting's `value` is validated against its own key-specific rule
 * from [SettingsSchema]. Any failure fails the entire request — bulk
 * updates are all-or-nothing.
 */
class BulkUpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'settings' => ['required', 'array', 'min:1', 'max:100'],
            'settings.*' => ['required', 'array'],
            'settings.*.key' => ['required', 'string'],
        ];
    }

       public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $settings = $this->input('settings', []);
            if (! is_array($settings)) {
                return;
            }

            // Check for duplicate keys — ambiguous input is a client bug.
            $keys = array_column($settings, 'key');
            if (count($keys) !== count(array_unique($keys))) {
                $validator->errors()->add(
                    'settings',
                    'Duplicate setting keys are not allowed.',
                );
                return;
            }

            foreach ($settings as $i => $setting) {
                $key = $setting['key'] ?? null;
                $value = $setting['value'] ?? null;

                if (! is_string($key) || $key === '') {
                    continue; // handled by the base rule
                }

                $rule = SettingsSchema::ruleFor($key);
                if (! $rule) {
                    $validator->errors()->add(
                        "settings.{$i}.key",
                        "Unknown setting key: {$key}",
                    );
                    continue;
                }

                // Build the rule set for this item, including sub-field
                // rules (e.g. "payments.enabled_methods.*") mapped onto
                // `value.*` so array items are validated.
                $itemRules = ['value' => $rule];
                $subRule = SettingsSchema::ruleFor($key . '.*');
                if ($subRule !== null) {
                    $itemRules['value.*'] = $subRule;
                }

                $sub = Validator::make(
                    ['value' => $value],
                    $itemRules,
                    ['value.required' => 'A value is required for ' . $key],
                );

                if ($sub->fails()) {
                    foreach ($sub->errors()->all() as $message) {
                        $validator->errors()->add(
                            "settings.{$i}.value",
                            $message,
                        );
                    }
                }
            }
        });
    }
}