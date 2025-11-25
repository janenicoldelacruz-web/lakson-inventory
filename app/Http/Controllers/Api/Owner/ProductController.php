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
    public function index(Request $request)
    {
        // Include relations so Vue can show category + brand names
        $query = Product::where('status', 'active')
            ->with(['category', 'brand']);

        if ($search = $request->search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                    ->orWhere('sku', 'like', "%$search%");
            });
        }

        if ($request->category_id) {
            $query->where('product_category_id', $request->category_id);
        }

        return $request->page
            ? $query->paginate(15)
            : $query->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_category_id' => ['required', 'integer', 'exists:product_categories,id'],
            'brand_id'            => ['nullable', 'integer', 'exists:brands,id'],
            'sku'                 => ['nullable', 'string', 'max:100', Rule::unique('products')->whereNull('deleted_at')],
            'name'                => ['required', 'string', 'max:255', Rule::unique('products')->whereNull('deleted_at')],
            'cost_price'          => ['required', 'numeric', 'min:0'],
            'selling_price'       => ['required', 'numeric', 'min:0'],
        ]);

        // Decide base_unit based on category name (feeds vs others)
        $category   = ProductCategory::findOrFail($data['product_category_id']);
        $feedsNames = ['feeds', 'feed', 'hog feed', 'hog feeds'];

        // 1 = Sack, 2 = Piece
        $baseUnit = in_array(strtolower($category->name), $feedsNames) ? 1 : 2;

        $product = Product::create([
            'product_category_id' => $data['product_category_id'],
            'brand_id'            => $data['brand_id'] ?? null,
            'sku'                 => $data['sku'],
            'name'                => $data['name'],
            'base_unit'           => $baseUnit,
            'cost_price'          => $data['cost_price'],
            'selling_price'       => $data['selling_price'],
            'current_stock'       => 0,
            'reorder_level'       => 30,
            'status'              => 'active',
        ]);

        return [
            'product' => $product->load(['category', 'brand']),
        ];
    }

    public function show($id)
    {
        $product = Product::with(['category', 'brand'])->findOrFail($id);

        return ['product' => $product];
    }

    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $data = $request->validate([
            'product_category_id' => ['sometimes', 'integer', 'exists:product_categories,id'],
            'brand_id'            => ['sometimes', 'nullable', 'integer', 'exists:brands,id'],
            'sku'                 => ['sometimes', 'nullable', 'max:100', Rule::unique('products')->ignore($id)->whereNull('deleted_at')],
            'name'                => ['sometimes', 'string', 'max:255', Rule::unique('products')->ignore($id)->whereNull('deleted_at')],
            'cost_price'          => ['sometimes', 'numeric'],
            'selling_price'       => ['sometimes', 'numeric'],
            'reorder_level'       => ['sometimes', 'integer'],
            'status'              => ['sometimes', 'in:active,inactive'],
        ]);

        // If category changes, recompute base_unit
        if (isset($data['product_category_id'])) {
            $category   = ProductCategory::findOrFail($data['product_category_id']);
            $feedsNames = ['feeds', 'feed', 'hog feed', 'hog feeds'];

            $data['base_unit'] = in_array(strtolower($category->name), $feedsNames) ? 1 : 2;
        }

        $product->update($data);

        return [
            'product' => $product->fresh()->load(['category', 'brand']),
        ];
    }

public function destroy($id)
{
    // Use find() instead of findOrFail() to avoid throwing an exception
    $product = Product::find($id);

    // If product is already deleted or doesn't exist, we still return 200 OK.
    if (! $product) {
        return response()->json([
            'message' => 'Product already deleted or not found.',
        ], 200);
    }

    // Soft delete or hard delete depending on your model
    $product->delete();

    return response()->json([
        'message' => 'Product deleted successfully.',
    ], 200);
}


    public function batches($id)
    {
        $product = Product::findOrFail($id);

        $batches = ProductBatch::where('product_id', $product->id)
            ->orderBy('expiry_date')
            ->get();

        return response()->json($batches);
    }
}
