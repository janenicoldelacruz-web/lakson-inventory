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
use Illuminate\Support\Facades\DB;
use App\Events\DashboardUpdated;

class DashboardController extends Controller
{
    public function summary()
    {
        $startOfMonth = now()->startOfMonth();
        $endOfMonth   = now()->endOfMonth();

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
        $totalProducts = Product::where('status', 'active')->count();

        $monthlySales = $this->getMonthlySales(6);
        $stockForecast = $this->getStockForecast($monthlySales, 6, 3);
        $lowStock = $this->getLowStockProducts();
        $expiringSoon = $this->getExpiringSoon();
        $categoryInventory = $this->getCategoryInventory();
        $topProducts = $this->getTopProducts();

        $peakSeasonRecommendations = $this->getPeakSeasonProducts(
            now()->subYear()->startOfYear(),
            now()->subYear()->endOfYear()
        );

        $payload = [
            'revenue'        => $revenue,
            'purchases'      => $purchases,
            'profit'         => $profit,
            'total_products' => $totalProducts,
            'monthly_sales'  => $monthlySales,
            'stock_forecast' => $stockForecast,
            'inventory_by_category' => $categoryInventory,
            'top_products'   => $topProducts,
            'low_stock'      => $lowStock,
            'expiring_soon'  => $expiringSoon,
            'peak_season_recommendations' => $peakSeasonRecommendations,
        ];

        broadcast(new DashboardUpdated($payload));
        return response()->json($payload);
    }

    protected function formatQuantityForProduct(float $qty, ?Product $product): string
{
    if ($qty <= 0) {
        return '0';
    }

    // Handle numeric codes from DB: 1 = kg, 2 = pcs
    $rawUnit = $product->base_unit ?? 1;

    if ($rawUnit === 2 || $rawUnit === '2') {
        $unit = 'pcs';
    } elseif ($rawUnit === 1 || $rawUnit === '1') {
        $unit = 'kg';
    } else {
        // If already stored as text (just in case)
        $unit = strtolower((string) $rawUnit);
    }

    // Pieces
    if (in_array($unit, ['pcs', 'piece', 'pieces', 'pc'], true)) {
        return $this->fmt($qty) . ' pcs';
    }

    // Feeds in kg -> convert to sacks (all feeds = 50kg per sack)
    if ($unit === 'kg') {
        $packSize = 50;

        $sacks = intdiv((int) floor($qty), $packSize);
        $remainingKg = $qty - ($sacks * $packSize);

        $parts = [];

        if ($sacks > 0) {
            $parts[] = $sacks . ' ' . ($sacks === 1 ? 'sack' : 'sacks');
        }

        if ($remainingKg > 0) {
            $parts[] = $this->fmt($remainingKg) . ' kg';
        }

        if (empty($parts)) {
            return $this->fmt($qty) . ' kg';
        }

        return implode(' + ', $parts) . ' (' . $this->fmt($qty) . ' kg)';
    }

    // Fallback for any other unit
    return $this->fmt($qty) . " {$unit}";
}


    protected function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    protected function getLowStockProducts()
    {
        return Product::where('status', 'active')
            ->whereNotNull('reorder_level')
            ->whereColumn('current_stock', '<=', 'reorder_level')
            ->orderBy('current_stock')
            ->get()
            ->map(function ($p) {
                return [
                    'id'              => $p->id,
                    'sku'             => $p->sku,
                    'name'            => $p->name,
                    'base_unit'       => $p->base_unit,
                    'current_stock'   => $p->current_stock,
                    'reorder_level'   => $p->reorder_level,
                    'current_display' => $this->formatQuantityForProduct($p->current_stock, $p),
                    'reorder_display' => $this->formatQuantityForProduct($p->reorder_level, $p),
                ];
            });
    }

