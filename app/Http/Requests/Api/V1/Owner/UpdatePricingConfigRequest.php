<?php

namespace App\Http\Requests\Api\V1\Owner;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for pricing config updates.
 *
 * All fields are required (full replace). Custom business rules:
 *   - rider_baseline_percent + rider_bonus_percent ≤ 100
 *   - reward_tier2_threshold > reward_tier1_threshold
 *
 * The dispatcher compensation model dictates which of the three
 * dispatcher fields is actually used; the others may be 0.
 */
class UpdatePricingConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ─── Local ────────────────────────────────────────
            'local_rate_per_km' =>
                'required|numeric|min:0|max:100000',
            'local_minimum_fee' =>
                'required|numeric|min:0|max:1000000',
            'local_rounding_increment' =>
                'required|integer|min:1|max:10000',

            // ─── Regional ─────────────────────────────────────
            'regional_base_fee' =>
                'required|numeric|min:0|max:1000000',
            'regional_minimum_fee' =>
                'required|numeric|min:0|max:1000000',
            'regional_rounding_increment' =>
                'required|integer|min:1|max:10000',

            // ─── Compensation ─────────────────────────────────
            'rider_baseline_percent' =>
                'required|numeric|min:0|max:100',
            'rider_bonus_percent' =>
                'required|numeric|min:0|max:100',

            'dispatcher_compensation_model' => [
                'required',
                Rule::in(['flat_per_package', 'shift_allowance', 'percent_of_fee']),
            ],
            'dispatcher_flat_fee' =>
                'required|numeric|min:0|max:1000000',
            'dispatcher_shift_allowance' =>
                'required|numeric|min:0|max:1000000',
            'dispatcher_percent' =>
                'required|numeric|min:0|max:100',

            // ─── Rewards ──────────────────────────────────────
            'reward_tier1_threshold' =>
                'required|integer|min:1|max:1000',
            'reward_tier1_discount_percent' =>
                'required|numeric|min:0|max:100',
            'reward_tier2_threshold' =>
                'required|integer|min:1|max:1000',
            'reward_rolling_window_days' =>
                'required|integer|min:1|max:365',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Business rule: baseline + bonus must not exceed 100%.
            $baseline = (float) $this->input('rider_baseline_percent', 0);
            $bonus = (float) $this->input('rider_bonus_percent', 0);
            if ($baseline + $bonus > 100.0) {
                $validator->errors()->add(
                    'rider_bonus_percent',
                    'Rider baseline percent plus bonus percent cannot exceed 100.',
                );
            }

            // Business rule: tier 2 requires more deliveries than tier 1.
            $tier1 = (int) $this->input('reward_tier1_threshold', 0);
            $tier2 = (int) $this->input('reward_tier2_threshold', 0);
            if ($tier1 > 0 && $tier2 > 0 && $tier2 <= $tier1) {
                $validator->errors()->add(
                    'reward_tier2_threshold',
                    'Tier 2 threshold must be greater than tier 1.',
                );
            }
        });
    }
}