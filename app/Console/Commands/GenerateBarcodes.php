<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Bus\Batch;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use App\Jobs\RenderBarcodeChunk;
use App\Jobs\FinalizeBarcodePackage;

class GenerateBarcodes extends Command
{
    protected $signature = 'barcodes:generate
        {start : 11-digit UPC base (no check)}
        {end   : 11-digit UPC base (no check)}
        {--order=1 : Order number for file names}
        {--out= : Optional output dir under storage/app/barcodes}';

    protected $description = 'Generate a full barcode package (UPC-12 & EAN-13) for a base range.';

    public function handle(): int
    {
        $start = $this->argument('start');
        $end   = $this->argument('end');
        $order = (string)$this->option('order');

        if (!ctype_digit($start) || !ctype_digit($end) || strlen($start) !== 11 || strlen($end) !== 11) {
            $this->error('start and end must be 11-digit numeric UPC base values (no check digit).');
            return self::FAILURE;
        }

        if (strcmp($start, $end) > 0) {
            $this->error('start must be <= end.');
            return self::FAILURE;
        }

        $outBase = $this->option('out') ?: 'order-' . now()->format('Ymd-His') . '-' . Str::random(6);
        $root = "barcodes/{$outBase}";
        Storage::makeDirectory($root.'/UPC-12/JPG');
        Storage::makeDirectory($root.'/UPC-12/PDF');
        Storage::makeDirectory($root.'/UPC-12/EPS');
        Storage::makeDirectory($root.'/EAN-13/JPG');
        Storage::makeDirectory($root.'/EAN-13/PDF');
        Storage::makeDirectory($root.'/EAN-13/EPS');

        // Chunk the range
        $chunkSize = (int) config('barcodes.chunk_size', 1000);
        $jobs = [];
        for ($cursor = $start; strcmp($cursor, $end) <= 0; $cursor = $this->inc($cursor, $chunkSize)) {
            $chunkEnd = $this->dec( min($this->add($cursor, $chunkSize), $this->add($end, 1)) );
            $jobs[] = new RenderBarcodeChunk($cursor, $chunkEnd, $root, $order);
        }

        Bus::batch($jobs)
            ->name("barcode-package-{$outBase}")
            ->then(function (Batch $batch) use ($root, $order) {
                FinalizeBarcodePackage::dispatch($root, $order)->onQueue(config('barcodes.queue', 'barcodes'));
            })
            ->onQueue(config('barcodes.queue', 'barcodes'))
            ->dispatch();

        $this->info("Queued " . count($jobs) . " chunk jobs for {$root}. Watch Horizon.");
        return self::SUCCESS;
    }

    // increment/decrement/add for 11-digit strings (keep zero padding)
    private function inc(string $s, int $by = 1): string { return $this->add($s, $by); }
    private function dec(string $s): string { return $this->add($s, -1); }
    private function add(string $s, int $by): string {
        $n = (int) $s + $by;
        if ($n < 0) $n = 0;
        return str_pad((string)$n, 11, '0', STR_PAD_LEFT);
    }
}
