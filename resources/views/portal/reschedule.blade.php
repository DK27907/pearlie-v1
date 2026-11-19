<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reschedule appointment · {{ $hospital->name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
    <main class="mx-auto max-w-2xl space-y-6 px-4 py-10 sm:px-6">
        <a href="{{ $showUrl }}" class="text-sm font-semibold text-cyan-800 hover:underline">← Appointment details</a>
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-semibold text-cyan-800">{{ $hospital->name }}</p>
            <h1 class="mt-2 text-2xl font-bold tracking-tight">Choose a new appointment time</h1>
            <p class="mt-2 text-sm text-slate-600">Available times are for {{ $appointment->doctor?->name ?? 'your doctor' }}.</p>
            @if ($errors->any())
                <p role="alert" class="mt-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">{{ $errors->first() }}</p>
            @endif
            @if ($slots === [])
                <p class="mt-6 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">No available times are currently listed. Please contact {{ $hospital->phone ?: $hospital->name }} for help.</p>
            @else
                <form method="POST" action="{{ $rescheduleUrl }}" class="mt-6 grid gap-4">
                    @csrf
                    <label class="grid gap-2 text-sm font-semibold text-slate-700" for="slot">Available date and time
                        <select id="slot" name="date_time" required class="rounded-xl border-slate-300">
                            <option value="">Select a time</option>
                            @foreach ($slots as $slot)
                                <option value="{{ $slot['date'].'|'.$slot['start'] }}" @selected(old('date_time') === $slot['date'].'|'.$slot['start'])>
                                    {{ \Carbon\CarbonImmutable::parse($slot['date'])->format('D, M j, Y') }} · {{ \Carbon\CarbonImmutable::parse($slot['start'])->format('g:i A') }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit" class="rounded-xl bg-[#0a2f44] px-4 py-3 font-semibold text-white hover:bg-cyan-900">Confirm new time</button>
                </form>
            @endif
        </section>
    </main>
</body>
</html>
