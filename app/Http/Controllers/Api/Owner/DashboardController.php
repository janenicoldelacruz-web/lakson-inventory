<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Purchase;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductCategory;
use Illuminate\Support\Carbon;
use App\Events\DashboardUpdated;
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
    ->whereColumn('current_stock', '<=', 'reorder_level')   // ✅ strict rule
    ->orderBy('current_stock')
    ->get([
        'id',
        'sku',
        'name',
        'current_stock',
        'reorder_level',
        'base_unit',
    ])
    ->map(function ($product) {
        $baseUnitCode = $product->base_unit;
        $current      = (float) $product->current_stock;
        $reorder      = (float) ($product->reorder_level ?? 0);

        // Map numeric base_unit → logical unit
        $baseUnit = 'kg';
        if ($baseUnitCode === 2 || $baseUnitCode === '2') {
            $baseUnit = 'pcs';
        }

        // Decide sack size for feeds
        $getPackSizeKg = function () use ($product, $baseUnit) {
            if ($baseUnit !== 'kg') {
                return null;
            }

            $name = strtolower($product->name ?? '');
            return str_contains($name, 'prestarter') ? 25 : 50; // starter=25kg, others=50kg
        };

        $packSizeKg = $getPackSizeKg();

        $formatQty = function (float $qty) use ($baseUnit, $packSizeKg) {
            // pcs
            if ($baseUnit === 'pcs') {
                $qtyFmt = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
                return "{$qtyFmt} pcs";
            }

            // feeds in kg
            if ($baseUnit === 'kg' && $packSizeKg) {
                if ($qty <= 0) {
                    return '0 sacks (0 kg)';
                }

                $kgFmt = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');

                // if less than 1 full sack → show only kg
                if ($qty < $packSizeKg) {
                    return "{$kgFmt} kg";
                }

                $sacks    = $qty / $packSizeKg;
                $sacksFmt = rtrim(rtrim(number_format($sacks, 2, '.', ''), '0'), '.');
                $label    = ((float) $sacksFmt === 1.0) ? 'sack' : 'sacks';

                return "{$sacksFmt} {$label} ({$kgFmt} kg)";
            }

            // fallback
            $qtyFmt = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
            return "{$qtyFmt} {$baseUnit}";
        };

        return [
            'id'              => $product->id,
            'sku'             => $product->sku,
            'name'            => $product->name,
            'current_stock'   => $current,
            'reorder_level'   => $reorder,
            'base_unit'       => $product->base_unit,
            'current_display' => $formatQty($current),
            'reorder_display' => $reorder > 0 ? $formatQty($reorder) : null,
        ];
    });


        // ---------------- EXPIRING SOON (NEXT 30 DAYS) ----------------
        $expiringSoon = ProductBatch::with(['product:id,sku,name,base_unit'])
            ->whereDate('expiry_date', '>=', now())
            ->whereDate('expiry_date', '<=', now()->addDays(30))
            ->orderBy('expiry_date')
            ->get([
                'id',
                'product_id',
                'batch_code',
                'expiry_date',
                'quantity',
            ])
            ->map(function (ProductBatch $batch) {
                $product  = $batch->product;
                $qty      = (float) $batch->quantity;
                $daysLeft = now()->diffInDays(Carbon::parse($batch->expiry_date), false);

                return [
                    'id'               => $batch->id,
                    'product_id'       => $batch->product_id,
                    'sku'              => $product->sku ?? null,
                    'product_name'     => $product->name ?? null,
                    'batch_code'       => $batch->batch_code,
                    'expiry_date'      => $batch->expiry_date,
                    'quantity_raw'     => $qty,
                    'quantity_display' => $this->formatQuantityForProduct($qty, $product),
                    'base_unit'        => $product->base_unit ?? null,
                    'days_left'        => (int) $daysLeft,
                ];
            });


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
            /** @var Product|null $product */
            $product = $products->get($productId);
            if (! $product || $product->status !== 'active') {
                continue;
            }

            $peakRow = $rows->sortByDesc('total_qty')->first();
            if (! $peakRow || $peakRow->total_qty <= 0) {
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

            $targetStock  = $peakQty * $safetyFactor;
            $currentStock = (float) $product->current_stock;

            $shouldRestock         = false;
            $recommendedAdditional = 0.0;

            if ($isNearPeak && $currentStock < $targetStock) {
                $shouldRestock         = true;
                $recommendedAdditional = max(0, $targetStock - $currentStock);
            }

            $peakSeasonRecommendations[] = [
                'product_id'                 => $product->id,
                'product_name'               => $product->name,
                'current_stock'              => $currentStock,
                'current_stock_display'      => $this->formatQuantityForProduct($currentStock, $product),
                'reorder_level'              => (float) $product->reorder_level,
                'reorder_level_display'      => $this->formatQuantityForProduct((float) $product->reorder_level, $product),
                'base_unit'                  => $product->base_unit,
                'peak_month'                 => $peakMonth,
                'peak_month_name'            => $peakMonthName,
                'last_peak_quantity'         => $peakQty,
                'last_peak_quantity_display' => $this->formatQuantityForProduct($peakQty, $product),
                'avg_monthly_quantity'       => $avgMonthlyQty,
                'avg_monthly_quantity_display' => $this->formatQuantityForProduct($avgMonthlyQty, $product),
                'upcoming_peak_date'         => $upcomingPeak->toDateString(),
                'near_peak_window_start'     => $windowStart->toDateString(),
                'is_near_peak'               => $isNearPeak,
                'target_stock_for_peak'      => $targetStock,
                'target_stock_for_peak_display' => $this->formatQuantityForProduct($targetStock, $product),
                'should_restock'             => $shouldRestock,
                'recommended_additional_qty' => $recommendedAdditional,
                'recommended_additional_qty_display' => $this->formatQuantityForProduct($recommendedAdditional, $product),
            ];
        }

        // ---------------- INVENTORY BY CATEGORY ----------------
       // ---------------- INVENTORY BY CATEGORY (SACKS + PCS) ----------------
