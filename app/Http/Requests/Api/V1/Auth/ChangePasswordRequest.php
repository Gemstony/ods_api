<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for a user changing their own password.
 *
 * `current_password` is verified against the stored hash in the
 * controller — Laravel's `current_password` rule would also work, but
 * an explicit check lets us return a field-scoped 422 that the Flutter
 * form can display next to the field.
 */
class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'different:current_password',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Your current password is required.',
            'password.required' => 'New password is required.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Passwords do not match.',
            'password.different' => 'New password must be different from your current password.',
        ];
    }
}
