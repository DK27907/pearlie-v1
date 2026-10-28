<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="@yield('meta_description', 'MediDesk Assistant platform administration.')">
    <meta name="theme-color" content="#0a2f44">
    <title>@yield('title', 'MediDesk Assistant')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="app-surface flex min-h-screen flex-col bg-slate-50 font-sans antialiased text-slate-900">
    <header class="sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 shadow-sm backdrop-blur">
        <nav aria-label="Platform administration" class="mx-auto flex max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
            <a href="{{ route('superadmin.dashboard') }}" class="flex items-center gap-3 text-lg font-bold tracking-tight text-[#0a2f44]">
                <img class="h-10 w-10 rounded-xl object-cover" src="{{ asset('images/medidesk-assistant-logo.jpg') }}" alt="">
                <span>MediDesk Assistant</span>
            </a>
            <div class="flex flex-wrap items-center justify-end gap-2 sm:gap-4">
                <a href="{{ route('superadmin.hospitals.index') }}" class="text-sm font-semibold text-slate-700 hover:text-[#1a5276]">Hospitals</a>
                <a href="{{ route('superadmin.users.index') }}" class="text-sm font-semibold text-slate-700 hover:text-[#1a5276]">Users</a>
                @auth
                    <span class="hidden text-sm text-slate-500 sm:inline">{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-sm font-semibold text-slate-700 hover:text-[#1a5276]">Log out</button>
                    </form>
                @endauth
            </div>
        </nav>
    </header>
    <main class="flex-1">
        @yield('content')
    </main>
    <footer class="border-t border-slate-200 bg-white px-4 py-4 text-center text-xs text-slate-500">
        MediDesk Assistant platform administration
    </footer>
    @stack('scripts')
</body>
</html>
