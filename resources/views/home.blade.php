@extends('layouts.app')

@section('content')
{{-- Index-First opening — chrome only. The lede/CTA/stats block below was
     deliberately trimmed (see the "trim homepage intro copy" commit); the
     endpoint index carries the page instead of a marketing hero. --}}
<div class="border-b border-[#241249]">
    <div class="mx-auto max-w-6xl px-5 pb-8 pt-10 sm:px-8 sm:pt-12">
        <h1 class="max-w-2xl font-display text-2xl font-bold leading-tight text-content-primary sm:text-[28px]">
            Booking Engine <span class="text-pink">API</span>
        </h1>
    </div>
</div>

<div class="mx-auto max-w-6xl px-5 py-10 sm:px-8">
    <div class="lg:flex lg:items-start lg:gap-10">
        {{-- Sidebar --}}
        <aside class="lg:sticky lg:top-[73px] lg:w-64 lg:shrink-0 lg:self-start">
            <div class="mb-4">
                <input
                    id="endpoint-search"
                    type="text"
                    placeholder="Filter endpoints&hellip;"
                    class="input !min-h-[40px] !text-sm"
                    autocomplete="off"
                >
            </div>
            <nav id="sidebar-nav" class="max-h-[75vh] space-y-5 overflow-y-auto pr-2 text-sm lg:max-h-[calc(100vh-140px)]">
                @foreach ($reference['groups'] as $group)
                    <div data-nav-group>
                        <a href="#{{ Str::slug($group['name']) }}" class="font-display text-[13px] font-semibold text-content-primary hover:text-gold">{{ $group['name'] }}</a>
                        @if (count($group['endpoints']))
                            <ul class="mt-1.5 space-y-1 border-l border-[#241249] pl-3">
                                @foreach ($group['endpoints'] as $endpoint)
                                    <li data-nav-item data-search="{{ strtolower($endpoint['method'].' '.$endpoint['path'].' '.$group['name']) }}">
                                        <a href="#{{ $endpoint['slug'] }}" class="flex items-center gap-2 py-0.5 text-content-muted transition hover:text-content-primary">
                                            <span class="font-mono text-[10px] font-bold {{ match($endpoint['method']) { 'GET' => 'text-gold', 'POST' => 'text-pink', 'DELETE' => 'text-red-400', default => 'text-[#8cc8ff]' } }}">{{ $endpoint['method'] }}</span>
                                            <span class="truncate font-mono text-[11px]">{{ preg_replace('#^/api/v1#', '', $endpoint['path']) ?: '/' }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endforeach
            </nav>
        </aside>

        {{-- Main content --}}
        <div class="mt-12 min-w-0 flex-1 lg:mt-0">
            @foreach ($reference['groups'] as $group)
                <section id="{{ Str::slug($group['name']) }}" class="scroll-mt-24 border-b border-[#241249] py-10 first:pt-0 last:border-0">
                    <h2 class="font-display text-2xl font-bold text-content-primary">{{ $group['name'] }}</h2>

                    @if (count($group['endpoints']) === 0)
                        {{-- Prose-only group (Global conventions, Enums reference) --}}
                        <div class="prose-docs mt-4">
                            {!! Str::markdown($group['intro']) !!}
                        </div>
                    @else
                        @if ($group['intro'])
                            <div class="prose-docs mt-3">
                                {!! Str::markdown($group['intro']) !!}
                            </div>
                        @endif

                        <div class="mt-6 space-y-5">
                            @foreach ($group['endpoints'] as $endpoint)
                                <article
                                    id="{{ $endpoint['slug'] }}"
                                    class="surface-card scroll-mt-24 p-6"
                                    data-endpoint-card
                                    data-search="{{ strtolower($endpoint['method'].' '.$endpoint['path'].' '.$group['name']) }}"
                                >
                                    <div class="flex flex-wrap items-center gap-2.5">
                                        <span class="chip-method chip-method-{{ strtolower($endpoint['method']) }}">{{ $endpoint['method'] }}</span>
                                        <code class="break-all font-mono text-[13px] font-semibold text-content-primary">{{ $endpoint['path'] }}</code>
                                        @if ($endpoint['idempotent'])
                                            <span class="chip">Idempotent</span>
                                        @endif
                                        <button
                                            type="button"
                                            class="copy-path-btn ml-auto shrink-0 text-[11px] font-medium text-content-muted transition hover:text-gold"
                                            data-copy="{{ $endpoint['path'] }}"
                                        >Copy path</button>
                                    </div>

                                    <div class="prose-docs mt-4">
                                        {!! Str::markdown($endpoint['body']) !!}
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    </div>
</div>
@endsection
