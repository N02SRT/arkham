<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BarcodeController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/barcodes', [BarcodeController::class, 'showForm'])->name('barcodes.index'); // optional helper
Route::get('/barcodes', [BarcodeController::class, 'showForm'])->name('barcodes.form'); // optional helper
Route::post('/barcodes', [BarcodeController::class, 'store'])->name('barcodes.store');

Route::get('/barcodes/{barcodeJob}', [BarcodeController::class, 'show'])->name('barcodes.show');
Route::get('/barcodes/{barcodeJob}/json', [BarcodeController::class, 'json'])->name('barcodes.json');
Route::get('/barcodes/{barcodeJob}/download', [BarcodeController::class, 'download'])->name('barcodes.download');


Route::get('/dev/upc/{base11}', function (string $base11) {
    abort_unless(preg_match('/^\d{11}$/', $base11), 400, 'base11 must be 11 digits');

    $r   = app(\App\Services\UpcRasterRenderer::class);
    $upc = $r->makeUpc12($base11);

    $jpeg = $r->renderUpcJpeg($upc, resource_path('fonts/OCRB.ttf'));

    return response($jpeg, 200, [
        'Content-Type'  => 'image/jpeg',
        'Cache-Control' => 'no-store',
    ]);
})->where('base11', '\d{11}');
