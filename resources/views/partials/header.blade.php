{{-- resources/views/partials/header.blade.php --}}
<header class="sb-header">
    <div class="sb-header__inner">
        <a class="sb-brand" href="{{ url('/') }}">
            <img src="{{ asset('images/logo-speedy-barcodes.png') }}" alt="Speedy Barcodes" loading="lazy">
        </a>

        <nav class="sb-nav" aria-label="Primary">
            <a href="{{ url('/') }}" class="sb-nav__link {{ request()->is('/') ? 'is-active':'' }}">HOME</a>
            <a href="{{ url('/pricing') }}" class="sb-nav__link">PRICING</a>
            <a href="{{ url('/how-it-works') }}" class="sb-nav__link">HOW IT WORKS</a>
            <a href="{{ url('/faqs') }}" class="sb-nav__link">FAQ’S</a>
            <a href="{{ url('/about') }}" class="sb-nav__link">ABOUT US</a>
            <a href="{{ url('/contact') }}" class="sb-nav__link">CONTACT</a>
        </nav>

        <div class="sb-header__spacer"></div>

        <div class="sb-toll">Toll Free <strong>(888) 511-0266</strong></div>

        <a href="{{ url('/cart') }}" class="sb-cart" aria-label="Cart">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M6 6h15l-2 8H8L6 6Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                <circle cx="9" cy="20" r="1.6" fill="currentColor"/>
                <circle cx="18" cy="20" r="1.6" fill="currentColor"/>
            </svg>
            <span>Cart</span>
        </a>

        <a href="{{ url('/login') }}" class="btn btn-blue">LOGIN</a>
        <a href="{{ url('/register') }}" class="btn btn-green">REGISTER</a>
    </div>
</header>
