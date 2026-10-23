<x-guest-layout>
    <h1 class="mb-2 text-2xl font-bold text-slate-900">Change your temporary password</h1>
    <p class="mb-6 text-sm text-slate-600">Choose a new password before continuing to your dashboard.</p>

    <form method="POST" action="{{ route('password.force.update') }}" class="space-y-5">
        @csrf
        @method('PUT')

        <div>
            <x-password-input id="password" name="password" :label="__('New password')" label-class="block text-sm font-medium text-gray-700" class="block w-full" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div>
            <x-password-input id="password_confirmation" name="password_confirmation" :label="__('Confirm new password')" label-class="block text-sm font-medium text-gray-700" class="block w-full" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <x-primary-button>{{ __('Change password') }}</x-primary-button>
    </form>
</x-guest-layout>
