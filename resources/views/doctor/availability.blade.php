@extends('layouts.app')

@section('header')
    <div>
        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-cyan-700">Clinic schedule</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Availability</h1>
        <p class="mt-1 text-sm text-slate-500">Set the times patients can request an appointment with you.</p>
    </div>
@endsection

@section('content')
    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        @if (session('status'))
            <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                <p class="font-semibold">Please check your schedule details.</p>
                <ul class="mt-1 list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4 sm:px-6">
                <h2 class="font-bold text-slate-900">Weekly working hours</h2>
                <p class="mt-1 text-sm text-slate-500">Each slot is offered up to the selected number of patients.</p>
            </div>
            <form method="POST" action="{{ route('doctor.availability.update') }}">
                @csrf
                <div class="divide-y divide-slate-100">
                    @foreach ([0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'] as $dayNumber => $dayName)
                        @php
                            $setting = $availability->get($dayNumber);
                            $isDefaultWeekday = in_array($dayNumber, config('pearlie.appointment.default_schedule.days', []), true);
                            $row = $loop->index;
                        @endphp
                        <div class="grid gap-4 px-5 py-4 sm:grid-cols-[minmax(8rem,1.1fr)_repeat(4,minmax(7rem,1fr))] sm:items-end sm:px-6">
                            <div class="flex items-center gap-3 sm:pb-2">
                                <input id="active-{{ $dayNumber }}" type="checkbox" name="availabilities[{{ $row }}][is_active]" value="1" @checked(old("availabilities.{$row}.is_active", $setting?->is_active ?? $isDefaultWeekday)) class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-600">
                                <label for="active-{{ $dayNumber }}" class="font-semibold text-slate-800">{{ $dayName }}</label>
                                <input type="hidden" name="availabilities[{{ $row }}][day_of_week]" value="{{ $dayNumber }}">
                            </div>
                            <label class="grid gap-1 text-xs font-medium text-slate-500">Start
                                <input type="time" name="availabilities[{{ $row }}][start_time]" value="{{ old("availabilities.{$row}.start_time", $setting ? substr($setting->start_time, 0, 5) : config('pearlie.appointment.default_schedule.start_time')) }}" required class="rounded-lg border-slate-300 text-sm text-slate-800 focus:border-cyan-600 focus:ring-cyan-600">
                            </label>
                            <label class="grid gap-1 text-xs font-medium text-slate-500">End
                                <input type="time" name="availabilities[{{ $row }}][end_time]" value="{{ old("availabilities.{$row}.end_time", $setting ? substr($setting->end_time, 0, 5) : config('pearlie.appointment.default_schedule.end_time')) }}" required class="rounded-lg border-slate-300 text-sm text-slate-800 focus:border-cyan-600 focus:ring-cyan-600">
                            </label>
                            <label class="grid gap-1 text-xs font-medium text-slate-500">Slot length
                                <select name="availabilities[{{ $row }}][slot_duration_minutes]" class="rounded-lg border-slate-300 text-sm text-slate-800 focus:border-cyan-600 focus:ring-cyan-600">
                                    @foreach (array_unique([15, 20, config('pearlie.appointment.slot_duration_minutes'), 45, 60]) as $minutes)
                                        <option value="{{ $minutes }}" @selected((int) old("availabilities.{$row}.slot_duration_minutes", $setting?->slot_duration_minutes ?? config('pearlie.appointment.slot_duration_minutes')) === $minutes)>{{ $minutes }} min</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="grid gap-1 text-xs font-medium text-slate-500">Patients per slot
                                <input type="number" name="availabilities[{{ $row }}][max_patients_per_slot]" min="1" max="50" value="{{ old("availabilities.{$row}.max_patients_per_slot", $setting?->max_patients_per_slot ?? 1) }}" required class="rounded-lg border-slate-300 text-sm text-slate-800 focus:border-cyan-600 focus:ring-cyan-600">
                            </label>
                        </div>
                    @endforeach
                </div>
                <div class="flex flex-wrap items-center justify-between gap-4 border-t border-slate-100 bg-slate-50 px-5 py-4 sm:px-6">
                    <p class="text-xs text-slate-500">Unchecked days will be unavailable to patients.</p>
                    <button type="submit" class="rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-cyan-800">Save weekly hours</button>
                </div>
            </form>
        </section>

        <section class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_19rem]">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-bold text-slate-900">Unavailable dates</h2>
                        <p class="mt-1 text-sm text-slate-500">These dates block all of your usual appointment slots.</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">{{ $unavailable_dates->count() }} dates</span>
                </div>
                <div class="mt-4 divide-y divide-slate-100">
                    @forelse ($unavailable_dates as $unavailableDate)
                        <div class="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div>
                                <p class="font-semibold text-slate-800">{{ $unavailableDate->date->format('l, F j, Y') }}</p>
                                <p class="mt-1 text-sm text-slate-500">{{ $unavailableDate->reason ?: 'Unavailable' }}</p>
                            </div>
                            <form method="POST" action="{{ route('doctor.unavailable.destroy', $unavailableDate->id) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rounded-lg px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">Remove</button>
                            </form>
                        </div>
                    @empty
                        <p class="py-6 text-sm text-slate-500">No unavailable dates have been added.</p>
                    @endforelse
                </div>
            </div>

            <aside class="h-fit rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="font-bold text-slate-900">Block a date</h2>
                <p class="mt-1 text-sm text-slate-500">Your current working hours will not be offered on this date.</p>
                <form method="POST" action="{{ route('doctor.unavailable.store') }}" class="mt-4 grid gap-3">
                    @csrf
                    <label class="grid gap-1.5 text-sm font-medium text-slate-700">Date
                        <input type="date" name="date" min="{{ now()->toDateString() }}" value="{{ old('date') }}" required class="rounded-lg border-slate-300 text-sm focus:border-cyan-600 focus:ring-cyan-600">
                    </label>
                    <label class="grid gap-1.5 text-sm font-medium text-slate-700">Reason <span class="font-normal text-slate-400">(optional)</span>
                        <input type="text" name="reason" value="{{ old('reason') }}" maxlength="255" placeholder="e.g. Annual leave" class="rounded-lg border-slate-300 text-sm focus:border-cyan-600 focus:ring-cyan-600">
                    </label>
                    <button type="submit" class="mt-1 rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-cyan-600 hover:text-cyan-800">Add unavailable date</button>
                </form>
            </aside>
        </section>

        <section class="rounded-2xl border border-cyan-100 bg-cyan-50/70 p-5">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="font-semibold text-slate-900">Today’s slots</p>
                    <p class="mt-1 text-sm text-slate-600">Currently open for new appointments.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @forelse (array_keys(array_filter($today_slots)) as $slot)
                        <span class="rounded-lg border border-cyan-100 bg-white px-3 py-1.5 text-xs font-semibold text-cyan-900">{{ $slot }}</span>
                    @empty
                        <span class="text-sm text-slate-500">No open times today</span>
                    @endforelse
                </div>
            </div>
        </section>
    </div>
@endsection
