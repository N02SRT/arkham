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
use Illuminate\Support\Facades\Http;

class FinalizeBarcodePackage implements ShouldQueue
{
    use Dispatchable, Queueable, InteractsWithQueue;

    public $timeout = 3600;
    public $tries   = 3;

    public function __construct(
        public string $root,          // e.g. barcodes/order-20250901-044730-XXXXXX
        public string $orderNo,
        public string $barcodeJobId
    ) {
        $this->onQueue(config('barcodes.queue', 'barcodes'));
    }

    public function handle(): void
    {
        $lockKey = "barcodes:finalize:lock:{$this->barcodeJobId}";
        if (!Redis::setnx($lockKey, (string) time())) {
            Log::info('FinalizeBarcodePackage: another finalizer already running', ['jobRowId' => $this->barcodeJobId]);
            return;
        }
        Redis::expire($lockKey, 600);

        $diskName = config('barcodes.disk', config('filesystems.default'));
        $disk     = Storage::disk($diskName);

        try {
            Log::info('FinalizeBarcodePackage: start', [
                'root'    => $this->root,
                'jobRowId'=> $this->barcodeJobId,
                'disk'    => $diskName,
            ]);

            $rootRel = $this->root;
            $rootAbs = $disk->path($rootRel);
            if (!is_dir($rootAbs)) {
                throw new \RuntimeException("Root not found: {$rootAbs}");
            }

            // 1) Ensure directory skeleton (matches the sample package layout)
            $this->ensureSkeleton($disk, $rootRel);

            // 2) Copy top-level extras (rename invoice/certificate to include order #)
            $this->copyTopLevelExtras($disk, $rootRel, $this->orderNo);

            // 3) Create UPC number list CSV (saved with .xls extension like the sample)
            $this->writeUpcNumberListXls($disk, $rootRel, $this->orderNo);

            // 4) Build ZIP
            $zipRel = dirname($rootRel) . '/' . basename($rootRel) . '.zip';
            if ($disk->exists($zipRel)) {
                $disk->delete($zipRel);
            }
            $zipAbs = $disk->path($zipRel);

            // Pre-count for logging (also useful when 7z path is used)
            [$expectedFiles, $expectedBytes] = $this->countFilesAndBytes($rootAbs);

            // Prefer multi-core 7-Zip if present
            $usedSevenZ = $this->buildZipMultiCore($rootAbs, $zipAbs);
            $added = $expectedFiles;
            $bytes = $expectedBytes;

            if (!$usedSevenZ) {
                // Fallback to ZipArchive (single-threaded)
                [$added, $bytes] = $this->buildZipWithZipArchive($rootAbs, $zipAbs);
            }

            if (!$disk->exists($zipRel)) {
                throw new \RuntimeException("Zip not found after close: {$zipAbs}");
            }

            // 5) Update DB so the UI shows the download button
            $bj = BarcodeJob::find($this->barcodeJobId);
            $bj?->update([
                'zip_rel_path' => $zipRel,
                'finished_at'  => now(),
            ]);

            // Mark counters complete in Redis (nice-to-have)
            $k = "barcodes:progress:job:{$this->barcodeJobId}";
            if ($bj && $bj->total_jobs) {
                Redis::hset($k, 'done', $bj->total_jobs);
                Redis::expire($k, 86400);
            }

            // 6) Optional callback to upstream system (e.g. Speedy) when ready
            $cbKey = "barcodes:callback:job:{$this->barcodeJobId}";
            try {
                $callbackUrl = Redis::hget($cbKey, 'url');
                if ($callbackUrl) {
                    $callbackToken = Redis::hget($cbKey, 'token') ?: null;

                    $payload = [
                        'job_id'       => $this->barcodeJobId,
                        'order_no'     => $this->orderNo,
                        'status'       => 'ready',
                        'download_url' => route('api.barcodes.download', $this->barcodeJobId),
                        'finished_at'  => $bj?->finished_at?->toISOString(),
                    ];

                    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

                    // If a per-job callback token was provided, sign the payload with HMAC-SHA256
                    $signature = null;
                    if ($callbackToken) {
                        $signature = hash_hmac('sha256', $json, $callbackToken);
                    }

                    $client = Http::timeout((int) config('barcodes.callback_timeout', 5));
                    if ($signature) {
                        $client = $client->withHeaders([
                            'X-Arkham-Signature' => $signature,
                        ]);
                    }

                    $response = $client->post($callbackUrl, $payload);

                    Log::info('FinalizeBarcodePackage: callback invoked', [
                        'jobRowId'    => $this->barcodeJobId,
                        'order_no'    => $this->orderNo,
                        'url'         => $callbackUrl,
                        'status_code' => $response->status(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('FinalizeBarcodePackage: callback failed', [
                    'jobRowId' => $this->barcodeJobId,
                    'order_no' => $this->orderNo,
                    'error'    => $e->getMessage(),
                ]);
            }

            Log::info('FinalizeBarcodePackage: zip written', [
                'zip'   => $zipRel,
                'files' => $added,
                'bytes' => $bytes,
                'engine'=> $usedSevenZ ? '7z' : 'ZipArchive',
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

    /**
     * Create the directory skeleton that mirrors the sample package.
     */
    private function ensureSkeleton($disk, string $rootRel): void
    {
        $dirs = [
            'UPC-12/JPG', 'UPC-12/PDF', 'UPC-12/EPS',
            'EAN-13/JPG', 'EAN-13/PDF', 'EAN-13/EPS',
            'Speedy Extras',
        ];
        foreach ($dirs as $d) {
            $disk->makeDirectory(trim($rootRel . '/' . $d, '/'));
        }
    }

    /**
     * Copy top-level PDFs if present in resources/barcodes.
     * - !Read Me First.pdf
     * - Speedy Invoice-Sample.pdf   -> Speedy Invoice-<ORDER>.pdf
     * - Speedy Certificate-Sample.pdf -> Speedy Certificate-<ORDER>.pdf
     */
    private function copyTopLevelExtras($disk, string $rootRel, string $orderNo): void
    {
        $srcBase = resource_path('barcodes');

        $map = [
            '!Read Me First.pdf'            => '!Read Me First.pdf',
            'Speedy Invoice-Sample.pdf'     => 'Speedy Invoice-' . $orderNo . '.pdf',
            'Speedy Certificate-Sample.pdf' => 'Speedy Certificate-' . $orderNo . '.pdf',
        ];

        foreach ($map as $srcName => $destName) {
            $src = $srcBase . DIRECTORY_SEPARATOR . $srcName;
            $destRel = $rootRel . '/' . $destName;
            if (is_readable($src) && !$disk->exists($destRel)) {
                $disk->put($destRel, file_get_contents($src));
                Log::info('FinalizeBarcodePackage: copied top-level extra', ['to' => $destRel]);
            }
        }
    }

    /**
     * Produce a simple CSV (saved with .xls extension like the sample) for the UPCs we generated.
     * Can generate from numeric range (preferred) or by scanning JPG files (fallback).
     */
    private function writeUpcNumberListXls($disk, string $rootRel, string $orderNo): void
    {
        // Check per-job options to see if XLS is desired
        $optKey = "barcodes:options:job:{$this->barcodeJobId}";
        $codes = [];
        
        try {
            $xlsOpt = Redis::hget($optKey, 'xls');
            if ($xlsOpt === '0') {
                Log::info('FinalizeBarcodePackage: XLS generation disabled for job', [
                    'jobRowId' => $this->barcodeJobId,
                    'root'     => $rootRel,
                ]);
                return;
            }
            
            // Try to get start/end range from Redis (preferred method)
            $start = Redis::hget($optKey, 'start');
            $end = Redis::hget($optKey, 'end');
            
            if ($start && $end && preg_match('/^\d{11}$/', $start) && preg_match('/^\d{11}$/', $end)) {
                // Generate codes directly from the numeric range
                for ($base = $start; strcmp($base, $end) <= 0; $base = $this->incBase($base)) {
                    $codes[] = $this->makeUpc12($base);
                }
                Log::info('FinalizeBarcodePackage: generated XLS from numeric range', [
                    'jobRowId' => $this->barcodeJobId,
                    'start'    => $start,
                    'end'      => $end,
                    'count'    => count($codes),
                ]);
            } else {
                // Fallback: scan JPG files if range not available
                $jpgDirRel = $rootRel . '/UPC-12/JPG';
                if ($disk->exists($jpgDirRel)) {
                    $files = $disk->files($jpgDirRel);
                    foreach ($files as $rel) {
                        $bn = basename($rel);
                        if (preg_match('/^UPC-12-(\d{12})\.jpg$/', $bn, $m)) {
                            $codes[] = $m[1];
                        }
                    }
                    sort($codes, SORT_STRING);
                    Log::info('FinalizeBarcodePackage: generated XLS from JPG filenames (fallback)', [
                        'jobRowId' => $this->barcodeJobId,
                        'count'    => count($codes),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // If Redis unavailable, fall back to scanning JPG files
            Log::warning('FinalizeBarcodePackage: failed to read options; falling back to JPG scan', [
                'jobRowId' => $this->barcodeJobId,
                'error'    => $e->getMessage(),
            ]);
            
            $jpgDirRel = $rootRel . '/UPC-12/JPG';
            if ($disk->exists($jpgDirRel)) {
                $files = $disk->files($jpgDirRel);
                foreach ($files as $rel) {
                    $bn = basename($rel);
                    if (preg_match('/^UPC-12-(\d{12})\.jpg$/', $bn, $m)) {
                        $codes[] = $m[1];
                    }
                }
                sort($codes, SORT_STRING);
            }
        }

        if (empty($codes)) {
            Log::warning('FinalizeBarcodePackage: no UPC codes found for XLS generation', [
                'jobRowId' => $this->barcodeJobId,
                'root'     => $rootRel,
            ]);
            return;
        }

        // CSV content with header
        $csv = "UPC-12\n" . implode("\n", $codes) . "\n";
        $xlsRel = $rootRel . '/UPC-12/UPC-12 XLS Number List - Order # ' . $orderNo . '.xls';
        $disk->put($xlsRel, $csv);
        Log::info('FinalizeBarcodePackage: wrote UPC number list (xls-as-csv)', [
            'file'  => $xlsRel,
            'count' => count($codes),
        ]);
    }

    /**
     * Build 12-digit UPC-A from 11-digit base (same algorithm as UpcRasterRenderer).
     */
    private function makeUpc12(string $base11): string
    {
        if (!preg_match('/^\d{11}$/', $base11)) {
            throw new \InvalidArgumentException('UPC base must be exactly 11 digits.');
        }
        $d = array_map('intval', str_split($base11));
        $sumOdd  = $d[0]+$d[2]+$d[4]+$d[6]+$d[8]+$d[10];
        $sumEven = $d[1]+$d[3]+$d[5]+$d[7]+$d[9];
        $check = (10 - ((($sumOdd * 3) + $sumEven) % 10)) % 10;
        return $base11 . $check;
    }

    /**
     * Increment an 11-digit base string by 1 (preserving zero padding).
     */
    private function incBase(string $base11): string
    {
        $n = (int) $base11 + 1;
        return str_pad((string)$n, 11, '0', STR_PAD_LEFT);
    }

    /**
     * Try multi-core zipping via 7-Zip (7zz or 7z).
     * Returns true on success (zip exists), false if 7z not available or failed.
     */
    private function buildZipMultiCore(string $rootAbs, string $zipAbs): bool
    {
        $sevenZ = trim(shell_exec('command -v 7zz || command -v 7z') ?? '');
        if ($sevenZ === '') {
            Log::info('FinalizeBarcodePackage: 7z/7zz not found; falling back to ZipArchive');
            return false;
        }

        // Threads (use all cores if possible)
        $threads = (int) (trim(shell_exec('nproc') ?? '0')) ?: 0;
        $threadsArg = $threads > 0 ? " -mmt={$threads}" : " -mmt=on";

        // Compression strategy:
        //   copy   -> fastest (store only)
        //   deflate-> smaller but slower
        $mode   = config('barcodes.zip.compression', 'copy'); // 'copy' | 'deflate'
        $level  = (int) config('barcodes.zip.level', 3);      // 0..9 (deflate only)
        $method = $mode === 'deflate'
            ? " -mm=Deflate -mx={$level}"
            : " -mm=Copy -mx=0";

        // Create parent directory for zip if needed
        @mkdir(dirname($zipAbs), 0775, true);

        $cwd = getcwd();
        chdir($rootAbs);

        // Add everything under the order folder (relative paths)
        $cmd = escapeshellcmd($sevenZ) . " a -tzip{$threadsArg}{$method} " .
            escapeshellarg($zipAbs) . " -r . 2>&1";

        exec($cmd, $out, $code);
        chdir($cwd);

        Log::info('FinalizeBarcodePackage: 7z result', [
            'cmd' => $cmd,
            'exit' => $code,
            'first_lines' => array_slice($out, 0, 8),
        ]);

        return $code === 0 && file_exists($zipAbs);
    }

    /**
     * Single-threaded zipping via ZipArchive with smart per-file compression.
     * Returns [filesAdded, totalBytes].
     */
    private function buildZipWithZipArchive(string $rootAbs, string $zipAbs): array
    {
        @mkdir(dirname($zipAbs), 0775, true);

        $zip = new \ZipArchive();
        if ($zip->open($zipAbs, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot open zip for write: {$zipAbs}");
        }

        $added = 0; $bytes = 0;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootAbs, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($it as $fsItem) {
            $realPath = $fsItem->getPathname();
            $relPath  = ltrim(str_replace($rootAbs, '', $realPath), '/\\');

            if ($fsItem->isDir()) {
                $zip->addEmptyDir($relPath);
                continue;
            }

            if ($zip->addFile($realPath, $relPath)) {
                // Store already-compressed formats to avoid wasted CPU
                $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','pdf','eps','zip'])) {
                    // CM_STORE = 0
                    if (method_exists($zip, 'setCompressionName')) {
                        $zip->setCompressionName($relPath, \ZipArchive::CM_STORE);
                    }
                } else {
                    if (method_exists($zip, 'setCompressionName')) {
                        $zip->setCompressionName($relPath, \ZipArchive::CM_DEFLATE);
                    }
                }

                $added++;
                $bytes += filesize($realPath) ?: 0;
            } else {
                Log::warning('FinalizeBarcodePackage: failed adding file to zip', ['file' => $realPath]);
            }
        }

        $zip->close();
        return [$added, $bytes];
    }

    /**
     * Count files/bytes under a directory (for logging & expectations).
     */
    private function countFilesAndBytes(string $rootAbs): array
    {
        $count = 0; $bytes = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootAbs, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $fsItem) {
            if ($fsItem->isFile()) {
                $count++;
                $bytes += filesize($fsItem->getPathname()) ?: 0;
            }
        }
        return [$count, $bytes];
    }
}
