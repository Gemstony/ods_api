<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a user editing their own profile.
 *
 * Email is intentionally NOT editable here — changing it would require a
 * re-verification flow that doesn't exist yet (same convention as
 * Owner\UpdateUserRequest). Name and phone are the user-editable fields.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'phone' => [
                'required',
                'string',
                'regex:/^(?:\+?255|0)(?:6[1-9]|7[1-9])\d{7}$/',
                Rule::unique('users', 'phone')->ignore($user->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Your full name is required.',
            'name.min' => 'Your name must be at least 2 characters.',
            'name.max' => 'Your name must be at most 100 characters.',
            'phone.required' => 'Phone number is required.',
            'phone.regex' => 'Enter a valid Tanzania phone number (e.g. 0712 345 678).',
            'phone.unique' => 'This phone number is already registered.',
        ];
    }
}
