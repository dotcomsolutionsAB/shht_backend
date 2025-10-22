<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\UserController;
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

    // users route
    Route::prefix('users')->group(function () {
        Route::post('/create', [UserController::class, 'create']);
        Route::post('/fetch/{id?}', [UserController::class, 'list']);
        Route::post('/edit/{id}', [UserController::class, 'update']);
        Route::delete('/delete/{id}', [UserController::class, 'delete']);
        Route::post('/reset_password', [AuthController::class, 'updatePassword']);
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    // category route
    Route::prefix('category')->group(function () {
            Route::post('/create', [CategoryController::class, 'create']);
            Route::post('/fetch/{id?}', [CategoryController::class, 'list']);
            Route::post('/edit/{id}', [CategoryController::class, 'update']);
            Route::delete('/delete/{id}', [CategoryController::class, 'delete']);
    });

    // sub-category route
    Route::prefix('sub_category')->group(function () {
        Route::post('/create', [SubCategoryController::class, 'create']);
        Route::post('/fetch/{id?}', [SubCategoryController::class, 'list']);
        Route::post('/edit/{id}', [SubCategoryController::class, 'update']);
        Route::delete('/delete/{id}', [SubCategoryController::class, 'delete']);
    });

    // tags route
    Route::prefix('tags')->group(function () {
        Route::post('/create', [TagsController::class, 'create']);
        Route::post('/fetch/{id?}', [TagsController::class, 'list']);
        Route::post('/edit/{id}', [TagsController::class, 'update']);
        Route::delete('/delete/{id}', [TagsController::class, 'delete']);
    });

    // clients route
    Route::prefix('clients')->group(function () {
        Route::post('/create', [ClientsController::class, 'create']);
        Route::post('/fetch/{id?}', [ClientsController::class, 'list']);
        Route::post('/edit/{id}', [ClientsController::class, 'update']);
        Route::delete('/delete/{id}', [ClientsController::class, 'delete']);

        // clients-contact-person route
        Route::prefix('contact_person')->group(function () {
            Route::post('/create', [ClientContactPersonController::class, 'create']);
            Route::post('/fetch/{id?}', [ClientContactPersonController::class, 'list']);
            Route::post('/edit/{id}', [ClientContactPersonController::class, 'update']);
            Route::delete('/delete/{id}', [ClientContactPersonController::class, 'delete']);
        });
    });
    
    // orders route
    Route::prefix('orders')->group(function () {
        Route::post('/create', [OrdersController::class, 'create']);
        Route::post('/fetch/{id?}', [OrdersController::class, 'list']);
        Route::post('/edit/{id}', [OrdersController::class, 'update']);
        Route::delete('/delete/{id}', [OrdersController::class, 'delete']);
    });

    // invoice route
    Route::prefix('invoice')->group(function () {
        Route::post('/create', [InvoiceController::class, 'create']);
        Route::post('/fetch/{id?}', [InvoiceController::class, 'list']);
        Route::post('/edit/{id}', [InvoiceController::class, 'update']);
        Route::delete('/delete/{id}', [InvoiceController::class, 'delete']);
    });
});
