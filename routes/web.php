<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\CreditPurchaseController;
use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/generations')->name('home');

Route::middleware('auth')->group(function () {
    Route::resource('generations', GenerationController::class)->only(['index', 'store']);
    Route::get('credits', [CreditController::class, 'index'])->name('credits.index');
    Route::resource('credit-purchases', CreditPurchaseController::class)->only(['store']);

    Route::get('admin/report', ReportController::class)->middleware('can:admin')->name('admin.report');
});

require __DIR__.'/settings.php';
