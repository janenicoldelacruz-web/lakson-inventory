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
     * Optional filters:
     *  - from (date)
     *  - to   (date)
     *  - supplier_name (like)
     */
    public function index(Request $request)
    {
        $query = Purchase::with('items.product')
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

        $perPage   = (int) $request->get('per_page', 15);
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

    /**
     * POST /api/owner/purchases
     *
     * Expects:
     * {
     *   "purchase_date": "2025-11-24",
     *   "supplier_name": "ABC Feeds",
     *   "items": [
     *     {
     *       "product_id": 1,
     *       "quantity": 10,
     *       "unit_cost": 1500,
     *       "expiry_date": "2026-01-01"
     *     },
     *     ...
     *   ]
     * }
     *
     * Stock-in comes ONLY from here.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'purchase_date'       => ['required', 'date'],
            'supplier_name'       => ['required', 'string', 'max:255'],
            'items'               => ['required', 'array', 'min:1'],
            'items.*.product_id'  => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity'    => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_cost'   => ['required', 'numeric', 'min:0'],
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
                $product = Product::findOrFail($itemData['product_id']);

                $displayQty = (float) $itemData['quantity'];
                $storedQty  = $this->convertToStoredQuantity($product, $displayQty);

                $unitCost  = (float) $itemData['unit_cost'];
                $lineTotal = $unitCost * $displayQty;

                // Create batch per line (supports multiple batches for same product)
                $batch = ProductBatch::create([
                    'product_id'  => $product->id,
                    'batch_code'  => 'PB-' . $product->id . '-' . time() . '-' . mt_rand(1000, 9999),
                    'expiry_date' => $itemData['expiry_date'] ?? null,
                    'quantity'    => $storedQty,
                ]);

                // Purchase item referencing that batch
                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id'  => $product->id,
                    'batch_id'    => $batch->id,
                    'quantity'    => $displayQty,
                    'unit_cost'   => $unitCost,
                    'line_total'  => $lineTotal,
                ]);

                // Increase product stock
                $product->increment('current_stock', $storedQty);

                // Stock movement
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

    protected function convertToStoredQuantity(Product $product, float $displayQuantity): float
    {
        if ((int) $product->base_unit === 1) {
            // Sack → 25kg rule
            return $displayQuantity * 25;
        }

        return $displayQuantity;
    }
}
