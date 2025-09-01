<?php

namespace App\Jobs;

use App\Models\BarcodeJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class FinalizeBarcodePackage implements ShouldQueue
{
    use Dispatchable, Queueable, InteractsWithQueue;

    public $timeout = 3600;
    public $tries   = 3;

    public function __construct(
        public string $root,          // e.g. barcodes/order-20250901-044730-mIHh3p  (relative to disk)
        public string $orderNo,
        public string $barcodeJobId
    ) {
        $this->onQueue(config('barcodes.queue', 'barcodes'));
    }

    public function handle(): void
    {
        $lockKey = "barcodes:finalize:lock:{$this->barcodeJobId}";

        // Prefer atomic NX+EX if available
        if (!Redis::set($lockKey, (string) time(), 'EX', 600, 'NX')) {
            Log::info('FinalizeBarcodePackage: another finalizer already running', ['jobRowId' => $this->barcodeJobId]);
            return;
        }

        try {
            Log::info('FinalizeBarcodePackage: start', [
                'root'    => $this->root,
                'jobRowId'=> $this->barcodeJobId,
            ]);

            $disk    = Storage::disk(); // default disk
            $rootRel = $this->root;
            $rootAbs = Storage::path($rootRel);

            if (!is_dir($rootAbs)) {
                throw new \RuntimeException("Root not found: {$rootAbs}");
            }

            // Zip alongside the folder: barcodes/<order>.zip
            $zipRel = dirname($rootRel) . '/' . basename($rootRel) . '.zip';
            $zipAbs = Storage::path($zipRel);

            // Idempotent: remove any existing zip
            if ($disk->exists($zipRel)) {
                $disk->delete($zipRel);
            }
            @mkdir(dirname($zipAbs), 0775, true);

            $zip = new \ZipArchive();
            if ($zip->open($zipAbs, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException("Cannot open zip for write: {$zipAbs}");
            }

            // ONE pass only — include only the file types we care about, keep paths relative to $rootAbs
            $allowed = ['jpg', 'jpeg', 'pdf', 'eps'];

            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($rootAbs, \FilesystemIterator::SKIP_DOTS)
            );

            $count = 0; $bytes = 0;
            foreach ($it as $fsItem) {
                if (!$fsItem->isFile()) continue;

                $ext = strtolower($fsItem->getExtension());
                if (!in_array($ext, $allowed, true)) continue;

                $abs = $fsItem->getPathname();
                // Inside-zip path like: "UPC-12/JPG/UPC-12-XXXX.jpg"
                $rel = ltrim(str_replace($rootAbs, '', $abs), DIRECTORY_SEPARATOR);

                if (!$zip->addFile($abs, $rel)) {
                    Log::warning('FinalizeBarcodePackage: failed adding file to zip', ['file' => $abs]);
                    continue;
                }
                $count++;
                $bytes += (int) @filesize($abs);
            }

            $zip->close();

            if (!$disk->exists($zipRel)) {
                throw new \RuntimeException("Zip not found after close: {$zipAbs}");
            }

            // Mark job finished so UI shows the download button
            BarcodeJob::where('id', $this->barcodeJobId)->update([
                'zip_rel_path' => $zipRel,
                'finished_at'  => now(),
            ]);

            // Push progress to Redis (nice-to-have)
            $bj = BarcodeJob::find($this->barcodeJobId);
            if ($bj && $bj->total_jobs) {
                $k = "barcodes:progress:job:{$this->barcodeJobId}";
                Redis::hset($k, 'done', $bj->total_jobs);
                Redis::expire($k, 86400);
            }

            Log::info('FinalizeBarcodePackage: zip written', [
                'zip'   => $zipRel,
                'files' => $count,
                'bytes' => $bytes,
            ]);
        } catch (\Throwable $e) {
            Log::error('FinalizeBarcodePackage: failed', [
                'root'   => $this->root,
                'error'  => $e->getMessage(),
                'trace'  => $e->getTraceAsString(),
            ]);
            throw $e;
        } finally {
            Redis::del($lockKey);
        }
    }
}
