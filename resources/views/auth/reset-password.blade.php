<x-guest-layout>
    <div class="mb-7">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-cyan-800">Secure account setup</p>
        <h1 class="font-display mt-2 text-3xl font-semibold tracking-tight text-midnight">{{ __('Set a new password') }}</h1>
        <p class="mt-2 text-sm leading-6 text-slate-600">Reset your password securely and choose a strong, private password for your account.</p>
    </div>
    <form method="POST" action="{{ route('password.store') }}" class="auth-form grid gap-4">
        @csrf

        <!-- Password Reset Token -->
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <input type="hidden" name="email" value="{{ old('email', $request->email) }}">
            <x-text-input id="email" class="mt-1 block w-full cursor-not-allowed bg-slate-100 text-slate-500" type="email" :value="old('email', $request->email)" disabled autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div x-data="passwordStrength" x-on:input="measure">
            <x-password-input id="password" name="password" :label="__('Password')" class="block w-full" required autocomplete="new-password" />
            <div class="mt-2" aria-live="polite">
                <div class="strength-track" role="meter" aria-label="Password strength" aria-valuemin="0" aria-valuemax="4" x-bind:aria-valuenow="score">
                    <div class="strength-bar" x-bind:class="barClass" x-bind:style="barStyle"></div>
                </div>
                <p class="mt-1 text-xs text-slate-500" x-text="label">Use at least 8 characters</p>
            </div>
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div>
            <x-password-input id="password_confirmation" name="password_confirmation" :label="__('Confirm Password')" class="block w-full" required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="pt-1">
            <x-primary-button class="group w-full">
                {{ __('Reset Password') }}
                <svg class="h-4 w-4 transition-transform duration-200 group-hover:translate-x-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
