<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Owner\ProductController;
use App\Http\Controllers\Api\Owner\ProductCategoryController;
use App\Http\Controllers\Api\Owner\PurchaseController;
use App\Http\Controllers\Api\Owner\SaleController;
use App\Http\Controllers\Api\Owner\OnlineOrderController;
use App\Http\Controllers\Api\Owner\DashboardController;
use App\Http\Controllers\Api\Owner\AnalyticsController;
use App\Http\Controllers\Api\Owner\BrandController;
use App\Http\Controllers\Api\Owner\ReportController;
use App\Http\Middleware\CorsMiddleware;

Route::middleware([CorsMiddleware::class])->group(function () {

    // ======================= AUTH =======================
    // OWNER (WEB)
    Route::post('/owner/login', [AuthController::class, 'ownerLogin']);

    // CUSTOMER (ANDROID / FLUTTER)
    Route::post('/customer/register', [AuthController::class, 'customerRegister']);
    Route::post('/customer/login',    [AuthController::class, 'customerLogin']);

    // ======================= PUBLIC =======================
    // Public product list (web + mobile)
    Route::get('/products', [ProductController::class, 'index']);

    // Simple list of categories for forms
    Route::get('/categories', [ProductCategoryController::class, 'all']);

    // =================== PROTECTED ROUTES ===================
    Route::middleware('auth:sanctum')->group(function () {

        // ------------- User session -------------
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me',      [AuthController::class, 'me']);

        // ------------- CUSTOMER PROFILE (ANDROID) -------------
        Route::get('/customer/profile',              [AuthController::class, 'getProfile']);
        Route::put('/customer/profile',              [AuthController::class, 'updateProfile']);
        Route::post('/customer/change-password',     [AuthController::class, 'changePassword']);

        // ------------- OWNER PRODUCT + BRAND CRUD -------------
        Route::get('/owner/brands',  [BrandController::class, 'index']);
        Route::post('/owner/brands', [BrandController::class, 'store']);   // <- re-added

        Route::get('/owner/products',               [ProductController::class, 'index']);
        Route::post('/owner/products',              [ProductController::class, 'store']);
        Route::get('/owner/products/{id}',          [ProductController::class, 'show']);
        Route::put('/owner/products/{id}',          [ProductController::class, 'update']);
        Route::delete('/owner/products/{id}',       [ProductController::class, 'destroy']);

        Route::get('/owner/products/{id}/batches',  [ProductController::class, 'batches']);

        // ------------- CATEGORY MANAGEMENT -------------
        Route::get('/owner/product-categories',         [ProductCategoryController::class, 'index']);
        Route::post('/owner/product-categories',        [ProductCategoryController::class, 'store']);
        Route::put('/owner/product-categories/{id}',    [ProductCategoryController::class, 'update']);
        Route::delete('/owner/product-categories/{id}', [ProductCategoryController::class, 'destroy']);

        // ------------- TRANSACTIONS -------------
        // Purchases
        Route::get('/owner/purchases',          [PurchaseController::class, 'index']);
        Route::get('/owner/purchases/{id}',     [PurchaseController::class, 'show']);
        Route::post('/owner/purchases',         [PurchaseController::class, 'store']);

        // Sales (FEFO)
        Route::get('/owner/sales',              [SaleController::class, 'index']);     // list sales
        Route::get('/owner/sales/{id}',         [SaleController::class, 'show']);      // show single sale
        Route::post('/owner/sales',             [SaleController::class, 'store']);     // create sale

        // Online orders (reuses SaleController logic)
        Route::post('/owner/online-orders',     [OnlineOrderController::class, 'store']);

        // ------------- DASHBOARD -------------
        Route::get('/owner/dashboard',          [DashboardController::class, 'summary']);

        // ------------- ANALYTICS -------------
        Route::get('/owner/analytics/summary',       [AnalyticsController::class, 'summary']);
        Route::get('/owner/analytics/sales-monthly', [AnalyticsController::class, 'salesMonthly']);

        // ------------- REPORTS -------------
        // Inventory
        Route::get('/owner/reports/inventory/excel', [ReportController::class, 'inventoryExcel']);
        Route::get('/owner/reports/inventory/pdf',   [ReportController::class, 'inventoryPdf']);
        Route::get('/owner/reports/inventory/data',  [ReportController::class, 'inventoryData']);

        // Sales
        Route::get('/owner/reports/sales/excel',     [ReportController::class, 'salesExcel']);
        Route::get('/owner/reports/sales/pdf',       [ReportController::class, 'salesPdf']);
        Route::get('/owner/reports/sales/data',      [ReportController::class, 'salesData']);

        // Fast vs slow moving products
        Route::get('/owner/reports/fast-slow/excel', [ReportController::class, 'fastSlowExcel']);
        Route::get('/owner/reports/fast-slow/pdf',   [ReportController::class, 'fastSlowPdf']);
        // If you later add a fastSlowData() in the controller, you can also expose:
        // Route::get('/owner/reports/fast-slow/data',  [ReportController::class, 'fastSlowData']);
    });
});
