<?php

namespace App\Http\Requests\Api\V1\Owner;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for creating a user (Manager / Receiver / Rider).
 *
 * Distributors are NOT created here — they self-register and are
 * approved via `POST /owner/users/{user}/approve`.
 * Owners are created by the seeder only.
 *
 * Role-specific rules:
 *   Manager  → branch_id required (assigned via Branch::assignManager())
 *   Receiver → branch_id required, dispatcher_enabled optional
 *   Rider    → branch_id required + all vehicle fields + NIDA
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $role = $this->input('role');

        $rules = [
            'role' => [
                'required',
                'string',
                Rule::in(['manager', 'receiver', 'rider']),
            ],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ],
            'phone' => [
                'required',
                'string',
                'regex:/^(?:\+?255|0)(?:6[1-9]|7[1-9])\d{7}$/',
                Rule::unique('users', 'phone'),
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ];

        if ($role === 'receiver') {
            $rules['dispatcher_enabled'] = ['nullable', 'boolean'];
        }

        if ($role === 'rider') {
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
        return [
            'role.required' => 'Role is required.',
            'role.in' => 'Role must be one of: manager, receiver, rider.',
            'name.required' => 'Full name is required.',
            'email.required' => 'Email is required.',
            'email.unique' => 'This email is already registered.',
            'phone.required' => 'Phone number is required.',
            'phone.regex' => 'Enter a valid Tanzania phone number (e.g. 0712 345 678).',
            'phone.unique' => 'This phone number is already registered.',
            'password.required' => 'Password is required.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Passwords do not match.',
            'branch_id.required' => 'Please assign a branch.',
            'branch_id.exists' => 'The selected branch does not exist.',
            'nida.required' => 'NIDA number is required for riders.',
            'nida.size' => 'NIDA number must be exactly 20 digits.',
            'sex.required' => 'Sex is required for riders.',
            'date_of_birth.required' => 'Date of birth is required for riders.',
            'date_of_birth.before' => 'Date of birth must be in the past.',
            'vehicle_type.required' => 'Vehicle type is required for riders.',
            'vehicle_color.required' => 'Vehicle color is required for riders.',
            'vehicle_ownership.required' => 'Vehicle ownership is required for riders.',
            'plate_number.required' => 'Plate number is required for riders.',
        ];
    }
}