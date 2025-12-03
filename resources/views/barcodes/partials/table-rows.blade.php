@php /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Support\Collection $jobs */ @endphp
@forelse ($jobs as $job)
    @php $pct = (int) ($job->total_jobs ? round(($job->processed_jobs/$job->total_jobs)*100) : 0); @endphp
    <tr class="hover:bg-slate-50" data-job-id="{{ $job->id }}">
        <td class="px-4 py-3">
            <div class="font-medium">{{ $job->order_no }}</div>
            <div class="text-xs muted">{{ $job->created_at->format('Y-m-d H:i') }}</div>
        </td>
        <td class="px-4 py-3">
            <div>#{{ $job->id }}</div>
            <div class="text-xs muted">Batch: <span data-batch>{{ $job->batch_id ?? '—' }}</span></div>
        </td>
        <td class="px-4 py-3">
            <div class="progress w-48"><div data-bar style="width: {{ $pct }}%"></div></div>
            <div class="mt-1 text-xs muted"><span data-pct>{{ $pct }}</span>% • <span data-proc>{{ $job->processed_jobs }}</span>/<span data-total>{{ $job->total_jobs }}</span></div>
        </td>
        <td class="px-4 py-3">
            @if ($job->zip_url)
                <a data-zip href="{{ $job->zip_url }}" class="text-brand hover:underline">Ready</a>
            @else
                <span data-zip class="muted">—</span>
            @endif
        </td>
        <td class="px-4 py-3">
            <div class="flex items-center gap-2">
                <a href="{{ route('barcodes.show',$job) }}" class="btn btn-ghost">View</a>
                <button class="btn btn-ghost text-red-600" data-delete="{{ route('barcodes.destroy',$job) }}">Delete</button>
                @if ($job->zip_url)
                    <a href="{{ $job->zip_url }}" class="btn btn-primary">Download</a>
                @endif
            </div>
        </td>
    </tr>
@empty
    <tr><td colspan="5" class="px-4 py-10 text-center muted">No jobs yet.</td></tr>
@endforelse
