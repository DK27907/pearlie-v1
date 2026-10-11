@extends('layouts.app')

@section('title', 'Knowledge base | '.pearlie_config('hospital.name'))

@section('content')
    <div class="mx-auto max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm font-semibold uppercase tracking-widest text-sky-700">{{ pearlie_config('hospital.name') }}</p>
                <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-900">Knowledge base</h1>
                <p class="mt-2 text-slate-600">Maintain answers used by the tenant assistant.</p>
            </div>
            <a href="{{ route('admin.knowledge-base.create') }}" class="rounded-xl bg-sky-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-sky-900">Add entry</a>
        </header>

        @if (session('status'))
            <p role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900">{{ session('status') }}</p>
        @endif

        <form method="GET" action="{{ route('admin.knowledge-base.index') }}" class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:flex-row">
            <label class="sr-only" for="kb-search">Search knowledge base</label>
            <input id="kb-search" type="search" name="search" value="{{ $search }}" placeholder="Search questions, answers, or categories" class="min-w-0 flex-1 rounded-xl border-slate-300">
            <button class="rounded-xl bg-slate-900 px-5 py-2.5 font-semibold text-white hover:bg-slate-700">Search</button>
        </form>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="divide-y divide-slate-100">
                @forelse ($entries as $entry)
                    <article class="flex flex-col gap-4 p-5 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold capitalize text-slate-700">{{ str_replace('_', ' ', $entry->category) }}</span>
                                @if ($entry->subcategory)
                                    <span class="text-xs text-slate-500">{{ str_replace(['-', '_'], ' ', $entry->subcategory) }}</span>
                                @endif
                            </div>
                            <h2 class="mt-2 font-bold text-slate-900">{{ $entry->question }}</h2>
                            <p class="mt-1 text-sm leading-6 text-slate-600">{{ \Illuminate\Support\Str::limit($entry->answer, 260) }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <a href="{{ route('admin.knowledge-base.edit', $entry) }}" class="text-sm font-semibold text-sky-800 hover:underline">Edit</a>
                            <form method="POST" action="{{ route('admin.knowledge-base.destroy', $entry) }}" data-confirm="Delete this knowledge entry?">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-sm font-semibold text-rose-700 hover:underline">Delete</button>
                            </form>
                        </div>
                    </article>
                @empty
                    <p class="p-8 text-slate-600">No knowledge entries match this search.</p>
                @endforelse
            </div>
        </div>

        {{ $entries->links() }}
    </div>
@endsection
