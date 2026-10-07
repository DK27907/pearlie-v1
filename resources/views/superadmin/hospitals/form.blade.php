@extends('layouts.app')

@section('content')
    <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <a href="{{ route('superadmin.hospitals.index') }}" class="text-sm font-semibold text-indigo-700 hover:underline">← Hospitals</a>
        <div><p class="text-sm font-semibold uppercase tracking-widest text-indigo-700">Platform management</p><h1 class="mt-2 text-3xl font-bold text-slate-900">{{ $hospital->exists ? 'Edit hospital' : 'Create a hospital' }}</h1></div>
        <form method="POST" action="{{ $hospital->exists ? route('superadmin.hospitals.update', $hospital) : route('superadmin.hospitals.store') }}" class="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            @csrf
            @if ($hospital->exists) @method('PUT') @endif
            <div class="grid gap-5 sm:grid-cols-2">
                @foreach (['name' => 'Hospital name', 'slug' => 'Tenant slug', 'email' => 'Contact email', 'phone' => 'Phone', 'city' => 'City', 'county' => 'County'] as $field => $label)
                    <label class="space-y-1 text-sm font-semibold text-slate-700">{{ $label }}<input name="{{ $field }}" value="{{ old($field, $hospital->{$field}) }}" class="w-full rounded-xl border-slate-300 font-normal" @if (in_array($field, ['name', 'slug'], true)) required @endif></label>
                @endforeach
                @unless ($hospital->exists)
                    <label class="space-y-1 text-sm font-semibold text-slate-700 sm:col-span-2">First hospital administrator email<input type="email" name="admin_email" value="{{ old('admin_email') }}" required class="w-full rounded-xl border-slate-300 font-normal"><span class="text-xs font-normal text-slate-500">We will send a secure, single-use account setup link to this address.</span></label>
                @endunless
                <label class="space-y-1 text-sm font-semibold text-slate-700 sm:col-span-2">Address<textarea name="address" rows="2" class="w-full rounded-xl border-slate-300 font-normal">{{ old('address', $hospital->address) }}</textarea></label>
                @foreach (['emergency_phone' => 'Emergency phone', 'website' => 'Website URL', 'whatsapp_number' => 'WhatsApp number', 'whatsapp_phone_number_id' => 'WhatsApp Business phone number ID', 'mpesa_shortcode' => 'M-Pesa shortcode'] as $field => $label)
                    <label class="space-y-1 text-sm font-semibold text-slate-700">{{ $label }}<input name="{{ $field }}" value="{{ old($field, $hospital->{$field}) }}" class="w-full rounded-xl border-slate-300 font-normal"></label>
                @endforeach
                <label class="space-y-1 text-sm font-semibold text-slate-700">Deposit amount (KES)<input type="number" min="0" step="1" name="deposit_amount" value="{{ old('deposit_amount', $hospital->deposit_amount ?? 500) }}" required class="w-full rounded-xl border-slate-300 font-normal"></label>
                <label class="space-y-1 text-sm font-semibold text-slate-700">Appointment slot duration (minutes)<input type="number" min="5" max="240" name="slot_duration_minutes" value="{{ old('slot_duration_minutes', $hospital->slot_duration_minutes ?? 30) }}" required class="w-full rounded-xl border-slate-300 font-normal"></label>
                <label class="space-y-1 text-sm font-semibold text-slate-700">No-show grace (minutes)<input type="number" min="0" max="240" name="no_show_grace_minutes" value="{{ old('no_show_grace_minutes', $hospital->no_show_grace_minutes ?? 30) }}" required class="w-full rounded-xl border-slate-300 font-normal"></label>
                <label class="space-y-1 text-sm font-semibold text-slate-700">Emergency hours<input name="hours_emergency" value="{{ old('hours_emergency', $hospital->hours_emergency ?? '24/7') }}" required class="w-full rounded-xl border-slate-300 font-normal"></label>
                <label class="space-y-1 text-sm font-semibold text-slate-700">Outpatient hours<input name="hours_outpatient" value="{{ old('hours_outpatient', $hospital->hours_outpatient ?? '8:00 AM - 6:00 PM, Mon-Sat') }}" required class="w-full rounded-xl border-slate-300 font-normal"></label>
                <label class="space-y-1 text-sm font-semibold text-slate-700">Subscription plan<select name="subscription_plan" class="w-full rounded-xl border-slate-300 font-normal">@foreach (['starter', 'professional', 'enterprise'] as $plan)<option value="{{ $plan }}" @selected(old('subscription_plan', $hospital->subscription_plan ?: 'starter') === $plan)>{{ ucfirst($plan) }}</option>@endforeach</select></label>
                <label class="space-y-1 text-sm font-semibold text-slate-700">Default language<select name="default_language" class="w-full rounded-xl border-slate-300 font-normal"><option value="en" @selected(old('default_language', $hospital->default_language ?: 'en') === 'en')>English</option><option value="sw" @selected(old('default_language', $hospital->default_language) === 'sw')>Kiswahili</option></select></label>
                <fieldset class="space-y-2 text-sm font-semibold text-slate-700"><legend>Supported languages</legend>@foreach (['en' => 'English', 'sw' => 'Kiswahili'] as $language => $label)<label class="mr-4 inline-flex items-center gap-2 font-normal"><input type="checkbox" name="supported_languages[]" value="{{ $language }}" @checked(in_array($language, old('supported_languages', $hospital->supported_languages ?? ['en', 'sw']), true))>{{ $label }}</label>@endforeach</fieldset>
                <label class="space-y-1 text-sm font-semibold text-slate-700">Primary color<input type="color" name="primary_color" value="{{ old('primary_color', $hospital->primary_color ?: '#0a2f44') }}" class="h-11 w-full rounded-xl border-slate-300"></label>
                <label class="space-y-1 text-sm font-semibold text-slate-700">Secondary color<input type="color" name="secondary_color" value="{{ old('secondary_color', $hospital->secondary_color ?: '#1a5276') }}" class="h-11 w-full rounded-xl border-slate-300"></label>
                <p class="text-xs text-slate-500 sm:col-span-2">M-Pesa and WhatsApp credentials are encrypted at rest. Leave secret fields blank to keep the existing value.</p>
                @foreach (['mpesa_consumer_key' => 'Daraja consumer key', 'mpesa_consumer_secret' => 'Daraja consumer secret', 'mpesa_passkey' => 'Daraja passkey', 'whatsapp_access_token' => 'WhatsApp access token', 'whatsapp_app_secret' => 'WhatsApp app secret', 'whatsapp_verify_token' => 'WhatsApp webhook verify token'] as $field => $label)
                    <label class="space-y-1 text-sm font-semibold text-slate-700">{{ $label }}<input type="password" name="{{ $field }}" autocomplete="new-password" class="w-full rounded-xl border-slate-300 font-normal"></label>
                @endforeach
                <label class="space-y-1 text-sm font-semibold text-slate-700">WhatsApp Graph API version<input name="whatsapp_api_version" value="{{ old('whatsapp_api_version', $hospital->whatsapp_api_version ?: 'v20.0') }}" required class="w-full rounded-xl border-slate-300 font-normal"></label>
                @if ($hospital->exists)
                    <label class="space-y-1 text-sm font-semibold text-slate-700">Status<select name="subscription_status" class="w-full rounded-xl border-slate-300 font-normal">@foreach (['active', 'trial', 'suspended'] as $status)<option value="{{ $status }}" @selected(old('subscription_status', $hospital->subscription_status) === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
                    <label class="space-y-1 text-sm font-semibold text-slate-700">Trial ends<input type="date" name="trial_ends_at" value="{{ old('trial_ends_at', $hospital->trial_ends_at?->format('Y-m-d')) }}" class="w-full rounded-xl border-slate-300 font-normal"></label>
                    <label class="space-y-1 text-sm font-semibold text-slate-700">Account active<select name="is_active" class="w-full rounded-xl border-slate-300 font-normal"><option value="1" @selected(old('is_active', (int) $hospital->is_active) === 1)>Yes</option><option value="0" @selected(old('is_active', (int) $hospital->is_active) === 0)>No</option></select></label>
                @else
                    <label class="space-y-1 text-sm font-semibold text-slate-700">Trial days<input type="number" name="trial_days" min="0" max="90" value="{{ old('trial_days', 14) }}" class="w-full rounded-xl border-slate-300 font-normal"></label>
                @endif
            </div>
            @if ($errors->any())<div role="alert" class="rounded-xl bg-rose-50 p-4 text-sm text-rose-800"><ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <button class="rounded-xl bg-indigo-700 px-5 py-3 font-semibold text-white hover:bg-indigo-800">{{ $hospital->exists ? 'Save changes' : 'Create hospital' }}</button>
        </form>
    </div>
@endsection
