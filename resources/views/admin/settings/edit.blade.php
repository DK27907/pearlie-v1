@extends('layouts.app')

@section('title', 'Hospital settings | '.pearlie_config('hospital.name'))

@section('content')
    @php
        $tabs = [
            'general' => 'General',
            'mpesa' => 'M-Pesa',
            'whatsapp' => 'WhatsApp',
            'sms' => 'SMS',
            'email' => 'Email',
            'branding' => 'Branding',
        ];
        $days = [
            'monday' => 'Monday',
            'tuesday' => 'Tuesday',
            'wednesday' => 'Wednesday',
            'thursday' => 'Thursday',
            'friday' => 'Friday',
            'saturday' => 'Saturday',
            'sunday' => 'Sunday',
        ];
        $providerNames = ['mpesa' => 'M-Pesa', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'Email'];
        $inputClass = 'mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-sky-700 focus:ring-sky-700';
    @endphp

    <div class="mx-auto max-w-5xl space-y-6">
        <header>
            <p class="text-sm font-semibold uppercase tracking-widest text-sky-700">{{ pearlie_config('hospital.name') }}</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-900">Hospital settings</h1>
            <p class="mt-2 text-slate-600">Configure appointment defaults and integrations for this hospital.</p>
        </header>

        @if (session('status'))
            <p role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900">{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-rose-800">
                <p class="font-semibold">Please correct the highlighted settings.</p>
                <ul class="mt-2 list-inside list-disc text-sm">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <nav role="tablist" aria-label="Hospital settings" class="flex gap-1 overflow-x-auto border-b border-slate-200 p-2">
                @foreach ($tabs as $tab => $label)
                    <a role="tab"
                        aria-selected="{{ $activeTab === $tab ? 'true' : 'false' }}"
                        href="{{ route('admin.integration-settings.edit', ['tab' => $tab]) }}"
                        @class([
                            'whitespace-nowrap rounded-lg px-4 py-2 text-sm font-semibold',
                            'bg-sky-800 text-white' => $activeTab === $tab,
                            'text-slate-600 hover:bg-slate-100' => $activeTab !== $tab,
                        ])>{{ $label }}</a>
                @endforeach
            </nav>

            @if ($activeTab === 'general')
                <section role="tabpanel" class="space-y-6 p-5 sm:p-7">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">General</h2>
                        <p class="mt-1 text-sm text-slate-600">Set the booking deposit, appointment duration, confirmation policy, and weekly hours.</p>
                    </div>
                    <form method="POST" action="{{ route('admin.integration-settings.general.update') }}" class="space-y-6">
                        @csrf
                        @method('PUT')
                        <div class="grid gap-5 sm:grid-cols-2">
                            <label class="block text-sm font-semibold text-slate-700">
                                Deposit amount (KES)
                                <input class="{{ $inputClass }}" type="number" name="deposit_amount" min="0" max="1000000" required value="{{ old('deposit_amount', $depositAmount) }}">
                            </label>
                            <label class="block text-sm font-semibold text-slate-700">
                                Slot duration (minutes)
                                <input class="{{ $inputClass }}" type="number" name="slot_duration_minutes" min="5" max="480" required value="{{ old('slot_duration_minutes', $slotDuration) }}">
                            </label>
                        </div>
                        <label class="flex items-start gap-3 text-sm font-semibold text-slate-700">
                            <input type="checkbox" name="auto_confirm_paid_appointments" value="1" @checked(old('auto_confirm_paid_appointments', $autoConfirm)) class="mt-0.5 rounded border-slate-300 text-sky-800 focus:ring-sky-700">
                            <span>Automatically confirm appointments after successful payment</span>
                        </label>
                        <fieldset>
                            <legend class="text-sm font-bold text-slate-900">Business hours</legend>
                            <div class="mt-3 space-y-3">
                                @foreach ($days as $day => $label)
                                    @php($hours = $businessHours[$day] ?? [])
                                    <div class="grid items-center gap-3 rounded-xl border border-slate-200 p-3 sm:grid-cols-[1fr_1fr_1fr_auto]">
                                        <span class="text-sm font-semibold text-slate-700">{{ $label }}</span>
                                        <label class="text-xs font-medium text-slate-500">Opens
                                            <input class="{{ $inputClass }}" type="time" name="business_hours[{{ $day }}][open]" value="{{ old("business_hours.$day.open", $hours['open'] ?? '09:00') }}">
                                        </label>
                                        <label class="text-xs font-medium text-slate-500">Closes
                                            <input class="{{ $inputClass }}" type="time" name="business_hours[{{ $day }}][close]" value="{{ old("business_hours.$day.close", $hours['close'] ?? '17:00') }}">
                                        </label>
                                        <label class="inline-flex items-center gap-2 text-sm text-slate-600">
                                            <input type="checkbox" name="business_hours[{{ $day }}][closed]" value="1" @checked(old("business_hours.$day.closed", $hours['closed'] ?? false)) class="rounded border-slate-300 text-sky-800 focus:ring-sky-700">
                                            Closed
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        </fieldset>
                        <button type="submit" class="rounded-lg bg-sky-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-sky-900">Save general settings</button>
                    </form>
                </section>
            @elseif ($activeTab === 'mpesa')
                <section role="tabpanel" class="space-y-6 p-5 sm:p-7">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div><h2 class="text-lg font-bold text-slate-900">M-Pesa</h2><p class="mt-1 text-sm text-slate-600">Payment credentials are encrypted when stored.</p></div>
                        @include('admin.settings.partials.configured', ['provider' => 'mpesa', 'credentials' => $credentials['mpesa']])
                    </div>
                    <form method="POST" action="{{ route('admin.integration-settings.mpesa.update') }}" class="space-y-5">
                        @csrf
                        @method('PUT')
                        <div class="grid gap-5 sm:grid-cols-2">
                            @foreach ([
                                'consumer_key' => ['Consumer key', true],
                                'consumer_secret' => ['Consumer secret', true],
                                'shortcode' => ['Shortcode', false],
                                'passkey' => ['Passkey', true],
                            ] as $field => [$label, $secret])
                                <label class="block text-sm font-semibold text-slate-700">{{ $label }}
                                    <input class="{{ $inputClass }}" type="{{ $secret ? 'password' : 'text' }}" name="{{ $field }}" @if (! $secret) value="{{ old($field, $credentials['mpesa'][$field] ?? '') }}" @else placeholder="{{ $credentials['mpesa'][$field] ?? '' }}" autocomplete="new-password" @endif>
                                    @if ($secret)<span class="mt-1 block text-xs font-normal text-slate-500">Leave blank to keep existing.</span>@endif
                                </label>
                            @endforeach
                            <label class="block text-sm font-semibold text-slate-700">Environment
                                <select class="{{ $inputClass }}" name="environment" required>
                                    @foreach (['sandbox' => 'Sandbox', 'production' => 'Production'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('environment', $credentials['mpesa']['environment'] ?? config('mpesa.environment', 'sandbox')) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block text-sm font-semibold text-slate-700">Callback URL
                                <input class="{{ $inputClass }}" type="url" name="callback_url" required value="{{ old('callback_url', $credentials['mpesa']['callback_url'] ?? url('/api/mpesa/callback')) }}">
                            </label>
                        </div>
                        <button type="submit" class="rounded-lg bg-sky-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-sky-900">Save M-Pesa settings</button>
                    </form>
                    @include('admin.settings.partials.clear-credentials', ['provider' => 'mpesa'])
                </section>
            @elseif ($activeTab === 'whatsapp')
                <section role="tabpanel" class="space-y-6 p-5 sm:p-7">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div><h2 class="text-lg font-bold text-slate-900">WhatsApp</h2><p class="mt-1 text-sm text-slate-600">Connect the hospital's WhatsApp Business account.</p></div>
                        @include('admin.settings.partials.configured', ['provider' => 'whatsapp', 'credentials' => $credentials['whatsapp']])
                    </div>
                    <form method="POST" action="{{ route('admin.integration-settings.whatsapp.update') }}" class="space-y-5">
                        @csrf
                        @method('PUT')
                        <div class="grid gap-5 sm:grid-cols-2">
                            <label class="block text-sm font-semibold text-slate-700">Phone number ID
                                <input class="{{ $inputClass }}" type="text" name="phone_number_id" required maxlength="64" value="{{ old('phone_number_id', $credentials['whatsapp']['phone_number_id'] ?? '') }}">
                            </label>
                            @foreach (['access_token' => 'Access token', 'verify_token' => 'Verify token', 'app_secret' => 'App secret'] as $field => $label)
                                <label class="block text-sm font-semibold text-slate-700">{{ $label }}
                                    <input class="{{ $inputClass }}" type="password" name="{{ $field }}" placeholder="{{ $credentials['whatsapp'][$field] ?? '' }}" autocomplete="new-password">
                                    <span class="mt-1 block text-xs font-normal text-slate-500">Leave blank to keep existing.</span>
                                </label>
                            @endforeach
                            <label class="block text-sm font-semibold text-slate-700">API version
                                <input class="{{ $inputClass }}" type="text" name="api_version" required maxlength="10" value="{{ old('api_version', $credentials['whatsapp']['api_version'] ?? 'v21.0') }}">
                            </label>
                        </div>
                        <button type="submit" class="rounded-lg bg-sky-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-sky-900">Save WhatsApp settings</button>
                    </form>
                    @include('admin.settings.partials.clear-credentials', ['provider' => 'whatsapp'])
                </section>
            @elseif ($activeTab === 'sms')
                <section role="tabpanel" class="space-y-6 p-5 sm:p-7">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div><h2 class="text-lg font-bold text-slate-900">SMS</h2><p class="mt-1 text-sm text-slate-600">Configure Africa's Talking or Twilio delivery credentials.</p></div>
                        @include('admin.settings.partials.configured', ['provider' => 'sms', 'credentials' => $credentials['sms']])
                    </div>
                    <form method="POST" action="{{ route('admin.integration-settings.sms.update') }}" class="space-y-5">
                        @csrf
                        @method('PUT')
                        <label class="block max-w-md text-sm font-semibold text-slate-700">Provider
                            <select class="{{ $inputClass }}" name="provider" required>
                                @foreach (['africastalking' => "Africa's Talking", 'twilio' => 'Twilio'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('provider', $credentials['sms']['provider'] ?? 'africastalking') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <div class="grid gap-5 sm:grid-cols-2">
                            @foreach ([
                                'username' => ['Username', false],
                                'api_key' => ['API key', true],
                                'sender_id' => ['Sender ID', false],
                                'account_sid' => ['Twilio account SID', true],
                                'auth_token' => ['Twilio auth token', true],
                                'from_number' => ['Twilio from number', false],
                            ] as $field => [$label, $secret])
                                <label class="block text-sm font-semibold text-slate-700">{{ $label }}
                                    <input class="{{ $inputClass }}" type="{{ $secret ? 'password' : 'text' }}" name="{{ $field }}" @if (! $secret) value="{{ old($field, $credentials['sms'][$field] ?? '') }}" @else placeholder="{{ $credentials['sms'][$field] ?? '' }}" autocomplete="new-password" @endif>
                                    @if ($secret)<span class="mt-1 block text-xs font-normal text-slate-500">Leave blank to keep existing.</span>@endif
                                </label>
                            @endforeach
                        </div>
                        <button type="submit" class="rounded-lg bg-sky-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-sky-900">Save SMS settings</button>
                    </form>
                    @include('admin.settings.partials.clear-credentials', ['provider' => 'sms'])
                </section>
            @elseif ($activeTab === 'email')
                <section role="tabpanel" class="space-y-6 p-5 sm:p-7">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div><h2 class="text-lg font-bold text-slate-900">Email</h2><p class="mt-1 text-sm text-slate-600">Configure Resend, SMTP, or log mail delivery.</p></div>
                        @include('admin.settings.partials.configured', ['provider' => 'email', 'credentials' => $credentials['email']])
                    </div>
                    <form method="POST" action="{{ route('admin.integration-settings.email.update') }}" class="space-y-5">
                        @csrf
                        @method('PUT')
                        <div class="grid gap-5 sm:grid-cols-2">
                            <label class="block text-sm font-semibold text-slate-700">Mailer
                                <select class="{{ $inputClass }}" name="mailer" required>
                                    @foreach (['resend' => 'Resend', 'smtp' => 'SMTP', 'log' => 'Log'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('mailer', $credentials['email']['mailer'] ?? 'resend') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block text-sm font-semibold text-slate-700">From address
                                <input class="{{ $inputClass }}" type="email" name="from_address" required value="{{ old('from_address', $credentials['email']['from_address'] ?? '') }}">
                            </label>
                            <label class="block text-sm font-semibold text-slate-700">From name
                                <input class="{{ $inputClass }}" type="text" name="from_name" required maxlength="120" value="{{ old('from_name', $credentials['email']['from_name'] ?? '') }}">
                            </label>
                            <label class="block text-sm font-semibold text-slate-700">Resend API key
                                <input class="{{ $inputClass }}" type="password" name="resend_api_key" placeholder="{{ $credentials['email']['resend_api_key'] ?? '' }}" autocomplete="new-password">
                                <span class="mt-1 block text-xs font-normal text-slate-500">Leave blank to keep existing.</span>
                            </label>
                            @foreach ([
                                'smtp_host' => 'SMTP host',
                                'smtp_port' => 'SMTP port',
                                'smtp_username' => 'SMTP username',
                                'smtp_password' => 'SMTP password',
                                'smtp_encryption' => 'SMTP encryption (tls, ssl, or none)',
                            ] as $field => $label)
                                <label class="block text-sm font-semibold text-slate-700">{{ $label }}
                                    <input class="{{ $inputClass }}" type="{{ $field === 'smtp_password' ? 'password' : ($field === 'smtp_port' ? 'number' : 'text') }}" name="{{ $field }}" @if ($field === 'smtp_password') placeholder="{{ $credentials['email']['smtp_password'] ?? '' }}" autocomplete="new-password" @else value="{{ old($field, $credentials['email'][$field] ?? '') }}" @endif>
                                    @if ($field === 'smtp_password')<span class="mt-1 block text-xs font-normal text-slate-500">Leave blank to keep existing.</span>@endif
                                </label>
                            @endforeach
                        </div>
                        <button type="submit" class="rounded-lg bg-sky-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-sky-900">Save email settings</button>
                    </form>
                    @include('admin.settings.partials.clear-credentials', ['provider' => 'email'])
                </section>
            @else
                <section role="tabpanel" class="space-y-6 p-5 sm:p-7">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Branding</h2>
                        <p class="mt-1 text-sm text-slate-600">Customize the header and footer shown on your hospital pages.</p>
                    </div>
                    <form method="POST" action="{{ route('admin.integration-settings.branding.update') }}" enctype="multipart/form-data" class="max-w-2xl space-y-5">
                        @csrf
                        @method('PUT')
                        <label class="block text-sm font-semibold text-slate-700">Chatbot name
                            <input class="{{ $inputClass }}" type="text" name="chatbot_name" maxlength="60" value="{{ old('chatbot_name', $hospital->chatbot_name) }}" placeholder="{{ $hospital->chatbotName() }}">
                            <span class="mt-1 block text-xs font-normal text-slate-500">The name patients see when chatting with your AI assistant. Leave blank to auto-derive from the hospital name.</span>
                        </label>
                        <label class="block text-sm font-semibold text-slate-700">Header text
                            <input class="{{ $inputClass }}" type="text" name="site_header_text" maxlength="120" value="{{ old('site_header_text', $hospital->site_header_text ?? $hospital->name) }}">
                        </label>
                        <label class="block text-sm font-semibold text-slate-700">Footer text
                            <textarea class="{{ $inputClass }}" name="site_footer_text" rows="4" maxlength="2000">{{ old('site_footer_text', $hospital->site_footer_text ?? trim($hospital->name.' — '.($hospital->address ?? ''))) }}</textarea>
                        </label>
                        <label class="block text-sm font-semibold text-slate-700">Hospital logo
                            <input class="{{ $inputClass }}" type="file" name="site_logo" accept="image/png,image/jpeg,image/svg+xml">
                            <span class="mt-1 block text-xs font-normal text-slate-500">Optional PNG, JPG, JPEG, or SVG image, up to 2 MB.</span>
                        </label>
                        @if ($hospital->site_logo_path)
                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($hospital->site_logo_path) }}" alt="Current hospital logo" class="h-16 w-auto rounded-lg border border-slate-200 bg-white p-2">
                        @endif
                        <button type="submit" class="rounded-lg bg-sky-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-sky-900">Save branding settings</button>
                    </form>
                </section>
            @endif
        </div>
    </div>
@endsection
