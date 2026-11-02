<?php

namespace App\Http\Requests\Api\V1\Manager;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a Manager creating a Staff member.
 *
 * Restricts `role` to `receiver` and `rider`. A Manager cannot create
 * another Manager (only the Owner can — Phase 5.2), and cannot create
 * Distributors (they self-register) or Owners (singleton).
 *
 * `branch_id` is NOT validated here — the controller forces it to
 * `auth.user.branch_id`. Including it in the payload has no effect.
 *
 * Field rules mirror Owner's `StoreUserRequest` (5.2) exactly, minus
 * the Manager role's requirements.
 */
class StoreStaffRequest extends FormRequest
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
                Rule::in(['receiver', 'rider']),
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
            'role.in' => 'You may only create Receivers or Riders.',
            'name.required' => 'Full name is required.',
            'email.unique' => 'This email is already registered.',
            'phone.regex' => 'Enter a valid Tanzania phone number.',
            'phone.unique' => 'This phone number is already registered.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Passwords do not match.',
            'nida.required' => 'NIDA number is required for riders.',
            'nida.size' => 'NIDA must be exactly 20 digits.',
            'sex.required' => 'Sex is required for riders.',
            'date_of_birth.required' => 'Date of birth is required for riders.',
            'vehicle_type.required' => 'Vehicle type is required for riders.',
            'vehicle_color.required' => 'Vehicle color is required for riders.',
            'vehicle_ownership.required' => 'Vehicle ownership is required for riders.',
            'plate_number.required' => 'Plate number is required for riders.',
        ];
    }
}