<?php

namespace App\Http\Requests\Api\V1\Owner;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for creating a branch.
 *
 * Authorization is handled by the `role:owner` middleware before this
 * FormRequest runs — hence `authorize()` just returns true.
 */
class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'code' => [
                'required',
                'string',
                'min:2',
                'max:20',
                'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('branches', 'code'),
            ],
            'address' => ['required', 'string', 'min:5', 'max:500'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'operating_hours' => ['nullable', 'array'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Branch name is required.',
            'name.min' => 'Branch name must be at least 2 characters.',
            'code.required' => 'Branch code is required.',
            'code.regex' => 'Code may only contain letters, numbers, and hyphens.',
            'code.unique' => 'This branch code is already in use.',
            'address.required' => 'Branch address is required.',
            'address.min' => 'Address must be at least 5 characters.',
            'latitude.required' => 'Latitude is required.',
            'latitude.between' => 'Latitude must be between -90 and 90.',
            'longitude.required' => 'Longitude is required.',
            'longitude.between' => 'Longitude must be between -180 and 180.',
            'status.in' => 'Status must be active or inactive.',
        ];
    }
}