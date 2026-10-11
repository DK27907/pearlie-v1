<div class="space-y-6 border-t border-gray-200 pt-6">
    <div>
        <h3 class="text-base font-medium text-gray-900">{{ __('Professional information') }}</h3>
        <p class="mt-1 text-sm text-gray-600">{{ __('These details appear to patients when they browse doctors and appointment times.') }}</p>
    </div>

    <div>
        <x-input-label for="specialization" :value="__('Specialty')" />
        <x-text-input id="specialization" name="specialization" type="text" class="mt-1 block w-full" :value="old('specialization', $user->specialization)" maxlength="255" required />
        <x-input-error class="mt-2" :messages="$errors->get('specialization')" />
    </div>

    <div>
        <x-input-label for="bio" :value="__('Professional bio')" />
        <textarea id="bio" name="bio" rows="4" maxlength="3000" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('bio', $user->bio) }}</textarea>
        <x-input-error class="mt-2" :messages="$errors->get('bio')" />
    </div>

    <div>
        <x-input-label for="consultation_fee" :value="__('Consultation fee (KSh)')" />
        <x-text-input id="consultation_fee" name="consultation_fee" type="number" min="0" max="1000000" step="0.01" class="mt-1 block w-full" :value="old('consultation_fee', $user->consultation_fee)" />
        <x-input-error class="mt-2" :messages="$errors->get('consultation_fee')" />
    </div>

    <div>
        <x-input-label for="licence_number" :value="__('Licence number')" />
        <x-text-input id="licence_number" name="licence_number" type="text" class="mt-1 block w-full" :value="old('licence_number', $user->licence_number)" maxlength="100" />
        <x-input-error class="mt-2" :messages="$errors->get('licence_number')" />
    </div>
</div>
