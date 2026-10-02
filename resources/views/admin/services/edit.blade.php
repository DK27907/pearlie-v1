@extends('layouts.app')

@section('title', 'Edit service | '.pearlie_config('hospital.name'))

@section('content')
    <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <header>
            <a href="{{ route('admin.services.index') }}" class="text-sm font-semibold text-sky-800 hover:underline">← Back to service catalog</a>
            <h1 class="mt-3 text-3xl font-bold tracking-tight text-slate-900">Edit service</h1>
        </header>

        @include('admin.services.partials.form', [
            'service' => $service,
            'action' => route('admin.services.update', $service),
            'method' => 'PUT',
        ])
    </div>
@endsection
