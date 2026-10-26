@extends('layouts.app')

@section('header')
    <div>
        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-cyan-700">Admin appointments</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Book a walk-in patient</h1>
        <p class="mt-1 text-sm text-slate-500">Reserve a real appointment slot for a patient at the hospital.</p>
    </div>
@endsection

@section('content')
    <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-cyan-200 bg-cyan-50 px-5 py-4">
            <p class="text-sm font-semibold text-cyan-950">{{ $hospital->name }}</p>
            <p class="mt-1 text-sm text-cyan-900">Default payment mode: <span class="font-semibold capitalize">{{ $defaultPaymentMode }}</span></p>
        </div>

        @if (session('status'))
            <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('admin.walk-in.store') }}" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"
            data-doctors-url="{{ route('admin.walk-in.doctors') }}"
            data-slots-url="{{ route('admin.walk-in.slots') }}">
            @csrf

            <div class="grid gap-5 sm:grid-cols-2">
                <label class="grid gap-2 text-sm font-semibold text-slate-800 sm:col-span-2" for="service_id">
                    Service
                    <select id="service_id" name="service_id" required class="rounded-lg border-slate-300 text-sm font-normal focus:border-cyan-700 focus:ring-cyan-700">
                        <option value="">Choose a service</option>
                        @foreach ($services as $service)
                            <option value="{{ $service->id }}" @selected((string) old('service_id') === (string) $service->id)>{{ $service->name }} · KSh {{ number_format((float) $service->price, 2) }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="grid gap-2 text-sm font-semibold text-slate-800 sm:col-span-2" for="doctor_id">
                    In-hospital doctor
                    <select id="doctor_id" name="doctor_id" required disabled class="rounded-lg border-slate-300 text-sm font-normal disabled:bg-slate-100">
                        <option value="">Choose a service first</option>
                    </select>
                </label>

                <label class="grid gap-2 text-sm font-semibold text-slate-800" for="date">
                    Appointment date
                    <input id="date" name="date" type="date" min="{{ $today }}" value="{{ old('date', $today) }}" required class="rounded-lg border-slate-300 text-sm font-normal">
                </label>

                <label class="grid gap-2 text-sm font-semibold text-slate-800" for="time">
                    Free appointment time
                    <select id="time" name="time" required disabled class="rounded-lg border-slate-300 text-sm font-normal disabled:bg-slate-100">
                        <option value="">Choose a doctor and date</option>
                    </select>
                </label>

                <label class="grid gap-2 text-sm font-semibold text-slate-800" for="patient_name">
                    Patient name
                    <input id="patient_name" name="patient_name" type="text" maxlength="255" value="{{ old('patient_name') }}" required autocomplete="name" class="rounded-lg border-slate-300 text-sm font-normal">
                </label>

                <label class="grid gap-2 text-sm font-semibold text-slate-800" for="phone">
                    Phone number
                    <input id="phone" name="phone" type="tel" maxlength="40" value="{{ old('phone') }}" required autocomplete="tel" class="rounded-lg border-slate-300 text-sm font-normal">
                </label>
            </div>

            <fieldset class="grid gap-3">
                <legend class="text-sm font-semibold text-slate-800">Payment for this booking</legend>
                <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 text-sm text-slate-700">
                    <input type="radio" name="payment_override" value="" @checked(old('payment_override', '') === '') class="mt-0.5 border-slate-300 text-cyan-700 focus:ring-cyan-700">
                    <span>Use hospital default (<span class="font-semibold capitalize">{{ $defaultPaymentMode }}</span>)</span>
                </label>
                <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 text-sm text-slate-700">
                    <input type="radio" name="payment_override" value="cash" @checked(old('payment_override') === 'cash') class="mt-0.5 border-slate-300 text-cyan-700 focus:ring-cyan-700">
                    <span>Pay at counter</span>
                </label>
                <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 text-sm text-slate-700">
                    <input type="radio" name="payment_override" value="none" @checked(old('payment_override') === 'none') class="mt-0.5 border-slate-300 text-cyan-700 focus:ring-cyan-700">
                    <span>No payment required</span>
                </label>
            </fieldset>

            <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-5">
                <button id="submit-booking" type="submit" disabled class="rounded-lg bg-cyan-800 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-cyan-900 disabled:cursor-not-allowed disabled:bg-slate-400">Confirm walk-in booking</button>
                <a href="{{ route('admin.appointments.index') }}" class="rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50">Cancel</a>
                <p id="slot-message" role="status" aria-live="polite" class="w-full text-sm text-slate-600 sm:w-auto"></p>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
    <script nonce="{{ Vite::cspNonce() }}">
        (() => {
            const form = document.querySelector('form[data-doctors-url]');
            const serviceSelect = document.getElementById('service_id');
            const doctorSelect = document.getElementById('doctor_id');
            const dateInput = document.getElementById('date');
            const timeSelect = document.getElementById('time');
            const submitButton = document.getElementById('submit-booking');
            const message = document.getElementById('slot-message');
            const previousDoctorId = @js((string) old('doctor_id', ''));
            const previousTime = @js((string) old('time', ''));
            let doctorRequest = 0;
            let slotRequest = 0;

            const setMessage = (text, isError = false) => {
                message.textContent = text;
                message.classList.toggle('text-rose-700', isError);
                message.classList.toggle('text-slate-600', !isError);
            };

            const resetSelect = (select, label) => {
                select.replaceChildren(new Option(label, ''));
                select.value = '';
            };

            const loadSlots = async () => {
                const requestId = ++slotRequest;
                resetSelect(timeSelect, 'Loading free slots…');
                timeSelect.disabled = true;
                submitButton.disabled = true;
                setMessage('');

                if (!doctorSelect.value || !dateInput.value) {
                    resetSelect(timeSelect, 'Choose a doctor and date');
                    return;
                }

                try {
                    const query = new URLSearchParams({ doctor_id: doctorSelect.value, date: dateInput.value });
                    const response = await fetch(`${form.dataset.slotsUrl}?${query}`, {
                        headers: { Accept: 'application/json' },
                    });
                    if (!response.ok) {
                        throw new Error('Could not load free slots. Check the selected doctor and date.');
                    }

                    const slots = await response.json();
                    if (requestId !== slotRequest) {
                        return;
                    }

                    resetSelect(timeSelect, slots.length ? 'Choose a free time' : 'No free slots for this date');
                    for (const slot of slots) {
                        timeSelect.add(new Option(slot.label, slot.time));
                    }
                    timeSelect.disabled = slots.length === 0;
                    setMessage(slots.length ? '' : 'No free slots for this date.');
                    if (previousTime && slots.some((slot) => slot.time === previousTime)) {
                        timeSelect.value = previousTime;
                    }
                    submitButton.disabled = !timeSelect.value;
                } catch (error) {
                    if (requestId === slotRequest) {
                        resetSelect(timeSelect, 'Slots could not be loaded');
                        setMessage(error.message, true);
                    }
                }
            };

            serviceSelect.addEventListener('change', async () => {
                const requestId = ++doctorRequest;
                ++slotRequest;
                resetSelect(doctorSelect, serviceSelect.value ? 'Loading doctors…' : 'Choose a service first');
                doctorSelect.disabled = true;
                resetSelect(timeSelect, 'Choose a doctor and date');
                timeSelect.disabled = true;
                submitButton.disabled = true;
                setMessage('');

                if (!serviceSelect.value) {
                    return;
                }

                try {
                    const query = new URLSearchParams({ service_id: serviceSelect.value });
                    const response = await fetch(`${form.dataset.doctorsUrl}?${query}`, {
                        headers: { Accept: 'application/json' },
                    });
                    if (!response.ok) {
                        throw new Error('Could not load in-hospital doctors for this service.');
                    }

                    const doctors = await response.json();
                    if (requestId !== doctorRequest) {
                        return;
                    }

                    resetSelect(doctorSelect, doctors.length
                        ? 'Choose an in-hospital doctor'
                        : 'No in-hospital doctors offer this service');
                    for (const doctor of doctors) {
                        doctorSelect.add(new Option(doctor.name, doctor.id));
                    }
                    doctorSelect.disabled = doctors.length === 0;
                    setMessage(doctors.length ? '' : 'No in-hospital doctors offer this service.');
                    if (previousDoctorId && doctors.some((doctor) => String(doctor.id) === previousDoctorId)) {
                        doctorSelect.value = previousDoctorId;
                        await loadSlots();
                    }
                } catch (error) {
                    if (requestId === doctorRequest) {
                        resetSelect(doctorSelect, 'Doctors could not be loaded');
                        setMessage(error.message, true);
                    }
                }
            });

            doctorSelect.addEventListener('change', loadSlots);
            dateInput.addEventListener('change', loadSlots);
            timeSelect.addEventListener('change', () => {
                submitButton.disabled = !timeSelect.value;
            });

            if (serviceSelect.value) {
                serviceSelect.dispatchEvent(new Event('change'));
            }
        })();
    </script>
@endpush
