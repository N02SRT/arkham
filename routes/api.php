<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BarcodeController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Barcode job API endpoints
Route::post('/barcodes', [BarcodeController::class, 'apiStore'])->name('api.barcodes.store');
Route::get('/barcodes/{barcodeJob}', [BarcodeController::class, 'apiShow'])->name('api.barcodes.show');
Route::get('/barcodes/{barcodeJob}/status', [BarcodeController::class, 'status'])->name('api.barcodes.status');
Route::get('/barcodes/{barcodeJob}/download', [BarcodeController::class, 'apiDownload'])->name('api.barcodes.download');

