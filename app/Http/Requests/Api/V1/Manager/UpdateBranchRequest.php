<?php

namespace App\Http\Requests\Api\V1\Manager;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a Manager updating their own branch.
 *
 * Deliberately OMITS `status`. Status transitions are Owner-only
 * (Phase 5.1). A Manager who needs to close their branch must request
 * it from the Owner — the branch stays active otherwise.
 *
 * Code is also immutable from the Manager side: it's the branch's
 * operational identifier and changing it would break delivery routes
 * and receipts. Only the Owner can change a code (Phase 5.1).
 */
class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'address' => ['required', 'string', 'min:5', 'max:500'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'operating_hours' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Branch name is required.',
            'address.required' => 'Branch address is required.',
            'latitude.required' => 'Latitude is required.',
            'latitude.between' => 'Latitude must be between -90 and 90.',
            'longitude.required' => 'Longitude is required.',
            'longitude.between' => 'Longitude must be between -180 and 180.',
        ];
    }
}