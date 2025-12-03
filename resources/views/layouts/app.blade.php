{{-- resources/views/layouts/app.blade.php --}}
    <!doctype html>
<html lang="{{ str_replace('_','-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Speedy Barcodes')</title>

    {{-- Fonts: Poppins (400–800) --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    {{-- Site styles (tokens, header/footer, etc.) --}}
    <link href="{{ asset('css/speedy.css') }}" rel="stylesheet">

    {{-- App bundle (Tailwind/Alpine/etc.) --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('head')
</head>
<body class="sb-body">
{{-- Skip link for accessibility --}}
<a href="#main-content" class="sr-only focus:not-sr-only focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-slate-900">
    Skip to content
</a>

{{-- Header (full width) --}}
@include('partials.header')

{{-- Optional page-specific hero section --}}
@yield('hero')

<main id="main-content" class="sb-main" role="main">
    <div class="sb-content">
        <div class="sb-container">
            {{-- Flash messages --}}
            @if (session('status'))
                <div class="flash">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="flash flash-error">
                    {{ $errors->first() }}
                </div>
            @endif
        </div>

        {{-- Page content --}}
        <div class="sb-container">
            @yield('content')
        </div>
    </div>
</main>

{{-- Footer (full width) --}}
@include('partials.footer')

@stack('modals')
@stack('scripts')
</body>
</html>
