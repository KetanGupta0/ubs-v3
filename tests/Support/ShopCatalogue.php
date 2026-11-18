<?php

namespace Tests\Support;

use App\Models\ProductAddon;
use App\Models\ProductTier;
use App\Models\Solution;
use App\Models\SolutionCategory;

/**
 * Made up products to sell in tests (§4): one sold with its source code by
 * licence tier, with add-ons; nothing here resembles a real catalogue entry.
 */
class ShopCatalogue
{
    /** @return array{solution: Solution, single: ProductTier, multi: ProductTier, install: ProductAddon, hours: ProductAddon, support: ProductAddon} */
    public static function product(string $title = 'Clinic desk', array $overrides = []): array
    {
        $category = SolutionCategory::query()->firstOrCreate(['slug' => 'healthcare'], ['name' => 'Healthcare', 'sort_order' => 1]);

        $solution = Solution::query()->create([
            'solution_category_id' => $category->id,
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'tagline' => 'Appointments without the paper',
            'summary' => 'Registration to discharge on one record.',
            'platforms' => ['Web'],
            'price_band_min' => 300000,
            'price_band_max' => 900000,
            'offerings' => ['custom_build', 'source_code'],
            'is_published' => true,
            ...$overrides,
        ]);

        return [
            'solution' => $solution,
            'single' => $solution->tiers()->create(['kind' => 'licence', 'name' => 'Single site', 'price' => 49_999_00, 'sites' => 1, 'update_months' => 12, 'support_months' => 3]),
            'multi' => $solution->tiers()->create(['kind' => 'licence', 'name' => 'Five sites', 'price' => 1_49_999_00, 'sites' => 5, 'update_months' => 12, 'support_months' => 6, 'sort_order' => 1]),
            'install' => $solution->addons()->create(['name' => 'Installation on your server', 'kind' => 'service', 'price' => 5_000_00]),
            'hours' => $solution->addons()->create(['name' => 'Customisation', 'kind' => 'service', 'price' => 1_500_00, 'unit' => 'hour', 'max_quantity' => 40]),
            'support' => $solution->addons()->create(['name' => 'Extra support', 'kind' => 'support', 'price' => 2_000_00, 'unit' => 'month', 'max_quantity' => 24]),
        ];
    }
}
