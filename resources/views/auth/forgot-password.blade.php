<x-guest-layout>
    <div class="mb-7 text-center">
        <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-cyan-50 text-cyan-700 ring-1 ring-cyan-100" aria-hidden="true">
            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5v10.5H3.75z"/><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 7.5 7.5 6 7.5-6"/></svg>
        </span>
        <p class="mt-5 text-xs font-bold uppercase tracking-[0.2em] text-cyan-800">Account recovery</p>
        <h1 class="font-display mt-2 text-3xl font-semibold tracking-tight text-midnight">{{ __('Forgot your password?') }}</h1>
        <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Enter your email and we will send you a reset link.') }}</p>
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="flash-message mb-5" :status="session('status')" />

    <form method="POST" action="{{ request()->routeIs('tenant.password.request', 'tenant.password.email') ? route('tenant.password.email', ['hospital' => hospital()->slug]) : route('password.email') }}" class="auth-form grid gap-5">
        @csrf

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email')" required autofocus />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="pt-1">
            <x-primary-button class="group w-full">
                {{ __('Email Password Reset Link') }}
                <svg class="h-4 w-4 transition-transform duration-200 group-hover:translate-x-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
