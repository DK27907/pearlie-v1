<x-guest-layout>
    <!-- Session Status -->
    <x-auth-session-status class="flash-message mb-5" :status="session('status')" />

    <div class="mb-7 animate-fade-up">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-cyan-800">Welcome back</p>
        <h1 class="mt-2 text-3xl font-bold tracking-tight text-midnight">Sign in to your workspace</h1>
        <p class="mt-2 text-sm leading-6 text-slate-600">Manage patient requests and care operations securely.</p>
    </div>
    <form method="POST" action="{{ route('login') }}" class="auth-form space-y-5">
        @csrf

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div>
            <x-password-input id="password" name="password" :label="__('Password')" class="mt-1 block w-full" required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="block">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded border-slate-300 text-cyan-700 shadow-sm focus:ring-cyan-600" name="remember">
                <span class="ms-2 text-sm text-gray-600">{{ __('Remember me') }}</span>
            </label>
        </div>

        <div class="flex items-center justify-between pt-2">
            @if (Route::has('password.request'))
                <a class="rounded-md text-sm font-semibold text-cyan-800 underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-cyan-600" href="{{ route('password.request') }}">
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            <x-primary-button class="group ms-3 w-full">
                {{ __('Sign in') }}
                <svg class="h-4 w-4 transition-transform duration-200 group-hover:translate-x-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
