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
    // Excel File Generation
    public function inventoryExcel()
{
    try {
        $fileName = 'inventory_report.xlsx';
        
        // Define the path where the Excel file will be stored
        $filePath = 'public/' . $fileName; // This ensures it is saved in the correct directory

        // Generate and store the Excel file
        Excel::store(new InventoryReportExport(), $filePath); // Store it in storage/app/public

        // Get the full path to the stored file
        $fullFilePath = storage_path('app/' . $filePath); // This path points to storage/app/public/

        // Log success message
        Log::info('Excel report generated successfully: ' . $fullFilePath);

        // Return the file for download
        return response()->download($fullFilePath, $fileName)->deleteFileAfterSend(true); // Delete file after sending
    } catch (\Exception $e) {
        // Log the error
        Log::error('Error generating Excel report: ' . $e->getMessage());
        return response()->json(['error' => 'Internal Server Error. Check logs for more details.'], 500);
    }
}


    // PDF File Generation
    public function inventoryPdf()
    {
        try {
            $products = Product::with('category', 'brand')->orderBy('name')->get();
            $pdf = Pdf::loadView('reports.inventory', ['products' => $products])
                ->setPaper('A4', 'landscape');

            $fileName = 'inventory_report.pdf';
            $filePath = storage_path('app/public/' . $fileName);

            // Save the PDF file
            $pdf->save($filePath);

            // Return the PDF for download
            return response()->download($filePath, $fileName)->deleteFileAfterSend(true);  // Delete file after sending
        } catch (\Exception $e) {
            Log::error('Error generating PDF report: ' . $e->getMessage());
            return response()->json(['error' => 'Internal Server Error'], 500);
        }
    }

    // Inventory Data (Excel & PDF)
    public function inventoryData()
    {
        try {
            $products = Product::with('category', 'brand')->get();

            return $products->map(function ($p) {
                $sackWeight = 25; // kg per sack
                $pieceWeight = 0.1; // kg per piece (adjust as necessary)

                if ($p->category->name === 'Feeds') {
                    // For Feeds, calculate sacks and weight in kg
                    $currentStockSacks = floor($p->current_stock / $sackWeight);
                    $currentStockKg = $currentStockSacks * $sackWeight;
                    $currentStockPieces = 0;
                    $totalValue = $currentStockSacks * $p->cost_price;
                } else {
                    // For Non-Feeds (like vitamins), calculate pieces only
                    $currentStockSacks = 0;
                    $currentStockPieces = $p->current_stock;
                    $currentStockKg = 0;
                    $totalValue = $currentStockPieces * $p->cost_price;
                }

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'category' => $p->category,
                    'brand' => $p->brand,
                    'current_stock_sacks' => $currentStockSacks,
                    'current_stock_pieces' => $currentStockPieces,
                    'current_stock_kg' => $currentStockKg,
                    'cost_price' => $p->cost_price,
                    'selling_price' => $p->selling_price,
                    'total_value' => $totalValue,
                ];
            });
        } catch (\Exception $e) {
            Log::error('Error fetching inventory data: ' . $e->getMessage());
            return response()->json(['error' => 'Internal Server Error'], 500);
        }
    }
}
