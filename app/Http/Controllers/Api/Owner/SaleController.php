<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    /**
     * GET /api/owner/sales
     * Optional filters:
     *  - from (date)
     *  - to   (date)
     *  - sale_type (walk_in, online)
     */
    public function index(Request $request)
    {
        $query = Sale::with('items.product')
            ->orderByDesc('sale_date');

        if ($request->filled('from')) {
            $query->whereDate('sale_date', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('sale_date', '<=', $request->input('to'));
        }

        if ($saleType = $request->input('sale_type')) {
            $query->where('sale_type', $saleType);
        }

        $perPage = (int) $request->get('per_page', 15);
        $sales   = $query->paginate($perPage);

        return response()->json($sales);
    }

    /**
     * GET /api/owner/sales/{id}
     */
    public function show($id)
    {
        $sale = Sale::with(['items.product', 'items.batch'])
            ->findOrFail($id);

        return response()->json([
            'sale' => $sale,
        ]);
    }

    /**
     * POST /api/owner/sales
     *
     * Expects:
     * {
     *   "sale_date": "2025-11-24",
     *   "sale_type": "walk_in",
     *   "customer_name": "Juan Dela Cruz",
     *   "customer_contact": "09123456789",
     *   "items": [
     *     {
     *       "product_id": 1,
     *       "quantity": 2,
     *       "unit_price": 1700
     *     }
     *   ]
     * }
     *
     * Stock-out comes ONLY from here (FEFO).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'sale_date'        => ['required', 'date'],
            'sale_type'        => ['required', 'in:walk_in,online'],
            'customer_name'    => ['nullable', 'string', 'max:255'],
            'customer_contact' => ['nullable', 'string', 'max:255'],
            'items'            => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity'   => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $sale = DB::transaction(function () use ($data) {
            $sale = Sale::create([
                'sale_date'        => $data['sale_date'],
                'sale_type'        => $data['sale_type'],
                'customer_name'    => $data['customer_name'] ?? null,
                'customer_contact' => $data['customer_contact'] ?? null,
                'status'           => 'completed',
                'total_amount'     => 0,
            ]);

            $totalAmount = 0;

            foreach ($data['items'] as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);
                $displayQty = (float) $itemData['quantity'];
                $unitPrice  = (float) $itemData['unit_price'];

                // FEFO allocation across batches
                $allocations = $this->deductFromBatchesFefo($product, $displayQty);

                // Approximate unit cost at sale (e.g., from average purchase cost)
                $unitCostAtSale = $this->getAverageUnitCost($product);

                foreach ($allocations as $alloc) {
                    $batch      = $alloc['batch'];
                    $allocDisp  = $alloc['display_qty'];
                    $allocStore = $alloc['stored_qty'];

                    $lineTotal = $allocDisp * $unitPrice;
                    $lineCost  = $allocDisp * $unitCostAtSale;

                    // Create sale item
                    $saleItem = SaleItem::create([
                        'sale_id'          => $sale->id,
                        'product_id'       => $product->id,
                        'batch_id'         => $batch ? $batch->id : null,
                        'quantity'         => $allocDisp,
                        'unit_price'       => $unitPrice,
                        'line_total'       => $lineTotal,
                        'unit_cost_at_sale'=> $unitCostAtSale,
                        'line_cost'        => $lineCost,
                    ]);

                    // Update batch qty
                    if ($batch) {
                        $batch->decrement('quantity', $allocStore);
                    }

                    // Update product stock
                    $product->decrement('current_stock', $allocStore);

                    // Stock movement
                    StockMovement::create([
                        'product_id'      => $product->id,
                        'batch_id'        => $batch ? $batch->id : null,
                        'movement_type'   => 'sale',
                        'reference_type'  => 'sale_item',
                        'reference_id'    => $saleItem->id,
                        'quantity_change' => -$allocStore,
                        'remarks'         => 'Sale stock-out',
                    ]);

                    $totalAmount += $lineTotal;
                }
            }

            $sale->update([
                'total_amount' => $totalAmount,
            ]);

            return $sale;
        });

        return response()->json([
            'message' => 'Sale created successfully.',
            'sale'    => $sale->load('items.product', 'items.batch'),
        ], 201);
    }

    /**
     * FEFO: allocate stock from earliest-expiry batches.
     * Returns an array of:
     *  [
     *    [
     *      'batch'       => ProductBatch|null,
     *      'display_qty' => float,
     *      'stored_qty'  => float,
     *    ],
     *    ...
     *  ]
     */
    protected function deductFromBatchesFefo(Product $product, float $displayQuantity): array
    {
        $remainingDisplay = $displayQuantity;
        $allocations      = [];

        // Load batches ordered by expiry (oldest first)
        $batches = ProductBatch::where('product_id', $product->id)
            ->where('quantity', '>', 0)
            ->orderBy('expiry_date')
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            if ($remainingDisplay <= 0) {
                break;
            }

            // Convert batch quantity (stored) back to display units for comparison
            $batchDisplayQty = $this->convertToDisplayQuantity($product, (float) $batch->quantity);

            if ($batchDisplayQty <= 0) {
                continue;
            }

            $usedDisplay = min($batchDisplayQty, $remainingDisplay);
            $usedStored  = $this->convertToStoredQuantity($product, $usedDisplay);

            $allocations[] = [
                'batch'       => $batch,
                'display_qty' => $usedDisplay,
                'stored_qty'  => $usedStored,
            ];

            $remainingDisplay -= $usedDisplay;
        }

        if ($remainingDisplay > 0) {
            // Not enough stock; you can throw exception or handle differently
            throw new \RuntimeException('Not enough stock to fulfill sale for product ID ' . $product->id);
        }

        return $allocations;
    }

    protected function convertToStoredQuantity(Product $product, float $displayQuantity): float
    {
        if ((int) $product->base_unit === 1) {
            return $displayQuantity * 25;
        }

        return $displayQuantity;
    }

    protected function convertToDisplayQuantity(Product $product, float $storedQuantity): float
    {
        if ((int) $product->base_unit === 1) {
            return $storedQuantity / 25;
        }

        return $storedQuantity;
    }

    /**
     * Approximate unit cost at sale time
     * using average purchase unit_cost for this product.
     */
    protected function getAverageUnitCost(Product $product): float
    {
        $avg = $product->purchaseItems()->avg('unit_cost');

        return $avg !== null ? (float) $avg : 0.0;
    }
}
