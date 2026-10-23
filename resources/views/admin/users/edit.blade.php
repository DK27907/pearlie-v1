@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div>
        <a href="{{ route('admin.users.index') }}" class="text-sm font-semibold text-cyan-800 hover:underline">← Back to users</a>
        <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">Edit user</h1>
        <p class="mt-1 text-sm text-slate-600">Update account details and hospital access.</p>
    </div>

    @if ($errors->any())
        <div role="alert" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('admin.users.update', $user) }}" class="space-y-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        @csrf
        @method('PUT')

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="name" class="mb-1 block text-sm font-medium text-slate-700">Name</label>
                <input id="name" name="name" value="{{ old('name', $user->name) }}" required maxlength="255" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-600 focus:ring-cyan-600">
                @error('name')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="email" class="mb-1 block text-sm font-medium text-slate-700">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required maxlength="255" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-600 focus:ring-cyan-600">
                @error('email')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="phone" class="mb-1 block text-sm font-medium text-slate-700">Phone</label>
                <input id="phone" name="phone" type="tel" value="{{ old('phone', $user->phone) }}" maxlength="30" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-600 focus:ring-cyan-600">
                @error('phone')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="role" class="mb-1 block text-sm font-medium text-slate-700">Role</label>
                <select id="role" name="role" required class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-600 focus:ring-cyan-600">
                    @foreach ($roles as $role)
                        <option value="{{ $role }}" @selected(old('role', $user->role) === $role)>{{ str($role)->replace('_', ' ')->title() }}</option>
                    @endforeach
                </select>
                @error('role')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="space-y-3 border-t border-slate-100 pt-5">
            <h2 class="text-lg font-semibold text-slate-900">Change password</h2>
            <p class="text-sm text-slate-600">Leave both password fields blank to keep the current password.</p>
            <div class="grid gap-5 sm:grid-cols-2">
            <div class="space-y-1">
                <x-password-input id="password" name="password" label="New password" label-class="block text-sm font-medium text-slate-700" autocomplete="new-password" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-600 focus:ring-cyan-600" />
                @error('password')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
            </div>
            <div class="space-y-1">
                <x-password-input id="password_confirmation" name="password_confirmation" label="Confirm new password" label-class="block text-sm font-medium text-slate-700" autocomplete="new-password" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-600 focus:ring-cyan-600" />
            </div>
            </div>
            <label for="must_change_password" class="flex items-start gap-3">
                <input type="hidden" name="must_change_password" value="0">
                <input id="must_change_password" name="must_change_password" type="checkbox" value="1" @checked(old('must_change_password', $user->must_change_password)) class="mt-1 rounded border-slate-300 text-cyan-700 focus:ring-cyan-600">
                <span class="text-sm text-slate-700">Force password change on next login</span>
            </label>
        </div>

        <div class="border-t border-slate-100 pt-5">
            <input type="hidden" name="is_active" value="0">
            <label for="is_active" class="flex items-start gap-3">
                <input id="is_active" name="is_active" type="checkbox" value="1" @checked(old('is_active', $user->is_active)) class="mt-1 rounded border-slate-300 text-cyan-700 focus:ring-cyan-600">
                <span class="text-sm text-slate-700">Account is active</span>
            </label>
            @error('is_active')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
        </div>

        <div class="flex justify-end border-t border-slate-100 pt-5">
            <button type="submit" class="rounded-lg bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">Save changes</button>
        </div>
    </form>
</div>
@endsection
