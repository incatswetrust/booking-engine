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
                <a href="https://github.com/incatswetrust/booking-engine" target="_blank" rel="noopener" class="btn-tertiary !min-h-0 !px-4 !py-2">GitHub</a>
            </div>

            <a href="{{ url('/docs') }}" class="btn-tertiary !min-h-0 !px-4 !py-2 text-xs sm:hidden">Swagger</a>
        </div>
    </nav>

    <main>
        @yield('content')
    </main>

    <footer class="border-t border-white/[0.06] px-5 py-10 sm:px-8">
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
