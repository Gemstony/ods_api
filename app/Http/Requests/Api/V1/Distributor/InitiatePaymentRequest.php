<?php

namespace App\Http\Requests\Api\V1\Distributor;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /distributor/payments/initiate.
 *
 * `phone_number` optional — when absent, the controller uses the
 * distributor's own phone. Allows paying from a different MSISDN
 * (e.g. a business line).
 *
 * `stage` optional — defaults to `full`. Regional deliveries that were
 * charged in two stages use `ods_fee` and `transport_fare`.
 */
class InitiatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'delivery_id' => ['required', 'integer', 'exists:deliveries,id'],
            'phone_number' => [
                'nullable',
                'string',
                'regex:/^255\d{9}$/',
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
        ];
    }

    public function messages(): array
    {
        return [
            'phone_number.regex' => 'Phone number must be in 255XXXXXXXXX format.',
            'stage.in'           => 'Stage must be full, ods_fee, or transport_fare.',
        ];
    }
}