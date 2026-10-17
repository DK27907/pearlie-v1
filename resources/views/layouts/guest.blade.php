@extends('layouts.master')

@section('page')
    @php
        $isTenantAuthPage = request()->routeIs('tenant.login', 'tenant.password.request', 'tenant.password.email');
        $isPlatformPage = request()->routeIs('login', 'register', 'password.request', 'password.email', 'password.reset', 'password.store', 'superadmin.*');
        $branding = $isTenantAuthPage
            ? tenant_branding(hospital())
            : ($isPlatformPage ? platform_branding() : hospital_branding());
    @endphp
    <div class="mx-auto flex w-full max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] flex-1 items-center justify-center px-4 py-12 sm:px-6 lg:px-8">
        <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl sm:p-8">
            <div class="mb-6 text-center">
                @if ($isTenantAuthPage && hospital())
                    @if ($branding['logo_url'])
                        <img class="mx-auto h-14 w-14 rounded-xl border border-slate-200 bg-white object-contain p-1" src="{{ $branding['logo_url'] }}" alt="{{ $branding['name'] }} logo">
                    @else
                        <span class="mx-auto grid h-14 w-14 place-items-center rounded-xl bg-[var(--hospital-primary)] text-xl font-bold text-white" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($branding['name'], 0, 1)) }}</span>
                    @endif
                    <h1 class="mt-3 text-xl font-bold text-slate-900">{{ $branding['name'] }}</h1>
                    @if ($branding['tagline'])
                        <p class="mt-1 text-sm text-slate-600">{{ $branding['tagline'] }}</p>
                    @endif
                    @if ($branding['phone'] || $branding['address'])
                        <p class="mt-2 text-sm text-slate-500">
                            @if ($branding['phone'])
                                <a class="hover:text-slate-700" href="tel:{{ $branding['phone'] }}">{{ $branding['phone'] }}</a>
                            @endif
                            @if ($branding['phone'] && $branding['address'])
                                <span aria-hidden="true"> · </span>
                            @endif
                            @if ($branding['address'])
                                {{ $branding['address'] }}
                            @endif
                        </p>
                    @endif
                    @if (is_array($branding['hours']))
                        @foreach ($branding['hours'] as $day => $hours)
                            @if (is_array($hours) && ! ($hours['closed'] ?? false) && filled($hours['open'] ?? null) && filled($hours['close'] ?? null))
                                <p class="text-xs text-slate-500">{{ ucfirst($day) }}: {{ $hours['open'] }}–{{ $hours['close'] }}</p>
                            @endif
                        @endforeach
                    @elseif ($branding['hours'])
                        <p class="mt-1 text-xs text-slate-500">Hours: {{ $branding['hours'] }}</p>
                    @endif
                @else
                    <span class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-[#0a2f44] text-2xl text-white" aria-hidden="true">🏥</span>
                    <p class="mt-3 text-lg font-bold text-[#0a2f44]">{{ $branding['header_text'] }}</p>
                    @if (! request()->routeIs('login', 'register', 'password.request', 'password.email', 'password.reset', 'password.store') && hospital())
                        <p class="text-sm text-slate-500">{{ pearlie_config('hospital.location') }}</p>
                    @endif
                @endif
            </div>
            {{ $slot }}
            <div class="mt-6 border-t border-slate-100 pt-4 text-center">
                @if ($isTenantAuthPage && hospital())
                    <a href="{{ route('tenant.home', ['slug' => hospital()->slug]) }}" class="text-sm font-semibold text-sky-800 hover:text-sky-950">Return to hospital website</a>
                @else
                    <a href="{{ route('home') }}" class="text-sm font-semibold text-[#1a5276] hover:text-[#0a2f44]">Return to chat</a>
                @endif
            </div>
        </div>
    </div>
@endsection
