<?php

namespace App\Services\Pricing;

use App\Models\PricingConfig;

/**
 * Fee computation for ODS deliveries.
 *
 * Reads the singleton `PricingConfig` (2.4) and produces the pricing
 * snapshot that a Delivery stores at creation time. The snapshot is
 * immutable once written — if the Owner later changes rates, existing
 * deliveries keep their original pricing.
 *
 * **Distance:** uses haversine ("as the crow flies"). Phase 10 may
 * replace with a routing API; the interface (`haversineKm`) becomes
 * `routingKm` with the same signature, so callers don't change.
 *
 * **Discounts:** apply to the ODS delivery fee only, never the bus/cargo
 * fare. "Free delivery" (reward tier 2) means the ODS portion is free —
 * the transport provider still gets paid.
 */
class PricingService
{
    private PricingConfig $config;

    public function __construct(?PricingConfig $config = null)
    {
        $this->config = $config ?? PricingConfig::current();
    }

    // ─────────────────────────────────────────────────────────
    // Local delivery (Receive + Send)
    // ─────────────────────────────────────────────────────────

    /**
     * Computes local delivery pricing.
     *
     * Formula:
     *   calculated  = distanceKm × ratePerKm
     *   ods_fee     = round_up(max(calculated, minimum_fee), rounding_increment)
     *   discount    = (reserved for Phase 13)
     *   total       = ods_fee - discount
     *
     * @return array The pricing snapshot shape that `deliveries` expects.
     */
    public function previewLocal(
        float $distanceKm,
        float $discountAmount = 0.0,
    ): array {
        $rate = (float) $this->config->local_rate_per_km;
        $minFee = (float) $this->config->local_minimum_fee;
        $increment = (int) $this->config->local_rounding_increment;

        $calculated = round($distanceKm * $rate, 2);
        $withMinimum = max($calculated, $minFee);
        $odsFee = $this->roundUp($withMinimum, $increment);
        $discountAmount = max(0.0, min($discountAmount, $odsFee));
        $total = max(0.0, $odsFee - $discountAmount);

        return [
            'distance_km'         => round($distanceKm, 2),
            'rate_per_km'         => $rate,
            'minimum_fee'         => $minFee,
            'rounding_increment'  => $increment,
            'calculated_fee'      => $calculated,
            'ods_delivery_fee'    => $odsFee,
            'bus_cargo_fare'      => null,
            'discount_amount'     => $discountAmount,
            'discount_reason'     => null,
            'total_amount'        => $total,
            'currency'            => 'TZS',
        ];
    }

    // ─────────────────────────────────────────────────────────
    // Regional delivery
    // ─────────────────────────────────────────────────────────

    /**
     * Computes regional delivery pricing.
     *
     * Formula:
     *   ods_fee  = round_up(max(regional_base_fee, regional_minimum_fee),
     *                       regional_rounding_increment)
     *   total    = (ods_fee - discount) + bus_cargo_fare
     *
     * Distance/rate are 0 (not applicable for regional). Flutter's
     * `PricePreviewResult.fromJson` requires non-null, so we send 0.
     */
    public function previewRegional(
        float $busCargoFare,
        float $discountAmount = 0.0,
    ): array {
        $base = (float) $this->config->regional_base_fee;
        $minFee = (float) $this->config->regional_minimum_fee;
        $increment = (int) $this->config->regional_rounding_increment;

        $withMinimum = max($base, $minFee);
        $odsFee = $this->roundUp($withMinimum, $increment);
        $discountAmount = max(0.0, min($discountAmount, $odsFee));
        $total = max(0.0, ($odsFee - $discountAmount) + $busCargoFare);

        return [
            'distance_km'         => 0.0,
            'rate_per_km'         => 0.0,
            'minimum_fee'         => $minFee,
            'rounding_increment'  => $increment,
            'calculated_fee'      => $odsFee,
            'ods_delivery_fee'    => $odsFee,
            'bus_cargo_fare'      => round($busCargoFare, 2),
            'discount_amount'     => $discountAmount,
            'discount_reason'     => null,
            'total_amount'        => round($total, 2),
            'currency'            => 'TZS',
        ];
    }

    // ─────────────────────────────────────────────────────────
    // Distance helper
    // ─────────────────────────────────────────────────────────

    /**
     * Haversine distance between two GPS points, in kilometers.
     *
     * Precision: ~1 meter on Earth's surface for inputs in degrees.
     */
    public function haversineKm(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2,
    ): float {
        $earthRadiusKm = 6371.0;

        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($lngDelta / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadiusKm * $c, 2);
    }

    // ─────────────────────────────────────────────────────────
    // Internal
    // ─────────────────────────────────────────────────────────

    private function roundUp(float $amount, int $increment): float
    {
        if ($increment <= 0) {
            return round($amount, 2);
        }
        return (float) (ceil($amount / $increment) * $increment);
    }
}