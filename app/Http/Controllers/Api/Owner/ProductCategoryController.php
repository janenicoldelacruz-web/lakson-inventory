<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use Illuminate\Http\Request;

class ProductCategoryController extends Controller
{
    /**
     * GET /api/owner/product-categories?paginate=15
     * Optional paginated list (for admin screens later)
     */
    public function index(Request $request)
    {
        $paginate = (int) $request->get('paginate', 15);

        $categories = ProductCategory::orderBy('name')
            ->paginate($paginate);

        return response()->json($categories);
    }

    /**
     * GET /api/categories
     * Simple list used by Vue ProductCreateView (id + name only)
     */
    public function all()
    {
        $categories = ProductCategory::orderBy('name')->get(['id', 'name']);

        return response()->json($categories);
    }

    /**
     * POST /api/owner/product-categories
     * (Optional, if you want to manage categories from UI)
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:product_categories,name'],
        ]);

        $category = ProductCategory::create($data);

        return response()->json([
            'message'  => 'Category created successfully.',
            'category' => $category,
        ], 201);
    }

    /**
     * PUT /api/owner/product-categories/{id}
     */
    public function update(Request $request, $id)
    {
        $category = ProductCategory::findOrFail($id);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:product_categories,name,' . $category->id],
        ]);

        $category->update($data);

        return response()->json([
            'message'  => 'Category updated successfully.',
            'category' => $category,
        ]);
    }

    /**
     * DELETE /api/owner/product-categories/{id}
     */
    public function destroy($id)
    {
        $category = ProductCategory::findOrFail($id);
        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully.',
        ]);
    }
}
