<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\SaleItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class InventoryController extends Controller
{
    /**
     * GET /api/owner/inventory/summary
     *
     * Returns products with:
     * - category, brand
     * - stock_quantity (sum of active batches.quantity_remaining)
     * - stock_value_estimated (stock_quantity * default_cost)
     */
    public function summary(Request $request)
    {
        $paginate = (int) $request->get('paginate', 15);

        $products = Product::with(['category', 'brand'])
            ->withSum(['batches as stock_quantity' => function ($q) {
                $q->where('is_active', true);
            }], 'quantity_remaining')
            ->paginate($paginate);

        // Map extra calculated field
        $products->getCollection()->transform(function ($product) {
            $stockQty = (float) ($product->stock_quantity ?? 0);
            $defaultCost = (float) ($product->default_cost ?? 0);
            $product->stock_value_estimated = $stockQty * $defaultCost;
            return $product;
        });

        return response()->json($products);
    }

    /**
     * GET /api/owner/inventory/low-stock
     *
     * Products where stock_quantity < safety_stock_kg
     */
    public function lowStock(Request $request)
    {
        $days = (int) $request->get('days', 0); // not used yet, but kept for future

        $products = Product::with(['category', 'brand'])
            ->withSum(['batches as stock_quantity' => function ($q) {
                $q->where('is_active', true);
            }], 'quantity_remaining')
            ->get()
            ->filter(function ($product) {
                $safety = (float) ($product->safety_stock_kg ?? 0);
                $stock  = (float) ($product->stock_quantity ?? 0);

                return $safety > 0 && $stock < $safety;
            })
            ->values();

        return response()->json([
            'data' => $products,
        ]);
    }

    /**
     * GET /api/owner/inventory/expiring-soon?days=30
     *
     * Returns batches that are nearing expiry.
     */
    public function expiringSoon(Request $request)
    {
        $days = (int) $request->get('days', 30);

        $from = Carbon::today();
        $to   = Carbon::today()->addDays($days);

        $batches = ProductBatch::with('product.category', 'product.brand')
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [$from->toDateString(), $to->toDateString()])
            ->where('quantity_remaining', '>', 0)
            ->orderBy('expiry_date')
            ->get();

        return response()->json([
            'data' => $batches,
        ]);
    }

    /**
     * GET /api/owner/inventory/top-products?days=30&limit=5
     *
     * Top products by quantity sold and total sales amount.
     */
    public function topProducts(Request $request)
    {
        $days  = (int) $request->get('days', 30);
        $limit = (int) $request->get('limit', 5);

        $from = Carbon::today()->subDays($days);

        $result = SaleItem::select(
                'product_id',
                DB::raw('SUM(quantity) as total_quantity_sold'),
                DB::raw('SUM(line_total) as total_sales_amount')
            )
            ->where('created_at', '>=', $from)
            ->groupBy('product_id')
            ->orderByDesc('total_quantity_sold')
            ->limit($limit)
            ->get()
            ->load('product.category', 'product.brand');

        return response()->json([
            'data' => $result,
        ]);
    }
}
