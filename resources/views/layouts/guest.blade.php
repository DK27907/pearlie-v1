<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Pearlie Admin') }} · Sign in</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col justify-center items-center px-6 py-12 bg-slate-950">
            <div class="mb-8 text-center">
                <a href="/" class="inline-flex items-center gap-3">
                    <span class="grid h-14 w-14 place-items-center rounded-2xl bg-cyan-400 text-3xl">✚</span>
                    <span class="text-3xl font-bold tracking-tight text-white">Pearlie <span class="font-normal text-cyan-300">Admin</span></span>
                </a>
                <p class="mt-3 text-sm text-slate-400">Secure care team workspace</p>
            </div>

            <div class="w-full sm:max-w-md px-8 py-8 bg-white shadow-2xl shadow-cyan-950/30 overflow-hidden rounded-2xl">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
