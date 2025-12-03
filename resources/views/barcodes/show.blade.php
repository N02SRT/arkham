@extends('app')

@section('title','Barcode Job • '.$barcodeJob->id)

@section('content')
    @php
        // convenience
        $canZip = $barcodeJob->zip_rel_path && \Illuminate\Support\Facades\Storage::exists($barcodeJob->zip_rel_path);
    @endphp

    <div class="grid lg:grid-cols-3 gap-6 -translate-y-4">
        {{-- Left: Job Summary --}}
        <section class="card p-6 md:p-8 lg:col-span-2">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-sb-ink text-xl font-extrabold">Job #{{ $barcodeJob->id }}</h2>
                    <div class="mt-1 text-slate-500 text-sm">
                        Created {{ $barcodeJob->created_at->format('Y-m-d H:i') }}
                        @if($barcodeJob->order_number) · Order <span class="font-semibold">{{ $barcodeJob->order_number }}</span>@endif
                    </div>
                </div>

                <span class="badge
        @if($barcodeJob->status==='complete') bg-emerald-100 text-emerald-700
        @elseif(in_array($barcodeJob->status,['running','queued'])) bg-amber-100 text-amber-700
        @elseif($barcodeJob->status==='failed') bg-rose-100 text-rose-700
        @else bg-slate-100 text-slate-700 @endif">
        {{ ucfirst($barcodeJob->status ?? 'unknown') }}
      </span>
            </div>

            {{-- Progress --}}
            <div class="mt-6">
                <div class="pill w-full">
                    <div class="h-2 rounded-full bg-sb-blue" style="width: {{ (int)($barcodeJob->progress ?? 0) }}%"></div>
                </div>
                <div class="text-sm text-slate-500 mt-2">{{ (int)($barcodeJob->progress ?? 0) }}% complete</div>
            </div>

            {{-- Meta --}}
            <dl class="grid sm:grid-cols-3 gap-4 mt-8 text-sm">
                <div class="p-4 rounded-xl bg-sb-gray">
                    <dt class="text-slate-500">Batch</dt>
                    <dd class="font-semibold text-sb-ink break-all">{{ $barcodeJob->batch_uuid ?? '—' }}</dd>
                </div>
                <div class="p-4 rounded-xl bg-sb-gray">
                    <dt class="text-slate-500">Start</dt>
                    <dd class="font-semibold text-sb-ink">{{ $barcodeJob->start ?? '—' }}</dd>
                </div>
                <div class="p-4 rounded-xl bg-sb-gray">
                    <dt class="text-slate-500">End</dt>
                    <dd class="font-semibold text-sb-ink">{{ $barcodeJob->end ?? '—' }}</dd>
                </div>
            </dl>

            {{-- Actions --}}
            <div class="mt-8 flex flex-wrap items-center gap-3">
                <a href="{{ route('barcodes.index') }}" class="btn btn-ghost">← Back to jobs</a>

                @if($canZip)
                    {{-- IMPORTANT: uses barcodes.download and the BarcodeJob model binding --}}
                    <a href="{{ route('barcodes.download', $barcodeJob) }}"
                       class="btn btn-primary">
                        Download ZIP
                    </a>
                @endif

                @if(!$canZip)
                    <span class="text-sm text-slate-500">Zip not ready yet.</span>
                @endif
            </div>
        </section>

        {{-- Right: Quick Actions / Files --}}
        <aside class="card p-6 md:p-8">
            <h3 class="text-sb-ink text-lg font-bold">Files</h3>
            <p class="text-slate-500 text-sm">When the job completes, your packaged ZIP will be available below.</p>

            <div class="mt-6 space-y-3">
                <div class="flex items-center justify-between rounded-xl border border-slate-200 p-4">
                    <div>
                        <div class="font-semibold text-sb-ink">ZIP Package</div>
                        <div class="text-xs text-slate-500 break-all">
                            {{ $barcodeJob->zip_rel_path ?: '—' }}
                        </div>
                    </div>

                    @if($canZip)
                        <a href="{{ route('barcodes.download', $barcodeJob) }}" class="btn btn-primary">Download</a>
                    @else
                        <span class="badge bg-amber-100 text-amber-700">Pending</span>
                    @endif
                </div>
            </div>

            {{-- Danger zone --}}
            <form action="{{ route('barcodes.destroy', $barcodeJob) }}" method="POST" class="mt-8"
                  onsubmit="return confirm('Delete this job? This cannot be undone.')">
                @csrf @method('DELETE')
                <button class="btn w-full border border-rose-300 text-rose-600 hover:bg-rose-50">
                    Delete Job
                </button>
            </form>
        </aside>
    </div>
@endsection
