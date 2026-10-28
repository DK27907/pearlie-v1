@props(['messages'])

@if ($messages)
    <ul {{ $attributes->merge(['class' => 'auth-error space-y-1 rounded-r-lg border-l-4 border-rose-500 bg-rose-50 px-3 py-2 text-sm text-rose-800']) }}>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
