<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name', 'Booking Engine') }}</title>
    <meta name="description" content="{{ $description ?? 'Multi-provider booking engine API — resources, availability, bookings, payments, webhooks, and more.' }}">

    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-app text-content-primary min-h-screen">
    <nav class="surface-nav sticky top-0 z-40">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-5 py-4 sm:px-8">
            <a href="{{ url('/') }}" class="flex items-center gap-2.5">
                <span class="glow-line block h-6 w-1.5 rounded-full"></span>
                <span class="font-display text-[15px] font-bold tracking-tight text-content-primary">Booking Engine</span>
            </a>

            <div class="hidden items-center gap-6 text-sm font-medium text-content-secondary sm:flex">
                <a href="{{ url('/') }}" class="transition hover:text-content-primary {{ request()->is('/') ? 'nav-link-active' : '' }}">API Reference</a>
                <a href="{{ url('/deployment') }}" class="transition hover:text-content-primary {{ request()->is('deployment') ? 'nav-link-active' : '' }}">Deployment</a>
                <a href="{{ url('/docs') }}" class="transition hover:text-content-primary">Swagger&nbsp;UI</a>
                <a href="https://github.com/incatswetrust/booking-engine" target="_blank" rel="noopener" aria-label="View source on GitHub" class="flex items-center text-content-secondary transition hover:text-content-primary">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">
                        <path d="M12 .5C5.65.5.5 5.65.5 12c0 5.08 3.29 9.39 7.86 10.91.57.1.78-.25.78-.55 0-.27-.01-1.17-.02-2.12-3.2.7-3.88-1.36-3.88-1.36-.52-1.33-1.28-1.68-1.28-1.68-1.04-.72.08-.7.08-.7 1.15.08 1.76 1.19 1.76 1.19 1.03 1.76 2.7 1.25 3.36.96.1-.75.4-1.25.73-1.54-2.55-.29-5.24-1.28-5.24-5.68 0-1.26.45-2.28 1.19-3.09-.12-.29-.51-1.46.11-3.05 0 0 .97-.31 3.18 1.18a11 11 0 0 1 5.79 0c2.2-1.49 3.17-1.18 3.17-1.18.63 1.59.24 2.76.12 3.05.74.81 1.19 1.83 1.19 3.09 0 4.41-2.69 5.39-5.25 5.67.41.36.78 1.06.78 2.14 0 1.55-.01 2.8-.01 3.18 0 .31.2.66.79.55A10.52 10.52 0 0 0 23.5 12C23.5 5.65 18.35.5 12 .5Z"/>
                    </svg>
                </a>
            </div>

            <a href="{{ url('/docs') }}" class="btn-tertiary !min-h-0 !px-4 !py-2 text-xs sm:hidden">Swagger</a>
        </div>
    </nav>

    <main>
        @yield('content')
    </main>

    <footer class="border-t border-[#241249] px-5 py-10 sm:px-8">
        <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 text-xs text-content-muted sm:flex-row">
            <p>Booking Engine API &mdash; MIT Licensed.</p>
            <div class="flex items-center gap-5">
                <a href="{{ url('/') }}" class="transition hover:text-content-primary">API Reference</a>
                <a href="{{ url('/deployment') }}" class="transition hover:text-content-primary">Deployment</a>
                <a href="{{ url('/health') }}" class="transition hover:text-content-primary">Health</a>
                <a href="https://github.com/incatswetrust/booking-engine" target="_blank" rel="noopener" class="transition hover:text-content-primary">Source</a>
            </div>
        </div>
    </footer>
</body>
</html>