    protected function getExpiringSoon()
    {
        return ProductBatch::with(['product:id,name,base_unit'])
            ->whereDate('expiry_date', '>=', now())
            ->whereDate('expiry_date', '<=', now()->addDays(30))
            ->orderBy('expiry_date')
            ->get()
            ->map(function ($b) {
                return [
                    'id'               => $b->id,
                    'batch_code'       => $b->batch_code,
                    'product_id'       => $b->product_id,
                    'product_name'     => $b->product->name,
                    'expiry_date'      => $b->expiry_date,
                    'quantity_raw'     => $b->quantity,
                    'quantity_display' => $this->formatQuantityForProduct($b->quantity, $b->product),
                    'days_left'        => now()->diffInDays($b->expiry_date),
                ];
            });
    }

    protected function getCategoryInventory()
    {
        return ProductCategory::with(['products'])
            ->get()
            ->map(function ($cat) {
                $kg = 0; $pcs = 0;
                foreach ($cat->products as $p) {
                    if ($p->base_unit === 'kg') $kg += $p->current_stock;
                    else $pcs += $p->current_stock;
                }

                return [
                    'category_id'   => $cat->id,
                    'category_name' => $cat->name,
                    'total_stock_kg' => $kg,
                    'total_stock_sacks' => $kg > 0 ? round($kg / 50, 2) : 0,
                    'total_stock_pcs' => $pcs,
                    'total_stock_display' => $kg > 0 
                        ? round($kg / 50, 2) . " sacks ({$kg} kg)"
                        : $pcs . " pcs",
                ];
            });
    }

