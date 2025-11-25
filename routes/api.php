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

// ======================= AUTH =======================

// OWNER (WEB)
Route::post('/owner/login',    [AuthController::class, 'ownerLogin']);

// CUSTOMER (ANDROID)
Route::post('/customer/register', [AuthController::class, 'customerRegister']);
Route::post('/customer/login',    [AuthController::class, 'customerLogin']);


// ======================= PUBLIC =======================

// Public product list (web + mobile)
Route::get('/products', [ProductController::class, 'index']);

// Simple list of categories for forms
Route::get('/categories', [ProductCategoryController::class, 'all']);


// =================== PROTECTED ROUTES ===================
Route::middleware('auth:sanctum')->group(function () {

    // User session
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);

    // ================== OWNER PRODUCT CRUD ==================
     Route::get('/owner/brands', [BrandController::class, 'index']);
    Route::get('/owner/products',              [ProductController::class, 'index']);
    Route::post('/owner/products',             [ProductController::class, 'store']);
    Route::get('/owner/products/{id}',         [ProductController::class, 'show']);
    Route::put('/owner/products/{id}',         [ProductController::class, 'update']);
    Route::delete('/owner/products/{id}',      [ProductController::class, 'destroy']);

    // ================= CATEGORY MANAGEMENT ===================
    Route::get('/owner/product-categories',          [ProductCategoryController::class, 'index']);
    Route::post('/owner/product-categories',         [ProductCategoryController::class, 'store']);
    Route::put('/owner/product-categories/{id}',     [ProductCategoryController::class, 'update']);
    Route::delete('/owner/product-categories/{id}',  [ProductCategoryController::class, 'destroy']);

    // ==================== TRANSACTIONS ======================
    // Purchases (stock in)
    Route::post('/owner/purchases', [PurchaseController::class, 'store']);

    // Sales (FEFO)
    Route::post('/owner/sales', [SaleController::class, 'store']);

    // Online orders (reuses SaleController logic)
    Route::post('/owner/online-orders', [OnlineOrderController::class, 'store']);

    // ===================== DASHBOARD ========================
    Route::get('/owner/dashboard',   [DashboardController::class, 'summary']);

    // ===================== ANALYTICS ========================
    Route::get('/owner/analytics/summary',      [AnalyticsController::class, 'summary']);
    Route::get('/owner/analytics/sales-monthly',[AnalyticsController::class, 'salesMonthly']);
});
