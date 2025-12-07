<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\InventoryReportExport;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ReportController extends Controller
{
    // ==============================
    // INVENTORY – EXCEL
    // ==============================
    public function inventoryExcel()
    {
        try {
            $fileName = 'inventory_report.xlsx';
            $filePath = 'public/' . $fileName; // storage/app/public/inventory_report.xlsx

            Excel::store(new InventoryReportExport(), $filePath);

            $fullFilePath = storage_path('app/' . $filePath);

            Log::info('Excel report generated successfully: ' . $fullFilePath);

            return response()
                ->download($fullFilePath, $fileName)
                ->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            Log::error('Error generating Excel report: ' . $e->getMessage());
            return response()->json([
                'error' => 'Internal Server Error. Check logs for more details.',
            ], 500);
        }
    }

    // ==============================
    // INVENTORY – PDF
    // ==============================
public function inventoryPdf()
{
    try {
        $products = Product::with(['category', 'brand'])
            ->join('product_categories', 'products.product_category_id', '=', 'product_categories.id')
            ->orderBy('product_categories.id')   // 1) by category number
            ->orderBy('products.name')           // 2) by product name
            ->select('products.*')               // avoid column name conflicts
            ->get();

        $company = [
            'name'    => 'Lakson Feed Trading',
            'address' => 'San Guillermo, Isabela',
            'contact' => '09108655383',
        ];

        $reportTitle = 'Inventory Report';

        $preparedBy  = auth()->user()->name ?? 'Owner';
        $signatories = [
            'prepared_by_label'  => 'Prepared by',
            'checked_by_name'    => '________________________',
            'checked_by_title'   => 'Checked by',
            'approved_by_name'   => '________________________',
            'approved_by_title'  => 'Approved by',
        ];

        $pdf = Pdf::loadView('reports.inventory', [
                'products'    => $products,
                'company'     => $company,
                'reportTitle' => $reportTitle,
                'preparedBy'  => $preparedBy,
                'signatories' => $signatories,
            ])
            ->setPaper('A4', 'landscape');

        $fileName = 'inventory_report.pdf';
        $filePath = storage_path('app/public/' . $fileName);

        $pdf->save($filePath);

        return response()
            ->download($filePath, $fileName)
            ->deleteFileAfterSend(true);
    } catch (\Exception $e) {
        Log::error('Error generating PDF report: ' . $e->getMessage());
        return response()->json(['error' => 'Internal Server Error'], 500);
    }
}


    // ==============================
    // INVENTORY – RAW DATA (used by JS / Excel)
    // ==============================
    public function inventoryData()
{
    try {
        $products = Product::with(['category', 'brand'])
            ->join('product_categories', 'products.product_category_id', '=', 'product_categories.id')
            ->orderBy('product_categories.id')
            ->orderBy('products.name')
            ->select('products.*')
            ->get();

        $SACK_WEIGHT_KG = 50;

        return $products->map(function ($p) use ($SACK_WEIGHT_KG) {
            $currentStock = (float) ($p->current_stock ?? 0);
            $baseUnit     = (int) ($p->base_unit ?? 1);

            $sacksDecimal = 0.0;
            $pieces       = 0;
            $qtyForValue  = 0.0;

            if ($baseUnit === 1) {
                $sacksDecimal = $SACK_WEIGHT_KG > 0 ? ($currentStock / $SACK_WEIGHT_KG) : 0;
                $pieces       = 0;
                $qtyForValue  = $sacksDecimal;
            } else {
                $sacksDecimal = 0.0;
                $pieces       = (int) $currentStock;
                $qtyForValue  = $pieces;
            }

            $cost  = (float) ($p->cost_price ?? 0);
            $sell  = (float) ($p->selling_price ?? $p->price ?? 0);
            $total = $qtyForValue * $cost;

            return [
                'id'                   => $p->id,
                'name'                 => $p->name,
                'category'             => optional($p->category)->name,
                'brand'                => optional($p->brand)->name,
                'sacks_decimal'        => $sacksDecimal,
                'pieces'               => $pieces,
                'cost_price'           => $cost,
                'selling_price'        => $sell,
                'total_value'          => $total,
            ];
        });
    } catch (\Exception $e) {
        Log::error('Error fetching inventory data: ' . $e->getMessage());
        return response()->json(['error' => 'Internal Server Error'], 500);
    }
}

}
