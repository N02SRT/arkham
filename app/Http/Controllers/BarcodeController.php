<?php

namespace App\Http\Controllers;

use App\Jobs\RenderBarcodeChunk;
use App\Jobs\WaitForBatchAndFinalize;
use App\Models\BarcodeJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BarcodeController extends Controller
{

    public function showForm(){
        return view('barcodes.index');
    }
    public function store(Request $req)
    {
        $data = $req->validate([
            'start'     => ['required', 'regex:/^\d{11}$/'],
            'end'       => ['required', 'regex:/^\d{11}$/'],
            'order_no'  => ['required', 'string', 'max:100'],
        ]);
        if (strcmp($data['start'], $data['end']) > 0) {
            return back()->withErrors(['end' => 'End must be >= start.'])->withInput();
        }

        // output dirs
        $outBase = 'order-' . now()->format('Ymd-His') . '-' . Str::random(6);
        $root = "barcodes/{$outBase}";
        foreach (['UPC-12/JPG','UPC-12/PDF','UPC-12/EPS'] as $p) {
            Storage::makeDirectory("{$root}/{$p}");
        }

        // DB row
        $job = BarcodeJob::create([
            'order_no'   => $data['order_no'],
            'root'       => $root,
            'started_at' => now(),
            'total_jobs' => 0,        // will fill below
        ]);

        // chunking
        $chunkSize = (int) config('barcodes.chunk_size', 200);
        $jobs = [];
        for ($cursor = $data['start']; strcmp($cursor, $data['end']) <= 0; $cursor = $this->add($cursor, $chunkSize)) {
            $chunkEnd = $this->add($cursor, $chunkSize - 1);
            if (strcmp($chunkEnd, $data['end']) > 0) $chunkEnd = $data['end'];
            $jobs[] = new RenderBarcodeChunk($cursor, $chunkEnd, $root, $data['order_no'], $job->id);
        }

        $batch = Bus::batch($jobs)
            ->name("barcode-package-{$outBase}")
            ->allowFailures()
            ->onQueue(config('barcodes.queue', 'barcodes'))
            ->then(function (Batch $b) use ($root, $data, $job) {
                // Native completion callback — enqueue the finalizer
                FinalizeBarcodePackage::dispatch($root, $data['order_no'], $job->id)
                    ->onQueue(config('barcodes.queue', 'barcodes'));
            })
            ->dispatch();

// progress seed (for your UI)
        $progressKey = "barcodes:progress:job:{$job->id}";
        Redis::hset($progressKey, 'total', count($jobs));
        Redis::hset($progressKey, 'done', 0);
        Redis::expire($progressKey, 86400);

// (optional) still queue the watcher as a backup
        \App\Jobs\WaitForBatchAndFinalize::dispatch($batch->id, $root, $data['order_no'], $job->id)
            ->onQueue(config('barcodes.queue', 'barcodes'));

        \Log::info('BarcodeController: batch queued', [
            'barcodeJobId' => $job->id,
            'batch_id'     => $batch->id,
            'total_jobs'   => $batch->totalJobs,
            'root'         => $root,
            'order_no'     => $data['order_no'],
        ]);

        WaitForBatchAndFinalize::dispatch($batch->id, $root, $data['order_no'], $job->id)
            ->onQueue(config('barcodes.queue', 'barcodes'));

        $job->update([
            'batch_id'    => $batch->id,
            'total_jobs'  => $batch->totalJobs,
        ]);

        return redirect()->route('barcodes.show', $job->id);
    }

    public function show(BarcodeJob $barcodeJob)
    {
        return view('barcodes.show', ['job' => $barcodeJob]);
    }

    public function json(BarcodeJob $barcodeJob)
    {
        $batch = $barcodeJob->batch_id ? \Illuminate\Support\Facades\Bus::findBatch($barcodeJob->batch_id) : null;

        $zipExists = $barcodeJob->zip_rel_path && Storage::exists($barcodeJob->zip_rel_path);
        if (!$barcodeJob->zip_rel_path) {
            $zipRelGuess = dirname($barcodeJob->root) . '/' . basename($barcodeJob->root) . '.zip';
            if (Storage::exists($zipRelGuess)) {
                $barcodeJob->update(['zip_rel_path' => $zipRelGuess, 'finished_at' => now()]);
                $zipExists = true;
            }
        }

        $k      = "barcodes:progress:job:{$barcodeJob->id}";
        $done   = (int) (Redis::hget($k, 'done')  ?? ($batch?->processedJobs() ?? $barcodeJob->processed_jobs ?? 0));
        $total  = (int) (Redis::hget($k, 'total') ?? ($batch?->totalJobs      ?? $barcodeJob->total_jobs      ?? 0));
        $pct    = $total > 0 ? (int) floor(($done / $total) * 100) : 0;
        if ($zipExists) $pct = 100;

        // keep DB in sync when batch object is available
        if ($batch) {
            $barcodeJob->update([
                'processed_jobs' => $batch->processedJobs(),
                'failed_jobs'    => $batch->failedJobs,
            ]);
        }

        return response()->json([
            'id'              => $barcodeJob->id,
            'order_no'        => $barcodeJob->order_no,
            'batch_id'        => $barcodeJob->batch_id,
            'total_jobs'      => $total,
            'processed_jobs'  => $done,
            'failed_jobs'     => $barcodeJob->failed_jobs,
            'percentage'      => $pct,
            'finished'        => (bool) $barcodeJob->finished_at,
            'zip_url'         => $zipExists ? route('barcodes.download', $barcodeJob->id) : null,
        ]);
    }


    public function download(BarcodeJob $barcodeJob)
    {
        abort_unless($barcodeJob->zip_rel_path && Storage::exists($barcodeJob->zip_rel_path), 404);
        return response()->download(Storage::path($barcodeJob->zip_rel_path));
    }

    private function add(string $s, int $by): string {
        return str_pad((string) ((int)$s + $by), 11, '0', STR_PAD_LEFT);
    }
}
