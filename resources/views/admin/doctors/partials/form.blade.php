@php($doctor = $doctor ?? null)

@foreach ([
    'name' => ['Name', 'text'],
    'email' => ['Email address', 'email'],
    'specialization' => ['Specialization', 'text'],
    'phone' => ['Phone', 'tel'],
] as $field => [$label, $type])
    <div>
        <label for="{{ $field }}" class="block text-sm font-medium text-slate-700">{{ $label }}</label>
        <input
            id="{{ $field }}"
            name="{{ $field }}"
            type="{{ $type }}"
            value="{{ old($field, $doctor?->{$field}) }}"
            required
            maxlength="{{ $field === 'phone' ? 30 : 255 }}"
            @if ($field === 'email') autocomplete="email" @endif
            @if ($field === 'phone') autocomplete="tel" @endif
            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-cyan-600 focus:ring-cyan-600"
        >
        @error($field)
            <p class="mt-1 text-sm text-rose-700">{{ $message }}</p>
        @enderror
    </div>
@endforeach

<div>
    <label for="bio" class="block text-sm font-medium text-slate-700">Professional bio</label>
    <textarea
        id="bio"
        name="bio"
        rows="4"
        maxlength="3000"
        class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-cyan-600 focus:ring-cyan-600"
    >{{ old('bio', $doctor?->bio) }}</textarea>
    @error('bio')
        <p class="mt-1 text-sm text-rose-700">{{ $message }}</p>
    @enderror
</div>

<div>
    <label for="consultation_fee" class="block text-sm font-medium text-slate-700">Consultation fee (KSh)</label>
    <input
        id="consultation_fee"
        name="consultation_fee"
        type="number"
        min="0"
        max="1000000"
        step="0.01"
        value="{{ old('consultation_fee', $doctor?->consultation_fee) }}"
        class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-cyan-600 focus:ring-cyan-600"
    >
    @error('consultation_fee')
        <p class="mt-1 text-sm text-rose-700">{{ $message }}</p>
    @enderror
</div>

<div>
    <label for="licence_number" class="block text-sm font-medium text-slate-700">Licence number</label>
    <input
        id="licence_number"
        name="licence_number"
        type="text"
        maxlength="100"
        value="{{ old('licence_number', $doctor?->licence_number) }}"
        class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-cyan-600 focus:ring-cyan-600"
    >
    @error('licence_number')
        <p class="mt-1 text-sm text-rose-700">{{ $message }}</p>
    @enderror
</div>

@if ($doctor)
        <div class="space-y-3 border-t border-slate-100 pt-5">
            <h2 class="text-lg font-semibold text-slate-900">Change password</h2>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <x-password-input
                        id="password"
                        name="password"
                        label="New password"
                        label-class="block text-sm font-medium text-slate-700"
                        autocomplete="new-password"
                        class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-600 focus:ring-cyan-600"
                    />
                    @error('password')
                        <p class="mt-1 text-sm text-rose-700">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <x-password-input
                        id="password_confirmation"
                        name="password_confirmation"
                        label="Confirm new password"
                        label-class="block text-sm font-medium text-slate-700"
                        autocomplete="new-password"
                        class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-600 focus:ring-cyan-600"
                    />
                </div>
            </div>
            <p class="text-sm text-slate-600">Leave blank to keep the current password.</p>
        </div>
@endif

<div class="rounded-lg border border-cyan-100 bg-cyan-50 p-4">
        <label for="use_temporary_password" class="flex items-start gap-3">
            <input
            id="use_temporary_password"
            name="use_temporary_password"
            type="checkbox"
            value="1"
            @checked(old('use_temporary_password'))
            class="mt-1 rounded border-slate-300 text-cyan-700 focus:ring-cyan-600"
        >
        <span>
            <span class="block text-sm font-semibold text-slate-900">Use temporary password (doctor must change on first login)</span>
            <span class="mt-1 block text-sm text-slate-600">The generated password is shown only once after saving. Otherwise, the usual invitation email is sent for a new account.</span>
        </span>
    </label>
    @error('use_temporary_password')
        <p class="mt-2 text-sm text-rose-700">{{ $message }}</p>
    @enderror
</div>

<div class="flex items-center justify-end gap-3 border-t border-slate-100 pt-5">
    <a href="{{ route('admin.doctors.index') }}" class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancel</a>
    <button type="submit" class="rounded-lg bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">{{ $submitLabel }}</button>
</div>
