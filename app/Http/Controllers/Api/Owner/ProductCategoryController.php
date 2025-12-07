<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use Illuminate\Http\Request;

class ProductCategoryController extends Controller
{
    /**
     * GET /api/owner/product-categories?paginate=15
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
        $categories = cache()->remember('product_categories', 60, function () {
            return ProductCategory::orderBy('name')->get(['id', 'name']);
        });

        return response()->json($categories);
    }

    /**
     * POST /api/owner/product-categories
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:product_categories,name'],
        ]);

        try {
            $category = ProductCategory::create($data);
            return response()->json([
                'message'  => 'Category created successfully.',
                'category' => $category,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to create category.'], 500);
        }
    }

    /**
     * PUT /api/owner/product-categories/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $category = ProductCategory::findOrFail($id);

            $data = $request->validate([
                'name' => ['required', 'string', 'max:255', 'unique:product_categories,name,' . $category->id],
            ]);

            $category->update($data);

            return response()->json([
                'message'  => 'Category updated successfully.',
                'category' => $category,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update category.'], 500);
        }
    }

    /**
     * DELETE /api/owner/product-categories/{id}
     */
    public function destroy($id)
    {
        try {
            $category = ProductCategory::findOrFail($id);
            $category->delete();

            return response()->json([
                'message' => 'Category deleted successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete category.'], 500);
        }
    }
}
