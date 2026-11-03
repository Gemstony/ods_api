<?php

namespace App\Http\Requests\Api\V1\Distributor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for `POST /distributor/deliveries`.
 *
 * Per-service rules:
 *   Receive  → pickup_branch_id + destination lat/lng
 *   Send     → pickup address + coords + recipient + destination
 *   Regional → pickup_branch_id + recipient + region/district/bus_stop
 *              + bus_cargo_fare
 *
 * Items: each row must have either an `exgo_order_id` (M-Commerce) OR
 * both `product_name` and `quantity` (WhatsApp order). Enforced in
 * withValidator.
 */
class StoreDeliveryRequest extends FormRequest
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

            // ─── Items (all services) ────────────────────────
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.exgo_order_id' => ['nullable', 'string', 'max:100'],
            'items.*.product_name' => ['nullable', 'string', 'max:150'],
            'items.*.product_code' => ['nullable', 'string', 'max:50'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],

            // ─── Extras ──────────────────────────────────────
            'notes' => ['nullable', 'string', 'max:1000'],
            'apply_reward' => ['nullable', 'boolean'],
        ];

        if (in_array($serviceType, ['receive', 'regional'], true)) {
            $rules['pickup_branch_id'] = [
                'required',
                'integer',
                'exists:branches,id',
            ];
            $rules['pickup_contact_phone'] = ['nullable', 'string', 'max:20'];
        }

        if ($serviceType === 'send') {
            $rules['pickup_address'] = ['required', 'string', 'max:500'];
            $rules['pickup_latitude'] = ['required', 'numeric', 'between:-90,90'];
            $rules['pickup_longitude'] = ['required', 'numeric', 'between:-180,180'];
            $rules['pickup_contact_phone'] = ['nullable', 'string', 'max:20'];
        }

        if (in_array($serviceType, ['receive', 'send'], true)) {
            $rules['destination_address'] = ['required', 'string', 'max:500'];
            $rules['destination_latitude'] = ['required', 'numeric', 'between:-90,90'];
            $rules['destination_longitude'] = ['required', 'numeric', 'between:-180,180'];
        }

        if (in_array($serviceType, ['send', 'regional'], true)) {
            $rules['recipient_name'] = ['required', 'string', 'max:100'];
            $rules['recipient_phone'] = [
                'required',
                'string',
                'regex:/^(?:\+?255|0)(?:6[1-9]|7[1-9])\d{7}$/',
            ];
        }

        if ($serviceType === 'regional') {
            $rules['destination_region'] = ['required', 'string', 'max:100'];
            $rules['destination_district'] = ['required', 'string', 'max:100'];
            $rules['destination_bus_stop'] = ['required', 'string', 'max:150'];
            $rules['bus_cargo_fare'] = ['required', 'numeric', 'min:0'];
        }

        return $rules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $items = $this->input('items');
            if (! is_array($items)) {
                return;
            }

            foreach ($items as $i => $item) {
                $hasExgo = ! empty($item['exgo_order_id']);
                $hasProduct = ! empty($item['product_name'])
                    && ! empty($item['quantity']);

                if (! $hasExgo && ! $hasProduct) {
                    $validator->errors()->add(
                        "items.{$i}",
                        'Each item must have an EXGO order ID OR both a product name and quantity.',
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'service_type.in' => 'Service type must be receive, send, or regional.',
            'items.required' => 'Add at least one item to the delivery.',
            'items.min' => 'Add at least one item to the delivery.',
            'items.max' => 'You can add at most 20 items per delivery.',
            'pickup_branch_id.required' => 'Select the pickup branch.',
            'pickup_address.required' => 'Pickup address is required.',
            'destination_address.required' => 'Delivery address is required.',
            'destination_latitude.required' => 'Destination location is required.',
            'destination_longitude.required' => 'Destination location is required.',
            'recipient_name.required' => 'Recipient name is required.',
            'recipient_phone.required' => 'Recipient phone is required.',
            'recipient_phone.regex' => 'Enter a valid Tanzania phone number.',
            'destination_region.required' => 'Destination region is required.',
            'destination_district.required' => 'Destination district is required.',
            'destination_bus_stop.required' => 'Bus stop / collection point is required.',
            'bus_cargo_fare.required' => 'Bus/cargo transport fare is required.',
            'bus_cargo_fare.min' => 'Transport fare cannot be negative.',
        ];
    }
}