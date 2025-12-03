<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BarcodeController;

Route::redirect('/', '/barcodes');

// List + create
Route::get('/barcodes', [BarcodeController::class, 'index'])->name('barcodes.index');
Route::post('/barcodes', [BarcodeController::class, 'store'])->name('barcodes.store');
Route::get('/barcodes/create',       [BarcodeController::class, 'showForm'])->name('barcodes.create'); // ✅ add this

// Show one job
Route::get('/barcodes/{barcodeJob}', [\App\Http\Controllers\BarcodeController::class, 'show'])
    ->name('barcodes.show');

// Polling endpoint (JSON)
Route::get('/barcodes/{barcodeJob}/status', [BarcodeController::class, 'status'])->name('barcodes.status');

// JSON: latest/paginated job summaries for live updates on the index page
Route::get('/barcodes-feed', [BarcodeController::class, 'feed'])->name('barcodes.feed');

// Download (if you already have a signed URL, skip this)
Route::get('/barcodes/{barcodeJob}/download', [BarcodeController::class, 'download'])->name('barcodes.download');

// Delete
Route::delete('/barcodes/{barcodeJob}', [BarcodeController::class, 'destroy'])->name('barcodes.destroy');
