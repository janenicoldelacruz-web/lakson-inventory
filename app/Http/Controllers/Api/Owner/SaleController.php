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
    // ===================== INDEX =====================

    public function index(Request $request)
    {
        $query = Sale::with('items.product')
            ->orderByRaw('sale_date IS NULL, sale_date ASC');

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
        return response()->json($query->paginate($perPage));
    }

    // ===================== STORE =====================

    public function store(Request $request)
    {
        $data = $request->validate([
            'sale_date'           => ['required', 'date'],
            'sale_type'           => ['required', 'in:walk_in,online'],
            'customer_name'       => ['nullable', 'string', 'max:255'],
            'customer_contact'    => ['nullable', 'string', 'max:255'],
            'items'               => ['required', 'array', 'min:1'],
            'items.*.product_id'  => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity'    => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price'  => ['required', 'numeric', 'min:0'],
            'items.*.base_unit'   => ['required', 'string', 'in:sack,kg,pcs'],
        ]);

        try {
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
                    $product    = Product::findOrFail($itemData['product_id']);
                    $displayQty = (float) $itemData['quantity'];     // qty as shown in UI
                    $unitPrice  = (float) $itemData['unit_price'];   // selling price
                    $baseUnit   = $itemData['base_unit'];            // sack | kg | pcs

                    // if brand has default selling price, you may override here
                    $unitPrice = $this->getUnitPriceBasedOnBrand($product, $unitPrice);

                    // ------- STOCK VALIDATION (in display units) -------
                    // Stock validation (respect base unit: sack vs kg)
                    $availableStock = $this->getAvailableStock($product, $baseUnit);

                    if ($availableStock < $displayQty && $baseUnit !== 'pcs') {
                        throw new \RuntimeException(
                            "Insufficient stock for product '{$product->name}'. Available: {$availableStock} {$baseUnit}"
                        );
                    }


                    // ------- FEFO ALLOCATION (stored units = kg for feeds) -------
                    $allocations = $this->deductFromBatchesFefo($product, $displayQty, $baseUnit);

                    // ------- COST COMPUTATION -------
                    // Average cost per sack (for feeds) or per base unit
                    $storedUnitCost  = $this->getAverageUnitCost($product);
                    // Convert to cost per displayed unit (sack or kg)
                    $displayUnitCost = $this->getUnitCostForDisplay($product, $storedUnitCost, $baseUnit);

                    foreach ($allocations as $alloc) {
                        $batch      = $alloc['batch'];
                        $allocDisp  = $alloc['display_qty']; // qty in UI unit (sack/kg/pcs)
                        $allocStore = $alloc['stored_qty'];  // qty in stored unit (kg for feeds)

                        $lineTotal = $allocDisp * $unitPrice;       // revenue
                        $lineCost  = $allocDisp * $displayUnitCost; // cost

                        $saleItem = SaleItem::create([
                            'sale_id'           => $sale->id,
                            'product_id'        => $product->id,
                            'batch_id'          => $batch ? $batch->id : null,
                            'quantity'          => $allocStore,          // stored unit (kg for feeds)
                            'unit_price'        => $unitPrice,           // selling price per display unit
                            'line_total'        => $lineTotal,
                            'unit_cost_at_sale' => $displayUnitCost,     // cost per display unit
                            'line_cost'         => $lineCost,
                        ]);

                        if ($batch) {
                            $batch->decrement('quantity', $allocStore);
                        }

                        $product->decrement('current_stock', $allocStore);

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

                $sale->update(['total_amount' => $totalAmount]);

                return $sale;
            });

            return response()->json([
                'message' => 'Sale created successfully.',
                'sale'    => $sale->load('items.product', 'items.batch'),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Sale creation failed.',
                'error'   => $e->getMessage(),
            ], 400);
        }
    }

    // ===================== HELPERS =====================

    /**
     * Available stock in display units (sacks or pieces) for validation.
     * Feeds: stored in kg, 1 sack = 50 kg, so convert to sacks.
     */
