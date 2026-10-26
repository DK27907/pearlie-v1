@php
    $isPlatformPage = request()->routeIs('login', 'register', 'password.request', 'password.email', 'password.reset', 'password.store', 'superadmin.*')
        || hospital() === null;
    $branding = $isPlatformPage ? platform_branding() : hospital_branding();
    $botName = $isPlatformPage ? 'Assistant' : (hospital()?->chatbotName() ?? 'Assistant');
    $metaDescription = $isPlatformPage
        ? $branding['header_text'].' — healthcare platform.'
        : $branding['header_text'].' in '.pearlie_config('hospital.location').'. Chat with '.$botName.' or book an appointment.';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="@yield('meta_description', $metaDescription)">
    <meta name="theme-color" content="{{ $branding['primary_color'] }}">
    <title>@yield('title', $branding['header_text'])</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="flex min-h-screen flex-col bg-slate-50 font-sans antialiased text-slate-900" style="--hospital-primary: {{ $branding['primary_color'] }}; --hospital-secondary: {{ $branding['secondary_color'] }}">
    <x-site-header />

    @if (auth()->user()?->isSuperAdmin() && session()->has('impersonating_hospital_id'))
        <div class="bg-amber-100 px-4 py-2 text-center text-sm font-semibold text-amber-950">
            Viewing {{ hospital()?->name }} as a platform administrator.
            <form method="POST" action="{{ route('superadmin.stop-impersonating') }}" class="ml-2 inline">
                @csrf
                <button class="underline" type="submit">Stop viewing hospital</button>
            </form>
        </div>
    @endif

    @if (auth()->check() && auth()->user()->isDoctor() && request()->routeIs('doctor.*'))
        @include('doctor.partials.nav')
    @endif

    @if (auth()->check() && auth()->user()->isAdmin() && request()->routeIs('admin.*'))
        <div class="mx-auto grid w-full max-w-[90rem] flex-1 gap-6 px-4 py-6 sm:px-6 lg:grid-cols-[15rem_minmax(0,1fr)] lg:px-8">
            <aside class="h-fit rounded-2xl border border-slate-200 bg-white p-3 shadow-sm lg:sticky lg:top-24" aria-label="Admin sidebar">
                <p class="px-3 py-2 text-xs font-bold uppercase tracking-wider text-slate-500">Hospital admin</p>
                <nav aria-label="Admin navigation" class="grid grid-cols-2 gap-1 lg:grid-cols-1">
                    <a href="{{ route('admin.dashboard') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Dashboard</a>
                    <a href="{{ route('admin.users.index') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Users</a>
                    @if (hospital()?->hasFeature('booking'))
                        <a href="{{ route('admin.appointments.index') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Appointments</a>
                        <a href="{{ route('admin.walk-in.create') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Walk-in booking</a>
                    @endif
                    @if (hospital()?->hasFeature('doctors'))
                        <a href="{{ route('admin.doctors.index') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Doctors</a>
                    @endif
                    <a href="{{ route('admin.services.index') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Service catalog</a>
                    <a href="{{ route('admin.knowledge-services.index') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Service answers</a>
                    <a href="{{ route('admin.knowledge-base.index') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Knowledge base</a>
                    <a href="{{ route('admin.conversations.index') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Conversations</a>
                    @if (hospital()?->hasFeature('escalation'))
                        <a href="{{ route('admin.escalations.index') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Escalations</a>
                    @endif
                    <a href="{{ route('admin.payments.index') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Payments</a>
                    <a href="{{ route('admin.audit.index') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Audit trail</a>
                    <a href="{{ route('admin.announcements.index') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Announcements</a>
                    <a href="{{ route('admin.notification-logs.index') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Notification logs</a>
                    <a href="{{ route('admin.reports.index') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Reports</a>
                    <a href="{{ route('admin.integration-settings.edit') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Settings</a>
                    <a href="{{ route('admin.integrations.index') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">API and webhooks</a>
                    <a href="{{ route('hospital.onboarding') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Hospital setup</a>
                </nav>
            </aside>
            <main class="min-w-0">@yield('page')</main>
        </div>
    @else
        <main class="flex-1">
            @yield('page')
        </main>
    @endif

    <x-site-footer />
    @stack('scripts')
</body>
</html>