    /**
 * Top-selling product in each category.
 * Result is still a flat list used by TopProductsChart.
 * Example item:
 * {
 *   product_id: 1,
 *   product_name: "Pre Starter",
 *   category_id: 2,
 *   category_name: "Feeds",
 *   total_qty: 151,
 *   display_qty: "3 sacks + 1 kg (151 kg)"
 * }
 */
/**
 * Top-selling product in each category (safe version).
 */
protected function getTopProducts()
{
    // 1) Aggregate total sold per product (kg / pcs)
    $rows = SaleItem::selectRaw('
            sale_items.product_id,
            SUM(sale_items.quantity) AS total_qty
        ')
        ->leftJoin('sales', 'sale_items.sale_id', '=', 'sales.id')
        ->where('sales.status', 'completed')
        ->groupBy('sale_items.product_id')
        ->get();

    if ($rows->isEmpty()) {
        return [];
    }

    // 2) Load products with category
    $products = Product::with('category')
        ->whereIn('id', $rows->pluck('product_id')->unique())
        ->get()
        ->keyBy('id');

    // 3) Build top-1-per-category in PHP
    $perCategory = []; // [category_id => ['row' => ..., 'product' => ...]]

    foreach ($rows as $row) {
        $product = $products->get($row->product_id);
        if (! $product) {
            continue;
        }

        // Skip products without category
        if (! $product->category) {
            continue;
        }

        $catId   = $product->category->id;
        $catName = $product->category->name;
        $qty     = (float) $row->total_qty;

        // If category not seen yet OR this product has higher qty → replace
        if (! isset($perCategory[$catId]) || $qty > $perCategory[$catId]['row']->total_qty) {
            $perCategory[$catId] = [
                'row'     => $row,
                'product' => $product,
                'cat_id'  => $catId,
                'cat_name'=> $catName,
            ];
        }
    }

    if (empty($perCategory)) {
        return [];
    }

    // 4) Map to final structure
    $result = [];

    foreach ($perCategory as $catId => $bundle) {
        $row     = $bundle['row'];
        $product = $bundle['product'];
        $qtyKg   = (float) $row->total_qty;

        $result[] = [
            'product_id'    => $product->id,
            'product_name'  => $product->name,
            'category_id'   => $bundle['cat_id'],
            'category_name' => $bundle['cat_name'],
            'total_qty'     => $qtyKg,
            'display_qty'   => $this->formatQuantityForProduct($qtyKg, $product),
        ];
    }

    // Sort by quantity desc for nicer chart ordering
    usort($result, fn ($a, $b) => $b['total_qty'] <=> $a['total_qty']);

    return $result;
}




    protected function getPeakSeasonProducts($start, $end)
    {
        $data = SaleItem::selectRaw("
                sale_items.product_id,
                MONTH(sales.sale_date) as month,
                SUM(sale_items.quantity) as total_qty
            ")
            ->leftJoin('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->whereBetween('sale_date', [$start, $end])
            ->where('sales.status', 'completed')
            ->groupBy('sale_items.product_id', 'month')
            ->get();

        if ($data->isEmpty()) return [];

        $grouped = $data->groupBy('product_id');
        $products = Product::whereIn('id', $grouped->keys())->get()->keyBy('id');

        $results = [];
        $currentMonth = now()->month;

        foreach ($grouped as $productId => $rows) {
            $product = $products[$productId];
            $monthly = [];
            foreach ($rows as $r) $monthly[$r->month] = $r->total_qty;

            $total = array_sum($monthly);
            if ($total < 10) continue;

            $avg = $total / count($monthly);
            arsort($monthly);

            $peakMonth = array_key_first($monthly);
            $peakQty = reset($monthly);

            if ($peakQty < $avg * 1.2) continue;

            $monthsUntil = ($peakMonth - $currentMonth + 12) % 12;
            if ($monthsUntil < 1 || $monthsUntil > 2) continue;

            $needed = max(($peakQty * 1.10) - $product->current_stock, 0);

            $results[] = [
                'product_id'    => $product->id,
                'product_name'  => $product->name,
                'peak_month'    => $peakMonth,
                'peak_month_label' => Carbon::create(null, $peakMonth, 1)->format('F'),
                'recommended_restock_display' => $this->formatQuantityForProduct($needed, $product),
                'current_stock_display'       => $this->formatQuantityForProduct($product->current_stock, $product),
            ];
        }

        return $results;
    }

    protected function getMonthlySales(int $months)
    {
        $start = now()->subMonths($months - 1)->startOfMonth();

        $raw = Sale::selectRaw("
                YEAR(sale_date) as year,
                MONTH(sale_date) as month,
                SUM(total_amount) as total
            ")
            ->where('status', 'completed')
            ->where('sale_date', '>=', $start)
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get();

        $monthly = [];
        $totals = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $date = now()->subMonths($i)->startOfMonth();
            $found = $raw->first(fn($r) => $r->year == $date->year && $r->month == $date->month);
            $total = $found ? $found->total : 0;

            $monthly[] = [
                'month' => $date->format('M Y'),
                'total' => $total,
            ];
            $totals[] = $total;
        }

        for ($i = 0; $i < count($totals); $i++) {
            $monthly[$i]['ma3'] = $i < 2 ? null : round(($totals[$i] + $totals[$i-1] + $totals[$i-2]) / 3, 2);
        }

        return $monthly;
    }

    protected function getStockForecast(array $sales, int $monthsAhead, int $window)
    {
        $history = collect($sales)->pluck('total')->values();
        if ($history->count() < $window)
            return $this->simpleAverageForecast($history->avg(), $monthsAhead);

        $rolling = $history->toArray();
        for ($i = 0; $i < $monthsAhead; $i++) {
            $lastN = array_slice($rolling, -$window);
            $rolling[] = array_sum($lastN) / $window;
        }

        $forecast = [];
        $start = count($history);

        for ($i = 1; $i <= $monthsAhead; $i++) {
            $date = now()->addMonths($i);
            $forecast[] = [
                'month' => $date->format('M Y'),
                'forecast' => round($rolling[$start + $i - 1], 2),
            ];
        }

        return $forecast;
    }

    protected function simpleAverageForecast(float $avg, int $monthsAhead)
    {
        $out = [];
        for ($i = 1; $i <= $monthsAhead; $i++) {
            $out[] = [
                'month' => now()->addMonths($i)->format('M Y'),
                'forecast' => round($avg, 2),
            ];
        }
        return $out;
    }
}
