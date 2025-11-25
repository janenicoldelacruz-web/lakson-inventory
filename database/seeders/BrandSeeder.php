<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

class BrandSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Map of category name => list of brand names
        $data = [
            // Feeds for pigs
            'Feeds' => [
                'B-MEG',
                'Purina',
                'Robina',
                'Pilmico',
                'Universal Feeds',
            ],

            // Supplements for pigs
            'Supplements' => [
                'OptiGro Hog Supplements',
                'Hog Plus Supplements',
                'ProMix Hog',
                'Vitaplus Swine',
                'MaxGro Swine',
            ],

            // Vitamins for pigs
            'Vitamins' => [
                'Vitameg Swine',
                'Pig-Vita Boost',
                'SwineCare Vitamins',
                'NutriHog',
                'HogStrong',
            ],

            // Medicines for pigs
            'Medicines' => [
                'SwineMed',
                'HogGuard',
                'PigSafe',
                'VetShield Swine',
                'FarmCare Swine',
            ],
        ];

        foreach ($data as $categoryName => $brands) {
            // Ensure the product category exists
            $category = ProductCategory::firstOrCreate(
                ['name' => $categoryName],
                [] // add other default fields if your table needs them
            );

            foreach ($brands as $brandName) {
                Brand::firstOrCreate(
                    [
                        'product_category_id' => $category->id,
                        'name' => $brandName,
                    ],
                    []
                );
            }
        }
    }
}
