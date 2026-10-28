<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="@yield('meta_description', $hospital->name.' — healthcare services and appointments.')">
    <meta name="theme-color" content="{{ $hospital->primary_color ?: '#0a2f44' }}">
    <title>@yield('title', hospital_branding()['header_text'])</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body
    class="tenant-public-page bg-slate-50 font-sans antialiased text-slate-900"
    style="--tenant-primary: {{ $hospital->primary_color ?: '#0a2f44' }}; --tenant-secondary: {{ $hospital->secondary_color ?: '#1a5276' }}"
>
    @yield('content')
    @stack('scripts')
</body>
</html>
