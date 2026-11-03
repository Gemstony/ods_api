<?php

namespace App\Http\Requests\Api\V1\Distributor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for `POST /distributor/deliveries/price-preview`.
 *
 * Accepts only the fields needed to compute a price:
 *   - service_type
 *   - pickup_branch_id (Receive + Regional)
 *   - pickup_lat/lng (Send)
 *   - destination_lat/lng (Receive + Send)
 *   - bus_cargo_fare (Regional — optional here, defaults to 0)
 *   - apply_reward (accepted, ignored until Phase 13)
 *
 * Order details (items) are NOT required for preview.
 */
class PricePreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $serviceType = $this->input('service_type');

        $rules = [
            'service_type' => [
                'required',
                'string',
                Rule::in(['receive', 'send', 'regional']),
            ],
            'apply_reward' => ['nullable', 'boolean'],
        ];

        if (in_array($serviceType, ['receive', 'regional'], true)) {
            $rules['pickup_branch_id'] = [
                'required',
                'integer',
                'exists:branches,id',
            ];
        }

        if ($serviceType === 'send') {
            $rules['pickup_latitude'] = ['required', 'numeric', 'between:-90,90'];
            $rules['pickup_longitude'] = ['required', 'numeric', 'between:-180,180'];
        }

        if (in_array($serviceType, ['receive', 'send'], true)) {
            $rules['destination_latitude'] = ['required', 'numeric', 'between:-90,90'];
            $rules['destination_longitude'] = ['required', 'numeric', 'between:-180,180'];
        }

        if ($serviceType === 'regional') {
            $rules['bus_cargo_fare'] = ['nullable', 'numeric', 'min:0'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'service_type.in' => 'Service type must be receive, send, or regional.',
            'pickup_branch_id.required' => 'Select the pickup branch.',
            'pickup_branch_id.exists' => 'The selected branch does not exist.',
            'pickup_latitude.required' => 'Pickup location is required.',
            'destination_latitude.required' => 'Destination location is required.',
            'destination_longitude.required' => 'Destination location is required.',
        ];
    }
}