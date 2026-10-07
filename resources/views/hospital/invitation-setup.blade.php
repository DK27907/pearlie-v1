<x-guest-layout>
    <div class="mx-auto max-w-xl px-4 py-12 sm:px-6">
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-semibold uppercase tracking-widest text-indigo-700">{{ $invite->hospital->name }}</p>
            <h1 class="mt-2 text-2xl font-bold text-slate-900">Set up your hospital administrator account</h1>
            <p class="mt-2 text-sm text-slate-600">Invitation for {{ $invite->email }}. Your link is valid for a limited time.</p>
            <form method="POST" action="{{ route('hospital.invitation.store', [$invite->hospital->slug, $invite->token]) }}" class="mt-6 space-y-4">
                @csrf
                <label class="block text-sm font-semibold text-slate-700">Your name<input name="name" value="{{ old('name') }}" required autocomplete="name" class="mt-1 w-full rounded-xl border-slate-300"></label>
                <label class="block text-sm font-semibold text-slate-700">Password<input type="password" name="password" required minlength="12" autocomplete="new-password" class="mt-1 w-full rounded-xl border-slate-300"><span class="mt-1 block text-xs font-normal text-slate-500">Use at least 12 characters.</span></label>
                <label class="block text-sm font-semibold text-slate-700">Confirm password<input type="password" name="password_confirmation" required autocomplete="new-password" class="mt-1 w-full rounded-xl border-slate-300"></label>
                @if ($errors->any())<div role="alert" class="rounded-xl bg-rose-50 p-4 text-sm text-rose-800">{{ $errors->first() }}</div>@endif
                <button class="w-full rounded-xl bg-indigo-700 px-5 py-3 font-semibold text-white hover:bg-indigo-800">Create account</button>
            </form>
        </div>
    </div>
</x-guest-layout>
