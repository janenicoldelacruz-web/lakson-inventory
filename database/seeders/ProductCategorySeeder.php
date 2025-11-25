<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductCategorySeeder extends Seeder
{
    public function run()
    {
        DB::table('product_categories')->insert([
            ['id' => 1, 'name' => 'Feeds'],
            ['id' => 2, 'name' => 'Vitamins'],
            ['id' => 3, 'name' => 'Supplements'],
            ['id' => 4, 'name' => 'Medicine'],
        ]);
    }
}
