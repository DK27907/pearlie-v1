<x-guest-layout>
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <div class="mb-6">
        <p class="text-sm font-semibold uppercase tracking-widest text-sky-700">Secure staff access</p>
        <h2 class="mt-2 text-2xl font-bold text-slate-900">Sign in to {{ $branding['name'] }}</h2>
        <p class="mt-1 text-sm text-slate-500">Manage your hospital's patient requests and care operations.</p>
    </div>

    <form method="POST" action="{{ route('tenant.login.store', ['hospital' => $hospital->slug]) }}" class="space-y-5">
        @csrf

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="mt-1 block w-full"
                type="password"
                name="password"
                required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="flex items-center justify-between gap-3">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" name="remember">
                <span class="ms-2 text-sm text-gray-600">{{ __('Remember me') }}</span>
            </label>
            <a class="text-sm font-medium text-sky-800 underline hover:text-sky-950" href="{{ route('tenant.password.request', ['hospital' => $hospital->slug]) }}">
                {{ __('Forgot your password?') }}
            </a>
        </div>

        <x-primary-button class="w-full justify-center !bg-sky-900 hover:!bg-sky-800">
            {{ __('Sign in') }}
        </x-primary-button>
    </form>
</x-guest-layout>
