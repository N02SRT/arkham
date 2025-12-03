<?php

namespace App\Http\Controllers;

use App\Jobs\FinalizeBarcodePackage;
use App\Jobs\RenderBarcodeChunk;
use App\Jobs\WaitForBatchAndFinalize;
use App\Models\BarcodeJob;
use Illuminate\Bus\Batch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BarcodeController extends Controller
{
    // index: search + paginate
    public function index(\Illuminate\Http\Request $req) {
        $q = $req->string('q');
        $jobs = \App\Models\BarcodeJob::query()
            ->when($q, fn($x)=>$x->where('order_no','like',"%{$q}%")->orWhere('id',$q))
            ->latest()->paginate(10);
        return view('barcodes.index', compact('jobs'));
    }

// show
    public function show(\App\Models\BarcodeJob $barcodeJob)
    {
        $job = $barcodeJob;
        $samples = [];
        // Try barcodes/show first; fall back to legacy show.blade.php
        return view()->first(['barcodes.show', 'show'], compact('job','samples'));
    }

// live status
    public function status(Request $req, \App\Models\BarcodeJob $barcodeJob)
    {
        // Log status check request (used by both web and API)
        if ($req->is('api/*')) {
            Log::info('API: Barcode job status check', [
                'method'     => $req->method(),
                'path'       => $req->path(),
                'ip'         => $req->ip(),
                'user_agent' => $req->userAgent(),
                'job_id'     => $barcodeJob->id,
                'order_no'   => $barcodeJob->order_no,
                'timestamp'  => now()->toISOString(),
            ]);
        }

        return response()->json([
            'batch_id'       => $barcodeJob->batch_id,
            'processed_jobs' => $barcodeJob->processed_jobs ?? 0,
            'total_jobs'     => $barcodeJob->total_jobs ?? 0,
            'failed_jobs'    => $barcodeJob->failed_jobs ?? 0,
            'ready'          => $barcodeJob->zip_rel_path && Storage::exists($barcodeJob->zip_rel_path),
        ]);
    }

    public function destroy(\App\Models\BarcodeJob $barcodeJob) {
        // optionally clean files: Storage::disk('s3')->deleteDirectory("barcodes/{$barcodeJob->id}");
        $barcodeJob->delete();
        return redirect()->route('barcodes.index')->with('ok','Job deleted.');
    }

    public function showForm()
    {
        return view('barcodes.index');
    }

    public function store(Request $req)
    {
        $data = $req->validate([
            'start'     => ['required', 'regex:/^\d{11}$/'],
            'end'       => ['required', 'regex:/^\d{11}$/'],
        ]);

        if (strcmp($data['start'], $data['end']) > 0) {
            return back()->withErrors(['end' => 'End must be >= start.'])->withInput();
        }

        // output dirs
        $outBase = 'order-' . now()->format('Ymd-His') . '-' . Str::random(6);
        $root = "barcodes/{$outBase}";
        foreach (['UPC-12/JPG', 'UPC-12/PDF', 'UPC-12/EPS'] as $p) {
            Storage::makeDirectory("{$root}/{$p}");
        }

        // DB row
        $job = BarcodeJob::create([
            'order_no'   => $data['order_no'],
            'root'       => $root,
            'started_at' => now(),
            'total_jobs' => 0, // will fill below
        ]);

        // chunking
        $chunkSize = (int) config('barcodes.chunk_size', 200);
        $jobs = [];
        for ($cursor = $data['start']; strcmp($cursor, $data['end']) <= 0; $cursor = $this->add($cursor, $chunkSize)) {
            $chunkEnd = $this->add($cursor, $chunkSize - 1);
            if (strcmp($chunkEnd, $data['end']) > 0) {
                $chunkEnd = $data['end'];
            }
            $jobs[] = new RenderBarcodeChunk($cursor, $chunkEnd, $root, $data['order_no'], $job->id);
        }

        $batch = Bus::batch($jobs)
            ->name("barcode-package-{$outBase}")
            ->allowFailures()
            ->onQueue(config('barcodes.queue', 'barcodes'))
            ->then(function (Batch $batch) use ($root, $data, $job) {
                // Native completion callback — enqueue the finalizer
                FinalizeBarcodePackage::dispatch($root, $data['order_no'], $job->id)
                    ->onQueue(config('barcodes.queue', 'barcodes'));
            })
            ->dispatch();

        // progress seed (for your UI) - optional, falls back to batch if Redis unavailable
        try {
            $progressKey = "barcodes:progress:job:{$job->id}";
            Redis::hset($progressKey, 'total', count($jobs));
            Redis::hset($progressKey, 'done', 0);
            Redis::expire($progressKey, 86400);
        } catch (\Throwable $e) {
            Log::warning('Redis unavailable for progress tracking, will use batch status', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);
        }

        // (optional) queue the watcher as a backup
        WaitForBatchAndFinalize::dispatch($batch->id, $root, $data['order_no'], $job->id)
            ->onQueue(config('barcodes.queue', 'barcodes'));

        \Log::info('BarcodeController: batch queued', [
            'barcodeJobId' => $job->id,
            'batch_id'     => $batch->id,
            'total_jobs'   => $batch->totalJobs,
            'root'         => $root,
            'order_no'     => $data['order_no'],
        ]);

        $job->update([
            'batch_id'   => $batch->id,
            'total_jobs' => $batch->totalJobs,
        ]);

        return response()->json(['redirect' => route('barcodes.show', $job)]);
    }

    public function json(BarcodeJob $barcodeJob)
    {
        $batch = $barcodeJob->batch_id
            ? \Illuminate\Support\Facades\Bus::findBatch($barcodeJob->batch_id)
            : null;

        $zipExists = $barcodeJob->zip_rel_path && Storage::exists($barcodeJob->zip_rel_path);
        if (!$barcodeJob->zip_rel_path) {
            $zipRelGuess = dirname($barcodeJob->root) . '/' . basename($barcodeJob->root) . '.zip';
            if (Storage::exists($zipRelGuess)) {
                $barcodeJob->update(['zip_rel_path' => $zipRelGuess, 'finished_at' => now()]);
                $zipExists = true;
            }
        }

        $k     = "barcodes:progress:job:{$barcodeJob->id}";
        try {
            $done  = (int) (Redis::hget($k, 'done') ?? ($batch?->processedJobs() ?? $barcodeJob->processed_jobs ?? 0));
            $total = (int) (Redis::hget($k, 'total') ?? ($batch?->totalJobs ?? $barcodeJob->total_jobs ?? 0));
        } catch (\Throwable $e) {
            // Fall back to batch or database values if Redis unavailable
            $done  = (int) ($batch?->processedJobs() ?? $barcodeJob->processed_jobs ?? 0);
            $total = (int) ($batch?->totalJobs ?? $barcodeJob->total_jobs ?? 0);
        }
        $pct   = $total > 0 ? (int) floor(($done / $total) * 100) : 0;
        if ($zipExists) {
            $pct = 100;
        }

        // keep DB in sync when batch object is available
        if ($batch) {
            $barcodeJob->update([
                'processed_jobs' => $batch->processedJobs(),
                'failed_jobs'    => $batch->failedJobs,
            ]);
        }

        return response()->json([
            'id'             => $barcodeJob->id,
            'order_no'       => $barcodeJob->order_no,
            'batch_id'       => $barcodeJob->batch_id,
            'total_jobs'     => $total,
            'processed_jobs' => $done,
            'failed_jobs'    => $barcodeJob->failed_jobs,
            'percentage'     => $pct,
            'finished'       => (bool) $barcodeJob->finished_at,
            'zip_url'        => $zipExists ? route('barcodes.download', $barcodeJob->id) : null,
        ]);
    }

    public function download(\App\Models\BarcodeJob $barcodeJob)
    {
        abort_unless($barcodeJob->zip_rel_path && Storage::exists($barcodeJob->zip_rel_path), 404);

        $name = 'barcodes-'.$barcodeJob->order_no.'-'.$barcodeJob->id.'.zip';
        return response()->download(
            Storage::path($barcodeJob->zip_rel_path),
            $name,
            ['Content-Type' => 'application/zip']
        );
    }


    private function add(string $s, int $by): string
    {
        return str_pad((string) ((int) $s + $by), 11, '0', STR_PAD_LEFT);
    }

    private function optimizePdfWithGs(string $inPdf, string $outPdf, string $gsPath, int $jpegQ = 70, int $imgDpi = 300): void
    {
        @mkdir(dirname($outPdf), 0775, true);

        $cmd = escapeshellcmd($gsPath)
            .' -dSAFER -dBATCH -dNOPAUSE'
            .' -sDEVICE=pdfwrite'
            .' -dCompatibilityLevel=1.4'
            // Recompress color/gray images with JPEG at desired quality
            .' -dAutoFilterColorImages=false -dColorImageFilter=/DCTEncode'
            .' -dAutoFilterGrayImages=false  -dGrayImageFilter=/DCTEncode'
            .' -dDownsampleColorImages=true -dColorImageResolution='.(int)$imgDpi
            .' -dDownsampleGrayImages=true  -dGrayImageResolution='.(int)$imgDpi
            .' -dJPEGQ='.(int)$jpegQ
            .' -sOutputFile='.escapeshellarg($outPdf)
            .' '.escapeshellarg($inPdf).' 2>&1';

        exec($cmd, $out, $code);
        if ($code !== 0 || !file_exists($outPdf)) {
            throw new \RuntimeException("Ghostscript optimize PDF failed ({$code}): ".implode("\n", $out));
        }
    }

    public function feed(Request $req)
    {
        // Same filter as index()
        $q = $req->string('q');
        $jobs = BarcodeJob::query()
            ->when($q, fn($x)=>$x->where('order_no','like',"%{$q}%")->orWhere('id',$q))
            ->latest()->paginate(10);

        // Return a compact JSON payload used by the index poller
        return response()->json([
            'html' => view('barcodes.partials.table-rows', ['jobs' => $jobs])->render(),
            'pagination' => (string) $jobs->withQueryString()->links(),
        ]);
    }

    /**
     * API endpoint to process barcode jobs
     * POST /api/barcodes
     */
    public function apiStore(Request $req)
    {
        // Log incoming API request
        Log::info('API: Barcode job creation request', [
            'method'     => $req->method(),
            'path'       => $req->path(),
            'ip'         => $req->ip(),
            'user_agent' => $req->userAgent(),
            'request_data' => $req->only(['order_no', 'start', 'end', 'callback_url', 'formats']),
            'timestamp'  => now()->toISOString(),
        ]);

        try {
            $data = $req->validate([
                'order_no' => ['required', 'string', 'max:255'],
                'start'    => ['required', 'regex:/^\d{11}$/'],
                'end'      => ['required', 'regex:/^\d{11}$/'],
                // Optional webhook back to the caller (e.g. Speedy)
                'callback_url'   => ['nullable', 'url', 'max:2000'],
                'callback_token' => ['nullable', 'string', 'max:255'],
                // Optional per-job output format selection
                // e.g. ["jpg","pdf"], ["jpg","pdf","eps","xls"], etc.
                'formats'   => ['nullable', 'array'],
                'formats.*' => ['string', Rule::in(['jpg','pdf','eps','xls'])],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('API: Barcode job creation validation failed', [
                'ip'         => $req->ip(),
                'errors'     => $e->errors(),
                'request_data' => $req->only(['order_no', 'start', 'end', 'callback_url', 'formats']),
            ]);
            throw $e;
        }

        if (strcmp($data['start'], $data['end']) > 0) {
            Log::warning('API: Barcode job creation - invalid range', [
                'ip'         => $req->ip(),
                'start'      => $data['start'],
                'end'        => $data['end'],
                'order_no'   => $data['order_no'],
            ]);
            return response()->json([
                'error' => 'Validation failed',
                'message' => 'End must be >= start.',
            ], 422);
        }

        // If this order number has previous jobs, remove their files AND delete the old job records
        // so the new request becomes the single source of truth for that order's package.
        try {
            $disk = Storage::disk(config('barcodes.disk', config('filesystems.default')));
            $oldJobs = BarcodeJob::where('order_no', $data['order_no'])->get();
            $deletedIds = [];
            $deletedFiles = [];
            foreach ($oldJobs as $old) {
                // Delete ZIP file first (if it exists)
                if ($old->zip_rel_path && $disk->exists($old->zip_rel_path)) {
                    $disk->delete($old->zip_rel_path);
                    $deletedFiles[] = $old->zip_rel_path;
                }
                // Delete directory tree
                if ($old->root && $disk->exists($old->root)) {
                    $disk->deleteDirectory($old->root);
                    $deletedFiles[] = $old->root;
                }
                // Also try to delete ZIP by convention (in case zip_rel_path wasn't set)
                $zipGuess = $old->root ? dirname($old->root) . '/' . basename($old->root) . '.zip' : null;
                if ($zipGuess && $disk->exists($zipGuess)) {
                    $disk->delete($zipGuess);
                    $deletedFiles[] = $zipGuess;
                }
                // Delete the database record so the old job_id becomes invalid
                $deletedIds[] = $old->id;
                $old->delete();
            }
            if (!empty($deletedIds)) {
                Log::info('API: cleaned up old barcode files and deleted old job records for existing order', [
                    'order_no' => $data['order_no'],
                    'deleted_job_ids' => $deletedIds,
                    'deleted_files' => $deletedFiles,
                    'reason' => 'New request received for same order_no - old job replaced',
                ]);
            } else {
                Log::info('API: no existing jobs found for order_no (first request)', [
                    'order_no' => $data['order_no'],
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('API: failed to clean up old files/jobs for existing order', [
                'order_no' => $data['order_no'],
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);
        }

        // output dirs
        $outBase = 'order-' . now()->format('Ymd-His') . '-' . Str::random(6);
        $root = "barcodes/{$outBase}";
        foreach (['UPC-12/JPG', 'UPC-12/PDF', 'UPC-12/EPS', 'EAN-13/JPG', 'EAN-13/PDF', 'EAN-13/EPS'] as $p) {
            Storage::makeDirectory("{$root}/{$p}");
        }

        // DB row
        $job = BarcodeJob::create([
            'order_no'   => $data['order_no'],
            'root'       => $root,
            'started_at' => now(),
            'total_jobs' => 0, // will fill below
        ]);

        // Persist optional callback info so the finalizer can POST back when ready
        $callbackUrl   = $data['callback_url']   ?? null;
        $callbackToken = $data['callback_token'] ?? null;
        if ($callbackUrl) {
            $cbKey = "barcodes:callback:job:{$job->id}";
            try {
                Redis::hset($cbKey, 'url', $callbackUrl);
                if ($callbackToken) {
                    Redis::hset($cbKey, 'token', $callbackToken);
                }
                // keep callback metadata around for a few days
                Redis::expire($cbKey, 3 * 86400);
            } catch (\Throwable $e) {
                Log::warning('API: failed to store callback metadata for barcode job', [
                    'job_id'   => $job->id,
                    'order_no' => $job->order_no,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        // Persist per-job output format preferences, if provided.
        // If formats is omitted or empty, we fall back to global config:
        // - JPG always on
        // - PDF/EPS controlled by barcodes.enable_pdf / barcodes.enable_eps
        $formats = $data['formats'] ?? null;
        $optKey = "barcodes:options:job:{$job->id}";
        try {
            if (is_array($formats) && count($formats) > 0) {
                $jpg = in_array('jpg', $formats, true);
                $pdf = in_array('pdf', $formats, true);
                $eps = in_array('eps', $formats, true);
                $xls = in_array('xls', $formats, true);

                Redis::hset($optKey, 'jpg', $jpg ? '1' : '0');
                Redis::hset($optKey, 'pdf', $pdf ? '1' : '0');
                Redis::hset($optKey, 'eps', $eps ? '1' : '0');
                Redis::hset($optKey, 'xls', $xls ? '1' : '0');
            } else {
                // Defaults: JPG on, XLS on, PDF/EPS from config
                Redis::hset($optKey, 'jpg', '1');
                Redis::hset($optKey, 'xls', '1');
                Redis::hset($optKey, 'pdf', config('barcodes.enable_pdf', false) ? '1' : '0');
                Redis::hset($optKey, 'eps', config('barcodes.enable_eps', false) ? '1' : '0');
            }
            // Store the start/end range so XLS can be generated without JPGs
            Redis::hset($optKey, 'start', $data['start']);
            Redis::hset($optKey, 'end', $data['end']);
            Redis::expire($optKey, 3 * 86400);
        } catch (\Throwable $e) {
            Log::warning('API: failed to store per-job format options', [
                'job_id'   => $job->id,
                'order_no' => $job->order_no,
                'error'    => $e->getMessage(),
            ]);
        }

        // chunking
        $chunkSize = (int) config('barcodes.chunk_size', 200);
        $jobs = [];
        for ($cursor = $data['start']; strcmp($cursor, $data['end']) <= 0; $cursor = $this->add($cursor, $chunkSize)) {
            $chunkEnd = $this->add($cursor, $chunkSize - 1);
            if (strcmp($chunkEnd, $data['end']) > 0) {
                $chunkEnd = $data['end'];
            }
            $jobs[] = new RenderBarcodeChunk($cursor, $chunkEnd, $root, $data['order_no'], $job->id);
        }

        $batch = Bus::batch($jobs)
            ->name("barcode-package-{$outBase}")
            ->allowFailures()
            ->onQueue(config('barcodes.queue', 'barcodes'))
            ->then(function (Batch $batch) use ($root, $data, $job) {
                // Native completion callback — enqueue the finalizer
                FinalizeBarcodePackage::dispatch($root, $data['order_no'], $job->id)
                    ->onQueue(config('barcodes.queue', 'barcodes'));
            })
            ->dispatch();

        // progress seed (for your UI) - optional, falls back to batch if Redis unavailable
        try {
            $progressKey = "barcodes:progress:job:{$job->id}";
            Redis::hset($progressKey, 'total', count($jobs));
            Redis::hset($progressKey, 'done', 0);
            Redis::expire($progressKey, 86400);
        } catch (\Throwable $e) {
            Log::warning('Redis unavailable for progress tracking, will use batch status', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);
        }

        // (optional) queue the watcher as a backup
        WaitForBatchAndFinalize::dispatch($batch->id, $root, $data['order_no'], $job->id)
            ->onQueue(config('barcodes.queue', 'barcodes'));

        \Log::info('BarcodeController: batch queued via API', [
            'barcodeJobId' => $job->id,
            'batch_id'     => $batch->id,
            'total_jobs'   => $batch->totalJobs,
            'root'         => $root,
            'order_no'     => $data['order_no'],
        ]);

        $job->update([
            'batch_id'   => $batch->id,
            'total_jobs' => $batch->totalJobs,
        ]);

        // Log successful job creation
        Log::info('API: Barcode job created successfully', [
            'ip'           => $req->ip(),
            'job_id'       => $job->id,
            'order_no'     => $job->order_no,
            'batch_id'     => $batch->id,
            'total_jobs'   => $batch->totalJobs,
            'start'        => $data['start'],
            'end'          => $data['end'],
            'formats'      => $formats ?? 'defaults',
            'new_job_id'   => $job->id, // IMPORTANT: This is the NEW job_id to use for downloads
        ]);

        $response = [
            'success' => true,
            'message' => 'Barcode job created successfully',
            'job' => [
                'id'             => $job->id,
                'order_no'       => $job->order_no,
                'batch_id'       => $job->batch_id,
                'total_jobs'     => $job->total_jobs,
                'processed_jobs' => $job->processed_jobs,
                'failed_jobs'    => $job->failed_jobs,
                'started_at'     => $job->started_at?->toISOString(),
                'status_url'     => route('api.barcodes.status', $job->id),
                'download_url'   => null, // Will be available when job completes
            ],
        ];
        
        Log::info('API: returning job creation response', [
            'order_no' => $job->order_no,
            'new_job_id' => $job->id,
            'formats_requested' => $formats ?? 'defaults',
            'warning' => 'IMPORTANT: Use this NEW job_id for all future requests. Old job_id is invalid.',
        ]);
        
        return response()->json($response, 201);
    }

    /**
     * API endpoint to get barcode job status
     * GET /api/barcodes/{barcodeJob}
     */
    public function apiShow(Request $req, BarcodeJob $barcodeJob)
    {
        // Log API status request
        Log::info('API: Barcode job status request', [
            'method'     => $req->method(),
            'path'       => $req->path(),
            'ip'         => $req->ip(),
            'user_agent' => $req->userAgent(),
            'job_id'     => $barcodeJob->id,
            'order_no'   => $barcodeJob->order_no,
            'timestamp'  => now()->toISOString(),
        ]);
        $batch = $barcodeJob->batch_id
            ? Bus::findBatch($barcodeJob->batch_id)
            : null;

        $zipExists = $barcodeJob->zip_rel_path && Storage::exists($barcodeJob->zip_rel_path);
        if (!$barcodeJob->zip_rel_path) {
            $zipRelGuess = dirname($barcodeJob->root) . '/' . basename($barcodeJob->root) . '.zip';
            if (Storage::exists($zipRelGuess)) {
                $barcodeJob->update(['zip_rel_path' => $zipRelGuess, 'finished_at' => now()]);
                $zipExists = true;
            }
        }

        $k     = "barcodes:progress:job:{$barcodeJob->id}";
        try {
            $done  = (int) (Redis::hget($k, 'done') ?? ($batch?->processedJobs() ?? $barcodeJob->processed_jobs ?? 0));
            $total = (int) (Redis::hget($k, 'total') ?? ($batch?->totalJobs ?? $barcodeJob->total_jobs ?? 0));
        } catch (\Throwable $e) {
            // Fall back to batch or database values if Redis unavailable
            $done  = (int) ($batch?->processedJobs() ?? $barcodeJob->processed_jobs ?? 0);
            $total = (int) ($batch?->totalJobs ?? $barcodeJob->total_jobs ?? 0);
        }
        $pct   = $total > 0 ? (int) floor(($done / $total) * 100) : 0;
        if ($zipExists) {
            $pct = 100;
        }

        // keep DB in sync when batch object is available
        if ($batch) {
            $barcodeJob->update([
                'processed_jobs' => $batch->processedJobs(),
                'failed_jobs'    => $batch->failedJobs,
            ]);
        }

        return response()->json([
            'id'             => $barcodeJob->id,
            'order_no'       => $barcodeJob->order_no,
            'batch_id'       => $barcodeJob->batch_id,
            'total_jobs'     => $total,
            'processed_jobs' => $done,
            'failed_jobs'    => $barcodeJob->failed_jobs,
            'percentage'     => $pct,
            'finished'       => (bool) $barcodeJob->finished_at,
            'started_at'     => $barcodeJob->started_at?->toISOString(),
            'finished_at'    => $barcodeJob->finished_at?->toISOString(),
            'zip_url'        => $zipExists ? route('api.barcodes.download', $barcodeJob->id) : null,
        ]);
    }

    /**
     * API endpoint to download barcode job zip file
     * GET /api/barcodes/{barcodeJob}/download
     */
    public function apiDownload(Request $req, BarcodeJob $barcodeJob)
    {
        // Log API download request
        Log::info('API: Barcode job download request', [
            'method'     => $req->method(),
            'path'       => $req->path(),
            'ip'         => $req->ip(),
            'user_agent' => $req->userAgent(),
            'job_id'     => $barcodeJob->id,
            'order_no'   => $barcodeJob->order_no,
            'timestamp'  => now()->toISOString(),
        ]);

        // Verify the job still exists and is valid (not deleted)
        $freshJob = BarcodeJob::find($barcodeJob->id);
        if (!$freshJob) {
            Log::warning('API: Barcode job download failed - job was deleted', [
                'ip'       => $req->ip(),
                'job_id'   => $barcodeJob->id,
                'order_no' => $barcodeJob->order_no,
            ]);
            abort(404, 'Job not found');
        }

        // Check if a newer job exists for this order_no (means this job was replaced)
        $newerJob = BarcodeJob::where('order_no', $barcodeJob->order_no)
            ->where('id', '!=', $barcodeJob->id)
            ->where('started_at', '>', $barcodeJob->started_at)
            ->first();
        
        if ($newerJob) {
            Log::warning('API: Barcode job download failed - job was replaced by newer job', [
                'ip'           => $req->ip(),
                'old_job_id'   => $barcodeJob->id,
                'new_job_id'   => $newerJob->id,
                'order_no'     => $barcodeJob->order_no,
                'message'      => 'This job was replaced. Use the new job_id: ' . $newerJob->id,
            ]);
            return response()->json([
                'error' => 'Job replaced',
                'message' => 'This job was replaced by a newer request. Please use the new job_id.',
                'new_job_id' => $newerJob->id,
                'new_download_url' => route('api.barcodes.download', $newerJob->id),
            ], 410); // 410 Gone - resource was replaced
        }

        if (!$barcodeJob->zip_rel_path || !Storage::exists($barcodeJob->zip_rel_path)) {
            Log::warning('API: Barcode job download failed - file not found', [
                'ip'       => $req->ip(),
                'job_id'   => $barcodeJob->id,
                'order_no' => $barcodeJob->order_no,
                'zip_path' => $barcodeJob->zip_rel_path,
            ]);
            abort(404, 'Zip file not found');
        }

        $name = 'barcodes-'.$barcodeJob->order_no.'-'.$barcodeJob->id.'.zip';
        
        Log::info('API: Barcode job download successful', [
            'ip'       => $req->ip(),
            'job_id'   => $barcodeJob->id,
            'order_no' => $barcodeJob->order_no,
            'filename' => $name,
            'zip_path' => $barcodeJob->zip_rel_path,
        ]);

        return response()->download(
            Storage::path($barcodeJob->zip_rel_path),
            $name,
            ['Content-Type' => 'application/zip']
        );
    }

}
