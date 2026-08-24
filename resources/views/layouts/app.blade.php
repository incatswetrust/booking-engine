<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Bukakke Monster' }}</title>
    <meta name="description" content="{{ $description ?? 'Multi-provider booking engine API — resources, availability, bookings, payments, webhooks, and more.' }}">

    <link rel="icon" type="image/png" href="{{ asset('images/favicon-32.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">

    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-app text-content-primary min-h-screen">
    @php
        $cmdkReference = require resource_path('data/api-reference.php');
        $cmdkEndpoints = collect($cmdkReference['groups'] ?? [])->flatMap(
            fn ($group) => collect($group['endpoints'] ?? [])->map(fn ($endpoint) => [
                'label' => $endpoint['method'].' '.$endpoint['path'],
                'method' => $endpoint['method'],
                'group' => $group['name'],
                'href' => url('/').'#'.$endpoint['slug'],
            ])
        );
        $cmdkPages = collect([
            ['label' => 'API Reference', 'method' => 'PAGE', 'group' => 'Pages', 'href' => url('/')],
            ['label' => 'Deployment guide', 'method' => 'PAGE', 'group' => 'Pages', 'href' => url('/deployment')],
            ['label' => 'Swagger UI', 'method' => 'PAGE', 'group' => 'Pages', 'href' => url('/docs')],
            ['label' => 'Health check', 'method' => 'PAGE', 'group' => 'Pages', 'href' => url('/health')],
        ]);
        $cmdkIndex = $cmdkPages->concat($cmdkEndpoints)->values();
    @endphp

    <nav class="surface-nav sticky top-0 z-40">
        <div class="mx-auto flex max-w-6xl items-center gap-3 px-5 py-3.5 sm:gap-5 sm:px-8">
            <a href="{{ url('/') }}" class="flex shrink-0 items-center gap-2.5">
                <img src="{{ asset('images/mnstr.png') }}" alt="Bukakke Monster" class="h-7 w-7 rounded-md object-cover">
                <span class="hidden font-display text-[15px] font-bold tracking-tight text-content-primary sm:inline">Bukakke Monster</span>
            </a>

            <button
                type="button"
                id="searchpill"
                class="searchpill min-w-0 flex-1 sm:max-w-xs"
                aria-haspopup="dialog"
                aria-expanded="false"
                aria-controls="cmdk"
            >
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                <span class="searchpill-text">Search endpoints&hellip;</span>
                <span class="searchpill-kbd"><kbd>&#8984;</kbd><kbd>K</kbd></span>
            </button>

            <div class="ml-auto hidden shrink-0 items-center gap-6 text-sm font-medium text-content-secondary sm:flex">
                <a href="{{ url('/') }}" class="transition hover:text-content-primary {{ request()->is('/') ? 'nav-link-active' : '' }}">API Reference</a>
                <a href="{{ url('/deployment') }}" class="transition hover:text-content-primary {{ request()->is('deployment') ? 'nav-link-active' : '' }}">Deployment</a>
                <a href="{{ url('/docs') }}" class="transition hover:text-content-primary">Swagger&nbsp;UI</a>
                <a href="https://github.com/incatswetrust/booking-engine" target="_blank" rel="noopener" aria-label="View source on GitHub" class="flex items-center text-content-secondary transition hover:text-content-primary">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">
                        <path d="M12 .5C5.65.5.5 5.65.5 12c0 5.08 3.29 9.39 7.86 10.91.57.1.78-.25.78-.55 0-.27-.01-1.17-.02-2.12-3.2.7-3.88-1.36-3.88-1.36-.52-1.33-1.28-1.68-1.28-1.68-1.04-.72.08-.7.08-.7 1.15.08 1.76 1.19 1.76 1.19 1.03 1.76 2.7 1.25 3.36.96.1-.75.4-1.25.73-1.54-2.55-.29-5.24-1.28-5.24-5.68 0-1.26.45-2.28 1.19-3.09-.12-.29-.51-1.46.11-3.05 0 0 .97-.31 3.18 1.18a11 11 0 0 1 5.79 0c2.2-1.49 3.17-1.18 3.17-1.18.63 1.59.24 2.76.12 3.05.74.81 1.19 1.83 1.19 3.09 0 4.41-2.69 5.39-5.25 5.67.41.36.78 1.06.78 2.14 0 1.55-.01 2.8-.01 3.18 0 .31.2.66.79.55A10.52 10.52 0 0 0 23.5 12C23.5 5.65 18.35.5 12 .5Z"/>
                    </svg>
                </a>
            </div>
        </div>
    </nav>

    <div class="cmdk" id="cmdk" aria-hidden="true">
        <div class="cmdk-backdrop" data-cmdk-close></div>
        <div class="cmdk-panel" role="dialog" aria-modal="true" aria-label="Search endpoints and pages">
            <div class="cmdk-field">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                <input id="cmdk-input" type="text" placeholder="Search endpoints, pages&hellip;" autocomplete="off" spellcheck="false">
                <kbd class="cmdk-esc">esc</kbd>
            </div>
            <div class="cmdk-results" id="cmdk-results"></div>
            <div class="cmdk-foot">
                <span><kbd>&uarr;</kbd><kbd>&darr;</kbd> navigate</span>
                <span><kbd>&crarr;</kbd> open</span>
                <span><kbd>esc</kbd> close</span>
            </div>
        </div>
    </div>
    <script type="application/json" id="cmdk-data">{!! json_encode($cmdkIndex, JSON_UNESCAPED_SLASHES) !!}</script>

    <main>
        @yield('content')
    </main>

    <footer class="foot-mast border-t border-[#241249] px-5 py-10 sm:px-8">
        <div class="mx-auto flex max-w-6xl flex-col gap-8 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="foot-mast-wordmark">Bukakke Monster</p>
                <p class="foot-mast-tagline">A multi-provider booking engine API &mdash; resources, availability, bookings, payments, webhooks, and calendar sync.</p>
            </div>
            <div class="sm:text-right">
                <nav class="foot-mast-links sm:justify-end">
                    <a href="{{ url('/') }}" class="transition">API Reference</a>
                    <a href="{{ url('/deployment') }}" class="transition">Deployment</a>
                    <a href="{{ url('/health') }}" class="transition">Health</a>
                    <a href="https://github.com/incatswetrust/booking-engine" target="_blank" rel="noopener" class="transition">Source</a>
                </nav>
                <p class="foot-mast-meta">MIT Licensed.</p>
            </div>
        </div>
    </footer>
</body>
</html>