$productInventoryByCategory = ProductCategory::with(['products' => function ($q) {
        $q->where('status', 'active');
    }])
    ->get()
    ->map(function ($category) {
        $totalKg   = 0.0;  // total weight in kg (feeds)
        $totalSacks = 0.0; // total sacks (25kg or 50kg depending on product)
        $totalPcs  = 0.0;  // total pieces (vitamins, etc.)

        foreach ($category->products as $product) {
            $qty = (float) $product->current_stock;
            if ($qty <= 0) {
                continue;
            }

            $baseUnit = strtolower((string) ($product->base_unit ?? ''));

            // Treat base_unit "2" or pcs as pieces
            if (in_array($baseUnit, ['2', 'pcs', 'piece', 'pieces', 'pc'], true)) {
                $totalPcs += $qty;
                continue;
            }

            // Treat base_unit "1" or kg as kilograms (feeds)
            if (in_array($baseUnit, ['1', 'kg', 'kilo', 'kilogram', 'kilograms', ''], true)) {
                $totalKg += $qty;

                $name     = strtolower($product->name ?? '');
                // Starter feeds = 25 kg per sack, others = 50 kg per sack
                $packSize = str_contains($name, 'prestarter') ? 25 : 50;

                if ($packSize > 0) {
                    $totalSacks += $qty / $packSize;
                }
            }
        }

        // Display string for tooltip / UI
        $trim = function (float $v): string {
            return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        };

        if ($totalSacks > 0) {
            $display = $trim($totalSacks) . ' sacks (' . $trim($totalKg) . ' kg)';
        } elseif ($totalPcs > 0) {
            $display = $trim($totalPcs) . ' pcs';
        } else {
            $display = '0';
        }

        return [
            'category_id'         => $category->id,
            'category_name'       => $category->name,
            'total_sacks'         => $totalSacks,   // for feeds categories
            'total_stock_kg'      => $totalKg,      // feeds weight
            'total_stock_pcs'     => $totalPcs,     // vitamins/supplements/etc.
            'total_stock_display' => $display,      // e.g. "37 sacks (1100 kg)"
        ];
    });

