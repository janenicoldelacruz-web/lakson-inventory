<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Purchase;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function summary()
    {
        $startOfMonth = now()->startOfMonth();
        $endOfMonth   = now()->endOfMonth();

        // ---------------- REVENUE / PURCHASES / PROFIT ----------------
        $revenue = Sale::whereBetween('sale_date', [$startOfMonth, $endOfMonth])
            ->where('status', 'completed')
            ->sum('total_amount');

        $purchases = Purchase::whereBetween('purchase_date', [$startOfMonth, $endOfMonth])
            ->sum('total_cost');

        $cogs = SaleItem::leftJoin('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.status', 'completed')
            ->whereBetween('sales.sale_date', [$startOfMonth, $endOfMonth])
            ->sum('sale_items.line_cost');

        $profit = $revenue - $cogs;

        // ---------------- MONTHLY SALES (CURRENT YEAR) ----------------
        $monthlySales = Sale::selectRaw('MONTH(sale_date) as month, SUM(total_amount) as total')
            ->whereYear('sale_date', now()->year)
            ->where('status', 'completed')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // ---------------- LOW STOCK ----------------
        $lowStock = Product::where('status', 'active')
            ->whereNotNull('reorder_level')
            ->whereColumn('current_stock', '<=', 'reorder_level')
            ->orderBy('current_stock')
            ->get([
                'id',
                'name',
                'current_stock',
                'reorder_level',
                'base_unit',
                'status',
            ]);

        $lowStockCount = $lowStock->count();

        // ---------------- EXPIRING SOON (NEXT 30 DAYS) ----------------
        $expiringSoon = ProductBatch::whereDate('expiry_date', '>=', now())
            ->whereDate('expiry_date', '<=', now()->addDays(30))
            ->orderBy('expiry_date')
            ->get([
                'id',
                'product_id',
                'batch_code',
                'expiry_date',
                'quantity',
            ]);

        $expiringSoonCount = $expiringSoon->count();

        // ---------------- SEASONAL DATA / PEAK SEASON ----------------
        $seasonStart = now()->copy()->subYear();
        $seasonEnd   = now();

        $seasonalData = SaleItem::query()
            ->selectRaw('sale_items.product_id, MONTH(sales.sale_date) as month, SUM(sale_items.quantity) as total_qty')
            ->leftJoin('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.status', 'completed')
            ->whereBetween('sales.sale_date', [$seasonStart, $seasonEnd])
            ->groupBy('sale_items.product_id', 'month')
            ->get();

        $productIds = $seasonalData->pluck('product_id')->unique()->all();

        $products = Product::whereIn('id', $productIds)
            ->get(['id', 'name', 'current_stock', 'reorder_level', 'base_unit', 'status'])
            ->keyBy('id');

        $peakSeasonRecommendations = [];
        $bufferDays   = 30;
        $safetyFactor = 1.2;
        $grouped      = $seasonalData->groupBy('product_id');

        foreach ($grouped as $productId => $rows) {

            $product = $products->get($productId);
            if (!$product || $product->status !== 'active') {
                continue;
            }

            $peakRow = $rows->sortByDesc('total_qty')->first();
            if (!$peakRow || $peakRow->total_qty <= 0) {
                continue;
            }

            $peakMonth     = (int) $peakRow->month;
            $peakMonthName = Carbon::create()->month($peakMonth)->format('F');

            $peakQty       = (float) $peakRow->total_qty;
            $avgMonthlyQty = (float) $rows->avg('total_qty');

            $now         = now();
            $currentYear = $now->year;

            $peakThisYear = Carbon::create($currentYear, $peakMonth, 1)->startOfDay();
            $peakNextYear = Carbon::create($currentYear + 1, $peakMonth, 1)->startOfDay();
            $upcomingPeak = $peakThisYear->lessThan($now) ? $peakNextYear : $peakThisYear;

            $windowStart = $upcomingPeak->copy()->subDays($bufferDays);
            $isNearPeak  = $now->between($windowStart, $upcomingPeak);

            $targetStock   = $peakQty * $safetyFactor;
            $currentStock  = (float) $product->current_stock;

            $shouldRestock         = false;
            $recommendedAdditional = 0.0;

            if ($isNearPeak && $currentStock < $targetStock) {
                $shouldRestock = true;
                $recommendedAdditional = max(0, $targetStock - $currentStock);
            }

            $peakSeasonRecommendations[] = [
                'product_id'                 => $product->id,
                'product_name'               => $product->name,
                'current_stock'              => $currentStock,
                'reorder_level'              => (float) $product->reorder_level,
                'base_unit'                  => $product->base_unit, // keep as string (kg, sack, etc.)
                'peak_month'                 => $peakMonth,
                'peak_month_name'            => $peakMonthName,
                'last_peak_quantity'         => $peakQty,
                'avg_monthly_quantity'       => $avgMonthlyQty,
                'upcoming_peak_date'         => $upcomingPeak->toDateString(),
                'near_peak_window_start'     => $windowStart->toDateString(),
                'is_near_peak'               => $isNearPeak,
                'target_stock_for_peak'      => $targetStock,
                'should_restock'             => $shouldRestock,
                'recommended_additional_qty' => $recommendedAdditional,
            ];
        }

        // ---------------- INVENTORY BY CATEGORY ----------------
        $productInventoryByCategory = ProductCategory::query()
            ->leftJoin('products', 'products.product_category_id', '=', 'product_categories.id')
            ->selectRaw('product_categories.id as category_id')
            ->selectRaw('product_categories.name as category_name')
            ->selectRaw('COALESCE(SUM(products.current_stock), 0) as total_stock')
            ->groupBy('product_categories.id', 'product_categories.name')
            ->orderBy('product_categories.name')
            ->get();

        // Count only categories that actually have stock > 0
        $productCategoryCount = $productInventoryByCategory
            ->filter(function ($row) {
                return (float) $row->total_stock > 0;
            })
            ->count();

        // ---------------- RESPONSE ----------------
        return response()->json([
            'revenue'                     => $revenue,
            'purchases'                   => $purchases,
            'profit'                      => $profit,
            'monthly_sales'               => $monthlySales,
            'low_stock'                   => $lowStock,
            'expiring_soon'               => $expiringSoon,
            'low_stock_count'             => $lowStockCount,
            'expiring_soon_count'         => $expiringSoonCount,
            'peak_season_recommendations' => $peakSeasonRecommendations,

            // NEW KEYS FOR FRONTEND
            'product_inventory_by_category' => $productInventoryByCategory,
            'product_category_count'        => $productCategoryCount,
        ]);
    }
}
