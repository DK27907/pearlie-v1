<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Appointment {{ $appointment->booking_reference }} · {{ $hospital->name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
    <main class="mx-auto max-w-3xl space-y-6 px-4 py-10 sm:px-6">
        <header class="flex items-center gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            @if ($hospital->logo_url)
                <img src="{{ $hospital->logo_url }}" alt="{{ $hospital->name }} logo" class="h-14 w-auto max-w-40 object-contain">
            @else
                <span class="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-cyan-100 text-lg font-bold text-cyan-900">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($hospital->name, 0, 2)) }}</span>
            @endif
            <div class="min-w-0">
                <p class="text-sm font-semibold text-cyan-800">{{ $hospital->name }}</p>
                <h1 class="mt-1 text-xl font-bold tracking-tight sm:text-2xl">Your appointment</h1>
            </div>
        </header>

        @if (session('status'))
            <p role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</p>
        @elseif (session('warning'))
            <p role="status" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ session('warning') }}</p>
        @endif
        @if ($errors->any())
            <p role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">{{ $errors->first() }}</p>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-slate-500">Booking reference</p>
                    <p class="mt-1 font-mono text-lg font-bold text-slate-900">{{ $appointment->booking_reference }}</p>
                </div>
                <span class="rounded-full bg-cyan-50 px-3 py-1 text-sm font-semibold capitalize text-cyan-900">{{ str_replace('_', ' ', $appointment->status) }}</span>
            </div>
            <dl class="mt-7 grid gap-5 border-t border-slate-100 pt-6 sm:grid-cols-2">
                <div><dt class="text-sm text-slate-500">Patient</dt><dd class="mt-1 font-semibold">{{ $appointment->name }}</dd></div>
                <div><dt class="text-sm text-slate-500">Service</dt><dd class="mt-1 font-semibold">{{ $appointment->service?->name ?? $appointment->reason }}</dd></div>
                <div><dt class="text-sm text-slate-500">Doctor</dt><dd class="mt-1 font-semibold">{{ $appointment->doctor?->name ?? 'To be assigned' }}</dd></div>
                <div><dt class="text-sm text-slate-500">Date and time</dt><dd class="mt-1 font-semibold">{{ $appointment->preferred_date?->format('l, F j, Y') ?? 'To be arranged' }}{{ $appointment->slot_start_time ? ' · '.\Carbon\CarbonImmutable::parse($appointment->slot_start_time)->format('g:i A') : '' }}</dd></div>
            </dl>
        </section>

        <section class="grid gap-3 sm:grid-cols-3">
            <a href="{{ $calendarUrl }}" class="rounded-xl bg-[#0a2f44] px-4 py-3 text-center text-sm font-semibold text-white hover:bg-cyan-900">Add to Calendar</a>
            @if (in_array($appointment->status, ['pending', 'confirmed'], true))
                <a href="{{ $rescheduleUrl }}" class="rounded-xl border border-cyan-800 bg-white px-4 py-3 text-center text-sm font-semibold text-cyan-900 hover:bg-cyan-50">Reschedule</a>
                <form method="POST" action="{{ $cancelUrl }}" data-confirm="Cancel this appointment?">
                    @csrf
                    <button type="submit" class="w-full rounded-xl border border-rose-300 bg-white px-4 py-3 text-sm font-semibold text-rose-800 hover:bg-rose-50">Cancel</button>
                </form>
            @endif
        </section>

        <footer class="rounded-2xl border border-slate-200 bg-white p-5 text-sm text-slate-600">
            <h2 class="font-semibold text-slate-900">{{ $hospital->name }}</h2>
            @if ($hospital->address || $hospital->city)
                <p class="mt-2">{{ implode(', ', array_filter([$hospital->address, $hospital->city])) }}</p>
            @endif
            @if ($hospital->phone)
                <p class="mt-1">Phone: <a class="font-medium text-cyan-800 underline" href="tel:{{ $hospital->phone }}">{{ $hospital->phone }}</a></p>
            @endif
        </footer>
    </main>
</body>
</html>
