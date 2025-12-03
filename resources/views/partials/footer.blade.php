{{-- resources/views/partials/footer.blade.php --}}
<footer class="sb-footer">
    <div class="sb-footer__wrap">
        <div class="sb-footer__grid">
            {{-- Brand + phones --}}
            <div class="sb-footer__brand">
                <a href="{{ url('/') }}" class="sb-footer__logo">
                    <img src="{{ asset('images/logo-speedy.svg') }}" alt="Speedy Barcodes" loading="lazy">
                </a>

                <p class="sb-footer__tag">Still have any Barcode Questions?<br>Call the Experts!</p>

                <div class="sb-phones">
                    <div class="sb-phone">
                        <span class="sb-phone__label">Toll Free Sales:</span>
                        <a class="sb-phone__num" href="tel:+18885110266">
                            <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M6.6 10.8c1.6 3.1 3.5 5 6.6 6.6l2.2-2.2c.3-.3.8-.4 1.2-.2 1 .3 2 .5 3.1.5.7 0 1.2.5 1.2 1.2V20c0 .7-.5 1.2-1.2 1.2C10.8 21.2 2.8 13.2 2.8 3.2 2.8 2.5 3.3 2 4 2h3.1c.7 0 1.2.5 1.2 1.2 0 1.1.2 2.1.5 3.1.1.4 0 .9-.3 1.2L6.6 10.8z"/></svg>
                            <span>+1 (888) 511-0266</span>
                        </a>
                    </div>

                    <div class="sb-phone">
                        <span class="sb-phone__label">Local Sales:</span>
                        <a class="sb-phone__num" href="tel:+13072004802">
                            <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M6.6 10.8c1.6 3.1 3.5 5 6.6 6.6l2.2-2.2c.3-.3.8-.4 1.2-.2 1 .3 2 .5 3.1.5.7 0 1.2.5 1.2 1.2V20c0 .7-.5 1.2-1.2 1.2C10.8 21.2 2.8 13.2 2.8 3.2 2.8 2.5 3.3 2 4 2h3.1c.7 0 1.2.5 1.2 1.2 0 1.1.2 2.1.5 3.1.1.4 0 .9-.3 1.2L6.6 10.8z"/></svg>
                            <span>+1 (307) 200-4802</span>
                        </a>
                    </div>
                </div>
            </div>

            {{-- Quick links --}}
            <nav class="sb-footer__links" aria-label="Footer – Quick Links">
                <h4>QUICK LINKS</h4>
                <ul>
                    <li><a href="{{ url('/') }}">Home</a></li>
                    <li><a href="{{ url('/pricing') }}">Buy Now</a></li>
                    <li><a href="{{ url('/faqs') }}">FAQ’s</a></li>
                    <li><a href="{{ url('/about') }}">About</a></li>
                    <li><a href="{{ url('/reviews') }}">Reviews</a></li>
                    <li><a href="{{ url('/blog') }}">Blog</a></li>
                </ul>
            </nav>

            {{-- Other links --}}
            <nav class="sb-footer__links" aria-label="Footer – Other">
                <h4>OTHER</h4>
                <ul>
                    <li><a href="{{ url('/login') }}">Login</a></li>
                    <li><a href="{{ url('/register') }}">Register</a></li>
                    <li><a href="{{ url('/terms') }}">Terms &amp; Conditions</a></li>
                    <li><a href="{{ url('/privacy') }}">Privacy Policy</a></li>
                    <li><a href="{{ url('/refunds') }}">Refunds</a></li>
                    <li><a href="{{ url('/contact') }}">Contact</a></li>
                </ul>
            </nav>

            {{-- Map --}}
            <div class="sb-footer__map">
                <iframe
                    class="sb-map"
                    title="Speedy Barcodes – Cheyenne, WY"
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade"
                    src="https://www.google.com/maps?q=1712%20Pioneer%20Ave%20Suite%20165,%20Cheyenne,%20WY%208201,&output=embed">
                </iframe>
            </div>
        </div>

        <div class="sb-footer__bottom">
            <div class="sb-payments">
                <img src="{{ asset('images/payments/paypal.svg') }}" alt="PayPal">
                <img src="{{ asset('images/payments/authorize-net.svg') }}" alt="Authorize.Net">
                <img src="{{ asset('images/payments/discover.svg') }}" alt="Discover">
                <img src="{{ asset('images/payments/visa.svg') }}" alt="Visa">
                <img src="{{ asset('images/payments/mastercard.svg') }}" alt="Mastercard">
                <img src="{{ asset('images/payments/amex.svg') }}" alt="American Express">
            </div>

            <div class="sb-legal">
                <p>All trademarks on SpeedyBarcodes.com are registered by their respective owners.</p>
                <p>Located in Cheyenne, Wyoming | Copyright © {{ date('Y') }} Speedy Barcodes, LLC</p>
            </div>
        </div>
    </div>
</footer>
