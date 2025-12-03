<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    /**
     * GET /api/owner/products
     *
     * Optional filters:
     *  - search       (by name or sku)
     *  - category_id  (int)
     *  - status       (active|inactive|all) - default: active
     *  - sku          (string) - optional filter for fetching product by SKU
     *  - page         (for pagination; if absent, returns full collection)
     */
    public function index(Request $request)
    {
        $query = Product::query()->with(['category', 'brand']); // Eager load category and brand relationships

        // SKU filter (if provided in query params)
        if ($sku = $request->get('sku')) {
            $query->where('sku', $sku);
        }

        // Status filter (default: active)
        $status = $request->get('status', 'active');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        // Search filter
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        // Category filter
        if ($categoryId = $request->get('category_id')) {
            $query->where('product_category_id', $categoryId); // Filter by category ID
        }

        // Basic ordering by ID
        $query->orderBy('id', 'asc');

        // If page param is present, return paginated list; otherwise full list
        if ($request->has('page')) {
            // Paginate the results with 15 items per page
            $products = $query->paginate(15);
        } else {
            // Return all products matching the filters
            $products = $query->get();
        }

        // Return the results in a JSON format
        return response()->json(['data' => $products]);
    }

    /**
     * POST /api/owner/products
     *
     * Create new product.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'product_category_id' => ['required', 'integer', 'exists:product_categories,id'],
            'brand_id' => ['required', 'integer', 'exists:brands,id'],  // Make brand_id required
            'sku' => [
                'required',  // Make SKU required
                'string',
                'max:100',
                Rule::unique('products')->whereNull('deleted_at'),
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('products')
                    ->whereNull('deleted_at')
                    ->where(function ($query) use ($request) {
                        // Enforce unique combination of product name, brand, and SKU
                        $query->where('name', $request->name)
                            ->where('brand_id', $request->brand_id)
                            ->where('sku', $request->sku);
                    }),
            ],
            'cost_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'current_stock' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'], // Keep reorder_level as nullable
        ]);

        // Remove logic related to "starter" and "feed"
        // Directly set base_unit based on category check without considering "starter" logic
        $category = ProductCategory::findOrFail($data['product_category_id']);
        $baseUnit = strtolower($category->name) === 'feed' ? 1 : 2; // Feed = 1 (sack), other = 2 (pieces)

        // Set default reorder level: 10 sacks for feed, 20 pieces for non-feeds
        $reorderLevel = $data['reorder_level'] ?? ($baseUnit === 1 ? 10 : 20);

        // Set current stock if not provided
        $currentStock = $data['current_stock'] ?? 0;

        $product = Product::create([
            'product_category_id' => $data['product_category_id'],
            'brand_id' => $data['brand_id'],
            'sku' => $data['sku'],
            'name' => $data['name'],
            'base_unit' => $baseUnit,
            'cost_price' => $data['cost_price'],
            'selling_price' => $data['selling_price'],
            'current_stock' => $currentStock,  // Will be 0 if not provided
            'reorder_level' => $reorderLevel,  // Set default reorder level
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Product created successfully.',
            'product' => $product->load(['category', 'brand']),
        ], 201);
    }

    public function show($id)
    {
        $product = Product::find($id); // or findOrFail($id)
        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }
        return response()->json($product);
    }

    /**
     * PUT/PATCH /api/owner/products/{id}
     *
     * Update product.
     */
    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        // Validate the request
        $data = $request->validate([
            'product_category_id' => ['sometimes', 'integer', 'exists:product_categories,id'],
            'brand_id' => ['sometimes', 'nullable', 'integer', 'exists:brands,id'],
            'sku' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                Rule::unique('products')
                    ->ignore($id)
                    ->whereNull('deleted_at')
                    ->where(function ($query) use ($request) {
                        // Enforce uniqueness across the combination of name, brand, and sku
                        $query->where('name', $request->name)
                            ->where('brand_id', $request->brand_id)
                            ->where('sku', $request->sku);
                    }),
            ],
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('products')
                    ->ignore($id)
                    ->whereNull('deleted_at')
                    ->where(function ($query) use ($request) {
                        // Enforce uniqueness across the combination of name, brand, and sku
                        $query->where('name', $request->name)
                            ->where('brand_id', $request->brand_id)
                            ->where('sku', $request->sku);
                    }),
            ],
            'cost_price' => ['sometimes', 'numeric', 'min:0'],
            'selling_price' => ['sometimes', 'numeric', 'min:0'],
            'current_stock' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        // If category changes, recompute base_unit
        if (isset($data['product_category_id'])) {
            $category = ProductCategory::findOrFail($data['product_category_id']);
            $baseUnit = strtolower($category->name) === 'feed' ? 1 : 2;
            $data['base_unit'] = $baseUnit;

            // Set default reorder level: 10 sacks for feed, 20 pieces for non-feeds
            $data['reorder_level'] = $data['reorder_level'] ?? ($baseUnit === 1 ? 10 : 20);
        }

        // Update the product with the validated data
        $product->update($data);

        // Return response
        return response()->json([
            'message' => 'Product updated successfully.',
            'product' => $product->fresh()->load(['category', 'brand']),
        ]);
    }

    /**
     * DELETE /api/owner/products/{id}
     *
     * Soft or hard delete depending on your Product model.
     */
    public function destroy($id)
    {
        $product = Product::find($id);

        // If product is already deleted or doesn't exist, still return 200 OK
        if (!$product) {
            return response()->json([
                'message' => 'Product already deleted or not found.',
            ], 200);
        }

        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully.',
        ], 200);
    }

    /**
     * GET /api/owner/products/{id}/batches
     *
     * Returns all batches for a product (for FEFO, expiry view, etc.).
     */
    public function batches($id)
    {
        $product = Product::findOrFail($id);

        $batches = ProductBatch::where('product_id', $product->id)
            ->orderBy('expiry_date')
            ->get();

        return response()->json([
            'product_id' => $product->id,
            'batches' => $batches,
        ]);
    }
}
