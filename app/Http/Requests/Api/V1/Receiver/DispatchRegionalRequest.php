<?php

namespace App\Http\Requests\Api\V1\Receiver;

use App\Models\DispatcherTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for dispatching a regional delivery via a bus/cargo provider.
 *
 * `transport_fare` is the actual amount owed to the provider; when
 * omitted, the controller inherits it from `deliveries.bus_cargo_fare`
 * (the distributor's estimate). Phase 11 can add reconciliation if the
 * estimate and actual diverge significantly.
 */
class DispatchRegionalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider_name' => ['required', 'string', 'max:100'],
            'provider_type' => [
                'required',
                Rule::in([
                    DispatcherTicket::PROVIDER_BUS,
                    DispatcherTicket::PROVIDER_CARGO,
                    DispatcherTicket::PROVIDER_OTHER,
                ]),
            ],
            'ticket_number' => ['required', 'string', 'max:100'],
            'route_from' => ['required', 'string', 'max:100'],
            'route_to' => ['required', 'string', 'max:100'],
            'transport_fare' => ['nullable', 'numeric', 'min:0'],
            'ticket_image_path' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'provider_type.in' => 'Provider type must be bus, cargo, or other.',
            'transport_fare.numeric' => 'Transport fare must be a number.',
        ];
    }
}