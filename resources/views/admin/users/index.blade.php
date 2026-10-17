@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-screen-2xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">User management</h1>
        <p class="mt-1 text-sm text-slate-600">Manage accounts and access for this hospital.</p>
    </div>

    @if (session('status'))
        <div role="status" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div role="alert" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="GET" action="{{ route('admin.users.index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
        <div class="w-full sm:max-w-md">
            <label for="search" class="mb-1 block text-sm font-medium text-slate-700">Search users</label>
            <input id="search" name="search" value="{{ $search }}" type="search" placeholder="Name, email or phone" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-600 focus:ring-cyan-600">
        </div>
        <div>
            <label for="role" class="mb-1 block text-sm font-medium text-slate-700">Role</label>
            <select id="role" name="role" class="rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-600 focus:ring-cyan-600">
                <option value="">All roles</option>
                @foreach ($roles as $role)
                    <option value="{{ $role }}" @selected($roleFilter === $role)>{{ str($role)->replace('_', ' ')->title() }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Filter</button>
    </form>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-5 py-3">Name</th>
                        <th scope="col" class="px-5 py-3">Email</th>
                        <th scope="col" class="px-5 py-3">Phone</th>
                        <th scope="col" class="px-5 py-3">Role</th>
                        <th scope="col" class="px-5 py-3">Status</th>
                        <th scope="col" class="px-5 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($users as $user)
                        <tr>
                            <td class="whitespace-nowrap px-5 py-4 font-medium text-slate-900">{{ $user->name }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-slate-600">{{ $user->email }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-slate-600">{{ $user->phone ?: '—' }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-slate-600">{{ str($user->role)->replace('_', ' ')->title() }}</td>
                            <td class="px-5 py-4">
                                <span @class([
                                    'rounded-full px-2.5 py-1 text-xs font-semibold',
                                    'bg-emerald-100 text-emerald-800' => $user->is_active,
                                    'bg-slate-100 text-slate-600' => ! $user->is_active,
                                ])>{{ $user->is_active ? 'Active' : 'Inactive' }}</span>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex flex-wrap items-center gap-3">
                                    <a href="{{ route('admin.users.edit', $user) }}" class="font-semibold text-cyan-800 hover:underline">Edit</a>
                                    @if ($user->is_active)
                                        <form method="POST" action="{{ route('admin.users.destroy', $user) }}" data-confirm="Deactivate this user account?">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="font-semibold text-rose-700 hover:underline">Deactivate</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-10 text-center text-slate-500">No users found for this hospital.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($users->hasPages())
            <div class="border-t border-slate-200 px-5 py-4">{{ $users->links() }}</div>
        @endif
    </div>
</div>
@endsection
