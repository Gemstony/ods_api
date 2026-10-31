<?php

namespace App\Http\Controllers\Api\V1\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Owner\UpdatePricingConfigRequest;
use App\Models\PricingConfig;
use App\Services\Audit\AuditLogger;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Owner-facing pricing configuration.
 *
 * Endpoints:
 *   GET /owner/pricing-config  → current singleton
 *   PUT /owner/pricing-config  → full replace
 *
 * The config is a singleton (id = 1). "Full replace" semantics mean
 * the client always sends the complete set of fields — no partial
 * updates that could leave the config in an inconsistent state.
 */
class PricingConfigController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    // ─── show ─────────────────────────────────────────────────
    public function show(): JsonResponse
    {
        return $this->success(
            $this->serialize(PricingConfig::current()),
            'OK',
        );
    }

    // ─── update ───────────────────────────────────────────────
    public function update(UpdatePricingConfigRequest $request): JsonResponse
    {
        $config = PricingConfig::current();
        $old = $this->serialize($config);

        $config->update([
            'local_rate_per_km' => $request->input('local_rate_per_km'),
            'local_minimum_fee' => $request->input('local_minimum_fee'),
            'local_rounding_increment' => $request->input('local_rounding_increment'),
            'regional_base_fee' => $request->input('regional_base_fee'),
            'regional_minimum_fee' => $request->input('regional_minimum_fee'),
            'regional_rounding_increment' => $request->input('regional_rounding_increment'),
            'rider_baseline_percent' => $request->input('rider_baseline_percent'),
            'rider_bonus_percent' => $request->input('rider_bonus_percent'),
            'dispatcher_compensation_model' => $request->input('dispatcher_compensation_model'),
            'dispatcher_flat_fee' => $request->input('dispatcher_flat_fee'),
            'dispatcher_shift_allowance' => $request->input('dispatcher_shift_allowance'),
            'dispatcher_percent' => $request->input('dispatcher_percent'),
            'reward_tier1_threshold' => $request->input('reward_tier1_threshold'),
            'reward_tier1_discount_percent' => $request->input('reward_tier1_discount_percent'),
            'reward_tier2_threshold' => $request->input('reward_tier2_threshold'),
            'reward_rolling_window_days' => $request->input('reward_rolling_window_days'),
            'updated_by' => $request->user()->id,
        ]);

        $config->refresh();
        $new = $this->serialize($config);

        $this->auditLogger->logCurrentUser(
            action: 'pricing_config.updated',
            description: 'Updated pricing configuration',
            subject: $config,
            changes: ['old' => $old, 'new' => $new],
        );

        return $this->success($new, 'Pricing configuration updated.');
    }

    // ─── Serializer ───────────────────────────────────────────
    private function serialize(PricingConfig $config): array
    {
        return [
            'id' => $config->id,
            'local_rate_per_km' => (float) $config->local_rate_per_km,
            'local_minimum_fee' => (float) $config->local_minimum_fee,
            'local_rounding_increment' => (int) $config->local_rounding_increment,
            'regional_base_fee' => (float) $config->regional_base_fee,
            'regional_minimum_fee' => (float) $config->regional_minimum_fee,
            'regional_rounding_increment' => (int) $config->regional_rounding_increment,
            'rider_baseline_percent' => (float) $config->rider_baseline_percent,
            'rider_bonus_percent' => (float) $config->rider_bonus_percent,
            'dispatcher_compensation_model' => $config->dispatcher_compensation_model,
            'dispatcher_flat_fee' => (float) $config->dispatcher_flat_fee,
            'dispatcher_shift_allowance' => (float) $config->dispatcher_shift_allowance,
            'dispatcher_percent' => (float) $config->dispatcher_percent,
            'reward_tier1_threshold' => (int) $config->reward_tier1_threshold,
            'reward_tier1_discount_percent' => (float) $config->reward_tier1_discount_percent,
            'reward_tier2_threshold' => (int) $config->reward_tier2_threshold,
            'reward_rolling_window_days' => (int) $config->reward_rolling_window_days,
            'updated_by' => $config->updated_by,
            'created_at' => $config->created_at?->toIso8601String(),
            'updated_at' => $config->updated_at?->toIso8601String(),
        ];
    }
}