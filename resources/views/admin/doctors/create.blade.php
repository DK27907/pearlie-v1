@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div>
        <a href="{{ route('admin.doctors.index') }}" class="text-sm font-semibold text-cyan-800 hover:underline">← Doctors</a>
        <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">Add a doctor</h1>
        <p class="mt-1 text-sm text-slate-600">The doctor will receive a one-time account setup link by email.</p>
    </div>

    <form method="POST" action="{{ route('admin.doctors.store') }}" class="space-y-5 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        @csrf
        @include('admin.doctors.partials.form', ['submitLabel' => 'Create doctor and send invite'])
    </form>
</div>
@endsection
