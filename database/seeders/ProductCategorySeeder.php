<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class ProductCategorySeeder extends Seeder
{
    public function run()
    {
        $now = Carbon::now();

        DB::table('product_categories')->insert([
            ['id' => 1, 'name' => 'feeds', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'vitamins', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'name' => 'supplements', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'name' => 'medicines', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
