<?php

namespace App\Http\Requests\Api\V1\Distributor;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /distributor/payments/manual.
 *
 * Client supplies: delivery_id, method (cash|manual), optional stage.
 * Notes field carries free-form context (e.g. "Paid at branch reception").
 */
class ManualPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'delivery_id' => ['required', 'integer', 'exists:deliveries,id'],
            'method' => [
                'required',
                'string',
                Rule::in([Payment::METHOD_CASH, Payment::METHOD_MANUAL]),
            ],
            'stage' => [
                'nullable',
                'string',
                Rule::in([
                    Payment::STAGE_FULL,
                    Payment::STAGE_ODS_FEE,
                    Payment::STAGE_TRANSPORT_FARE,
                ]),
            ],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'method.in' => 'Method must be cash or manual.',
            'stage.in' => 'Stage must be full, ods_fee, or transport_fare.',
        ];
    }
}