@extends('layouts.app')

@section('title', 'Create announcement')

@section('content')
    <div class="mx-auto max-w-3xl space-y-6">
        <header>
            <a href="{{ route('admin.announcements.index') }}" class="text-sm font-semibold text-sky-800 hover:underline">← Announcements</a>
            <h1 class="mt-3 text-3xl font-bold tracking-tight text-slate-900">Create announcement</h1>
            <p class="mt-2 text-sm text-slate-600">Use {patient_name}, {doctor_name}, and {appointment_time} to personalize each message.</p>
        </header>

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
                <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <form id="announcement-form" method="POST" action="{{ route('admin.announcements.store') }}" class="space-y-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" data-preview-url="{{ route('admin.announcements.preview') }}">
            @csrf
            <label class="block text-sm font-semibold text-slate-700">Message
                <textarea class="mt-1 block w-full rounded-lg border-slate-300 text-sm" name="message" rows="4" maxlength="1000" required>{{ old('message') }}</textarea>
            </label>
            <div class="grid gap-5 sm:grid-cols-2">
                <label class="block text-sm font-semibold text-slate-700">Audience
                    <select class="mt-1 block w-full rounded-lg border-slate-300 text-sm" name="audience" required>
                        <option value="tomorrow" @selected(old('audience', 'tomorrow') === 'tomorrow')>All appointments tomorrow</option>
                        <option value="doctor" @selected(old('audience') === 'doctor')>Specific doctor</option>
                        <option value="service" @selected(old('audience') === 'service')>Specific service</option>
                        <option value="upcoming" @selected(old('audience') === 'upcoming')>All upcoming appointments</option>
                    </select>
                </label>
                <label class="block text-sm font-semibold text-slate-700">Doctor (for doctor audience)
                    <select class="mt-1 block w-full rounded-lg border-slate-300 text-sm" name="doctor_id">
                        <option value="">Select a doctor</option>
                        @foreach ($doctors as $doctor)<option value="{{ $doctor->id }}" @selected((string) old('doctor_id') === (string) $doctor->id)>{{ $doctor->name }}</option>@endforeach
                    </select>
                </label>
                <label class="block text-sm font-semibold text-slate-700">Service (for service audience)
                    <select class="mt-1 block w-full rounded-lg border-slate-300 text-sm" name="service_id">
                        <option value="">Select a service</option>
                        @foreach ($services as $service)<option value="{{ $service->id }}" @selected((string) old('service_id') === (string) $service->id)>{{ $service->name }}</option>@endforeach
                    </select>
                </label>
                <label class="block text-sm font-semibold text-slate-700">Channel
                    <select class="mt-1 block w-full rounded-lg border-slate-300 text-sm" name="channel" required>
                        <option value="sms">SMS</option><option value="whatsapp">WhatsApp</option><option value="both">Both</option>
                    </select>
                </label>
                <label class="block text-sm font-semibold text-slate-700">Delivery
                    <select class="mt-1 block w-full rounded-lg border-slate-300 text-sm" name="send_option" id="send-option">
                        <option value="now">Send now</option><option value="scheduled">Schedule</option>
                    </select>
                </label>
                <label class="block text-sm font-semibold text-slate-700">Schedule time
                    <input class="mt-1 block w-full rounded-lg border-slate-300 text-sm" type="datetime-local" name="scheduled_for">
                </label>
            </div>
            <div class="flex flex-wrap gap-3">
                <button type="button" id="preview-announcement" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">Preview</button>
                <button type="submit" class="rounded-lg bg-sky-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-sky-900">Send or schedule</button>
            </div>
            <div id="announcement-preview" class="hidden rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-slate-800" aria-live="polite"></div>
        </form>
    </div>

    <script>
        document.getElementById('preview-announcement').addEventListener('click', async () => {
            const form = document.getElementById('announcement-form');
            const preview = document.getElementById('announcement-preview');
            const response = await fetch(form.dataset.previewUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(Object.fromEntries(new FormData(form))),
            });
            const result = await response.json();
            preview.textContent = response.ok ? result.preview : (result.message || 'Preview could not be generated.');
            preview.classList.remove('hidden');
        });
    </script>
@endsection
