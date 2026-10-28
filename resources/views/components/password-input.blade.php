@props([
    'name',
    'id' => null,
    'label' => null,
    'labelClass' => 'block text-sm font-semibold text-slate-700',
    'autocomplete' => 'current-password',
    'wrapperClass' => '',
])

@php
    $inputId = $id ?? \Illuminate\Support\Str::slug($name, '-');
@endphp

<div class="{{ $wrapperClass }}">
    @if ($label)
        <label for="{{ $inputId }}" class="{{ $labelClass }}">{{ $label }}</label>
    @endif
    <div class="relative mt-1" x-data="passwordInput">
        <input
            x-ref="password"
            type="password"
            name="{{ $name }}"
            id="{{ $inputId }}"
            autocomplete="{{ $autocomplete }}"
            {{ $attributes->merge(['class' => 'input-premium pr-11']) }}
        >
        <button
            type="button"
            x-on:click="toggleVisibility"
            x-bind:aria-label="visible ? 'Hide password' : 'Show password'"
            x-bind:aria-pressed="visible"
            aria-label="Show password"
            class="absolute inset-y-0 right-0 flex items-center px-3 text-slate-500 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-sky-700"
        >
            <svg x-show="! visible" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7s-8.268-2.943-9.542-7z"/>
                <circle cx="12" cy="12" r="3" stroke-width="2"/>
            </svg>
            <svg x-show="visible" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3l18 18M10.6 10.6a2 2 0 002.8 2.8m3.4 3.4A10.9 10.9 0 0112 19c-4.5 0-8.3-2.9-9.5-7a10.9 10.9 0 013.2-4.5M9.9 5.2A10.8 10.8 0 0112 5c4.5 0 8.3 2.9 9.5 7a10.8 10.8 0 01-2.2 3.7"/>
            </svg>
        </button>
    </div>
</div>
