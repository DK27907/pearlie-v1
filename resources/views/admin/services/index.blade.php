@extends('layouts.app')

@section('title', 'Service catalog | '.pearlie_config('hospital.name'))

@section('content')
    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm font-semibold uppercase tracking-widest text-sky-700">{{ pearlie_config('hospital.name') }}</p>
                <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-900">Service catalog</h1>
                <p class="mt-2 text-slate-600">Set the prices and durations used for patient bookings.</p>
            </div>
            <a href="{{ route('admin.services.create') }}" class="rounded-xl bg-sky-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-sky-900">Add service</a>
        </header>

        @if (session('status'))
            <p role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900">{{ session('status') }}</p>
        @endif

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-5 py-3">Name</th>
                            <th scope="col" class="px-5 py-3">Price</th>
                            <th scope="col" class="px-5 py-3">Duration</th>
                            <th scope="col" class="px-5 py-3">Category</th>
                            <th scope="col" class="px-5 py-3">Status</th>
                            <th scope="col" class="px-5 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($services as $service)
                            <tr>
                                <td class="px-5 py-4 font-semibold text-slate-900">
                                    {{ $service->name }}
                                    @if ($service->requires_specialty)
                                        <p class="mt-1 text-xs font-normal text-slate-500">Specialty: {{ $service->requires_specialty }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-slate-700">KSh {{ number_format((float) $service->price, 2) }}</td>
                                <td class="whitespace-nowrap px-5 py-4 text-slate-700">{{ $service->duration_minutes }} min</td>
                                <td class="px-5 py-4 text-slate-700">{{ $service->category ?: '—' }}</td>
                                <td class="px-5 py-4">
                                    <span @class([
                                        'rounded-full px-2.5 py-1 text-xs font-semibold',
                                        'bg-emerald-100 text-emerald-800' => $service->is_active,
                                        'bg-slate-100 text-slate-600' => ! $service->is_active,
                                    ])>{{ $service->is_active ? 'Active' : 'Inactive' }}</span>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <a href="{{ route('admin.services.edit', $service) }}" class="font-semibold text-sky-800 hover:underline">Edit</a>
                                        @if ($service->is_active)
                                            <form method="POST" action="{{ route('admin.services.destroy', $service) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="font-semibold text-rose-700 hover:underline">Deactivate</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('admin.services.toggle-active', $service) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="font-semibold text-emerald-700 hover:underline">Activate</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-10 text-center text-slate-500">No services have been added yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($services->hasPages())
                <div class="border-t border-slate-200 px-5 py-4">{{ $services->links() }}</div>
            @endif
        </div>
    </div>
@endsection
