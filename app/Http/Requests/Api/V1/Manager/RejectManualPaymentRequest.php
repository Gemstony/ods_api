<?php

namespace App\Http\Requests\Api\V1\Manager;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /manager/payments/{id}/reject.
 * A rejection must carry a reason — the Distributor sees it in the
 * notification and the payment's failure_reason field.
 */
class RejectManualPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A rejection reason is required.',
            'reason.min' => 'Please provide a reason of at least 5 characters.',
        ];
    }
}