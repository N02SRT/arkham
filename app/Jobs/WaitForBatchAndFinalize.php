<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Redis;
use App\Models\BarcodeJob;

class WaitForBatchAndFinalize implements ShouldQueue
{
    use Dispatchable, Queueable, InteractsWithQueue;

    public $timeout = 60;
    public $tries   = 10000;

    public function __construct(
        public string $batchId,
        public string $root,            // e.g. barcodes/order-...
        public string $orderNo,
        public string $barcodeJobId
    ) {
        $this->onQueue(config('barcodes.queue', 'barcodes'));
    }

    public function backoff(): int|array { return 15; }

    public function handle(): void
    {
        // 1) Read Redis progress hash for THIS job id (optional: falls back gracefully if Redis is unavailable)
        $k = "barcodes:progress:job:{$this->barcodeJobId}";
        $done  = 0;
        $total = 0;
        try {
            $done  = (int) (Redis::hget($k, 'done')  ?? 0);
            $total = (int) (Redis::hget($k, 'total') ?? 0);
        } catch (\Throwable $e) {
            Log::debug('WaitForBatchAndFinalize: Redis unavailable for progress read, using DB only', [
                'job_id' => $this->barcodeJobId,
                'error'  => $e->getMessage(),
            ]);
        }

        // 2) DB fallback (in case Redis 'done' isn't being incremented yet)
        $bj = BarcodeJob::find($this->barcodeJobId);
        if ($bj) {
            if ($total === 0) $total = (int) $bj->total_jobs;
            // Use the larger of Redis and DB for 'done'
            $done = max($done, (int) $bj->processed_jobs);
        }

        Log::info('WaitForBatchAndFinalize: progress', [
            'done' => $done, 'total' => $total, 'db_processed' => $bj?->processed_jobs ?? 0,
        ]);

        // 3) Idempotency: if zip already exists, we’re finished
        $zipRel = dirname($this->root) . '/' . basename($this->root) . '.zip'; // barcodes/order-....zip
        if (Storage::exists($zipRel)) {
            return;
        }

        // 4) All chunks finished → dispatch finalizer once
        if ($total > 0 && $done >= $total) {
            Log::info('WaitForBatchAndFinalize: all chunks done; dispatching finalizer', [
                'jobRowId' => $this->barcodeJobId,
            ]);
            FinalizeBarcodePackage::dispatch($this->root, $this->orderNo, $this->barcodeJobId)
                ->onQueue(config('barcodes.queue', 'barcodes'));
            return;
        }

        // 5) Not done yet → recheck later
        $this->release($this->backoff());
    }
}
