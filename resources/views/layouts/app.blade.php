@extends('layouts.master')

@section('page')
    @hasSection('header')
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                @yield('header')
            </div>
        </header>
    @endif
    @yield('content')
    {{ $slot ?? '' }}
@endsection