// Count only categories that actually have stock
$productCategoryCount = $productInventoryByCategory
    ->filter(function ($row) {
        return ($row['total_sacks'] ?? 0) > 0 || ($row['total_stock_pcs'] ?? 0) > 0;
    })
    ->count();

        // ---------------- TOP PRODUCTS (CURRENT MONTH) ----------------
        $topProducts = SaleItem::query()
            ->selectRaw('sale_items.product_id, products.name as product_name, SUM(sale_items.quantity) as total_qty')
            ->leftJoin('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->leftJoin('products', 'sale_items.product_id', '=', 'products.id')
            ->where('sales.status', 'completed')
            ->whereBetween('sales.sale_date', [$startOfMonth, $endOfMonth])
            ->groupBy('sale_items.product_id', 'products.name')
            ->orderByDesc('total_qty')
            ->limit(5)
            ->get()
            ->map(function ($row) use ($products) {
                /** @var Product|null $product */
                $product = $products->get($row->product_id);
                $qty     = (float) $row->total_qty;

                $row->total_qty_display = $product
                    ? $this->formatQuantityForProduct($qty, $product)
                    : $qty;

                return $row;
            });

        // ---------------- RESPONSE PAYLOAD ----------------
        $dashboardData = [
            'revenue'                       => $revenue,
            'purchases'                     => $purchases,
            'profit'                        => $profit,
            'monthly_sales'                 => $monthlySales,
            'low_stock'                     => $lowStock,
            'expiring_soon'                 => $expiringSoon,
            'peak_season_recommendations'   => $peakSeasonRecommendations,
            'product_inventory_by_category' => $productInventoryByCategory,
            'product_category_count'        => $productCategoryCount,
            'top_products'                  => $topProducts,
        ];

        broadcast(new DashboardUpdated($dashboardData));

        return response()->json($dashboardData);
    }

    /**
     * Format a quantity for a given product:
     * - Feeds (base_unit = kg): "X sacks (Y kg)" using 25kg for "starter", 50kg for others
     * - Pieces: "N pcs"
     * - Fallback: "N unit"
     */
   /**
 * Format a quantity for a given product:
 * - Feeds (base_unit = kg): "1 sack (25 kg)" / "5 sacks (250 kg)"
 * - Pieces: "N pcs"
 * - Fallback: "N unit"
 */
protected function formatQuantityForProduct(float $qty, ?Product $product): string
{
    $baseUnit = strtolower($product->base_unit ?? 'kg');

    // Pieces / vitamins etc.
    if (in_array($baseUnit, ['pcs', 'piece', 'pieces', 'pc'])) {
        $qtyFmt = $this->fmt($qty);
        return "{$qtyFmt} pcs";
    }

    // Feeds in kg -> compute sacks
    if ($baseUnit === 'kg') {
        $name = strtolower($product->name ?? '');

        // Starter → 25kg sacks, others → 50kg
        $packSizeKg = str_contains($name, 'prestarter') ? 25 : 50;

        if ($qty <= 0 || $packSizeKg <= 0) {
            return '0 sacks (0 kg)';
        }

        $sacks   = $qty / $packSizeKg;
        $sacksFmt = $this->fmt($sacks);
        $kgFmt    = $this->fmt($qty);

        // singular / plural
        $sackLabel = ((float) $sacksFmt == 1.0) ? 'sack' : 'sacks';

        return "{$sacksFmt} {$sackLabel} ({$kgFmt} kg)";
    }

    // Fallback – just show value + base unit
    $qtyFmt = $this->fmt($qty);
    return "{$qtyFmt} {$baseUnit}";
}

protected function fmt(float $value): string
{
    // 25, 250 → no decimals; 12.5 → "12.5"
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
}

}
