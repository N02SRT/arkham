@extends('layouts.app')

@section('content')
    <div class="container max-w-3xl mx-auto py-8">
        <h1 class="text-2xl font-semibold mb-4">Order #{{ $job->order_no }}</h1>
        <p class="text-sm text-gray-600 mb-6">Job ID: {{ $job->id }}</p>

        <div id="status" class="space-y-2">
            <div>Batch ID: <span id="batch">{{ $job->batch_id ?? '—' }}</span></div>
            <div>Progress: <span id="percent">0</span>%</div>
            <div>Processed: <span id="processed">0</span> / <span id="total">0</span></div>
            <div>Failed: <span id="failed">0</span></div>
        </div>

        <div id="download" class="mt-6 hidden">
            <a id="dl" href="#" class="bg-green-600 text-white px-4 py-2 rounded">Download ZIP</a>
        </div>

        <div id="notready" class="mt-6 text-gray-500">Preparing your package… this page will refresh automatically.</div>
    </div>

    <script>
        const poll = async () => {
            const res = await fetch('{{ route('barcodes.json', $job->id) }}', { cache: 'no-store' });
            const j = await res.json();
            document.getElementById('batch').textContent = j.batch_id ?? '—';
            document.getElementById('percent').textContent = j.percentage ?? 0;
            document.getElementById('processed').textContent = j.processed_jobs ?? 0;
            document.getElementById('total').textContent = j.total_jobs ?? 0;
            document.getElementById('failed').textContent = j.failed_jobs ?? 0;

            if (j.zip_url) {
                document.getElementById('dl').href = j.zip_url;
                document.getElementById('download').classList.remove('hidden');
                document.getElementById('notready').classList.add('hidden');
            }
        };
        poll();
        setInterval(poll, 2500);
    </script>
@endsection
