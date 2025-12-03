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
    // Fetch sales list with pagination and filters
    public function index(Request $request)
    {
        $query = Sale::with('items.product') // Eager load sale items and their products
            ->orderByRaw('sale_date IS NULL, sale_date ASC'); // Default order by sale date ascending

        // Apply filters if provided
        if ($request->filled('from')) {
            $query->whereDate('sale_date', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('sale_date', '<=', $request->input('to'));
        }

        if ($saleType = $request->input('sale_type')) {
            $query->where('sale_type', $saleType);
        }

        // Pagination: default to 15 items per page
        $perPage = (int) $request->get('per_page', 15);
        $sales = $query->paginate($perPage);

        return response()->json($sales);
    }

    // Store a new sale and update stock
    public function store(Request $request)
    {
        // Validate request data
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

        try {
            $sale = DB::transaction(function () use ($data) {
                // Create the sale
                $sale = Sale::create([
                    'sale_date'        => $data['sale_date'],
                    'sale_type'        => $data['sale_type'],
                    'customer_name'    => $data['customer_name'] ?? null,
                    'customer_contact' => $data['customer_contact'] ?? null,
                    'status'           => 'completed',
                    'total_amount'     => 0,
                ]);

                $totalAmount = 0;

                // Process each item in the sale
                foreach ($data['items'] as $itemData) {
                    $product = Product::findOrFail($itemData['product_id']);
                    $displayQty = (float) $itemData['quantity'];
                    $unitPrice  = (float) $itemData['unit_price'];

                    // Ensure unit_price matches the product's brand price
                    $unitPrice = $this->getUnitPriceBasedOnBrand($product, $unitPrice);

                    // Check stock before proceeding
                    $availableStock = $this->getAvailableStock($product);
                    if ($availableStock < $displayQty) {
                        throw new \RuntimeException("Insufficient stock for product '{$product->name}'. Available stock: {$availableStock}");
                    }

                    // FEFO allocation across batches
                    $allocations = $this->deductFromBatchesFefo($product, $displayQty);

                    // Approximate unit cost at sale (e.g., from average purchase cost)
                    $unitCostAtSale = $this->getAverageUnitCost($product);

                    // Process each allocation from batches
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
                            'unit_cost_at_sale' => $unitCostAtSale,
                            'line_cost'        => $lineCost,
                        ]);

                        // Update batch quantity
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

                        // Add to total amount
                        $totalAmount += $lineTotal;
                    }
                }

                // Update total amount of the sale
                $sale->update([
                    'total_amount' => $totalAmount,
                ]);

                return $sale;
            });

            return response()->json([
                'message' => 'Sale created successfully.',
                'sale'    => $sale->load('items.product', 'items.batch'),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Sale creation failed.',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    // Get available stock based on product category and stock type
    // Get available stock based on product category and stock type
protected function getAvailableStock(Product $product)
{
    $availableStock = 0;

    // For feed-related categories (Pre Starter Feed, Other Feed), assume 1 sack = 50kg
    if ($product->category->name == 'Pre Starter Feed' || $product->category->name == 'Other Feed') {
        $availableStock = floor($product->current_stock / 50); // 1 sack = 50kg
    } else {
        // For other categories (e.g., pieces like vitamins/medicines), count by individual pieces
        $availableStock = $product->current_stock;
    }

    return $availableStock;
}


    // Ensure unit price matches the product's brand price
    protected function getUnitPriceBasedOnBrand(Product $product, $unitPrice)
    {
        return $product->brand ? ($unitPrice ?: $product->selling_price) : $unitPrice;
    }

    // Allocate stock based on earliest-expiry batches (FEFO)
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
            throw new \RuntimeException('Not enough stock to fulfill sale for product ID ' . $product->id);
        }

        return $allocations;
    }

    protected function convertToStoredQuantity(Product $product, float $displayQuantity): float
    {
        return (int) $product->base_unit === 1 ? $displayQuantity * 25 : $displayQuantity;
    }

    protected function convertToDisplayQuantity(Product $product, float $storedQuantity): float
    {
        return (int) $product->base_unit === 1 ? $storedQuantity / 25 : $storedQuantity;
    }

    // Approximate unit cost at sale time using average purchase cost for the product
    protected function getAverageUnitCost(Product $product): float
    {
        $avg = $product->purchaseItems()->avg('unit_cost');
        return $avg !== null ? (float) $avg : 0.0;
    }
}
