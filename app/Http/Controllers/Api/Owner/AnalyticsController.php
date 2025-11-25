<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function summary(Request $request)
    {
        $now = Carbon::now();

        $startOfToday  = $now->copy()->startOfDay();
        $startOfWeek   = $now->copy()->startOfWeek();
        $startOfMonth  = $now->copy()->startOfMonth();
        $startOfYear   = $now->copy()->startOfYear();

        $revenueToday = (float) Sale::where('status', 'completed')
            ->whereBetween('sale_date', [$startOfToday, $now])
            ->sum('total_amount');

        $revenueWeek = (float) Sale::where('status', 'completed')
            ->whereBetween('sale_date', [$startOfWeek, $now])
            ->sum('total_amount');

        $revenueMonth = (float) Sale::where('status', 'completed')
            ->whereBetween('sale_date', [$startOfMonth, $now])
            ->sum('total_amount');

        $revenueYear = (float) Sale::where('status', 'completed')
            ->whereBetween('sale_date', [$startOfYear, $now])
            ->sum('total_amount');

        $totalSalesToday = (int) Sale::where('status', 'completed')
            ->whereBetween('sale_date', [$startOfToday, $now])
            ->count();

        $totalSalesMonth = (int) Sale::where('status', 'completed')
            ->whereBetween('sale_date', [$startOfMonth, $now])
            ->count();

        $cogsMonth = (float) SaleItem::leftJoin('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.status', 'completed')
            ->whereBetween('sales.sale_date', [$startOfMonth, $now])
            ->sum('sale_items.line_cost');

        $profitMonth = $revenueMonth - $cogsMonth;

        return response()->json([
            'revenue_today'          => round($revenueToday, 2),
            'revenue_this_week'      => round($revenueWeek, 2),
            'revenue_this_month'     => round($revenueMonth, 2),
            'revenue_this_year'      => round($revenueYear, 2),

            'profit_this_month'      => round($profitMonth, 2),
            'cogs_this_month'        => round($cogsMonth, 2),

            'total_sales_today'      => $totalSalesToday,
            'total_sales_this_month' => $totalSalesMonth,
        ]);
    }

    public function salesMonthly(Request $request)
    {
        $year = (int) ($request->get('year') ?? Carbon::now()->year);

        $rows = Sale::leftJoin('sale_items', 'sales.id', '=', 'sale_items.sale_id')
            ->select(
                DB::raw('MONTH(sales.sale_date) as month'),
                DB::raw('SUM(sales.total_amount) as total_sales'),
                DB::raw('COUNT(sales.id) as total_orders')
            )
            ->where('sales.status', 'completed')
            ->whereYear('sales.sale_date', $year)
            ->groupBy(DB::raw('MONTH(sales.sale_date)'))
            ->orderBy(DB::raw('MONTH(sales.sale_date)'))
            ->get();

        $data = [];
        for ($m = 1; $m <= 12; $m++) {
            $row = $rows->firstWhere('month', $m);

            $data[] = [
                'month'        => $m,
                'total_sales'  => $row ? (float) $row->total_sales : 0.0,
                'total_orders' => $row ? (int) $row->total_orders : 0,
            ];
        }

        return response()->json([
            'year' => $year,
            'data' => $data,
        ]);
    }
}
