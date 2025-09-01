<?php

namespace App\Jobs;

use App\Models\BarcodeJob;
use App\Services\UpcRasterRenderer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class RenderBarcodeChunk implements ShouldQueue
{
    use Dispatchable, Queueable, Batchable;

    public $timeout = 3600;
    public $tries   = 3;

    public function __construct(
        public string $startBase,       // 11-digit start
        public string $endBase,         // 11-digit end (inclusive)
        public string $root,            // barcodes/order-...
        public string $orderNo,
        public string $barcodeJobId
    ) {
        $this->onQueue(config('barcodes.queue', 'barcodes'));
    }

    public function handle(\App\Services\UpcRasterRenderer $renderer): void
    {
        $ttf = resource_path('fonts/OCRB.ttf');
        if (!is_readable($ttf)) {
            throw new \RuntimeException("OCRB.ttf not readable at: {$ttf}");
        }

        // ensure directories (JPG + PDF + EPS)
        Storage::makeDirectory($this->root . '/UPC-12/JPG');
        Storage::makeDirectory($this->root . '/UPC-12/PDF');
        Storage::makeDirectory($this->root . '/UPC-12/EPS');

        // feature flags (default ON)
        $makePdf = (bool) (config('barcodes.make_pdf', env('BARCODES_MAKE_PDF', true)));
        $makeEps = (bool) (config('barcodes.make_eps', env('BARCODES_MAKE_EPS', true)));

        $pdfRenderer = app(\App\Services\UpcPdfRenderer::class);
        $epsRenderer = app(\App\Services\UpcEpsRenderer::class);

        for ($base = $this->startBase; strcmp($base, $this->endBase) <= 0; $base = $this->inc($base)) {
            $upc = $renderer->makeUpc12($base);

            $jpgRel = "{$this->root}/UPC-12/JPG/UPC-12-{$upc}.jpg";
            $pdfRel = "{$this->root}/UPC-12/PDF/UPC-12-{$upc}.pdf";
            $epsRel = "{$this->root}/UPC-12/EPS/UPC-12-{$upc}.eps";

            $jpgAbs = Storage::path($jpgRel);
            $pdfAbs = Storage::path($pdfRel);
            $epsAbs = Storage::path($epsRel);

            try {
                // Render raster barcode first
                $renderer->render($upc, $jpgAbs, $ttf);
                Log::info('RenderBarcodeChunk: wrote jpg', ['path' => $jpgAbs]);

                // Then wrap into PDF/EPS if enabled
                if ($makePdf) {
                    try {
                        $pdfRenderer->jpgToPdf($jpgAbs, $pdfAbs);
                        Log::info('RenderBarcodeChunk: wrote pdf', ['path' => $pdfAbs]);
                    } catch (\Throwable $e) {
                        Log::warning('RenderBarcodeChunk: pdf failed', ['upc' => $upc, 'err' => $e->getMessage()]);
                        \App\Models\BarcodeJob::where('id', $this->barcodeJobId)->increment('failed_jobs');
                    }
                }

                if ($makeEps) {
                    try {
                        $epsRenderer->jpgToEps($jpgAbs, $epsAbs);
                        Log::info('RenderBarcodeChunk: wrote eps', ['path' => $epsAbs]);
                    } catch (\Throwable $e) {
                        Log::warning('RenderBarcodeChunk: eps failed', ['upc' => $upc, 'err' => $e->getMessage()]);
                        \App\Models\BarcodeJob::where('id', $this->barcodeJobId)->increment('failed_jobs');
                    }
                }
            } catch (\Throwable $e) {
                Log::error('RenderBarcodeChunk: render failed', [
                    'base11' => $base, 'upc' => $upc, 'error' => $e->getMessage(),
                ]);
                \App\Models\BarcodeJob::where('id', $this->barcodeJobId)->increment('failed_jobs');
            }
        }

        // DB progress (+1 chunk)
        \App\Models\BarcodeJob::where('id', $this->barcodeJobId)->increment('processed_jobs');

        // Redis progress (+1 chunk)
        $k = "barcodes:progress:job:{$this->barcodeJobId}";
        \Illuminate\Support\Facades\Redis::hincrby($k, 'done', 1);
        \Illuminate\Support\Facades\Redis::expire($k, 86400);
    }



    private function inc(string $s): string
    {
        return str_pad((string)((int)$s + 1), 11, '0', STR_PAD_LEFT);
    }
}
