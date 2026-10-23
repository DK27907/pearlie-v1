@extends('layouts.app')

@section('title', ($entry->exists ? 'Edit' : 'Add').' '.($serviceOnly ? 'service' : 'knowledge entry').' | '.pearlie_config('hospital.name'))

@section('content')
    <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <header>
            <a href="{{ $serviceOnly ? route('admin.knowledge-services.index') : route('admin.knowledge-base.index') }}" class="text-sm font-semibold text-sky-800 hover:underline">← Back to {{ $serviceOnly ? 'service answers' : 'knowledge base' }}</a>
            <h1 class="mt-3 text-3xl font-bold tracking-tight text-slate-900">{{ $entry->exists ? 'Edit' : 'Add' }} {{ $serviceOnly ? 'service' : 'knowledge entry' }}</h1>
        </header>

        <form method="POST" action="{{ $actionUrl }}" class="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            @csrf
            @if ($method !== 'POST')
                @method($method)
            @endif

            @unless ($serviceOnly)
                <label class="block space-y-1 text-sm font-semibold text-slate-700">Category
                    <select name="category" required class="w-full rounded-xl border-slate-300 font-normal">
                        @foreach ([
                            'service' => 'Service',
                            'services' => 'Services (legacy)',
                            'general_info' => 'General Information',
                            'policy' => 'Policy',
                            'insurance' => 'Insurance',
                            'location' => 'Location',
                            'hours' => 'Hours',
                            'emergency' => 'Emergency',
                            'payment' => 'Payment',
                            'appointments' => 'Appointments',
                            'doctors' => 'Doctors',
                            'visiting' => 'Visiting',
                            'amenities' => 'Amenities',
                            'resources' => 'Resources',
                            'greeting' => 'Greeting',
                            'general' => 'General (legacy)',
                        ] as $category => $label)
                            <option value="{{ $category }}" @selected(old('category', $entry->category ?: 'general_info') === $category)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block space-y-1 text-sm font-semibold text-slate-700">Language
                    <select name="language" required class="w-full rounded-xl border-slate-300 font-normal">
                        @foreach (['en' => 'English', 'sw' => 'Swahili', 'both' => 'Both'] as $language => $label)
                            <option value="{{ $language }}" @selected(old('language', $entry->language ?: 'both') === $language)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            @endunless

            <label class="block space-y-1 text-sm font-semibold text-slate-700">Subcategory
                <input name="subcategory" value="{{ old('subcategory', $entry->subcategory) }}" maxlength="100" required class="w-full rounded-xl border-slate-300 font-normal">
            </label>
            <label class="block space-y-1 text-sm font-semibold text-slate-700">Keywords <span class="font-normal text-slate-500">(separate with commas or new lines)</span>
                <textarea name="keywords" rows="3" required class="w-full rounded-xl border-slate-300 font-normal">{{ old('keywords', $entry->keywords_string) }}</textarea>
            </label>
            <label class="block space-y-1 text-sm font-semibold text-slate-700">Question
                <input name="question" value="{{ old('question', $entry->question) }}" maxlength="1000" required class="w-full rounded-xl border-slate-300 font-normal">
            </label>
            <label class="block space-y-1 text-sm font-semibold text-slate-700">Answer
                <textarea name="answer" rows="7" maxlength="10000" required class="w-full rounded-xl border-slate-300 font-normal">{{ old('answer', $entry->answer) }}</textarea>
            </label>
            <label class="block space-y-1 text-sm font-semibold text-slate-700">Source
                <input name="source" value="{{ old('source', $entry->source) }}" maxlength="255" class="w-full rounded-xl border-slate-300 font-normal">
            </label>

            @if ($errors->any())
                <div role="alert" class="rounded-xl bg-rose-50 p-4 text-sm text-rose-800">
                    <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="rounded-xl bg-sky-800 px-5 py-3 font-semibold text-white hover:bg-sky-900">Save {{ $serviceOnly ? 'service' : 'entry' }}</button>
                <a href="{{ $serviceOnly ? route('admin.knowledge-services.index') : route('admin.knowledge-base.index') }}" class="rounded-xl border border-slate-300 px-5 py-3 font-semibold text-slate-700 hover:bg-slate-50">Cancel</a>
            </div>
        </form>
    </div>
@endsection
