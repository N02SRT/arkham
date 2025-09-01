@extends('layouts.app')

@section('content')
    <div class="container max-w-3xl mx-auto py-8">
        <h1 class="text-2xl font-semibold mb-6">Generate Barcode Package</h1>

        @if ($errors->any())
            <div class="bg-red-100 text-red-800 p-3 rounded mb-4">
                <ul class="list-disc ml-5">
                    @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('barcodes.store') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium">Start (11 digits)</label>
                <input name="start" value="{{ old('start','12345678900') }}" class="w-full border rounded px-3 py-2" required pattern="\d{11}">
            </div>
            <div>
                <label class="block text-sm font-medium">End (11 digits)</label>
                <input name="end" value="{{ old('end','12345678999') }}" class="w-full border rounded px-3 py-2" required pattern="\d{11}">
            </div>
            <div>
                <label class="block text-sm font-medium">Order #</label>
                <input name="order_no" value="{{ old('order_no','TEST') }}" class="w-full border rounded px-3 py-2" required>
            </div>
            <button class="bg-indigo-600 text-white px-4 py-2 rounded">Start</button>
        </form>
    </div>
@endsection
