<?php
namespace App\Exports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class InventoryReportExport implements FromCollection, WithHeadings
{
    public function collection()
    {
        // Get the products from the database
        $products = Product::with('category', 'brand')->get();

        // Prepare the data to be exported
        return $products->map(function ($product) {
            $sackWeight = 25; // kg per sack
            $pieceWeight = 0.1; // kg per piece (adjust as necessary)

            if ($product->category->name === 'Feeds') {
                // For Feeds, calculate sacks and weight in kg
                $currentStockSacks = floor($product->current_stock / $sackWeight);
                $currentStockKg = $currentStockSacks * $sackWeight;
                $currentStockPieces = 0;
                $totalValue = $currentStockSacks * $product->cost_price;
            } else {
                // For Non-Feeds (like vitamins), calculate pieces only
                $currentStockSacks = 0;
                $currentStockPieces = $product->current_stock;
                $currentStockKg = 0;
                $totalValue = $currentStockPieces * $product->cost_price;
            }

            return [
                'name' => $product->name,
                'category' => $product->category->name,
                'brand' => $product->brand->name,
                'current_stock_sacks' => $currentStockSacks,
                'current_stock_pieces' => $currentStockPieces,
                'cost_price' => $product->cost_price,
                'selling_price' => $product->selling_price,
                'total_value' => $totalValue
            ];
        });
    }

    public function headings(): array
    {
        // Define the headings for the Excel file
        return [
            'Product Name',
            'Category',
            'Brand',
            'Current Stock',
            'Cost Price',
            'Selling Price',
            'Total Value',
        ];
    }
}
