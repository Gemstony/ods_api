<?php

namespace Database\Factories;

use App\Models\PricingConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PricingConfig>
 */
class PricingConfigFactory extends Factory
{
    protected $model = PricingConfig::class;

    public function definition(): array
    {
        return [
            'local_rate_per_km'              => 500.00,
            'local_minimum_fee'              => 1000.00,
            'local_rounding_increment'       => 500,
            'regional_base_fee'              => 0.00,
            'regional_minimum_fee'           => 0.00,
            'regional_rounding_increment'    => 500,
            'rider_baseline_percent'         => 80.00,
            'rider_bonus_percent'            => 10.00,
            'dispatcher_compensation_model'  => PricingConfig::DISPATCHER_FLAT,
            'dispatcher_flat_fee'            => 500.00,
            'dispatcher_shift_allowance'     => 0.00,
            'dispatcher_percent'             => 0.00,
            'reward_tier1_threshold'         => 20,
            'reward_tier1_discount_percent'  => 50.00,
            'reward_tier2_threshold'         => 50,
            'reward_rolling_window_days'     => 30,
        ];
    }
}