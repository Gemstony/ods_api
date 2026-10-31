<?php

namespace App\Http\Requests\Api\V1\Owner;

use App\Services\Settings\SettingsSchema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;

/**
 * Validation for a single setting update.
 *
 * The route param `{key}` selects the validation rule from the schema.
 * Unknown keys and invalid values both produce 422 responses.
 *
 * Payload: `{"value": <whatever the setting expects>}`
 */
class UpdateSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $key = (string) $this->route('key');
        $rule = SettingsSchema::ruleFor($key);

        // If key is unknown, no rules exist for `value`. We deliberately
        // return no rules here and let withValidator add the error so
        // the response shape stays consistent (422 with errors.value).
        if (!$rule) {
            return [];
        }

        $rules = ['value' => $rule];

        // Map sub-field rules (e.g. "payments.enabled_methods.*") onto the
        // payload's `value.*` path so item-level validation runs. Without
        // this, array values pass the base `required|array` rule but their
        // individual items are never validated.
        $subRule = SettingsSchema::ruleFor($key . '.*');
        if ($subRule !== null) {
            $rules['value.*'] = $subRule;
        }

        return $rules;
    }

    public function withValidator($validator): void
    {
        $key = (string) $this->route('key');

        $validator->after(function ($validator) use ($key) {
            if (!SettingsSchema::isKnown($key)) {
                $validator->errors()->add(
                    'value',
                    "Unknown setting key: {$key}",
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'value.required' => 'A value is required.',
        ];
    }
}