protected function getAvailableStock(Product $product, string $baseUnit)
{
    $categoryName = strtolower($product->category?->name ?? '');
    $current = (float) ($product->current_stock ?? 0);

    // Feeds are stored in kg, but user can sell as 'sack' or 'kg'
    if ($categoryName === 'feeds' || $categoryName === 'feed') {
        // selling by sack → convert kg to sacks (50kg per sack)
        if ($baseUnit === 'sack') {
            return floor($current / 50); // number of whole sacks
        }

        // selling by kg → just use kg
        if ($baseUnit === 'kg') {
            return $current; // kg
        }
    }

    // Non-feeds: current_stock already in the unit you use (pcs, kg, etc.)
    return $current;
}


    protected function getUnitPriceBasedOnBrand(Product $product, $unitPrice)
    {
        // adjust as needed; for now just fallback to product selling_price
        return $unitPrice ?: (float) $product->selling_price;
    }

    /**
     * FEFO allocation.
     * - displayQuantity: qty in UI unit (sack / kg / pcs)
     * - stored unit: kg for feeds, same as display for pcs.
     */
    protected function deductFromBatchesFefo(Product $product, float $displayQuantity, string $baseUnit): array
    {
        $remainingDisplay = $displayQuantity;
        $allocations = [];

        $batches = ProductBatch::where('product_id', $product->id)
            ->where('quantity', '>', 0)
            ->orderBy('expiry_date')
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            if ($remainingDisplay <= 0) break;

            $batchDisplayQty = $this->convertToDisplayQuantity($product, (float) $batch->quantity, $baseUnit);
            if ($batchDisplayQty <= 0) continue;

            $usedDisplay = min($batchDisplayQty, $remainingDisplay);
            $usedStored  = $this->convertToStoredQuantity($product, $usedDisplay, $baseUnit);

            $allocations[] = [
                'batch'       => $batch,
                'display_qty' => $usedDisplay,
                'stored_qty'  => $usedStored,
            ];

            $remainingDisplay -= $usedDisplay;
        }

        if ($remainingDisplay > 0) {
            throw new \RuntimeException('Not enough stock for product ID ' . $product->id);
        }

        return $allocations;
    }

    /**
     * Convert from display unit (sack / kg / pcs) to stored unit.
     * For feeds: stored in kg, 1 sack = 50 kg.
     */
    protected function convertToStoredQuantity(Product $product, float $displayQuantity, string $baseUnit): float
    {
        $category = strtolower($product->category->name ?? '');

        if (in_array($category, ['feed', 'feeds']) && $baseUnit === 'sack') {
            return $displayQuantity * 50;  // sacks → kg
        }

        // kg and pcs are stored as-is
        return $displayQuantity;
    }

    /**
     * Convert from stored unit (kg) to display unit.
     */
    protected function convertToDisplayQuantity(Product $product, float $storedQuantity, string $baseUnit): float
    {
        $category = strtolower($product->category->name ?? '');

        if (in_array($category, ['feed', 'feeds']) && $baseUnit === 'sack') {
            return $storedQuantity / 50;   // kg → sacks
        }

        return $storedQuantity; // kg or pcs
    }

    /**
     * Average unit cost from purchases.
     * For feeds, purchase_items.unit_cost is entered PER SACK.
     */
protected function getAverageUnitCost(Product $product): float
{
    $avg = $product->purchaseItems()->avg('unit_cost');
    if ($avg === null) {
        return 0.0;
    }

    $categoryName = strtolower($product->category?->name ?? '');

    // For feeds, unit_cost in DB is PER SACK (50kg).
    // Convert to PER KG as our internal base.
    if ($categoryName === 'feeds' || $categoryName === 'feed') {
        return (float) $avg / 50.0; // cost per kg
    }

    // For non-feeds, we assume unit_cost is already in the stored/base unit (pcs, kg, etc.)
    return (float) $avg;
}


    /**
     * Convert storedUnitCost (per sack for feeds) to display unit cost.
     *
     * - If selling FEEDS by SACK  → cost stays per sack.
     * - If selling FEEDS by KG    → cost_per_kg = cost_per_sack / 50.
     * - For other categories or pcs, return cost as-is.
     */
protected function getUnitCostForDisplay(Product $product, float $costPerKg, string $baseUnit): float
{
    $categoryName = strtolower($product->category?->name ?? '');

    // Feeds: if selling by sack, show cost per sack (kg cost × 50).
    if (($categoryName === 'feeds' || $categoryName === 'feed') && $baseUnit === 'sack') {
        return $costPerKg * 50.0; // cost per sack
    }

    // Selling by kg (feeds) OR any other product: use costPerKg / base-unit cost directly
    return $costPerKg;
}

}
