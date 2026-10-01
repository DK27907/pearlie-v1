@extends('layouts.app')

@section('title', ($adminSettings ? 'Hospital settings' : 'Hospital onboarding').' | '.$hospital->name)

@section('content')
    <div class="mx-auto max-w-5xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
        <header><p class="text-sm font-semibold uppercase tracking-widest text-indigo-700">{{ $adminSettings ? 'Hospital settings' : 'Hospital onboarding' }}</p><h1 class="mt-2 text-3xl font-bold text-slate-900">{{ $adminSettings ? $hospital->name.' settings' : $hospital->name.' setup' }}</h1><p class="mt-2 text-slate-600">{{ $adminSettings ? 'Manage hospital details, assistant behavior, appointments, and integrations.' : 'Configure your tenant profile and complete the assistant launch checklist.' }}</p></header>
        @if (session('status'))<div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900">{{ session('status') }}</div>@endif
        @unless ($adminSettings)
            <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" aria-label="Onboarding checklist">
                @foreach (['profile' => 'Hospital profile', 'branding' => 'Branding', 'doctors' => 'Add doctors', 'knowledge' => 'Knowledge base', 'payments' => 'M-Pesa'] as $key => $label)
                    <article class="rounded-xl border {{ $checklist[$key] ? 'border-emerald-200 bg-emerald-50' : 'border-slate-200 bg-white' }} p-4"><p class="text-xl">{{ $checklist[$key] ? '✓' : '○' }}</p><p class="mt-2 text-sm font-semibold">{{ $label }}</p><p class="mt-1 text-xs text-slate-500">{{ $checklist[$key] ? 'Complete' : 'Needs attention' }}</p></article>
                @endforeach
            </section>
        @endunless
        <form method="POST" action="{{ $formAction }}" enctype="multipart/form-data" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            @csrf @method('PUT')
            <h2 class="text-lg font-bold">Hospital profile and assistant branding</h2>
            <div class="grid gap-5 sm:grid-cols-2">
                @foreach (['name' => 'Hospital name', 'phone' => 'Phone', 'emergency_phone' => 'Emergency phone', 'email' => 'Email', 'website' => 'Website', 'whatsapp_number' => 'WhatsApp number', 'city' => 'City', 'county' => 'County', 'hours_emergency' => 'Emergency hours', 'hours_outpatient' => 'Outpatient hours'] as $field => $label)
                    <label class="space-y-1 text-sm font-semibold text-slate-700">{{ $label }}<input name="{{ $field }}" value="{{ old($field, $hospital->{$field}) }}" class="w-full rounded-xl border-slate-300 font-normal" @if ($field === 'name' || str_starts_with($field, 'hours_')) required @endif></label>
                @endforeach
                <label class="space-y-1 text-sm font-semibold text-slate-700 sm:col-span-2">Address<textarea name="address" rows="2" class="w-full rounded-xl border-slate-300 font-normal">{{ old('address', $hospital->address) }}</textarea></label>
                <label class="space-y-1 text-sm font-semibold text-slate-700">Primary color<input type="color" name="primary_color" value="{{ old('primary_color', $hospital->primary_color) }}" class="h-11 w-full rounded-xl border-slate-300"></label>
                <label class="space-y-1 text-sm font-semibold text-slate-700">Secondary color<input type="color" name="secondary_color" value="{{ old('secondary_color', $hospital->secondary_color) }}" class="h-11 w-full rounded-xl border-slate-300"></label>
                <label class="space-y-1 text-sm font-semibold text-slate-700">Default language<select name="default_language" class="w-full rounded-xl border-slate-300 font-normal"><option value="en" @selected(old('default_language', $hospital->default_language) === 'en')>English</option><option value="sw" @selected(old('default_language', $hospital->default_language) === 'sw')>Kiswahili</option></select></label>
                <fieldset class="space-y-2 text-sm font-semibold text-slate-700"><legend>Supported languages</legend>@foreach (['en' => 'English', 'sw' => 'Kiswahili'] as $language => $label)<label class="mr-4 inline-flex items-center gap-2 font-normal"><input type="checkbox" name="supported_languages[]" value="{{ $language }}" @checked(in_array($language, old('supported_languages', $hospital->supported_languages ?? ['en', 'sw']), true))>{{ $label }}</label>@endforeach</fieldset>
                <label class="space-y-1 text-sm font-semibold text-slate-700 sm:col-span-2">Hospital logo (PNG, JPG, or WebP; max 2 MB)<input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="block w-full rounded-xl border border-slate-300 p-2 font-normal"></label>
                <label class="space-y-1 text-sm font-semibold text-slate-700 sm:col-span-2">About the hospital<textarea name="settings[about]" rows="3" class="w-full rounded-xl border-slate-300 font-normal">{{ old('settings.about', data_get($hospital->settings, 'about')) }}</textarea></label>
                <label class="space-y-1 text-sm font-semibold text-slate-700 sm:col-span-2">Assistant instructions<textarea name="settings[ai_instructions]" rows="4" class="w-full rounded-xl border-slate-300 font-normal">{{ old('settings.ai_instructions', data_get($hospital->settings, 'ai_instructions')) }}</textarea></label>
                <div class="sm:col-span-2">
                    <input type="hidden" name="settings[auto_confirm_paid_appointments]" value="0">
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                        <input type="checkbox" name="settings[auto_confirm_paid_appointments]" value="1" @checked(old('settings.auto_confirm_paid_appointments', data_get($hospital->settings, 'auto_confirm_paid_appointments', false)))>
                        Auto-confirm paid appointments
                    </label>
                </div>
                <label class="space-y-1 text-sm font-semibold text-slate-700">Appointment slot duration (minutes)<input type="number" name="slot_duration_minutes" min="5" max="240" value="{{ old('slot_duration_minutes', $hospital->slot_duration_minutes) }}" required class="w-full rounded-xl border-slate-300 font-normal"></label>
                <label class="space-y-1 text-sm font-semibold text-slate-700">No-show grace period (minutes)<input type="number" name="no_show_grace_minutes" min="0" max="1440" value="{{ old('no_show_grace_minutes', $hospital->no_show_grace_minutes) }}" required class="w-full rounded-xl border-slate-300 font-normal"></label>
            </div>
            <section class="space-y-4 border-t border-slate-100 pt-6">
                <div><h3 class="font-bold text-slate-900">Appointment deposits</h3><p class="mt-1 text-sm text-slate-500">Provider credentials are encrypted at rest. Leave a credential blank to keep the current value.</p></div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <label class="space-y-1 text-sm font-semibold text-slate-700">M-Pesa shortcode<input name="mpesa_shortcode" value="{{ old('mpesa_shortcode', $hospital->mpesa_shortcode) }}" class="w-full rounded-xl border-slate-300 font-normal"></label>
                    <label class="space-y-1 text-sm font-semibold text-slate-700">Appointment deposit (KSh)<input type="number" min="0" step="1" name="deposit_amount" value="{{ old('deposit_amount', $hospital->deposit_amount) }}" required class="w-full rounded-xl border-slate-300 font-normal"></label>
                    <label class="space-y-1 text-sm font-semibold text-slate-700">Daraja consumer key<input type="password" name="mpesa_consumer_key" autocomplete="new-password" class="w-full rounded-xl border-slate-300 font-normal"></label>
                    <label class="space-y-1 text-sm font-semibold text-slate-700">Daraja consumer secret<input type="password" name="mpesa_consumer_secret" autocomplete="new-password" class="w-full rounded-xl border-slate-300 font-normal"></label>
                    <label class="space-y-1 text-sm font-semibold text-slate-700 sm:col-span-2">Daraja passkey<input type="password" name="mpesa_passkey" autocomplete="new-password" class="w-full rounded-xl border-slate-300 font-normal"></label>
                </div>
            </section>
            <section class="space-y-4 border-t border-slate-100 pt-6">
                    <div><h3 class="font-bold text-slate-900">WhatsApp Business integration</h3><p class="mt-1 text-sm text-slate-500">Credentials are encrypted at rest. Configure the tenant webhook URL <code class="rounded bg-slate-100 px-1">{{ url('/api/whatsapp/webhook/'.$hospital->slug) }}</code>.</p></div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="space-y-1 text-sm font-semibold text-slate-700">Business phone number ID<input name="whatsapp_phone_number_id" value="{{ old('whatsapp_phone_number_id', $hospital->whatsapp_phone_number_id) }}" class="w-full rounded-xl border-slate-300 font-normal"></label>
                        <label class="space-y-1 text-sm font-semibold text-slate-700">Graph API version<input name="whatsapp_api_version" value="{{ old('whatsapp_api_version', $hospital->whatsapp_api_version ?: 'v20.0') }}" required class="w-full rounded-xl border-slate-300 font-normal"></label>
                        <label class="space-y-1 text-sm font-semibold text-slate-700">Access token<input type="password" name="whatsapp_access_token" autocomplete="new-password" class="w-full rounded-xl border-slate-300 font-normal"></label>
                        <label class="space-y-1 text-sm font-semibold text-slate-700">App secret<input type="password" name="whatsapp_app_secret" autocomplete="new-password" class="w-full rounded-xl border-slate-300 font-normal"></label>
                        <label class="space-y-1 text-sm font-semibold text-slate-700 sm:col-span-2">Webhook verify token<input type="password" name="whatsapp_verify_token" autocomplete="new-password" class="w-full rounded-xl border-slate-300 font-normal"></label>
                    </div>
            </section>
            @if ($errors->any())<div role="alert" class="rounded-xl bg-rose-50 p-4 text-sm text-rose-800"><ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <button class="rounded-xl bg-indigo-700 px-5 py-3 font-semibold text-white hover:bg-indigo-800">{{ $adminSettings ? 'Save settings' : 'Save setup' }}</button>
        </form>
    </div>
@endsection
