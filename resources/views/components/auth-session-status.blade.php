@props(['status'])

@if ($status)
    <div role="status" {{ $attributes->merge(['class' => 'flash-message rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900']) }}>
        {{ $status }}
    </div>
@endif
