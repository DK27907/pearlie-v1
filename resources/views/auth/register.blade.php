<x-guest-layout>
    <div class="mb-7 animate-fade-up">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-cyan-800">Get started</p>
        <h1 class="font-display mt-2 text-3xl font-semibold tracking-tight text-midnight">{{ __('Create an account') }}</h1>
        <p class="mt-2 text-sm leading-6 text-slate-600">Set up secure access to your MediDesk Assistant workspace.</p>
    </div>
    <form method="POST" action="{{ route('register') }}" class="auth-form space-y-4">
        @csrf

        <!-- Name -->
        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" class="mt-1 block w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <!-- Email Address -->
        <div class="mt-4">
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div x-data="passwordStrength" x-on:input="measure">
            <x-password-input id="password" name="password" :label="__('Password')" label-class="block text-sm font-medium text-gray-700" class="block w-full" required autocomplete="new-password" />
            <div class="mt-2" aria-live="polite">
                <div class="strength-track" role="meter" aria-label="Password strength" aria-valuemin="0" aria-valuemax="4" x-bind:aria-valuenow="score">
                    <div class="strength-bar" x-bind:class="barClass" x-bind:style="barStyle"></div>
                </div>
                <p class="mt-1 text-xs text-slate-500" x-text="label">Use at least 8 characters</p>
            </div>
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-password-input id="password_confirmation" name="password_confirmation" :label="__('Confirm Password')" label-class="block text-sm font-medium text-gray-700" class="block w-full" required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        @if(!($allow_registration ?? false))
            <div class="mt-4">
                <x-input-label for="invite_token" :value="__('Invite Token')" />
                <x-text-input id="invite_token" class="mt-1 block w-full" type="text" name="invite_token" :value="old('invite_token')" />
                <x-input-error :messages="$errors->get('invite_token')" class="mt-2" />
            </div>
        @endif

        <div class="flex flex-col-reverse items-stretch justify-between gap-4 pt-2 sm:flex-row sm:items-center">
            <a class="rounded-md text-center text-sm font-semibold text-cyan-800 underline-offset-4 hover:underline sm:text-left" href="{{ route('login') }}">
                {{ __('Already registered?') }}
            </a>

            <button type="submit" class="btn-amber group">
                {{ __('Register') }}
                <svg class="h-4 w-4 transition-transform duration-200 group-hover:translate-x-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
            </button>
        </div>
    </form>
</x-guest-layout>
