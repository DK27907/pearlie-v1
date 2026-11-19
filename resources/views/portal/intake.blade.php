<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pre-visit intake · {{ $appointment->hospital?->name ?? 'MediDesk Assistant' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
    <main class="mx-auto max-w-2xl px-4 py-10 sm:px-6">
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-semibold text-cyan-800">{{ $appointment->hospital?->name ?? 'MediDesk Assistant' }}</p>
            <h1 class="mt-2 text-2xl font-bold tracking-tight">Pre-visit intake form</h1>
            <p class="mt-2 text-sm text-slate-600">Please share the information below so your care team can prepare for your visit.</p>
            @if ($errors->any())
                <p role="alert" class="mt-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">{{ $errors->first() }}</p>
            @endif
            <form method="POST" action="{{ route('intake.submit', ['token' => request()->route('token')]) }}" class="mt-6 grid gap-5">
                @csrf
                <label class="grid gap-2 text-sm font-semibold text-slate-700">Allergies
                    <textarea name="allergies" rows="3" maxlength="5000" class="rounded-xl border-slate-300 font-normal" placeholder="List known allergies, or leave blank if none">{{ old('allergies') }}</textarea>
                </label>
                <label class="grid gap-2 text-sm font-semibold text-slate-700">Current medications
                    <textarea name="medications" rows="3" maxlength="5000" class="rounded-xl border-slate-300 font-normal">{{ old('medications') }}</textarea>
                </label>
                <label class="grid gap-2 text-sm font-semibold text-slate-700">Symptoms / reason for visit
                    <textarea name="symptoms" rows="4" maxlength="5000" required class="rounded-xl border-slate-300 font-normal">{{ old('symptoms') }}</textarea>
                </label>
                <label class="grid gap-2 text-sm font-semibold text-slate-700">Emergency contact name
                    <input name="emergency_contact_name" maxlength="255" required value="{{ old('emergency_contact_name') }}" class="rounded-xl border-slate-300 font-normal">
                </label>
                <label class="grid gap-2 text-sm font-semibold text-slate-700">Emergency contact phone
                    <input name="emergency_contact_phone" maxlength="40" required value="{{ old('emergency_contact_phone') }}" class="rounded-xl border-slate-300 font-normal">
                </label>
                <button type="submit" class="rounded-xl bg-[#0a2f44] px-4 py-3 font-semibold text-white hover:bg-cyan-900">Submit intake form</button>
            </form>
        </section>
    </main>
</body>
</html>
