@extends('layouts.app')

@section('title', 'Service answers | '.pearlie_config('hospital.name'))

@section('content')
    <div class="mx-auto max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm font-semibold uppercase tracking-widest text-sky-700">{{ pearlie_config('hospital.name') }}</p>
                <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-900">Service answers</h1>
                <p class="mt-2 text-slate-600">Manage the service information used by the assistant.</p>
            </div>
            <a href="{{ route('admin.knowledge-services.create') }}" class="rounded-xl bg-sky-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-sky-900">Add service answer</a>
        </header>

        @if (session('status'))
            <p role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900">{{ session('status') }}</p>
        @endif

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($entries as $entry)
                <article class="flex h-full flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <h2 class="text-lg font-bold capitalize text-slate-900">{{ str_replace(['-', '_'], ' ', $entry->subcategory) }}</h2>
                        <span class="rounded-full bg-sky-50 px-2.5 py-1 text-xs font-semibold text-sky-800">Information</span>
                    </div>
                    <p class="mt-3 text-sm font-semibold text-slate-700">{{ $entry->question }}</p>
                    <p class="mt-2 flex-1 text-sm leading-6 text-slate-600">{{ \Illuminate\Support\Str::limit($entry->answer, 220) }}</p>
                    <p class="mt-3 text-xs text-slate-500">Keywords: {{ $entry->keywords_string }}</p>
                    <div class="mt-5 flex items-center gap-3 border-t border-slate-100 pt-4">
                        <a href="{{ route('admin.knowledge-services.edit', $entry) }}" class="text-sm font-semibold text-sky-800 hover:underline">Edit</a>
                        <form method="POST" action="{{ route('admin.knowledge-services.destroy', $entry) }}" data-confirm="Delete this service entry?">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-sm font-semibold text-rose-700 hover:underline">Delete</button>
                        </form>
                    </div>
                </article>
            @empty
                <p class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-slate-600 md:col-span-2 xl:col-span-3">No service information has been added yet.</p>
            @endforelse
        </div>

        {{ $entries->links() }}
    </div>
@endsection
