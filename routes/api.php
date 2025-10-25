<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\CounterController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\SubCategoryController;
use App\Http\Controllers\TagsController;
use App\Http\Controllers\ClientsController;
use App\Http\Controllers\ClientContactPersonController;
use App\Http\Controllers\OrdersController;
use App\Http\Controllers\InvoiceController;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::post('/register', [UserController::class, 'create']);

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum', 'role:admin,sales,staff,dispatch')->group(function () {

    Route::get('/dashboard', [UserController::class, 'summary']);
    // users route
    Route::prefix('users')->group(function () {
        Route::post('/create', [UserController::class, 'create']);
        Route::post('/retrieve/{id?}', [UserController::class, 'fetch']);
        Route::post('/update/{id}', [UserController::class, 'edit']);
        Route::delete('/delete/{id}', [UserController::class, 'delete']);
        Route::post('/reset_password', [AuthController::class, 'updatePassword']);
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    // category route
    Route::prefix('category')->group(function () {
            Route::post('/create', [CategoryController::class, 'create']);
            Route::post('/retrieve/{id?}', [CategoryController::class, 'fetch']);
            Route::post('/update/{id}', [CategoryController::class, 'edit']);
            Route::delete('/delete/{id}', [CategoryController::class, 'delete']);
    });

    // sub-category route
    Route::prefix('sub_category')->group(function () {
        Route::post('/create', [SubCategoryController::class, 'create']);
        Route::post('/retrieve/{id?}', [SubCategoryController::class, 'fetch']);
        Route::post('/update/{id}', [SubCategoryController::class, 'edit']);
        Route::delete('/delete/{id}', [SubCategoryController::class, 'delete']);
    });

    // tags route
    Route::prefix('tags')->group(function () {
        Route::post('/create', [TagsController::class, 'create']);
        Route::post('/retrieve/{id?}', [TagsController::class, 'fetch']);
        Route::post('/update/{id}', [TagsController::class, 'edit']);
        Route::delete('/delete/{id}', [TagsController::class, 'delete']);
    });

    // clients route
    Route::prefix('clients')->group(function () {
        Route::post('/create', [ClientsController::class, 'create']);
        Route::post('/retrieve/{id?}', [ClientsController::class, 'fetch']);
        Route::post('/update/{id}', [ClientsController::class, 'edit']);
        Route::delete('/delete/{id}', [ClientsController::class, 'delete']);

        // clients-contact-person route
        Route::prefix('contact_person')->group(function () {
            Route::post('/create', [ClientContactPersonController::class, 'create']);
            Route::post('/retrieve/{id?}', [ClientContactPersonController::class, 'fetch']);
            Route::post('/update/{id}', [ClientContactPersonController::class, 'edit']);
            Route::delete('/delete/{id}', [ClientContactPersonController::class, 'delete']);
        });
    });
    
    // counter route
    Route::prefix('counter')->group(function () {
        Route::post('/create', [CounterController::class, 'create']);
        Route::post('/retrieve/{id?}', [CounterController::class, 'fetch']);
        Route::post('/update/{id}', [CounterController::class, 'edit']);
        Route::delete('/delete/{id}', [CounterController::class, 'delete']);
    });

    // orders route
    Route::prefix('orders')->group(function () {
        Route::post('/create', [OrdersController::class, 'create']);
        Route::post('/retrieve/{id?}', [OrdersController::class, 'fetch']);
        Route::post('/update/{id}', [OrdersController::class, 'edit']);
        Route::delete('/delete/{id}', [OrdersController::class, 'delete']);
        Route::get('/get_order_status/{id}', [OrdersController::class, 'validate_order_status']);
        Route::post('/changeStatus', [OrdersController::class, 'updateStatus']);
    });

    // invoice route
    Route::prefix('invoice')->group(function () {
        Route::post('/create', [InvoiceController::class, 'create']);
        Route::post('/retrieve/{id?}', [InvoiceController::class, 'fetch']);
        Route::post('/update/{id}', [InvoiceController::class, 'edit']);
        Route::delete('/delete/{id}', [InvoiceController::class, 'delete']);
    });
});



