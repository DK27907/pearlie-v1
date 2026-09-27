<x-guest-layout>
    <div class="mb-6 space-y-2">
        <h1 class="text-xl font-semibold text-slate-900">Set up your doctor account</h1>
        <p class="text-sm text-slate-600">Welcome, {{ $invite->doctor->name }}. Choose a password to activate your account.</p>
    </div>

    <form method="POST" action="{{ route('doctor.setup.store', ['token' => $token]) }}" class="space-y-4">
        @csrf
        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="mt-1 block w-full" type="password" name="password" required autocomplete="new-password" />
            <p class="mt-1 text-xs text-slate-500">Use at least 12 characters, including letters and numbers.</p>
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="password_confirmation" :value="__('Confirm password')" />
            <x-text-input id="password_confirmation" class="mt-1 block w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>
        <div class="flex justify-end">
            <x-primary-button>{{ __('Set password and continue') }}</x-primary-button>
        </div>
    </form>
</x-guest-layout>
