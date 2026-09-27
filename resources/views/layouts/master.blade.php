<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="@yield('meta_description', pearlie_config('hospital.name').' in '.pearlie_config('hospital.location').'. Chat with Pearlie or book an appointment.')">
    <meta name="theme-color" content="{{ hospital()?->primary_color ?? '#0a2f44' }}">
    <title>@yield('title', pearlie_config('hospital.name'))</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="flex min-h-screen flex-col bg-slate-50 font-sans antialiased text-slate-900" style="--hospital-primary: {{ hospital()?->primary_color ?? '#0a2f44' }}; --hospital-secondary: {{ hospital()?->secondary_color ?? '#1a5276' }}">
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
        <nav aria-label="Admin navigation" class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-7xl gap-2 overflow-x-auto px-4 py-2 sm:px-6 lg:px-8">
                <a href="{{ url('/admin/dashboard') }}" class="whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Dashboard</a>
                @if (hospital()?->hasFeature('booking'))
                    <a href="{{ route('admin.appointments.index') }}" class="whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Appointments</a>
                @endif
                @if (hospital()?->hasFeature('doctors'))
                    <a href="{{ route('admin.doctors.index') }}" class="whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Doctors</a>
                @endif
                @if (hospital()?->hasFeature('escalation'))
                    <a href="{{ route('admin.escalations.index') }}" class="whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Escalations</a>
                @endif
                <a href="{{ route('hospital.onboarding') }}" class="whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Hospital setup</a>
            </div>
        </nav>
    @endif

    <main class="flex-1">
        @yield('page')
    </main>

    <x-site-footer />
    @stack('scripts')
</body>
</html>
