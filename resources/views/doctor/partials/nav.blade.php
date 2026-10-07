<nav class="border-b border-slate-800 bg-slate-950">
    <div class="mx-auto flex min-h-16 max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:px-8">
        <a href="{{ route('doctor.dashboard') }}" class="flex items-center gap-3">
            <span class="grid h-10 w-10 place-items-center rounded-xl bg-cyan-400 text-lg font-bold text-slate-950">+</span>
            <span class="text-lg font-bold tracking-tight text-white">{{ pearlie_config('hospital.name') }}</span>
        </a>

        <div class="flex flex-1 items-center justify-end gap-1 overflow-x-auto sm:justify-center">
            <a href="{{ route('doctor.dashboard') }}" @class([
                'whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold transition',
                'bg-slate-800 text-cyan-300' => request()->routeIs('doctor.dashboard'),
                'text-slate-300 hover:bg-slate-900 hover:text-white' => ! request()->routeIs('doctor.dashboard'),
            ])>Dashboard</a>
            <a href="{{ route('doctor.appointments') }}" @class([
                'whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold transition',
                'bg-slate-800 text-cyan-300' => request()->routeIs('doctor.appointments*'),
                'text-slate-300 hover:bg-slate-900 hover:text-white' => ! request()->routeIs('doctor.appointments*'),
            ])>Appointments</a>
            <a href="{{ route('doctor.availability') }}" @class([
                'whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold transition',
                'bg-slate-800 text-cyan-300' => request()->routeIs('doctor.availability*'),
                'text-slate-300 hover:bg-slate-900 hover:text-white' => ! request()->routeIs('doctor.availability*'),
            ])>Availability</a>
            <a href="{{ route('doctor.escalations.index') }}" @class([
                'whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold transition',
                'bg-slate-800 text-cyan-300' => request()->routeIs('doctor.escalations*'),
                'text-slate-300 hover:bg-slate-900 hover:text-white' => ! request()->routeIs('doctor.escalations*'),
            ])>Escalations</a>
            <a href="{{ route('password.edit') }}" class="whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold text-slate-300 transition hover:bg-slate-900 hover:text-white">Change Password</a>
        </div>

        <div class="hidden items-center gap-3 sm:flex">
            <div class="text-right">
                <p class="text-sm font-semibold text-white">{{ auth()->user()->name }}</p>
                <p class="text-xs text-slate-400">{{ auth()->user()->specialization ?: 'Doctor' }}</p>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="rounded-lg border border-slate-700 px-3 py-2 text-xs font-semibold text-slate-300 transition hover:border-cyan-400 hover:text-white">Sign out</button>
            </form>
        </div>
    </div>
</nav>
