<?php

namespace App\Jobs;

use App\Models\BarcodeJob;
use App\Services\UpcRasterRenderer;
use App\Services\Ean13RasterRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use App\Services\VectorBarcodeRenderer;

class RenderBarcodeChunk implements ShouldQueue
{
    use Dispatchable, Queueable, Batchable, InteractsWithQueue;

    public $timeout = 3600;
    public $tries   = 3;

    public string $barcodeJobId;

    public function __construct(
        public string $startBase,
        public string $endBase,
        public string $root,
        public string $orderNo,
        string $barcodeJobId,
        public ?int $chunkIndex = null,
    ) {
        $this->barcodeJobId = $barcodeJobId;
        $this->onQueue(config('barcodes.queue', 'barcodes'));
    }

    public function handle(
        UpcRasterRenderer   $upcRenderer,
        Ean13RasterRenderer $eanRenderer,
        VectorBarcodeRenderer $vec
    ): void {
        $disk    = Storage::disk();   // default disk
        $rootRel = $this->root;

        // feature flags
        $makePdf = (bool) config('barcodes.enable_pdf', false);
        $makeEps = (bool) config('barcodes.enable_eps', false);

        // size/quality knobs (optional)
        $optimizePdf = (bool) config('barcodes.optimize_pdf', true);

        $ttf = resource_path('fonts/OCRB.ttf');
        if (!is_readable($ttf)) {
            throw new \RuntimeException("OCRB.ttf not readable at: {$ttf}");
        }

        foreach ([
                     'UPC-12/JPG','UPC-12/PDF','UPC-12/EPS',
                     'EAN-13/JPG','EAN-13/PDF','EAN-13/EPS',
                 ] as $d) {
            $disk->makeDirectory("{$rootRel}/{$d}");
        }

        $gsPath = trim(shell_exec('which gs') ?? '');
        Log::info('RenderBarcodeChunk: start', [
            'batch_id'   => $this->batchId ?? null,
            'job_row'    => $this->barcodeJobId,
            'start'      => $this->startBase,
            'end'        => $this->endBase,
            'root'       => $this->root,
            'enable_pdf' => $makePdf,
            'enable_eps' => $makeEps,
            'opt_pdf'    => $optimizePdf,
            'gs'         => $gsPath ?: 'NOT_FOUND',
        ]);

        $failed = 0;

        $modPt  = (float) config('barcodes.vector_module_pt', 1.0);
        $barHp  = (float) config('barcodes.vector_bar_height_pt', 50);
        $quiet  = (int)   config('barcodes.vector_quiet_modules', 11);
        $font   = (string)config('barcodes.vector_font', 'Helvetica');
        $fontPt = (float) config('barcodes.vector_font_pt', 10.0);
        $gapPt  = (float) config('barcodes.vector_text_gap_pt', 2.0);

        for ($base = $this->startBase; strcmp($base, $this->endBase) <= 0; $base = $this->inc($base)) {
            try {
                $upc12 = $upcRenderer->makeUpc12($base); // 12-digit with check
                $ean13 = '0' . $upc12;                   // 13-digit with leading 0

                // relative paths
                $upcJpgRel = "{$rootRel}/UPC-12/JPG/UPC-12-{$upc12}.jpg";
                $upcPdfRel = "{$rootRel}/UPC-12/PDF/UPC-12-{$upc12}.pdf";
                $upcEpsRel = "{$rootRel}/UPC-12/EPS/UPC-12-{$upc12}.eps";

                $eanJpgRel = "{$rootRel}/EAN-13/JPG/EAN-13-{$ean13}.jpg";
                $eanPdfRel = "{$rootRel}/EAN-13/PDF/EAN-13-{$ean13}.pdf";
                $eanEpsRel = "{$rootRel}/EAN-13/EPS/EAN-13-{$ean13}.eps";

                // absolute paths
                $upcJpgAbs = Storage::path($upcJpgRel);
                $upcPdfAbs = Storage::path($upcPdfRel);
                $upcEpsAbs = Storage::path($upcEpsRel);

                $eanJpgAbs = Storage::path($eanJpgRel);
                $eanPdfAbs = Storage::path($eanPdfRel);
                $eanEpsAbs = Storage::path($eanEpsRel);

                // --- render original JPGs ---
                if (!$disk->exists($upcJpgRel)) {
                    $upcRenderer->render($upc12, $upcJpgAbs, $ttf);
                    Log::info('RenderBarcodeChunk: wrote jpg', ['path' => $upcJpgAbs]);
                }
                if (!$disk->exists($eanJpgRel)) {
                    $eanRenderer->render($ean13, $eanJpgAbs, $ttf);
                    Log::info('RenderBarcodeChunk: wrote jpg', ['path' => $eanJpgAbs]);
                }

                // --- PDFs ---
                if ($makePdf) {
                    if (!$disk->exists($upcPdfRel)) { $vec->renderPdfUpc12($upc12, $upcPdfAbs, $modPt, $barHp, $quiet, true, $font, $fontPt, $gapPt); }
                    if (!$disk->exists($eanPdfRel)) { $vec->renderPdfEan13($ean13, $eanPdfAbs, $modPt, $barHp, $quiet, true, $font, $fontPt, $gapPt); }
                }
                // --- EPS ---
                if ($makeEps) {
                    if (!$disk->exists($upcEpsRel)) { $vec->renderEpsUpc12($upc12, $upcEpsAbs, $modPt, $barHp, $quiet, true, $font, $fontPt, $gapPt); }
                    if (!$disk->exists($eanEpsRel)) { $vec->renderEpsEan13($ean13, $eanEpsAbs, $modPt, $barHp, $quiet, true, $font, $fontPt, $gapPt); }
                }

            } catch (\Throwable $e) {
                $failed++;
                Log::error('RenderBarcodeChunk: render failed', [
                    'base11' => $base,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        if ($failed > 0) {
            BarcodeJob::where('id', $this->barcodeJobId)->increment('failed_jobs', $failed);
        }

        BarcodeJob::where('id', $this->barcodeJobId)->increment('processed_jobs');

        // Optional Redis progress tracking - falls back to database if unavailable
        try {
            $k = "barcodes:progress:job:{$this->barcodeJobId}";
            Redis::hincrby($k, 'done', 1);
            Redis::expire($k, 86400);
        } catch (\Throwable $e) {
            Log::debug('Redis unavailable for progress tracking in RenderBarcodeChunk', [
                'job_id' => $this->barcodeJobId,
                'error' => $e->getMessage(),
            ]);
        }

        $bj = BarcodeJob::find($this->barcodeJobId);
        Log::info('RenderBarcodeChunk: done', [
            'batch_id'       => $this->batchId ?? null,
            'job_row'        => $this->barcodeJobId,
            'processed_jobs' => $bj?->processed_jobs,
            'total_jobs'     => $bj?->total_jobs,
            'failed_in_chunk'=> $failed,
        ]);
    }

    private function inc(string $s): string
    {
        return str_pad((string)((int)$s + 1), 11, '0', STR_PAD_LEFT);
    }

    // --- Converters ---

    /**
     * Write a tiny single-page PDF that embeds the JPG as /Image XObject at 300dpi-equivalent page size,
     * then optionally re-compress/optimize via Ghostscript (PDF->PDF).
     */
    private function jpgToPdf(
        string $jpgAbs,
        string $pdfAbs,
        ?string $gsPath = null,
        bool $optimizeWithGs = true,
        int $jpegQ = 70,
        int $imgDpi = 300,
        bool $logInfo = true
    ): void {
        if (!is_readable($jpgAbs)) {
            throw new \RuntimeException("JPG not readable: " . $this->relFromAbs($jpgAbs));
        }
        @mkdir(dirname($pdfAbs), 0775, true);

        [$w, $h] = @getimagesize($jpgAbs) ?: [0, 0];
        if (!$w || !$h) {
            throw new \RuntimeException("Could not read image size for: {$jpgAbs}");
        }
        $ptsW = $w * 72 / max(1, $imgDpi);
        $ptsH = $h * 72 / max(1, $imgDpi);

        $img = file_get_contents($jpgAbs);
        $len = strlen($img);

        $objs = [];
        $objs[] = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n";
        $objs[] = "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n";
        $objs[] = "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 {$ptsW} {$ptsH}] /Resources << /XObject << /Im1 4 0 R >> /ProcSet [/PDF /ImageC] >> /Contents 5 0 R >> endobj\n";
        $objs[] = "4 0 obj << /Type /XObject /Subtype /Image /Width {$w} /Height {$h} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length {$len} >> stream\n{$img}\nendstream endobj\n";
        $stream = "q {$ptsW} 0 0 {$ptsH} 0 0 cm /Im1 Do Q";
        $objs[] = "5 0 obj << /Length " . strlen($stream) . " >> stream\n{$stream}\nendstream endobj\n";

        $pdf = "%PDF-1.4\n";
        $offs = [0];
        foreach ($objs as $o) { $offs[] = strlen($pdf); $pdf .= $o; }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".count($offs)."\n0000000000 65535 f \n";
        for ($i=1;$i<count($offs);$i++) { $pdf .= sprintf("%010d 00000 n \n", $offs[$i]); }
        $pdf .= "trailer << /Root 1 0 R /Size ".count($offs)." >>\nstartxref\n{$xref}\n%%EOF";

        file_put_contents($pdfAbs, $pdf);
        if (!file_exists($pdfAbs)) {
            throw new \RuntimeException("Failed to write PDF: {$pdfAbs}");
        }
        if ($logInfo) {
            Log::info('RenderBarcodeChunk: wrote pdf (pure-php)', ['path' => $pdfAbs]);
        }

        // Optional optimization via Ghostscript (PDF -> PDF)
        if ($optimizeWithGs && $gsPath) {
            $tmpOptim = $pdfAbs.'.opt.pdf';
            $cmd = escapeshellcmd($gsPath)
                .' -dSAFER -dBATCH -dNOPAUSE'
                .' -sDEVICE=pdfwrite'
                .' -dCompatibilityLevel=1.4'
                .' -dAutoFilterColorImages=false -dColorImageFilter=/DCTEncode'
                .' -dAutoFilterGrayImages=false  -dGrayImageFilter=/DCTEncode'
                .' -dDownsampleColorImages=true -dColorImageResolution='.(int)$imgDpi
                .' -dDownsampleGrayImages=true  -dGrayImageResolution='.(int)$imgDpi
                .' -dJPEGQ='.(int)$jpegQ
                .' -sOutputFile='.escapeshellarg($tmpOptim)
                .' '.escapeshellarg($pdfAbs).' 2>&1';

            exec($cmd, $out, $code);
            if ($code === 0 && file_exists($tmpOptim)) {
                @rename($tmpOptim, $pdfAbs);
            } else {
                @unlink($tmpOptim);
                Log::warning('RenderBarcodeChunk: PDF optimize (gs) skipped/fallback', [
                    'pdf'   => $pdfAbs,
                    'code'  => $code,
                    'out'   => implode("\n", $out),
                ]);
            }
        }
    }

    /**
     * JPG -> (tiny PDF) -> EPS via Ghostscript eps2write,
     * with optional JPEG recompression/downsampling inside the EPS.
     */
    private function jpgToEps(
        string $jpgAbs,
        string $epsAbs,
        string $gsPath = '',
        int $jpegQ = 70,
        int $imgDpi = 300
    ): void {
        if (!is_readable($jpgAbs)) {
            throw new \RuntimeException("JPG not readable: " . $this->relFromAbs($jpgAbs));
        }
        @mkdir(dirname($epsAbs), 0775, true);

        if ($gsPath === '') {
            throw new \RuntimeException("Ghostscript (gs) not found for EPS conversion.");
        }

        $tmpPdf = rtrim(sys_get_temp_dir(), '/').'/sb_tmp_'.bin2hex(random_bytes(6)).'.pdf';
        try {
            // make minimal PDF without extra log noise
            $this->jpgToPdf($jpgAbs, $tmpPdf, $gsPath, false, $jpegQ, $imgDpi, false);

            $cmd = escapeshellcmd($gsPath)
                .' -dSAFER -dBATCH -dNOPAUSE'
                .' -sDEVICE=eps2write'
                .' -dAutoRotatePages=/None'
                // Recompression + downsample
                .' -dAutoFilterColorImages=false -dColorImageFilter=/DCTEncode'
                .' -dAutoFilterGrayImages=false  -dGrayImageFilter=/DCTEncode'
                .' -dDownsampleColorImages=true -dColorImageResolution='.(int)$imgDpi
                .' -dDownsampleGrayImages=true  -dGrayImageResolution='.(int)$imgDpi
                .' -dJPEGQ='.(int)$jpegQ
                .' -sOutputFile='.escapeshellarg($epsAbs)
                .' '.escapeshellarg($tmpPdf).' 2>&1';

            exec($cmd, $out, $code);
            if ($code !== 0 || !file_exists($epsAbs)) {
                throw new \RuntimeException("Ghostscript (PDF→EPS) failed ({$code}): ".implode("\n", $out));
            }

            Log::info('RenderBarcodeChunk: wrote eps (pdf->eps via gs)', ['path' => $epsAbs]);
        } finally {
            @unlink($tmpPdf);
        }
    }

    private function relFromAbs(string $abs): string
    {
        $storage = rtrim(Storage::path(''), '/');
        return ltrim(str_replace($storage, '', $abs), '/');
    }
}
