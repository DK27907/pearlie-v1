@extends('layouts.tenant')

@section('title', $hospital->name.' — Healthcare made simpler')

@php
    $chatRoute = route('tenant.chat.page', ['slug' => $hospital->slug]);
    $homeRoute = route('tenant.home', ['slug' => $hospital->slug]);
    $primary = $hospital->primary_color ?: '#0a2f44';
    $secondary = $hospital->secondary_color ?: '#1a5276';
    $branding = hospital_branding();
@endphp

@section('content')
    <header class="sticky top-0 z-50 border-b border-slate-200 bg-white/95 backdrop-blur">
        <nav class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
            <a href="{{ $homeRoute }}" class="flex items-center gap-3">
                @if ($branding['logo_url'])
                    <img class="h-11 w-11 rounded-xl object-contain" src="{{ $branding['logo_url'] }}" alt="">
                @else
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl text-lg font-bold text-white shadow-sm" style="background: {{ $primary }};">
                        {{ strtoupper(substr($hospital->name, 0, 1)) }}
                    </div>
                @endif
                <div>
                    <div class="text-base font-extrabold tracking-tight" style="color: {{ $primary }};">{{ $branding['header_text'] }}</div>
                    <div class="text-[10px] uppercase tracking-[0.16em] text-slate-500">Digital Front Desk</div>
                </div>
            </a>

            <details class="relative ml-auto lg:hidden">
                <summary class="cursor-pointer list-none rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700">
                    Menu
                </summary>
                <div class="absolute right-0 z-50 mt-2 grid min-w-48 gap-1 rounded-xl border border-slate-200 bg-white p-2 shadow-lg">
                    <a href="{{ $homeRoute }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Home</a>
                    <a href="#services" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Services</a>
                    <a href="#doctors" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Doctors</a>
                    <a href="#how-it-works" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Appointments</a>
                    <a href="#about" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">About</a>
                    <a href="#contact" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Contact</a>
                </div>
            </details>

            <div class="hidden items-center gap-5 lg:flex">
                <a href="{{ $homeRoute }}" class="text-sm font-semibold text-slate-600 hover:text-slate-900">Home</a>
                <a href="#services" class="text-sm font-semibold text-slate-600 hover:text-slate-900">Services</a>
                <a href="#doctors" class="text-sm font-semibold text-slate-600 hover:text-slate-900">Doctors</a>
                <a href="#how-it-works" class="text-sm font-semibold text-slate-600 hover:text-slate-900">Appointments</a>
                <a href="#hours" class="text-sm font-semibold text-slate-600 hover:text-slate-900">Hours</a>
                <a href="#about" class="text-sm font-semibold text-slate-600 hover:text-slate-900">About</a>
                <a href="#contact" class="text-sm font-semibold text-slate-600 hover:text-slate-900">Contact</a>
            </div>

            <a href="{{ $chatRoute }}" class="inline-flex items-center justify-center rounded-xl px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:opacity-95" style="background: {{ $secondary }};">
                Talk to MediDesk
            </a>
        </nav>
    </header>

    <main>
        <section class="bg-gradient-to-br from-white to-slate-100">
            <div class="mx-auto grid max-w-7xl items-center gap-10 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:px-8 lg:py-24">
                <div>
                    <p class="inline-flex rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-sky-700">Healthcare made simple</p>
                    <h1 class="mt-6 text-4xl font-extrabold tracking-tight text-slate-900 sm:text-5xl">
                        Healthcare information and appointments, made simpler.
                    </h1>
                    <p class="mt-5 max-w-xl text-lg leading-8 text-slate-600">
                        Get answers, discover healthcare services and book appointments through our intelligent digital front desk.
                    </p>
                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <a href="{{ $chatRoute }}" class="inline-flex items-center justify-center rounded-xl px-6 py-3 text-base font-bold text-white" style="background: {{ $secondary }};">Talk to MediDesk</a>
                        <a href="{{ $chatRoute }}" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-6 py-3 text-base font-bold text-slate-800">Book Appointment</a>
                    </div>
                </div>

                <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="rounded-2xl border border-slate-200 p-5">
                        <div class="flex items-center justify-between border-b border-slate-200 pb-4">
                            <div>
                                <p class="text-sm font-semibold text-slate-500">Today at {{ $hospital?->name ?? 'MediDesk AI' }}</p>
                                <p class="mt-1 text-2xl font-extrabold text-slate-900">24/7 Support</p>
                            </div>
                            <span class="rounded-full px-3 py-1 text-xs font-bold text-white" style="background: {{ $primary }};">Online</span>
                        </div>
                        <div class="mt-5 space-y-3 text-sm text-slate-600">
                            @if ($hospital->address)
                                <div class="rounded-xl bg-slate-50 p-3">📍 {{ $hospital->address }}</div>
                            @endif
                            @if ($hospital->phone)
                                <div class="rounded-xl bg-slate-50 p-3">📞 {{ $hospital->phone }}</div>
                            @endif
                            @if ($hospital->hours_outpatient)
                                <div class="rounded-xl bg-slate-50 p-3">🕐 {{ $hospital->hours_outpatient }}</div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="services" class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
            <div class="mb-8 flex items-end justify-between gap-4">
                <div>
                    <p class="text-sm font-bold uppercase tracking-[0.2em] text-sky-700">Services</p>
                    <h2 class="mt-2 text-3xl font-extrabold text-slate-900">Care designed around your needs</h2>
                </div>
            </div>
            @if ($catalogServices->isNotEmpty())
                <form action="{{ $chatRoute }}" method="GET" class="mb-8 grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-[1fr_auto] sm:items-end">
                    <div>
                        <label for="homeBookingService" class="block text-sm font-semibold text-slate-700">Select a service to book (optional)</label>
                        <select id="homeBookingService" name="service_id" data-service-select class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-cyan-600 focus:ring-cyan-600">
                            <option value="" disabled selected>Select a service</option>
                            @foreach ($catalogServices as $catalogService)
                                <option value="{{ $catalogService->id }}" data-price="{{ number_format((float) $catalogService->price, 2, '.', '') }}" data-duration="{{ $catalogService->duration_minutes }}">
                                    {{ $catalogService->name }} — KSh {{ number_format((float) $catalogService->price, 2) }} ({{ $catalogService->duration_minutes }} min)
                                </option>
                            @endforeach
                        </select>
                        <output data-service-price aria-live="polite" class="mt-2 block text-sm font-semibold text-slate-700">Select a service to see its price.</output>
                    </div>
                    <button type="submit" class="inline-flex items-center justify-center rounded-xl px-5 py-3 text-sm font-bold text-white" style="background: {{ $secondary }};">Continue to booking</button>
                </form>
            @endif
            <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                @forelse($catalogServices as $service)
                    <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl text-xl text-white" style="background: {{ $primary }};">
                            {{ strtoupper(substr($service->category ?? 'S', 0, 1)) }}
                        </div>
                        <h3 class="text-xl font-bold text-slate-900">{{ $service->name }}</h3>
                        <p class="mt-3 text-sm leading-6 text-slate-600">{{ $service->description }}</p>
                        <p class="mt-3 text-sm font-semibold text-slate-700">KSh {{ number_format((float) $service->price, 2) }} · {{ $service->duration_minutes }} min</p>
                        <a href="{{ $chatRoute }}?service_id={{ $service->id }}" class="mt-5 inline-flex rounded-xl px-4 py-2 text-sm font-bold text-white" style="background: {{ $secondary }};">Book this service</a>
                    </article>
                @empty
                    <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-slate-600 md:col-span-2 xl:col-span-3">No services have been published yet for this hospital.</div>
                @endforelse
            </div>
        </section>

        <section id="doctors" class="bg-slate-900 py-16 text-white">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mb-8">
                    <p class="text-sm font-bold uppercase tracking-[0.2em] text-sky-300">Doctors</p>
                    <h2 class="mt-2 text-3xl font-extrabold">Meet our care team</h2>
                </div>
                <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-4">
                    @forelse($doctors as $doctor)
                        <article class="rounded-2xl border border-slate-700 bg-slate-800 p-5">
                            <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-sky-500 text-lg font-bold text-white">
                                {{ strtoupper(substr($doctor->name ?? 'D', 0, 1)) }}
                            </div>
                            <h3 class="text-lg font-bold">{{ $doctor->name }}</h3>
                            <p class="mt-1 text-sm text-slate-300">{{ $doctor->specialization ?? 'General Care' }}</p>
                            @if (filled($doctor->bio))
                                <p class="mt-3 text-sm leading-6 text-slate-300">{{ Str::limit($doctor->bio, 180) }}</p>
                            @endif
                            @if ($doctor->consultation_fee !== null)
                                <p class="mt-3 text-sm text-slate-200"><strong>Consultation fee:</strong> KSh {{ number_format((float) $doctor->consultation_fee, 2) }}</p>
                            @endif
                            @if (filled($doctor->licence_number))
                                <p class="mt-2 text-sm text-slate-300"><strong>Licence:</strong> {{ $doctor->licence_number }}</p>
                            @endif
                            <a href="{{ $chatRoute }}?doctor={{ $doctor->id }}" class="mt-5 inline-flex rounded-xl bg-sky-500 px-4 py-2 text-sm font-bold text-white">Book Appointment</a>
                        </article>
                    @empty
                        <div class="rounded-2xl border border-dashed border-slate-600 bg-slate-800 p-8 text-slate-300 md:col-span-2 xl:col-span-4">Our doctors will appear here once they are added to the hospital.</div>
                    @endforelse
                </div>
            </div>
        </section>

        <section id="hours" class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
            <div class="rounded-3xl border border-slate-200 bg-white p-8 shadow-sm">
                <p class="text-sm font-bold uppercase tracking-[0.2em] text-sky-700">Hours</p>
                <h2 class="mt-2 text-3xl font-extrabold text-slate-900">When to visit or call</h2>
                <div class="mt-6 grid gap-4 sm:grid-cols-2">
                    @if ($hospital->hours_emergency)
                        <p class="rounded-xl bg-rose-50 p-4 text-slate-700"><strong>Emergency:</strong> {{ $hospital->hours_emergency }}</p>
                    @endif
                    @if ($hospital->emergency_phone)
                        <p class="rounded-xl bg-rose-50 p-4 text-slate-700"><strong>Emergency phone:</strong> <a href="tel:{{ $hospital->emergency_phone }}">{{ $hospital->emergency_phone }}</a></p>
                    @endif
                    @if ($hospital->hours_outpatient)
                        <p class="rounded-xl bg-slate-50 p-4 text-slate-700"><strong>Out-patient:</strong> {{ $hospital->hours_outpatient }}</p>
                    @endif
                    @if (! $hospital->hours_emergency && ! $hospital->hours_outpatient)
                        <p class="text-slate-600">Please contact {{ $hospital->name }} to confirm visiting hours.</p>
                    @endif
                </div>
            </div>
        </section>

        <section id="how-it-works" class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
            <h2 class="text-center text-3xl font-extrabold text-slate-900">How it works</h2>
            <div class="mt-10 grid gap-6 md:grid-cols-4">
                @foreach(['Ask', 'Choose', 'Book', 'Confirm'] as $step)
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 text-center shadow-sm">
                        <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full font-bold text-white" style="background: {{ $primary }};">{{ $loop->iteration }}</div>
                        <h3 class="text-lg font-bold text-slate-900">{{ $step }}</h3>
                    </div>
                @endforeach
            </div>
        </section>

        <section id="about" class="bg-white py-16">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <p class="text-sm font-bold uppercase tracking-[0.2em] text-sky-700">About</p>
                <h2 class="mt-2 text-3xl font-extrabold text-slate-900">A patient-first healthcare experience</h2>
                <p class="mt-6 text-lg leading-8 text-slate-600">
                    {{ $about ?: 'The hospital will share more information about its care and facilities here.' }}
                </p>
            </div>
        </section>

        <section id="contact" class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
            <div class="grid gap-6 lg:grid-cols-2">
                <div class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
                    <p class="text-sm font-bold uppercase tracking-[0.2em] text-sky-700">Contact</p>
                    <h2 class="mt-2 text-3xl font-extrabold text-slate-900">We’re here to help</h2>
                    <div class="mt-6 space-y-4 text-slate-600">
                        @if ($hospital->address)
                            <p><strong>Address:</strong> {{ $hospital->address }}</p>
                        @endif
                        @if ($hospital->phone)
                            <p><strong>Phone:</strong> <a href="tel:{{ $hospital->phone }}">{{ $hospital->phone }}</a></p>
                        @endif
                        @if ($hospital->email)
                            <p><strong>Email:</strong> <a href="mailto:{{ $hospital->email }}">{{ $hospital->email }}</a></p>
                        @endif
                        @if ($hospital->hours_outpatient)
                            <p><strong>Hours:</strong> {{ $hospital->hours_outpatient }}</p>
                        @endif
                    </div>
                    @if ($whatsappNumber !== '')
                        <a href="https://wa.me/{{ $whatsappNumber }}" class="mt-6 inline-flex rounded-xl px-5 py-3 font-bold text-white" style="background: {{ $secondary }};">Open in WhatsApp</a>
                    @endif
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-900 p-8 text-white shadow-sm">
                    <p class="text-sm font-bold uppercase tracking-[0.2em] text-sky-300">Why MediDesk</p>
                    <div class="mt-6 grid gap-4 sm:grid-cols-2">
                        @foreach(['24/7','English & Swahili','Booking','WhatsApp','M-Pesa','Human escalation'] as $feature)
                            <div class="rounded-xl border border-slate-700 bg-slate-800 p-4 text-sm font-semibold">{{ $feature }}</div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-6 text-sm text-slate-600 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-8">
            <div>
                <p class="font-bold text-slate-900">{{ $branding['footer_text'] }}</p>
                <p class="text-xs">Powered by MediDesk AI · By AxiomForge Digital Solutions</p>
                <p>{{ $hospital->name }}@if ($hospital->phone) · {{ $hospital->phone }}@endif</p>
                @if ($hospital->address)
                    <p>{{ $hospital->address }}</p>
                @endif
                @if ($hospital->email)
                    <p><a class="hover:text-slate-900" href="mailto:{{ $hospital->email }}">{{ $hospital->email }}</a></p>
                @endif
                @if ($whatsappNumber !== '')
                    <p><a class="hover:text-slate-900" href="https://wa.me/{{ $whatsappNumber }}">WhatsApp</a></p>
                @endif
            </div>
            <div class="flex gap-4">
                <a href="#" class="hover:text-slate-900">Privacy</a>
                <a href="#" class="hover:text-slate-900">Terms</a>
                <a href="{{ $chatRoute }}" class="hover:text-slate-900">Chat</a>
                <a href="{{ $chatRoute }}" class="hover:text-slate-900">Book</a>
            </div>
        </div>
    </footer>
@endsection

@push('scripts')
    @vite('resources/js/pearlie.js')
@endpush
