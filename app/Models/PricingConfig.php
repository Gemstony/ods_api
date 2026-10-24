<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Singleton pricing configuration (id = 1).
 *
 * Always access via [current()] — never query directly. The helper creates
 * the singleton row with defaults if it doesn't yet exist, so Phase 10 can
 * assume a config always exists.
 */
class PricingConfig extends Model
{
    use HasFactory;

    protected $table = 'pricing_config';

    public const DISPATCHER_FLAT    = 'flat_per_package';
    public const DISPATCHER_SHIFT   = 'shift_allowance';
    public const DISPATCHER_PERCENT = 'percent_of_fee';

    protected $fillable = [
        'local_rate_per_km',
        'local_minimum_fee',
        'local_rounding_increment',
        'regional_base_fee',
        'regional_minimum_fee',
        'regional_rounding_increment',
        'rider_baseline_percent',
        'rider_bonus_percent',
        'dispatcher_compensation_model',
        'dispatcher_flat_fee',
        'dispatcher_shift_allowance',
        'dispatcher_percent',
        'reward_tier1_threshold',
        'reward_tier1_discount_percent',
        'reward_tier2_threshold',
        'reward_rolling_window_days',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'local_rate_per_km'              => 'float',
            'local_minimum_fee'              => 'float',
            'local_rounding_increment'       => 'integer',
            'regional_base_fee'              => 'float',
            'regional_minimum_fee'           => 'float',
            'regional_rounding_increment'    => 'integer',
            'rider_baseline_percent'         => 'float',
            'rider_bonus_percent'            => 'float',
            'dispatcher_flat_fee'            => 'float',
            'dispatcher_shift_allowance'     => 'float',
            'dispatcher_percent'             => 'float',
            'reward_tier1_threshold'         => 'integer',
            'reward_tier1_discount_percent'  => 'float',
            'reward_tier2_threshold'         => 'integer',
            'reward_rolling_window_days'     => 'integer',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Returns the singleton row, creating it with sensible defaults if missing.
     */
    public static function current(): self
    {
        return static::firstOrCreate(
            ['id' => 1],
            [
                'local_rate_per_km'              => 500.00,
                'local_minimum_fee'              => 1000.00,
                'local_rounding_increment'       => 500,
                'regional_base_fee'              => 0.00,
                'regional_minimum_fee'           => 0.00,
                'regional_rounding_increment'    => 500,
                'rider_baseline_percent'         => 80.00,
                'rider_bonus_percent'            => 10.00,
                'dispatcher_compensation_model'  => self::DISPATCHER_FLAT,
                'dispatcher_flat_fee'            => 0.00,
                'dispatcher_shift_allowance'     => 0.00,
                'dispatcher_percent'             => 0.00,
                'reward_tier1_threshold'         => 20,
                'reward_tier1_discount_percent'  => 50.00,
                'reward_tier2_threshold'         => 50,
                'reward_rolling_window_days'     => 30,
            ],
        );
    }
}