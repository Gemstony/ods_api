<?php

namespace App\Http\Requests\Api\V1\Owner;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for updating a user's basic info.
 *
 * Email is immutable — changing it would require re-verification.
 * Role is immutable — use a dedicated endpoint when that's needed.
 * Password is immutable here — use `POST /owner/users/{user}/reset-password`.
 *
 * Branch changes are permitted for non-Manager roles. For Managers,
 * use `POST /owner/branches/{branch}/assign-manager` so the assignment
 * history is preserved.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var \App\Models\User $target */
        $target = $this->route('user');
        $isRider = $target->hasRole(Role::RIDER);
        $isReceiver = $target->hasRole(Role::RECEIVER);

        $rules = [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'phone' => [
                'required',
                'string',
                'regex:/^(?:\+?255|0)(?:6[1-9]|7[1-9])\d{7}$/',
                Rule::unique('users', 'phone')->ignore($target->id),
            ],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ];

        if ($isReceiver) {
            $rules['dispatcher_enabled'] = ['nullable', 'boolean'];
        }

        if ($isRider) {
            $rules['nida'] = ['required', 'string', 'size:20', 'regex:/^\d+$/'];
            $rules['sex'] = ['required', Rule::in(['male', 'female', 'other'])];
            $rules['date_of_birth'] = ['required', 'date', 'before:today'];
            $rules['vehicle_type'] = ['required', 'string', 'max:50'];
            $rules['vehicle_color'] = ['required', 'string', 'max:50'];
            $rules['vehicle_ownership'] = [
                'required',
                Rule::in(['owned', 'rented', 'company']),
            ];
            $rules['plate_number'] = ['required', 'string', 'max:20'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return (new StoreUserRequest())->messages() +
            ['phone.unique' => 'This phone number is already registered.'];
    }
}