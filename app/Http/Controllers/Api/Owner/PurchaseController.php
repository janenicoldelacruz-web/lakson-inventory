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
     * From your Vue form:
     * {
     *   "purchase_date": "2025-11-24",
     *   "supplier_name": "ABC Feeds",
     *   "items": [
     *     {
     *       "product_id": 1,
     *       "quantity": 10,         // from v-model="item.quantity"  → SACKS
     *       "unit_cost": 1500,
     *       "expiry_date": "2026-01-01"
     *     },
     *     ...
     *   ]
     * }
     *
     * Rules:
     *  - PurchaseItem.quantity      = sacks (what you typed in the form)
     *  - ProductBatch.quantity      = kilos
     *  - Product.current_stock      = kilos
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'purchase_date'       => ['required', 'date'],
            'supplier_name'       => ['required', 'string', 'max:255'],
            'items'               => ['required', 'array', 'min:1'],
            'items.*.product_id'  => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity'    => ['required', 'numeric', 'min:0.01'], // SACKS from Vue
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
                /** @var \App\Models\Product $product */
                $product = Product::findOrFail($itemData['product_id']);

                // 1) Quantity the user entered in the Vue form (in SACKS)
                $displayQtySacks = (float) $itemData['quantity'];

                // 2) Convert sacks → kilos for storage
                $storedQtyKg = $this->convertToStoredQuantity($product, $displayQtySacks);

                $unitCost  = (float) $itemData['unit_cost'];
                // Unit cost is per sack here
                $lineTotal = $unitCost * $displayQtySacks;

                // 3) Create batch with quantity IN KILOS
                $batch = ProductBatch::create([
                    'product_id'  => $product->id,
                    'batch_code' => 'B-' . date('ymd') . '-' . mt_rand(100, 999),
                    'expiry_date' => $itemData['expiry_date'] ?? null,
                    'quantity'    => $storedQtyKg,          // KILOS
                ]);

                // 4) Purchase item: keep quantity IN SACKS (for your reference)
                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id'  => $product->id,
                    'batch_id'    => $batch->id,
                    'quantity'    => $displayQtySacks,      // SACKS
                    'unit_cost'   => $unitCost,
                    'line_total'  => $lineTotal,
                ]);

                // 5) Increase product stock in KILOS
                $product->increment('current_stock', $storedQtyKg);

                // 6) Stock movement in KILOS
                StockMovement::create([
                    'product_id'      => $product->id,
                    'batch_id'        => $batch->id,
                    'movement_type'   => 'purchase',
                    'reference_type'  => 'purchase',
                    'reference_id'    => $purchase->id,
                    'quantity_change' => $storedQtyKg,      // KILOS
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
     * Convert the "display quantity" from the form (in SACKS)
     * into the stored quantity (in KILOS).
     *
     * Business rule you gave:
     *   - Starter feeds  = 25 kg per sack
     *   - Other feeds    = 50 kg per sack
     *
     * No new tables: we infer starter vs others based on product name and base_unit.
     */
    protected function convertToStoredQuantity(Product $product, float $displayQuantity): float
    {
        // If product is tracked in sacks (assuming base_unit = 1 means "sack")
        if ((int) $product->base_unit === 1) {
            $name = strtolower($product->name ?? '');

            // Starter feeds: if name contains "starter" (e.g. "Starter Feeds 25kg")
            if (str_contains($name, 'prestarter')) {
                $kgPerSack = 25;
            } else {
                // All other sack feeds: 50 kg per sack
                $kgPerSack = 50;
            }

            // Return total kilos
            return $displayQuantity * $kgPerSack;
        }

        // For products whose base unit is already kg, pcs, etc.,
        // store exactly what the user enters.
        return $displayQuantity;
    }
}
