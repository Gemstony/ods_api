<?php

namespace Database\Seeders;

use App\Models\PricingConfig;
use Illuminate\Database\Seeder;

/**
 * Seeds the singleton pricing-configuration row.
 *
 * Delegates to [PricingConfig::current()], which is idempotent and uses
 * the ODS-document-specified defaults (rate 500/km, min 1000, round to
 * 500, rider 80% + 10% bonus, dispatcher flat).
 */
class PricingConfigSeeder extends Seeder
{
    public function run(): void
    {
        $config = PricingConfig::current();
        $this->command?->info("Pricing config ready (id={$config->id}).");
    }
}