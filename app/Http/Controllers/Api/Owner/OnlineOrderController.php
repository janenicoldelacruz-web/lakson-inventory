<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\Owner\SaleController;

class OnlineOrderController extends Controller
{
    public function store(Request $request)
    {
        // Force sale_type = online
        $request->merge(['sale_type' => 'online']);

        // Reuse the SaleController logic
        $saleController = app(SaleController::class);

        return $saleController->store($request);
    }
}
