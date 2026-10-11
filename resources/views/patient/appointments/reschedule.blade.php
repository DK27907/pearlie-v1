@extends('layouts.app')

@section('header')
    <div>
        <a href="{{ route('appointments.show', $appointment->id) }}" class="text-sm font-semibold text-cyan-800 hover:text-cyan-600">← Appointment details</a>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Reschedule appointment #{{ $appointment->id }}</h1>
    </div>
@endsection

@section('content')
    <div class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $errors->first() }}</div>
        @endif

        <form method="GET" action="{{ route('appointments.reschedule.create', $appointment->id) }}" class="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="grid gap-2">
                <label for="date-filter" class="text-sm font-medium text-slate-700">Find availability on</label>
                <input id="date-filter" name="date" type="date" value="{{ $date }}" min="{{ now()->toDateString() }}" max="{{ now()->addYear()->toDateString() }}" required class="rounded-lg border-slate-300 text-sm">
            </div>
            <button type="submit" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Show available slots</button>
        </form>

        <form method="POST" action="{{ route('appointments.reschedule.store', $appointment->id) }}" class="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            @csrf
            <input type="hidden" name="date" value="{{ $date }}">
            <h2 class="font-bold text-slate-900">Choose a new doctor and time</h2>
            @php
                $availableSlots = collect($doctorSlots)->flatMap(function (array $option): array {
                    return collect($option['slots'])
                        ->filter(fn (bool $available): bool => $available)
                        ->keys()
                        ->map(fn (string $time): array => [
                            'doctor_id' => $option['doctor']->id,
                            'doctor_name' => $option['doctor']->name,
                            'time' => $time,
                        ])
                        ->all();
                });
            @endphp
            @if ($availableSlots->isNotEmpty())
                <label class="grid gap-2 text-sm font-medium text-slate-700">
                    <span>Doctor and available time</span>
                    <select name="slot" required class="rounded-lg border-slate-300 text-sm">
                        <option value="">Choose a slot</option>
                        @foreach ($availableSlots as $slot)
                            @php($slotValue = $slot['doctor_id'].'|'.$slot['time'])
                            <option value="{{ $slotValue }}" @selected(old('slot') === $slotValue)>
                                {{ $slot['doctor_name'] }} · {{ \Carbon\Carbon::parse($slot['time'])->format('g:i A') }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <div class="grid gap-2">
                    <label for="reason" class="text-sm font-medium text-slate-700">Reason (optional)</label>
                    <input id="reason" name="reason" maxlength="1000" value="{{ old('reason') }}" class="rounded-lg border-slate-300 text-sm">
                </div>
                <button type="submit" class="rounded-lg bg-cyan-800 px-4 py-2.5 text-sm font-semibold text-white hover:bg-cyan-700">Confirm new appointment time</button>
            @else
                <p class="text-sm text-slate-600">There are no available slots on {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}.</p>
            @endif
        </form>
    </div>
@endsection
