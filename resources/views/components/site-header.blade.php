<header class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 shadow-sm backdrop-blur">
    @php
        $tenantHome = request()->routeIs('tenant.home') && hospital();
        $homeUrl = $tenantHome ? route('tenant.home', hospital()->slug) : url('/');
        $assistantUrl = $tenantHome ? route('tenant.home', hospital()->slug) : url('/pearlie');
    @endphp
    <nav aria-label="Main navigation" class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex min-h-20 items-center justify-between gap-4">
            <a href="{{ $homeUrl }}" class="flex shrink-0 items-center gap-3" aria-label="{{ pearlie_config('hospital.name') }} home">
                @if (hospital()?->logo_url)
                    <img class="h-11 w-11 rounded-xl object-contain" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url(hospital()->logo_url) }}" alt="">
                @else
                    <span class="grid h-11 w-11 place-items-center rounded-xl bg-[var(--hospital-primary)] text-2xl" aria-hidden="true">🏥</span>
                @endif
                <span class="min-w-0">
                    <span class="block text-lg font-bold leading-tight text-[var(--hospital-primary)]">{{ pearlie_config('hospital.name') }}</span>
                    <span class="block max-w-[15rem] truncate text-xs font-medium text-slate-500">{{ pearlie_config('hospital.location') }}</span>
                </span>
            </a>

            <div class="hidden items-center gap-1 md:flex">
                <a href="{{ $homeUrl }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 hover:text-[var(--hospital-primary)]">Home</a>
                <a href="{{ $assistantUrl }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 hover:text-[var(--hospital-primary)]">Chat with Pearlie</a>
                <a href="{{ $assistantUrl }}" class="rounded-lg bg-[var(--hospital-secondary)] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[var(--hospital-primary)]">Book Appointment</a>
            </div>

            <div class="hidden items-center gap-3 md:flex">
                @auth
                    @if (auth()->user()->isSuperAdmin())
                        <a href="{{ route('superadmin.dashboard') }}" class="text-sm font-semibold text-[#1a5276] hover:text-[#0a2f44]">Platform Admin</a>
                    @elseif (auth()->user()->isAdmin())
                        <a href="{{ url('/admin/dashboard') }}" class="text-sm font-semibold text-[#1a5276] hover:text-[#0a2f44]">Admin Dashboard</a>
                    @endif
                    @if (auth()->user()->isDoctor())
                        <a href="{{ url('/doctor/dashboard') }}" class="text-sm font-semibold text-[#1a5276] hover:text-[#0a2f44]">My Dashboard</a>
                    @endif
                    <details class="group relative">
                        <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                            <span>{{ auth()->user()->name }}</span>
                            <svg class="h-4 w-4 transition group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.22 7.22a.75.75 0 0 1 1.06 0L10 10.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/></svg>
                        </summary>
                        <div class="absolute right-0 top-full z-50 mt-2 w-48 rounded-xl border border-slate-200 bg-white p-1 shadow-xl">
                            <a href="{{ route('settings.profile') }}" class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">Settings</a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50">Logout</button>
                            </form>
                        </details>
                    </div>
                @else
                    <a href="{{ url('/login') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:text-[#0a2f44]">Login</a>
                    <a href="{{ url('/register') }}" class="rounded-lg border border-[#1a5276] px-3 py-2 text-sm font-semibold text-[#1a5276] transition hover:bg-[#1a5276] hover:text-white">Register</a>
                @endauth
            </div>

            <button type="button" data-site-menu-toggle aria-controls="site-mobile-menu" aria-expanded="false" aria-label="Toggle navigation menu" class="inline-flex items-center justify-center rounded-lg p-2 text-[#0a2f44] hover:bg-slate-100 md:hidden">
                <svg data-menu-open-icon class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                <svg data-menu-close-icon hidden class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 6 12 12M18 6 6 18"/></svg>
            </button>
        </div>

        <div id="site-mobile-menu" hidden class="space-y-1 border-t border-slate-100 py-3 md:hidden">
            <a href="{{ $homeUrl }}" class="block rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Home</a>
            <a href="{{ $assistantUrl }}" class="block rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Chat with Pearlie</a>
            <a href="{{ $assistantUrl }}" class="block rounded-lg px-3 py-2 text-sm font-semibold text-[var(--hospital-secondary)] hover:bg-slate-50">Book Appointment</a>
            @auth
                <p class="px-3 py-2 text-sm font-semibold text-[#0a2f44]">{{ auth()->user()->name }}</p>
                @if (auth()->user()->isSuperAdmin())
                    <a href="{{ route('superadmin.dashboard') }}" class="block rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Platform Admin</a>
                @elseif (auth()->user()->isAdmin())
                    <a href="{{ url('/admin/dashboard') }}" class="block rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Admin Dashboard</a>
                @endif
                @if (auth()->user()->isDoctor())
                    <a href="{{ url('/doctor/dashboard') }}" class="block rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">My Dashboard</a>
                @endif
                <a href="{{ route('settings.profile') }}" class="block rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Settings</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-slate-50">Logout</button>
                </form>
            @else
                <a href="{{ url('/login') }}" class="block rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Login</a>
                <a href="{{ url('/register') }}" class="block rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Register</a>
            @endauth
        </div>
    </nav>
</header>
