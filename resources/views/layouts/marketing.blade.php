<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="@yield('meta_description', 'MediDesk Assistant is the 24/7 digital front desk for hospitals.')">
    <meta name="theme-color" content="#0a2f44">
    <title>@yield('title', 'MediDesk Assistant — Digital Front Desk for Hospitals')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="marketing-page bg-white font-sans antialiased text-slate-900">
    @yield('content')
    @stack('scripts')
</body>
</html>
