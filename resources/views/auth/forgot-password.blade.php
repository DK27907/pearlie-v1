<x-guest-layout>
    <div class="mb-4">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-cyan-800">Account recovery</p>
        <h1 class="mt-2 text-3xl font-bold tracking-tight text-midnight">{{ __('Forgot your password?') }}</h1>
        <p class="mt-2 text-sm text-gray-600">
            {{ __('No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.') }}
        </p>
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="flash-message mb-5" :status="session('status')" />

    <form method="POST" action="{{ request()->routeIs('tenant.password.request', 'tenant.password.email') ? route('tenant.password.email', ['hospital' => hospital()->slug]) : route('password.email') }}" class="auth-form space-y-5">
        @csrf

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email')" required autofocus />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="flex justify-end pt-2">
            <x-primary-button class="group w-full sm:w-auto">
                {{ __('Email Password Reset Link') }}
                <svg class="h-4 w-4 transition-transform duration-200 group-hover:translate-x-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
