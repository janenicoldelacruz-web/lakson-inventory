<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    /**
     * GET /api/owner/brands
     * GET /api/owner/brands?category_id=1
     *
     * Returns list of brands. If category_id is provided,
     * it auto-filters brands for that product category.
     */
    public function index(Request $request)
    {
        $query = Brand::query()
            ->with('category')
            ->orderBy('name');

        if ($request->filled('category_id')) {
            $query->where('product_category_id', $request->category_id);
        }

        $brands = $query->get(['id', 'product_category_id', 'name']);

        return response()->json([
            'data' => $brands,
        ]);
    }
}
