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
        $query = Product::query()
            ->with(['category', 'brand']); // brand.name is your "word" brand

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
            $query->where('product_category_id', $categoryId);
        }

        // Basic ordering by ID
        $query->orderBy('id', 'asc');

        // If page param is present, return paginated list; otherwise full list
        if ($request->has('page')) {
            $products = $query->paginate(15);
        } else {
            $products = $query->get();
        }

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
            'brand_id'            => ['required', 'integer', 'exists:brands,id'],
            'sku'                 => [
                'required',
                'string',
                'max:100',
                // Do NOT make SKU globally unique; DB constraint is on (name, brand_id, sku)
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                // Enforce uniqueness of the combination (name, brand_id, sku), ignoring soft-deleted rows.
                Rule::unique('products', 'name')
                    ->whereNull('deleted_at')
                    ->where(function ($query) use ($request) {
                        $query->where('brand_id', $request->brand_id)
                              ->where('sku', $request->sku);
                    }),
            ],
            'cost_price'    => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'current_stock' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Determine base_unit based on category name
       $category = ProductCategory::findOrFail($data['product_category_id']);

$categoryName = strtolower(trim($category->name));
// Anything considered feed → sacks (base_unit = 1)
$isFeed = in_array($categoryName, ['feed', 'feeds', 'feeds 50kg']);

$baseUnit = $isFeed ? 1 : 2; // 1 = Sack, 2 = Piece


        // Default reorder level: 10 sacks for feed, 20 pieces for non-feeds
        $reorderLevel = $data['reorder_level'] ?? ($baseUnit === 1 ? 10 : 20);

        $currentStock = $data['current_stock'] ?? 0;

        $product = Product::create([
            'product_category_id' => $data['product_category_id'],
            'brand_id'            => $data['brand_id'],
            'sku'                 => $data['sku'],
            'name'                => $data['name'],
            'base_unit'           => $baseUnit,
            'cost_price'          => $data['cost_price'],
            'selling_price'       => $data['selling_price'],
            'current_stock'       => $currentStock,
            'reorder_level'       => $reorderLevel,
            'status'              => 'active',
        ]);

        return response()->json([
            'message' => 'Product created successfully.',
            'product' => $product->load(['category', 'brand']),
        ], 201);
    }

    public function show($id)
    {
        $product = Product::with(['category', 'brand'])->find($id);

        if (! $product) {
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

        // Use current values as fallbacks when validating composite uniqueness
        $brandId = $request->input('brand_id', $product->brand_id);
        $sku     = $request->input('sku', $product->sku);

        $data = $request->validate([
            'product_category_id' => ['sometimes', 'integer', 'exists:product_categories,id'],
            'brand_id'            => ['sometimes', 'nullable', 'integer', 'exists:brands,id'],
            'sku'                 => [
                'sometimes',
                'string',
                'max:100',
                // No standalone unique rule here; DB constraint will enforce (name, brand_id, sku)
            ],
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('products', 'name')
                    ->ignore($product->id)
                    ->whereNull('deleted_at')
                    ->where(function ($query) use ($brandId, $sku) {
                        $query->where('brand_id', $brandId)
                              ->where('sku', $sku);
                    }),
            ],
            'cost_price'    => ['sometimes', 'numeric', 'min:0'],
            'selling_price' => ['sometimes', 'numeric', 'min:0'],
            'current_stock' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'status'        => ['sometimes', 'in:active,inactive'],
        ]);

        // If category changes, recompute base_unit + default reorder_level (if not supplied)
if (isset($data['product_category_id'])) {
    $category = ProductCategory::findOrFail($data['product_category_id']);

    $categoryName = strtolower(trim($category->name));
    $isFeed = in_array($categoryName, ['feed', 'feeds', 'feeds 50kg']);

    $baseUnit = $isFeed ? 1 : 2;
    $data['base_unit'] = $baseUnit;

    $data['reorder_level'] = $data['reorder_level'] ?? ($baseUnit === 1 ? 10 : 20);
}


        $product->update($data);

        return response()->json([
            'message' => 'Product updated successfully.',
            'product' => $product->fresh()->load(['category', 'brand']),
        ]);
    }

    /**
     * DELETE /api/owner/products/{id}
     *
     * Soft delete (assuming Product uses SoftDeletes).
     */
    public function destroy($id)
    {
        $product = Product::find($id);

        if (! $product) {
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
            'batches'    => $batches,
        ]);
    }
}
