<?php

namespace App\Http\Requests\Api\V1\Distributor;

use App\Models\Dispute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared shape for the 3 role-specific `POST .../disputes` endpoints
 * (Distributor, Manager, Rider). Scoping and delivery-access checks
 * happen inside `DisputeService::open()` — this class only handles
 * payload validation.
 */
class OpenDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'delivery_id' => ['required', 'integer', 'exists:deliveries,id'],
            'category' => [
                'required',
                'string',
                Rule::in([
                    Dispute::CATEGORY_DELIVERY,
                    Dispute::CATEGORY_PAYMENT,
                    Dispute::CATEGORY_PACKAGE,
                    Dispute::CATEGORY_COMPENSATION,
                    Dispute::CATEGORY_OTHER,
                ]),
            ],
            'subject' => ['required', 'string', 'min:3', 'max:200'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'priority' => [
                'nullable',
                'string',
                Rule::in([
                    Dispute::PRIORITY_LOW,
                    Dispute::PRIORITY_NORMAL,
                    Dispute::PRIORITY_HIGH,
                    Dispute::PRIORITY_URGENT,
                ]),
            ],
            'against_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'delivery_id.exists' => 'The specified delivery does not exist.',
            'description.min'    => 'Please describe the issue in at least 10 characters.',
        ];
    }
}