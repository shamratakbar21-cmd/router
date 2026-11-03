<!DOCTYPE html>
<html lang="{{ $locale ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $pageTitle = $title ?? ($brand['name'] . ' | ' . $brand['domain']);
        $pageDescription = $description ?? $brand['description'];
        $canonicalBase = rtrim(config('app.url'), '/');
        $canonicalPath = trim(request()->path(), '/');
        $canonicalUrl = $canonicalPath === '' ? $canonicalBase : $canonicalBase . '/' . $canonicalPath;
        $ogImage = $socialImage ?? ($canonicalBase . '/media/screenshots/wincontrol-dev13-arp.png');
        $robotsMeta = $robots ?? 'index, follow, max-image-preview:large, max-snippet:-1';
        $jsonLdGraph = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Organization',
                    '@id' => $canonicalBase . '/#organization',
                    'name' => $brand['name'],
                    'url' => $canonicalBase,
                    'logo' => $canonicalBase . '/favicon.svg',
                    'sameAs' => [
                        'https://github.com/shamratakbar21-cmd/router',
                    ],
                ],
                [
                    '@type' => 'WebSite',
                    '@id' => $canonicalBase . '/#website',
                    'url' => $canonicalBase,
                    'name' => $brand['name'],
                    'description' => $brand['tagline'],
                    'publisher' => ['@id' => $canonicalBase . '/#organization'],
                ],
                [
                    '@type' => 'SoftwareApplication',
                    '@id' => $canonicalBase . '/#software',
                    'name' => $release['product'] ?? 'NurRouterOS',
                    'softwareVersion' => $release['version'] ?? '0.5.0-dev.13',
                    'operatingSystem' => 'Linux (OpenWrt ' . ($release['openwrt_base'] ?? '24.10.0') . ' x86_64)',
                    'applicationCategory' => 'NetworkingApplication',
                    'url' => $canonicalBase . '/downloads',
                    'image' => $ogImage,
                    'description' => $pageDescription,
                    'author' => ['@id' => $canonicalBase . '/#organization'],
                ],
            ],
        ];
    @endphp
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $pageDescription }}">
    <meta name="robots" content="{{ $robotsMeta }}">
    <meta name="theme-color" content="#176bcc">
    <link rel="canonical" href="{{ $canonicalUrl }}">
    <meta property="og:locale" content="{{ ($locale ?? 'en') === 'bn' ? 'bn_BD' : 'en_US' }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $brand['name'] }}">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:width" content="2048">
    <meta property="og:image:height" content="1088">
    <meta property="og:image:alt" content="{{ $brand['name'] }} WinControl and RouterOS overview">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $pageTitle }}">
    <meta name="twitter:description" content="{{ $pageDescription }}">
    <meta name="twitter:image" content="{{ $ogImage }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="sitemap" type="application/xml" title="Sitemap" href="{{ route('sitemap') }}">
    <link rel="stylesheet" href="{{ asset('css/nur-public.css') }}?v=20261104-publication1">
    <script type="application/ld+json">{!! json_encode($jsonLdGraph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to content</a>
    @php
        $t = $i18n ?? config('nurrouter.i18n.en');
        $locale = $locale ?? 'en';
    @endphp

    <div class="status-strip">
        <div class="container">
            <div class="status-strip-left">
                <span class="status-dot" aria-hidden="true"></span>
                <span>{{ $t['status_banner'] }}</span>
            </div>
            <div class="status-strip-links" aria-label="Quick resources">
                <a href="{{ route('release', ['lang' => $locale ?? 'en']) }}">Release {{ $release['version'] ?? '0.5.0-dev.13' }}</a>
                <a href="{{ route('screenshots', ['lang' => $locale ?? 'en']) }}">WinControl</a>
                <a href="{{ route('docs.show', ['slug' => 'gaps', 'lang' => $locale ?? 'en']) }}">Known Limitations</a>
                <a href="https://github.com/shamratakbar21-cmd/router" target="_blank" rel="noopener">GitHub Source ↗</a>
                <a href="{{ url()->current() }}?lang=en" lang="en">English</a>
                <a href="{{ url()->current() }}?lang=bn" lang="bn">বাংলা</a>
            </div>
        </div>
    </div>

    <header class="site-head">
        <div class="container head-row">
            <a class="logo" href="{{ route('home', ['lang' => $locale ?? 'en']) }}">
                <span class="logo-mark" aria-hidden="true">{{ Str::upper(Str::substr($brand['name'], 0, 2)) }}</span>
                <span class="logo-text">
                    <strong>{{ $brand['name'] }}</strong>
                    <small>RouterOS &amp; WinControl</small>
                </span>
            </a>

            <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="primary-nav">Menu ☰</button>
            <nav class="site-nav" id="primary-nav" aria-label="Main navigation">
                <a href="{{ route('home', ['lang' => $locale ?? 'en']) }}" class="{{ request()->routeIs('home') ? 'is-active' : '' }}">{{ $t['nav_home'] }}</a>
                <a href="{{ route('features', ['lang' => $locale ?? 'en']) }}" class="{{ request()->routeIs('features') ? 'is-active' : '' }}">{{ $t['nav_features'] }}</a>
                <a href="{{ route('downloads', ['lang' => $locale ?? 'en']) }}" class="{{ request()->routeIs('downloads') ? 'is-active' : '' }}">{{ $t['nav_downloads'] }}</a>
                <a href="{{ route('docs.index', ['lang' => $locale ?? 'en']) }}" class="{{ request()->routeIs('docs.*') ? 'is-active' : '' }}">{{ $t['nav_docs'] }}</a>
                <a href="{{ route('licensing', ['lang' => $locale ?? 'en']) }}" class="{{ request()->routeIs('licensing') ? 'is-active' : '' }}">{{ $t['nav_licensing'] }}</a>
                <a href="{{ route('release', ['lang' => $locale ?? 'en']) }}" class="{{ request()->routeIs('release') ? 'is-active' : '' }}">{{ $t['nav_release'] }}</a>

            </nav>

            <div class="head-actions">
                <a class="btn btn-primary btn-sm" href="{{ route('downloads', ['lang' => $locale ?? 'en']) }}">{{ $t['cta_download'] }}</a>
            </div>
        </div>
    </header>

    <nav class="site-subnav" aria-label="Product and software categories">
        <div class="container">
            <a href="{{ route('downloads', ['lang' => $locale ?? 'en']) }}" class="{{ request()->routeIs('downloads') ? 'is-active' : '' }}">RouterOS Downloads</a>
            <span class="subnav-sep" aria-hidden="true">·</span>
            <a href="{{ route('screenshots', ['lang' => $locale ?? 'en']) }}" class="{{ request()->routeIs('screenshots') ? 'is-active' : '' }}">WinControl Workspace</a>
            <span class="subnav-sep" aria-hidden="true">·</span>
            <a href="{{ route('features', ['lang' => $locale ?? 'en']) }}" class="{{ request()->routeIs('features') ? 'is-active' : '' }}">Verified Feature Matrix</a>
            <span class="subnav-sep" aria-hidden="true">·</span>
            <a href="{{ route('docs.show', ['slug' => 'install', 'lang' => $locale ?? 'en']) }}">Install &amp; Recovery</a>
            <span class="subnav-sep" aria-hidden="true">·</span>
            <a href="{{ route('docs.show', ['slug' => 'deploy', 'lang' => $locale ?? 'en']) }}">WinControl Deployment</a>
            <span class="subnav-sep" aria-hidden="true">·</span>
            <a href="{{ route('blog', ['lang' => $locale ?? 'en']) }}" class="{{ request()->routeIs('blog') ? 'is-active' : '' }}">Engineering Blog</a>
            <span class="subnav-sep" aria-hidden="true">·</span>
            <a href="{{ route('contact', ['lang' => $locale ?? 'en']) }}" class="{{ request()->routeIs('contact') ? 'is-active' : '' }}">Support &amp; Contact</a>
        </div>
    </nav>

    <main id="main-content">
        @if (($locale ?? 'en') === 'bn')
            <section class="container" lang="bn" style="padding-top:20px">
                <p>বর্তমান অ্যাপ্লায়েন্স <strong>{{ $release['version'] }}</strong>; বহিরাগত WinControl ডেস্কটপ <strong>candidate-10</strong>। অ্যাপ্লায়েন্সটি কেবল বিচ্ছিন্ন পরীক্ষার জন্য। সীমিত VM পরীক্ষা সম্পন্ন হলেও বাস্তব হার্ডওয়্যার, USB পেলোড শনাক্তকরণ ও বাণিজ্যিক অ্যাক্টিভেশন যাচাই সম্পূর্ণ হয়নি। নিচের কারিগরি নথি ও পরিভাষার কিছু অংশ ইংরেজিতে দেওয়া আছে।</p>
            </section>
        @endif
        @yield('content')
    </main>

    <footer class="site-foot">
        <div class="foot-top-bar">
            <div class="container foot-newsletter-grid">
                <div class="foot-newsletter-copy">
                    <strong>Follow NurRouterOS release bulletins &amp; changelogs</strong>
                    <p>Track verified OpenWrt 24.10.0 x86/64 appliance images, SHA-256 manifests, and WinControl updates.</p>
                </div>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <a class="btn btn-primary btn-sm" href="{{ route('release', ['lang' => $locale ?? 'en']) }}">View {{ $release['version'] ?? '0.5.0-dev.13' }} Changelog</a>
                    <a class="mtk-button mtk-button-ghost-light btn-sm" href="https://github.com/shamratakbar21-cmd/router" target="_blank" rel="noopener">GitHub Source ↗</a>
                </div>
            </div>
        </div>

        <div class="container foot-grid">
            <div class="foot-brand">
                <strong>{{ $brand['name'] }}</strong>
                <p>{{ $brand['tagline'] }}</p>
                <p style="margin-top:12px; font-size:12px; color:#77899b;">OpenWrt {{ $release['openwrt_base'] ?? '24.10.0' }} ({{ $release['target'] ?? 'x86/64' }}) · External Laravel WinControl</p>
            </div>

            <div>
                <h4>Software &amp; Images</h4>
                <ul>
                    <li><a href="{{ route('downloads', ['lang' => $locale ?? 'en']) }}">RouterOS Downloads</a></li>
                    <li><a href="{{ route('features', ['lang' => $locale ?? 'en']) }}">Feature Matrix</a></li>
                    <li><a href="{{ route('screenshots', ['lang' => $locale ?? 'en']) }}">WinControl Screenshots</a></li>
                    <li><a href="{{ route('licensing', ['lang' => $locale ?? 'en']) }}">Device Licensing</a></li>
                    <li><a href="{{ route('release', ['lang' => $locale ?? 'en']) }}">Release Changelogs</a></li>
                </ul>
            </div>

            <div>
                <h4>Documentation</h4>
                <ul>
                    <li><a href="{{ route('docs.show', ['slug' => 'install', 'lang' => $locale ?? 'en']) }}">Install &amp; Recovery</a></li>
                    <li><a href="{{ route('docs.show', ['slug' => 'deploy', 'lang' => $locale ?? 'en']) }}">WinControl Deployment</a></li>
                    <li><a href="{{ route('docs.show', ['slug' => 'licensing', 'lang' => $locale ?? 'en']) }}">Licensing Guide</a></li>
                    <li><a href="{{ route('docs.show', ['slug' => 'backup', 'lang' => $locale ?? 'en']) }}">Backup &amp; Restore</a></li>
                    <li><a href="{{ route('docs.show', ['slug' => 'gaps', 'lang' => $locale ?? 'en']) }}">Known Feature Gaps</a></li>
                </ul>
            </div>

            <div>
                <h4>Qualification Status</h4>
                <ul>
                    <li class="host-row"><span class="host">{{ $release['version'] ?? '0.5.0-dev.13' }}</span><span class="tag">DEV</span></li>
                    <li class="host-row"><span class="host">OpenWrt {{ $release['openwrt_base'] ?? '24.10.0' }}</span></li>
                    <li class="host-row"><span class="host">QEMU/KVM lab verified</span></li>
                    <li class="host-row"><span class="host">Bare-metal: not qualified</span></li>
                </ul>
            </div>

            <div>
                <h4>About &amp; Legal</h4>
                <ul>
                    <li><a href="{{ route('about', ['lang' => $locale ?? 'en']) }}">About NurRouter</a></li>
                    <li><a href="{{ route('blog', ['lang' => $locale ?? 'en']) }}">Engineering Blog</a></li>
                    <li><a href="{{ route('contact', ['lang' => $locale ?? 'en']) }}">Contact &amp; Support</a></li>
                    <li><a href="{{ route('terms', ['lang' => $locale ?? 'en']) }}">Terms of Use</a></li>
                    <li><a href="{{ route('privacy', ['lang' => $locale ?? 'en']) }}">Privacy Notice</a></li>
                </ul>
            </div>
        </div>

        <div class="container foot-bottom">
            <span>&copy; {{ date('Y') }} {{ $brand['name'] }}. Controlled-pilot release candidate documentation and artifacts.</span>
            <span><a href="{{ route('terms', ['lang' => $locale ?? 'en']) }}">Terms</a> · <a href="{{ route('privacy', ['lang' => $locale ?? 'en']) }}">Privacy</a> · <a href="{{ route('sitemap') }}">Sitemap</a> · {{ $brand['domain'] }}</span>
        </div>
    </footer>

    <script>
        document.documentElement.classList.add('js-nav');
        const menuButton = document.querySelector('.nav-toggle');
        const primaryNav = document.getElementById('primary-nav');
        if (menuButton && primaryNav) {
            menuButton.addEventListener('click', () => {
                const expanded = menuButton.getAttribute('aria-expanded') !== 'true';
                menuButton.setAttribute('aria-expanded', String(expanded));
                primaryNav.classList.toggle('nav-open', expanded);
            });
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape' && primaryNav.classList.contains('nav-open')) {
                    primaryNav.classList.remove('nav-open');
                    menuButton.setAttribute('aria-expanded', 'false');
                    menuButton.focus();
                }
            });
        }
    </script>
</body>
</html>
