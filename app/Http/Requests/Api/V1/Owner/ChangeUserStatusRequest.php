<?php

namespace App\Http\Requests\Api\V1\Owner;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for status transitions.
 *
 * `pending` is intentionally absent — approval flows through
 * `POST /owner/users/{user}/approve` so the semantic event is distinct.
 */
class ChangeUserStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::in(['active', 'suspended', 'deactivated']),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Status is required.',
            'status.in' => 'Status must be active, suspended, or deactivated.',
        ];
    }
}