@extends('layouts.app')

@section('title', 'Barcode Studio')

@section('content')
    {{-- HERO STRIP (same navy fade as Figma) --}}
    <section class="hero-strip">
        <div class="container-xl hero-inner">
            <h1 class="hero-title">Barcode Studio</h1>
            <p class="hero-sub">Generate, track, and download your barcode jobs.</p>
        </div>
    </section>

    {{-- JOB CARD --}}
    <section class="container-xl">
        <div class="card card--elev mb-24">
            <div class="card-hd">
                <h2 class="card-title">Generate Barcode Package</h2>
                <p class="card-kicker">We’ll render, package, and notify you as soon as it’s ready.</p>
            </div>

            <form action="{{ route('barcodes.store') }}" method="POST" class="studio-grid">
                @csrf
                <label class="fld">
                    <span>Start (11 digits)</span>
                    <input name="start" type="text" inputmode="numeric" maxlength="11" placeholder="e.g. 12345678901" required>
                </label>

                <label class="fld">
                    <span>End (11 digits)</span>
                    <input name="end" type="text" inputmode="numeric" maxlength="11" placeholder="e.g. 12345678910" required>
                </label>

                <label class="fld">
                    <span>Order # <i class="txt-dim">(optional)</i></span>
                    <input name="order_ref" type="text" placeholder="(optional)">
                </label>

                <div class="actions">
                    <button class="btn btn-primary">
                        <span class="plus">+</span> Start Job
                    </button>
                </div>
            </form>
        </div>
    </section>

    {{-- RECENT JOBS --}}
    <section class="container-xl">
        <div class="card card--elev">
            <div class="card-hd row-between">
                <h3 class="card-title">Recent Jobs</h3>
                <div class="filters">
                    <input class="search" type="search" placeholder="Search order #, job id, …">
                    <button class="btn btn-ghost">Filter</button>
                </div>
            </div>

            <div class="jobs-table-shell">
                {{-- your table/list markup goes here --}}
                <div class="empty">No jobs yet.</div>
            </div>
        </div>
    </section>
@endsection
