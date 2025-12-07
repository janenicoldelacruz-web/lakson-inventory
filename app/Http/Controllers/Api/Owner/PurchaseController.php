<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    /**
     * GET /api/owner/purchases
     */
    public function index(Request $request)
    {
        $query = Purchase::with('items.product.brand', 'items.product.category')
            ->orderByDesc('purchase_date');

        if ($request->filled('from')) {
            $query->whereDate('purchase_date', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('purchase_date', '<=', $request->input('to'));
        }

        if ($supplier = $request->input('supplier_name')) {
            $query->where('supplier_name', 'like', "%{$supplier}%");
        }

        $perPage = (int) $request->get('per_page', 15);
        $purchases = $query->paginate($perPage);

        return response()->json($purchases);
    }

    /**
     * GET /api/owner/purchases/{id}
     */
    public function show($id)
    {
        $purchase = Purchase::with(['items.product', 'items.batch'])
            ->findOrFail($id);

        return response()->json([
            'purchase' => $purchase,
        ]);
    }
public function store(Request $request)
{
    $data = $request->validate([
        'purchase_date'       => ['required', 'date'],
        'supplier_name'       => ['required', 'string', 'max:255'],
        'items'               => ['required', 'array', 'min:1'],
        'items.*.product_id'  => ['required', 'integer', 'exists:products,id'],
        'items.*.quantity'    => ['required', 'numeric', 'min:0.01'],
        'items.*.unit_cost'   => ['required', 'numeric', 'min:0'],
        'items.*.base_unit'   => ['nullable', 'string'], // optional from frontend
        'items.*.expiry_date' => ['nullable', 'date'],
    ]);

    $purchase = DB::transaction(function () use ($data) {

        $purchase = Purchase::create([
            'purchase_date' => $data['purchase_date'],
            'supplier_name' => $data['supplier_name'],
            'total_cost'    => 0,
        ]);

        $totalCost = 0;

        foreach ($data['items'] as $itemData) {
            $product = Product::with('category')->findOrFail($itemData['product_id']);

            $displayQty = (float) $itemData['quantity']; 
            $unitCost   = (float) $itemData['unit_cost'];
            $lineTotal  = $displayQty * $unitCost;

            // Determine if product is feed
            $categoryName = strtolower($product->category->name ?? '');
            $isFeed = $categoryName === 'feeds' || $categoryName === 'feed';

            // Backend-enforced base unit and stored quantity
            if ($isFeed) {
                $storedQty = $displayQty * 50; // 1 sack = 50kg
                $baseUnit = 'kg';              // always kg in DB
            } else {
                $storedQty = $displayQty;      // pcs
                $baseUnit = 'pcs';
            }

            // Create batch
            $batch = ProductBatch::create([
                'product_id'  => $product->id,
                'batch_code'  => 'B-' . date('ymd') . '-' . mt_rand(100, 999),
                'expiry_date' => $itemData['expiry_date'] ?? null,
                'quantity'    => $storedQty,
            ]);

            // Create purchase item
            PurchaseItem::create([
                'purchase_id' => $purchase->id,
                'product_id'  => $product->id,
                'batch_id'    => $batch->id,
                'quantity'    => $displayQty, // what owner entered
                'unit_cost'   => $unitCost,
                'line_total'  => $lineTotal,
                'base_unit'   => $baseUnit,    // kg or pcs
            ]);

            // Update product stock
            $product->increment('current_stock', $storedQty);

            // Record stock movement
            StockMovement::create([
                'product_id'      => $product->id,
                'batch_id'        => $batch->id,
                'movement_type'   => 'purchase',
                'reference_type'  => 'purchase',
                'reference_id'    => $purchase->id,
                'quantity_change' => $storedQty,
                'remarks'         => 'Purchase stock-in',
            ]);

            $totalCost += $lineTotal;
        }

        $purchase->update([
            'total_cost' => $totalCost,
        ]);

        return $purchase;
    });

    return response()->json([
        'message'  => 'Purchase created successfully.',
        'purchase' => $purchase->load('items.product', 'items.batch'),
    ], 201);
}


/**
 * Convert quantity to stored format
 * Feed: sacks → kg, Non-feed: pcs → pcs
 */
protected function convertToStoredQuantity(Product $product, float $quantity, string $baseUnit): float
{
    $categoryName = strtolower($product->category->name ?? '');
    $isFeed = $categoryName === 'feeds' || $categoryName === 'feed';

    if ($isFeed && $baseUnit === 'sack') {
        return $quantity * 50; // 1 sack = 50kg
    }

    return $quantity; // pcs or kg directly entered
}

}