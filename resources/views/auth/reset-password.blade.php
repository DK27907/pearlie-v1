<x-guest-layout>
    <div class="mb-7">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-cyan-800">Secure account setup</p>
        <h1 class="mt-2 text-3xl font-bold tracking-tight text-midnight">{{ __('Reset your password') }}</h1>
        <p class="mt-2 text-sm leading-6 text-slate-600">Choose a strong, private password for your account.</p>
    </div>
    <form method="POST" action="{{ route('password.store') }}" class="auth-form space-y-4">
        @csrf

        <!-- Password Reset Token -->
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email', $request->email)" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-password-input id="password" name="password" :label="__('Password')" class="block w-full" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-password-input id="password_confirmation" name="password_confirmation" :label="__('Confirm Password')" class="block w-full" required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex justify-end pt-2">
            <x-primary-button class="group w-full sm:w-auto">
                {{ __('Reset Password') }}
                <svg class="h-4 w-4 transition-transform duration-200 group-hover:translate-x-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
