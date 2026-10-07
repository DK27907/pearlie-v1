@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">Doctor management</h1>
            <p class="mt-1 text-sm text-slate-600">Manage doctor profiles and account invitations.</p>
        </div>
        <a href="{{ route('admin.doctors.create') }}" class="rounded-lg bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">Add doctor</a>
    </div>

    @if (session('status'))
        <div role="status" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div role="alert" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">{{ session('error') }}</div>
    @endif

    <form method="GET" action="{{ route('admin.doctors.index') }}" class="flex flex-col gap-3 sm:flex-row">
        <label for="search" class="sr-only">Search doctors</label>
        <input id="search" name="search" value="{{ $search }}" type="search" placeholder="Search name, email, specialty or phone" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-600 focus:ring-cyan-600 sm:max-w-md">
        <button type="submit" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Search</button>
    </form>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-5 py-3">Name</th>
                        <th scope="col" class="px-5 py-3">Email</th>
                        <th scope="col" class="px-5 py-3">Specialization</th>
                        <th scope="col" class="px-5 py-3">Phone</th>
                        <th scope="col" class="px-5 py-3">Status</th>
                        <th scope="col" class="px-5 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($doctors as $doctor)
                        <tr>
                            <td class="whitespace-nowrap px-5 py-4 font-medium text-slate-900">{{ $doctor->name }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-slate-600">{{ $doctor->email }}</td>
                            <td class="px-5 py-4 text-slate-600">{{ $doctor->specialization }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-slate-600">{{ $doctor->phone }}</td>
                            <td class="px-5 py-4">
                                <span @class([
                                    'rounded-full px-2.5 py-1 text-xs font-semibold',
                                    'bg-emerald-100 text-emerald-800' => $doctor->isDoctor(),
                                    'bg-slate-100 text-slate-600' => ! $doctor->isDoctor(),
                                ])>{{ $doctor->isDoctor() ? 'Active' : 'Inactive' }}</span>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex flex-wrap items-center gap-3">
                                    <a href="{{ route('admin.doctors.edit', $doctor) }}" class="font-semibold text-cyan-800 hover:underline">Edit</a>
                                    @if ($doctor->isDoctor())
                                        <form method="POST" action="{{ route('admin.doctors.invite', $doctor) }}">
                                            @csrf
                                            <button type="submit" class="font-semibold text-slate-700 hover:underline">Resend invite</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.doctors.destroy', $doctor) }}" onsubmit="return confirm('Deactivate this doctor account?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="font-semibold text-rose-700 hover:underline">Deactivate</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-10 text-center text-slate-500">No doctor accounts found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($doctors->hasPages())
            <div class="border-t border-slate-200 px-5 py-4">{{ $doctors->links() }}</div>
        @endif
    </div>
</div>
@endsection
