<?php

namespace App\Http\Requests\Api\V1\Owner;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for updating a branch.
 *
 * Identical rules to [StoreBranchRequest] except the `code` uniqueness
 * check excludes the branch being updated — keeping your own code is
 * valid.
 */
class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Route model binding resolves `{branch}` before this runs, so
        // $this->route('branch') is the Branch model, not the raw ID.
        $branchId = $this->route('branch')->id;

        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'code' => [
                'required',
                'string',
                'min:2',
                'max:20',
                'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('branches', 'code')->ignore($branchId),
            ],
            'address' => ['required', 'string', 'min:5', 'max:500'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'operating_hours' => ['nullable', 'array'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    public function messages(): array
    {
        // Reuse store messages — the field meanings are identical.
        return (new StoreBranchRequest())->messages();
    }
}