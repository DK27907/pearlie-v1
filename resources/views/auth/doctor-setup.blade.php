<x-guest-layout>
    <div class="mb-6 space-y-2">
        <h1 class="text-xl font-semibold text-slate-900">Set up your doctor account</h1>
        <p class="text-sm text-slate-600">Welcome, {{ $invite->doctor->name }}. Choose a password to activate your account.</p>
    </div>

    <form method="POST" action="{{ route('doctor.setup.store', ['token' => $token, 'hospital' => $invite->doctor->hospital?->slug]) }}" class="space-y-4">
        @csrf
        <div>
            <x-password-input id="password" name="password" :label="__('Password')" label-class="block text-sm font-medium text-gray-700" class="block w-full" required autocomplete="new-password" />
            <p class="mt-1 text-xs text-slate-500">Use at least 12 characters, including letters and numbers.</p>
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>
        <div>
            <x-password-input id="password_confirmation" name="password_confirmation" :label="__('Confirm password')" label-class="block text-sm font-medium text-gray-700" class="block w-full" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>
        <div class="flex justify-end">
            <x-primary-button>{{ __('Set password and continue') }}</x-primary-button>
        </div>
    </form>
</x-guest-layout>
