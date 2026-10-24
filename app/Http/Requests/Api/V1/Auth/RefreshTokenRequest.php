<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RefreshTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'refresh_token' => ['required', 'string', 'min:32', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'refresh_token.required' => 'Refresh token is required.',
            'refresh_token.min'      => 'Refresh token is malformed.',
        ];
    }
}