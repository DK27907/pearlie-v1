<x-guest-layout>
    <x-auth-session-status class="flash-message mb-5" :status="session('status')" />

    <div class="mb-6">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-[var(--hospital-primary)]">Secure staff access</p>
        <h1 class="mt-2 text-3xl font-bold tracking-tight text-midnight">Sign in to {{ $branding['name'] }}</h1>
        <p class="mt-1 text-sm text-slate-500">Manage your hospital's patient requests and care operations.</p>
    </div>
    <form method="POST" action="{{ route('tenant.login.store', ['hospital' => $hospital->slug]) }}" class="auth-form space-y-5">
        @csrf

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <x-password-input id="password" name="password" :label="__('Password')" class="block w-full" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="flex items-center justify-between gap-3">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded border-slate-300 text-cyan-700 shadow-sm focus:ring-cyan-600" name="remember">
                <span class="ms-2 text-sm text-gray-600">{{ __('Remember me') }}</span>
            </label>
            <a class="rounded-md text-sm font-semibold text-[var(--hospital-primary)] underline-offset-4 hover:underline" href="{{ route('tenant.password.request', ['hospital' => $hospital->slug]) }}">
                {{ __('Forgot your password?') }}
            </a>
        </div>

        <x-primary-button class="group w-full">
            {{ __('Sign in') }}
            <svg class="h-4 w-4 transition-transform duration-200 group-hover:translate-x-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
        </x-primary-button>
    </form>
</x-guest-layout>
