<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="@yield('meta_description', 'MediDesk AI platform administration.')">
    <meta name="theme-color" content="#0a2f44">
    <title>@yield('title', 'MediDesk AI')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="flex min-h-screen flex-col bg-slate-50 font-sans antialiased text-slate-900">
    <header class="border-b border-slate-200 bg-white">
        <nav aria-label="Platform administration" class="mx-auto flex max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
            <a href="{{ route('superadmin.dashboard') }}" class="text-lg font-bold tracking-tight text-[#0a2f44]">MediDesk AI</a>
            <div class="flex items-center gap-4">
                <a href="{{ route('superadmin.hospitals.index') }}" class="text-sm font-semibold text-slate-700 hover:text-[#1a5276]">Hospitals</a>
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
        MediDesk AI platform administration
    </footer>
    @stack('scripts')
</body>
</html>
