<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for distributor self-registration.
 *
 * All unique checks hit the users table. The phone regex matches the
 * Flutter-side Validators.phone() pattern exactly, so users can't submit
 * something the client accepted but the server rejects.
 */
class RegisterDistributorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'distributor_id' => [
                'required',
                'string',
                'min:3',
                'max:50',
                'unique:users,distributor_id',
            ],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => [
                'required',
                'string',
                'regex:/^(?:\+?255|0)(?:6[1-9]|7[1-9])\d{7}$/',
                'unique:users,phone',
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Your full name is required.',
            'name.min' => 'Your name must be at least 2 characters.',
            'distributor_id.required' => 'Your NeoLife distributor ID is required.',
            'distributor_id.min' => 'Distributor ID must be at least 3 characters.',
            'distributor_id.unique' => 'This distributor ID is already registered.',
            'email.required' => 'Email is required.',
            'email.email' => 'Please enter a valid email address.',
            'email.unique' => 'This email is already registered.',
            'phone.required' => 'Phone number is required.',
            'phone.regex' => 'Enter a valid Tanzania phone number (e.g. 0712 345 678).',
            'phone.unique' => 'This phone number is already registered.',
            'password.required' => 'Password is required.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Passwords do not match.',
        ];
    }
}