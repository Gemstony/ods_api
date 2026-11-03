<?php

namespace App\Http\Controllers\Api\V1\Distributor;

use App\Http\Controllers\Controller;
use App\Models\PricingConfig;
use App\Models\Reward;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Distributor-facing rewards endpoint.
 *
 * Endpoint:
 *   GET /rewards/me  → rewards progress + current thresholds
 *
 * Response shape (matches the `rewards` field of `GET /distributor/dashboard`
 * — see DashboardController 7.2, and Flutter's `DistributorRewardKpis`):
 *   {
 *     "progress":   { id, distributor_id, monthly_count, rolling_count, ... },
 *     "thresholds": { tier1, tier2, tier1_discount_percent }
 *   }
 *
 * Only Distributors have rewards. Manager/Owner/Rider/Receiver get 403.
 */
class RewardsController extends Controller
{
    use ApiResponse;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $config = PricingConfig::current();
        $reward = Reward::for($user);

        return $this->success(
            data: [
                'progress' => $reward->toArray(),
                'thresholds' => [
                    'tier1'                  => (int) $config->reward_tier1_threshold,
                    'tier2'                  => (int) $config->reward_tier2_threshold,
                    'tier1_discount_percent' => (float) $config->reward_tier1_discount_percent,
                ],
            ],
            message: 'OK',
        );
    }
}