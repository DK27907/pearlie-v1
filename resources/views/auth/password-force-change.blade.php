<x-guest-layout>
    <h1 class="mb-2 text-2xl font-bold text-slate-900">Change your temporary password</h1>
    <p class="mb-6 text-sm text-slate-600">Choose a new password before continuing to your dashboard.</p>

    <form method="POST" action="{{ route('password.force.update') }}" class="space-y-5">
        @csrf
        @method('PUT')

        <div>
            <x-input-label for="password" :value="__('New password')" />
            <x-text-input id="password" class="mt-1 block w-full" type="password" name="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password_confirmation" :value="__('Confirm new password')" />
            <x-text-input id="password_confirmation" class="mt-1 block w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <x-primary-button>{{ __('Change password') }}</x-primary-button>
    </form>
</x-guest-layout>
