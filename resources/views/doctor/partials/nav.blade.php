@php($doctorBranding = hospital_branding())
<nav class="border-b border-slate-200 bg-white/95 shadow-sm backdrop-blur">
    <div class="mx-auto flex min-h-16 max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:px-8">
        <a href="{{ route('doctor.dashboard') }}" class="flex items-center gap-3">
            @if ($doctorBranding['logo_url'])
                <img class="h-10 w-10 rounded-xl border border-slate-100 object-contain" src="{{ $doctorBranding['logo_url'] }}" alt="">
            @else
                <span class="grid h-10 w-10 place-items-center rounded-xl bg-[var(--hospital-primary)] text-lg font-bold text-white" aria-hidden="true">+</span>
            @endif
            <span class="text-lg font-bold tracking-tight text-slate-900">{{ $doctorBranding['header_text'] }}</span>
        </a>

        <div class="flex flex-1 items-center justify-end gap-1 overflow-x-auto sm:justify-center">
            <a href="{{ route('doctor.dashboard') }}" @class([
                'whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold transition',
                'bg-slate-100 text-[var(--hospital-primary)]' => request()->routeIs('doctor.dashboard'),
                'text-slate-600 hover:bg-slate-100 hover:text-slate-950' => ! request()->routeIs('doctor.dashboard'),
            ])>Dashboard</a>
            <a href="{{ route('doctor.appointments') }}" @class([
                'whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold transition',
                'bg-slate-100 text-[var(--hospital-primary)]' => request()->routeIs('doctor.appointments*'),
                'text-slate-600 hover:bg-slate-100 hover:text-slate-950' => ! request()->routeIs('doctor.appointments*'),
            ])>Appointments</a>
            <a href="{{ route('doctor.patients.index') }}" @class([
                'whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold transition',
                'bg-slate-100 text-[var(--hospital-primary)]' => request()->routeIs('doctor.patients*'),
                'text-slate-600 hover:bg-slate-100 hover:text-slate-950' => ! request()->routeIs('doctor.patients*'),
            ])>My Patients</a>
            <a href="{{ route('doctor.availability') }}" @class([
                'whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold transition',
                'bg-slate-100 text-[var(--hospital-primary)]' => request()->routeIs('doctor.availability*'),
                'text-slate-600 hover:bg-slate-100 hover:text-slate-950' => ! request()->routeIs('doctor.availability*'),
            ])>Availability</a>
            <a href="{{ route('doctor.escalations.index') }}" @class([
                'whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold transition',
                'bg-slate-100 text-[var(--hospital-primary)]' => request()->routeIs('doctor.escalations*'),
                'text-slate-600 hover:bg-slate-100 hover:text-slate-950' => ! request()->routeIs('doctor.escalations*'),
            ])>Escalations</a>
            <a href="{{ route('password.edit') }}" class="whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-950">Change Password</a>
        </div>

        <div class="hidden items-center gap-3 sm:flex">
            <div class="text-right">
                <p class="text-sm font-semibold text-slate-900">{{ auth()->user()->name }}</p>
                <p class="text-xs text-slate-500">{{ auth()->user()->specialization ?: 'Doctor' }}</p>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-950">Sign out</button>
            </form>
        </div>
        <form method="POST" action="{{ route('logout') }}" class="sm:hidden">
            @csrf
            <button type="submit" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50">Sign out</button>
        </form>
    </div>
</nav>
