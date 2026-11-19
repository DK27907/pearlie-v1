@extends('layouts.master')

@section('auth-surface', 'true')

@section('page')
    @php
        $isTenantAuthPage = request()->routeIs('tenant.login', 'tenant.password.*');
        $isPlatformPage = ! $isTenantAuthPage
            && (request()->routeIs('login', 'register', 'password.*', 'superadmin.*') || hospital() === null);
        $branding = $isTenantAuthPage
            ? array_merge(hospital_branding(), tenant_branding(hospital()))
            : ($isPlatformPage ? platform_branding() : hospital_branding());
        $authBrandName = $branding['name'] ?? $branding['header_text'] ?? 'MediDesk Assistant';
        $isPlatformLogin = request()->routeIs('login');
        $isSplitAuthPage = request()->routeIs('login', 'register', 'tenant.login');
        $supportEmail = config('mail.from.address');
    @endphp
    <div @class([
        'auth-shell grid w-full min-w-0 flex-1 grid-cols-1',
        'lg:grid-cols-2' => $isSplitAuthPage,
    ]) style="--auth-primary: {{ $branding['primary_color'] ?? '#06b6d4' }}; --auth-secondary: {{ $branding['secondary_color'] ?? '#0891b2' }}">
        <aside @class([
            'auth-brand-panel animate-gradient relative min-h-screen flex-col justify-between overflow-hidden px-10 py-10 text-white xl:px-16 xl:py-14',
            'hidden lg:flex' => $isSplitAuthPage,
            'hidden' => ! $isSplitAuthPage,
        ])>
            <a href="{{ $isTenantAuthPage && hospital() ? route('tenant.home', ['slug' => hospital()->slug]) : route('home') }}" class="relative z-10 flex items-center gap-4">
                @if ($isTenantAuthPage && $branding['logo_url'])
                    <img class="animate-scale-in h-14 w-14 rounded-2xl border border-white/30 bg-white object-contain p-1 shadow-xl" src="{{ $branding['logo_url'] }}" alt="{{ $branding['name'] }} logo">
                @elseif ($isPlatformPage)
                    <img class="animate-scale-in h-14 w-14 rounded-2xl border border-white/20 object-cover shadow-xl" src="{{ asset('images/medidesk-assistant-logo.jpg') }}" alt="MediDesk Assistant logo">
                @else
                    <span class="grid h-14 w-14 place-items-center rounded-2xl border border-white/25 bg-white/10 text-2xl font-bold shadow-xl" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($authBrandName, 0, 1)) }}</span>
                @endif
                <span>
                    <span class="block text-xl font-bold tracking-tight">{{ $branding['header_text'] ?? $authBrandName }}</span>
                    <span class="mt-1 block text-sm text-white/75">{{ $isPlatformPage ? config('app.tagline', 'AI-powered healthcare operations') : 'Secure workspace' }}</span>
                </span>
            </a>

            <div class="relative z-10 my-16 max-w-xl">
                <p class="text-xs font-bold uppercase tracking-[0.24em] text-cyan-100">{{ $isTenantAuthPage ? 'Welcome to' : 'MediDesk Assistant' }}</p>
                <h2 class="font-display mt-5 text-4xl font-semibold leading-tight tracking-tight xl:text-5xl">
                    {{ $isTenantAuthPage ? $branding['name'] : 'The 24/7 AI front desk for Kenyan hospitals.' }}
                </h2>
                <p class="mt-5 max-w-lg text-lg leading-8 text-white/85">
                    {{ $isTenantAuthPage
                        ? ($branding['tagline'] ?: 'A secure workspace for your hospital care team.')
                        : 'A calmer, clearer way to welcome patients and keep care operations moving.' }}
                </p>

                @if ($isPlatformPage)
                    <ul class="mt-8 grid gap-4 text-sm font-medium text-white/90">
                        <li class="flex items-center gap-3"><span class="grid h-8 w-8 place-items-center rounded-lg border border-white/15 bg-white/10 text-cyan-100" aria-hidden="true">◎</span>Bilingual patient conversations</li>
                        <li class="flex items-center gap-3"><span class="grid h-8 w-8 place-items-center rounded-lg border border-white/15 bg-white/10 text-cyan-100" aria-hidden="true">↗</span>Real appointment bookings</li>
                        <li class="flex items-center gap-3"><span class="grid h-8 w-8 place-items-center rounded-lg border border-white/15 bg-white/10 text-cyan-100" aria-hidden="true">✓</span>M-Pesa ready workflows</li>
                    </ul>
                @endif

                @if ($isTenantAuthPage && ($branding['phone'] || $branding['address']))
                    <p class="mt-7 text-sm leading-6 text-white/75">
                        @if ($branding['phone'])
                            <a class="underline decoration-white/40 underline-offset-4 hover:text-white" href="tel:{{ $branding['phone'] }}">{{ $branding['phone'] }}</a>
                        @endif
                        @if ($branding['phone'] && $branding['address'])
                            <span aria-hidden="true"> · </span>
                        @endif
                        @if ($branding['address'])
                            {{ $branding['address'] }}
                        @endif
                    </p>
                @endif

                @if ($isTenantAuthPage && is_array($branding['hours']))
                    <div class="mt-2 grid gap-1 text-xs text-white/70">
                        @foreach ($branding['hours'] as $day => $hours)
                            @if (is_array($hours) && ! ($hours['closed'] ?? false) && filled($hours['open'] ?? null) && filled($hours['close'] ?? null))
                                <p>{{ ucfirst($day) }}: {{ $hours['open'] }}–{{ $hours['close'] }}</p>
                            @endif
                        @endforeach
                    </div>
                @elseif ($isTenantAuthPage && $branding['hours'])
                    <p class="mt-2 text-xs text-white/70">Hours: {{ $branding['hours'] }}</p>
                @endif
            </div>

            <p class="relative z-10 text-xs text-white/65">{{ $isPlatformPage ? 'Powered by AxiomForge Digital' : $branding['footer_text'] }}</p>
            <div class="pointer-events-none absolute -bottom-28 -right-20 h-96 w-96 rounded-full border border-white/10" aria-hidden="true"></div>
            <div class="pointer-events-none absolute -bottom-16 -right-8 h-72 w-72 rounded-full border border-white/10" aria-hidden="true"></div>
        </aside>

        <section class="grid min-h-screen w-full min-w-0 place-items-center px-4 py-8 sm:px-6 lg:px-10">
            <div class="auth-card animate-fade-up glass-card w-full min-w-0 max-w-md p-6 sm:p-10">
                <div class="mb-7 text-center">
                    @if ($isTenantAuthPage && $branding['logo_url'])
                        <img class="mx-auto h-14 w-14 rounded-2xl border border-slate-200 bg-white object-contain p-1 shadow-sm" src="{{ $branding['logo_url'] }}" alt="{{ $branding['name'] }} logo">
                    @elseif ($isPlatformPage)
                        <img class="mx-auto h-14 w-14 rounded-2xl object-cover shadow-sm" src="{{ asset('images/medidesk-assistant-logo.jpg') }}" alt="MediDesk Assistant logo">
                    @else
                        <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-[var(--hospital-primary)] text-xl font-bold text-white" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($authBrandName, 0, 1)) }}</span>
                    @endif
                    <p class="mt-3 text-lg font-bold text-midnight">{{ $branding['header_text'] ?? $authBrandName }}</p>
                </div>

                {{ $slot }}

                <div class="mt-7 border-t border-slate-100 pt-5 text-center">
                    @if ($isTenantAuthPage && hospital())
                        <a href="{{ route('tenant.home', ['slug' => hospital()->slug]) }}" class="rounded-md text-sm font-semibold text-[var(--hospital-primary)] underline-offset-4 hover:underline">Return to hospital website</a>
                    @else
                        <a href="{{ route('home') }}" class="rounded-md text-sm font-semibold text-cyan-800 underline-offset-4 hover:underline">Return to chat</a>
                    @endif
                </div>

                @if ($isPlatformLogin)
                    <div class="mt-5 border-t border-slate-100 pt-4 text-center text-xs text-slate-500">
                        <div class="flex flex-wrap items-center justify-center gap-x-4 gap-y-2">
                            @if ($supportEmail)
                                <a class="font-semibold text-cyan-800 hover:underline" href="mailto:{{ $supportEmail }}">Need help?</a>
                            @endif
                            <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 font-semibold">v{{ config('app.version', '1.0') }}</span>
                        </div>
                        <div class="mt-3 flex justify-center gap-4">
                            <a class="hover:text-slate-800" href="#">Privacy</a>
                            <a class="hover:text-slate-800" href="#">Terms</a>
                        </div>
                        <p class="mt-3">© {{ date('Y') }} {{ $branding['header_text'] }}. All rights reserved.</p>
                    </div>
                @endif
            </div>
        </section>
    </div>
@endsection
