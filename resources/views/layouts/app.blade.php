@extends('layouts.master')

@section('page')
    @hasSection('header')
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] px-4 py-6 sm:px-6 lg:px-8">
                @yield('header')
            </div>
        </header>
    @endif
    @yield('content')
    @if (($slot ?? null) instanceof \Illuminate\View\ComponentSlot)
        {{ $slot }}
    @endif
@endsection
