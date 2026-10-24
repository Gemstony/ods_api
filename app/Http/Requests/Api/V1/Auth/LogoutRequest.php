<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `refresh_token` is optional on logout — the client may only want to
 * invalidate the access token. If provided, we also revoke that refresh
 * token (bound to the caller's own user id).
 */
class LogoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'refresh_token' => ['nullable', 'string', 'min:32', 'max:255'],
        ];
    }
}