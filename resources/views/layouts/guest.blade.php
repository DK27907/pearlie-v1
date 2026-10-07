@extends('layouts.master')

@section('page')
    <div class="mx-auto flex w-full max-w-7xl flex-1 items-center justify-center px-4 py-12 sm:px-6 lg:px-8">
        <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl sm:p-8">
            <div class="mb-6 text-center">
                <span class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-[#0a2f44] text-2xl text-white" aria-hidden="true">🏥</span>
                <p class="mt-3 text-lg font-bold text-[#0a2f44]">{{ pearlie_config('hospital.name') }}</p>
                <p class="text-sm text-slate-500">{{ pearlie_config('hospital.location') }}</p>
            </div>
            {{ $slot }}
            <div class="mt-6 border-t border-slate-100 pt-4 text-center">
                <a href="{{ route('pearlie.home') }}" class="text-sm font-semibold text-[#1a5276] hover:text-[#0a2f44]">Return to chat</a>
            </div>
        </div>
    </div>
@endsection
