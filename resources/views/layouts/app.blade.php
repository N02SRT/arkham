<!DOCTYPE html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Barcode Generator')</title>

    {{-- If you already have Vite/Tailwind in your app, this will pull your compiled CSS/JS --}}
    @vite(['resources/css/app.css','resources/js/app.js'])
    {{-- Fallback Tailwind (no build needed) --}}
    <script>
        if (!document.querySelector('link[href*="resources/css/app.css"]')) {
            const s = document.createElement('script'); s.src='https://cdn.tailwindcss.com'; document.head.appendChild(s);
        }
    </script>

    <link rel="icon" href="data:,">
    @stack('head')
    <style>
        /* Small niceties even without Tailwind preflight */
        html,body{height:100%}
    </style>
    <script>
        // Dark mode: keep user preference in localStorage
        (function() {
            const key='theme';
            const saved = localStorage.getItem(key);
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            const mode = saved ?? (prefersDark ? 'dark' : 'light');
            if (mode === 'dark') document.documentElement.classList.add('dark');
            document.documentElement.dataset.theme = mode;
            window.__toggleTheme = function(){
                const now = document.documentElement.classList.toggle('dark') ? 'dark' : 'light';
                localStorage.setItem(key, now);
                document.documentElement.dataset.theme = now;
            };
        })();
    </script>
</head>
<body class="h-full bg-gray-50 text-gray-900 dark:bg-gray-900 dark:text-gray-100">
<div class="min-h-full flex flex-col">

    {{-- Header / Nav --}}
    <header class="border-b border-gray-200 bg-white/80 backdrop-blur dark:bg-gray-800/60 dark:border-gray-700">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-4 flex items-center justify-between">
            <a href="{{ url('/') }}" class="flex items-center gap-2 font-semibold tracking-tight">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor"><path d="M3 5h1v14H3V5Zm3 0h2v14H6V5Zm4 0h1v14h-1V5Zm3 0h2v14h-2V5Zm4 0h1v14h-1V5Zm3 0h1v14h-1V5Z"/></svg>
                <span>Barcode Generator</span>
            </a>
            <nav class="flex items-center gap-3">
                <a href="/barcodes"
                   class="px-3 py-2 rounded-lg text-sm font-medium hover:bg-gray-100 dark:hover:bg-gray-700">Generate</a>
                <button type="button" onclick="__toggleTheme()"
                        class="px-3 py-2 rounded-lg text-sm font-medium hover:bg-gray-100 dark:hover:bg-gray-700"
                        title="Toggle dark mode">🌓</button>
            </nav>
        </div>
    </header>

    {{-- Flash messages --}}
    <div class="max-w-6xl mx-auto px-4 sm:px-6 mt-4 w-full">
        @if (session('status'))
            <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-green-800 dark:border-green-900 dark:bg-green-900/30 dark:text-green-200">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-800 dark:border-red-900 dark:bg-red-900/30 dark:text-red-200">
                <ul class="list-disc ml-5 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    {{-- Main content --}}
    <main class="flex-1">
        @yield('content')
    </main>

    {{-- Footer --}}
    <footer class="mt-8 border-t border-gray-200 bg-white/60 dark:bg-gray-800/40 dark:border-gray-700">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-6 text-sm text-gray-500 dark:text-gray-400">
            <div class="flex items-center justify-between">
                <span>&copy; {{ date('Y') }} American Glass Professionals</span>
                <span>Powered by Laravel & Horizon</span>
            </div>
        </div>
    </footer>
</div>

@stack('scripts')
</body>
</html>
