@extends('layouts.marketing')

@section('title', 'MediDesk AI — Your Hospital\'s 24/7 Digital Front Desk')
@section('meta_description', 'MediDesk AI is the 24/7 digital front desk for hospitals. Answer patient questions, manage appointments, collect M-Pesa deposits, and connect patients with staff.')

@section('content')
    <header class="sticky top-0 z-50 border-b border-slate-200/80 bg-white/95 shadow-sm backdrop-blur">
        <nav aria-label="Main navigation" class="mx-auto flex min-h-18 max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] items-center justify-between gap-5 px-4 py-3 sm:px-6 lg:px-8">
            <a href="#home" class="flex shrink-0 items-center gap-3">
                <span class="grid h-11 w-11 place-items-center rounded-xl bg-[#0a2f44] text-white" aria-hidden="true">
                    <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18m-9-9h18M5.6 5.6l12.8 12.8m0-12.8L5.6 18.4"/></svg>
                </span>
                <span>
                    <span class="block text-lg font-extrabold leading-tight tracking-tight text-[#0a2f44]">MediDesk AI</span>
                    <span class="block text-[11px] font-medium text-slate-500">by AxiomForge</span>
                </span>
            </a>
            <div class="hidden items-center gap-7 md:flex">
                <a href="#features" class="text-sm font-semibold text-slate-600 transition hover:text-[#0a2f44]">Features</a>
                <a href="#how-it-works" class="text-sm font-semibold text-slate-600 transition hover:text-[#0a2f44]">How It Works</a>
                <a href="#pricing" class="text-sm font-semibold text-slate-600 transition hover:text-[#0a2f44]">Pricing</a>
                <a href="#demo" class="text-sm font-semibold text-slate-600 transition hover:text-[#0a2f44]">Demo</a>
                <a href="#faq" class="text-sm font-semibold text-slate-600 transition hover:text-[#0a2f44]">FAQ</a>
            </div>
            <a href="#demo" class="hidden rounded-xl bg-[#1a5276] px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-[#0a2f44] sm:inline-flex">Book a Demo</a>
            <details class="group relative sm:hidden">
                <summary class="grid h-10 w-10 cursor-pointer list-none place-items-center rounded-lg border border-slate-200 text-[#0a2f44]" aria-label="Open menu">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>
                </summary>
                <div class="absolute right-0 top-12 grid min-w-52 gap-1 rounded-xl border border-slate-200 bg-white p-2 shadow-xl">
                    <a class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" href="#features">Features</a>
                    <a class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" href="#how-it-works">How It Works</a>
                    <a class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" href="#pricing">Pricing</a>
                    <a class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" href="#demo">Demo</a>
                    <a class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" href="#faq">FAQ</a>
                    <a class="rounded-lg bg-[#1a5276] px-3 py-2 text-sm font-bold text-white" href="#demo">Book a Demo</a>
                </div>
            </details>
        </nav>
    </header>

    <main id="home">
        <section class="relative isolate overflow-hidden bg-gradient-to-br from-[#f4f9fc] via-white to-[#e9f4f8]">
            <div class="absolute -right-32 -top-28 -z-10 h-96 w-96 rounded-full bg-cyan-100/60 blur-3xl" aria-hidden="true"></div>
            <div class="relative z-10 mx-auto grid max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] items-center gap-8 px-4 py-10 sm:px-6 sm:py-14 lg:grid-cols-[1.04fr_.96fr] lg:gap-12 lg:px-8 lg:py-20">
                <figure class="relative order-first mx-auto w-full max-w-2xl lg:order-last">
                    <div class="absolute -inset-3 rounded-[2.25rem] bg-gradient-to-br from-cyan-200 via-sky-100 to-emerald-100 blur-sm" aria-hidden="true"></div>
                    <div class="relative overflow-hidden rounded-[1.75rem] border border-white bg-white shadow-2xl shadow-[#0a2f44]/20 sm:rounded-[2rem]">
                        <img
                            src="https://media.gettyimages.com/id/2000034600/photo/virtual-assistant-on-internet.jpg?s=612x612&amp;w=0&amp;k=20&amp;c=iN9JyW0ssGkPTU13WQbu8kPj9szPggekTlJiq-e9XDY="
                            alt="Virtual assistant helping a person through an online chat"
                            class="aspect-[4/3] w-full object-cover sm:aspect-square"
                            width="612"
                            height="612"
                            fetchpriority="high"
                        >
                        <div class="absolute left-3 top-3 inline-flex items-center gap-2 rounded-full border border-white/70 bg-white/95 px-3 py-2 text-xs font-bold text-[#0a2f44] shadow-lg sm:left-5 sm:top-5 sm:text-sm">
                            <span class="relative flex h-2.5 w-2.5"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500"></span></span>
                            Always here for patients
                        </div>
                        <div class="absolute inset-x-3 bottom-3 flex items-end justify-between gap-3 sm:inset-x-5 sm:bottom-5">
                            <div class="max-w-[75%] rounded-2xl rounded-bl-md border border-white/70 bg-white/95 px-3 py-2.5 shadow-xl backdrop-blur-sm sm:px-4 sm:py-3">
                                <p class="text-[10px] font-extrabold uppercase tracking-wider text-emerald-700 sm:text-xs">MediDesk AI</p>
                                <p class="mt-1 text-xs font-semibold leading-5 text-slate-800 sm:text-sm">How can I help you today?</p>
                            </div>
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-[#1a5276] text-xl text-white shadow-lg sm:h-12 sm:w-12" aria-hidden="true">✦</span>
                        </div>
                    </div>
                    <figcaption class="mt-3 flex items-center justify-between gap-4 px-1 text-xs text-slate-500">
                        <span class="font-semibold">A more connected patient experience</span>
                        <a
                            href="https://media.gettyimages.com/id/2000034600/photo/virtual-assistant-on-internet.jpg?s=612x612&amp;w=0&amp;k=20&amp;c=iN9JyW0ssGkPTU13WQbu8kPj9szPggekTlJiq-e9XDY="
                            target="_blank"
                            rel="noopener noreferrer"
                            class="shrink-0 underline decoration-slate-300 underline-offset-4 hover:text-[#1a5276]"
                        >Image: Getty Images</a>
                    </figcaption>
                </figure>

                <div class="max-w-2xl">
                    <p class="inline-flex items-center gap-2 rounded-full border border-cyan-200 bg-white/90 px-3 py-1.5 text-xs font-bold uppercase tracking-wider text-[#1a5276] shadow-sm">
                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span> Built for Kenyan healthcare
                    </p>
                    <h1 class="mt-5 text-4xl font-extrabold leading-[1.08] tracking-tight text-[#0a2f44] sm:text-5xl lg:text-6xl">Your Hospital's 24/7 <span class="text-[#1a5276]">Digital Front Desk</span></h1>
                    <p class="mt-5 max-w-xl text-lg leading-8 text-slate-600">MediDesk AI answers patient questions, books appointments, takes M-Pesa deposits, and connects patients to your staff — through WhatsApp and web chat, in English and Kiswahili.</p>
                    <div class="mt-7 flex flex-col gap-3 sm:flex-row">
                        <a href="#demo" class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#1a5276] px-6 py-3.5 text-base font-bold text-white shadow-lg shadow-[#1a5276]/20 transition hover:-translate-y-0.5 hover:bg-[#0a2f44]">Book a Free Demo <span aria-hidden="true">→</span></a>
                        <a href="#how-it-works" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white/90 px-6 py-3.5 text-base font-bold text-[#0a2f44] transition hover:border-[#1a5276] hover:bg-white">See How It Works</a>
                    </div>
                    <p class="mt-4 text-sm text-slate-500">No pressure. See how the digital front desk fits your hospital.</p>
                    <div class="mt-7 flex flex-wrap gap-2 text-xs font-semibold text-slate-700 sm:gap-3 sm:text-sm">
                        <span class="rounded-full border border-white bg-white/80 px-3 py-2 shadow-sm">24/7 answers</span>
                        <span class="rounded-full border border-white bg-white/80 px-3 py-2 shadow-sm">WhatsApp + web</span>
                        <span class="rounded-full border border-white bg-white/80 px-3 py-2 shadow-sm">English + Kiswahili</span>
                    </div>
                </div>
            </div>
        </section>

        <section aria-label="Platform highlights" class="border-y border-slate-200 bg-white">
            <div class="mx-auto flex max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] flex-wrap items-center justify-center gap-x-10 gap-y-4 px-4 py-6 text-sm font-semibold text-slate-600 sm:px-6 lg:justify-between lg:px-8">
                <span class="inline-flex items-center gap-2"><span class="text-lg" aria-hidden="true">🇰🇪</span> Built for Kenyan hospitals</span>
                <span class="inline-flex items-center gap-2"><span class="text-lg" aria-hidden="true">📲</span> Works with M-Pesa</span>
                <span class="inline-flex items-center gap-2"><span class="text-lg" aria-hidden="true">💬</span> English &amp; Kiswahili</span>
                <span class="inline-flex items-center gap-2"><span class="text-lg" aria-hidden="true">✓</span> WhatsApp Business</span>
            </div>
        </section>

        <section class="mx-auto max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-sm font-extrabold uppercase tracking-[.18em] text-[#1a5276]">The challenge</p>
                <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-[#0a2f44] sm:text-4xl">The front desk can't keep up</h2>
                <p class="mt-4 text-lg leading-7 text-slate-600">Your team deserves tools that keep patient communication moving, even when the phone is busy or the doors are closed.</p>
            </div>
            <div class="mt-10 grid gap-5 md:grid-cols-3">
                <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><span class="grid h-12 w-12 place-items-center rounded-xl bg-rose-50 text-2xl" aria-hidden="true">📞</span><h3 class="mt-5 text-lg font-bold text-[#0a2f44]">Missed calls</h3><p class="mt-2 leading-7 text-slate-600">Patients call while staff are helping someone else. Their questions and appointment requests can get lost.</p></article>
                <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><span class="grid h-12 w-12 place-items-center rounded-xl bg-amber-50 text-2xl" aria-hidden="true">🌙</span><h3 class="mt-5 text-lg font-bold text-[#0a2f44]">After-hours gaps</h3><p class="mt-2 leading-7 text-slate-600">Patients still need reliable service information and clear next steps when the front desk is closed.</p></article>
                <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><span class="grid h-12 w-12 place-items-center rounded-xl bg-sky-50 text-2xl" aria-hidden="true">📅</span><h3 class="mt-5 text-lg font-bold text-[#0a2f44]">No-shows</h3><p class="mt-2 leading-7 text-slate-600">Unconfirmed appointments leave gaps in the schedule and make it harder to plan care.</p></article>
            </div>
        </section>

        <section id="features" class="scroll-mt-24 bg-[#f4f8fb] py-16 sm:py-20">
            <div class="mx-auto max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] px-4 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center"><p class="text-sm font-extrabold uppercase tracking-[.18em] text-[#1a5276]">One connected front desk</p><h2 class="mt-3 text-3xl font-extrabold tracking-tight text-[#0a2f44] sm:text-4xl">Everything patients need to take the next step</h2></div>
                <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ([
                        ['🤖', '24/7 AI Assistant', 'Answer common hospital and service questions in natural language, day or night.'],
                        ['💬', 'WhatsApp + Web', 'Meet patients on the channels they already use, with English and Kiswahili support.'],
                        ['📅', 'Real Booking', 'Show available doctor slots and capture appointment requests in one guided conversation.'],
                        ['📲', 'M-Pesa Deposits', 'Send secure STK prompts and track payment outcomes alongside the appointment.'],
                        ['🩺', 'Doctor Dashboard', 'Give clinicians a clear view of their schedule, appointment details, and availability.'],
                        ['🙋', 'Human Escalation', 'Bring a health worker into the conversation when a patient needs human support.'],
                    ] as [$icon, $title, $description])
                        <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:shadow-md"><span class="grid h-12 w-12 place-items-center rounded-xl bg-[#eaf3f8] text-2xl" aria-hidden="true">{{ $icon }}</span><h3 class="mt-5 text-lg font-bold text-[#0a2f44]">{{ $title }}</h3><p class="mt-2 leading-7 text-slate-600">{{ $description }}</p></article>
                    @endforeach
                </div>
            </div>
        </section>

        <section id="how-it-works" class="scroll-mt-24 mx-auto max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
            <div class="mx-auto max-w-2xl text-center"><p class="text-sm font-extrabold uppercase tracking-[.18em] text-[#1a5276]">Simple for patients, useful for staff</p><h2 class="mt-3 text-3xl font-extrabold tracking-tight text-[#0a2f44] sm:text-4xl">How it works</h2></div>
            <ol class="mt-10 grid gap-5 md:grid-cols-4">
                @foreach ([
                    ['01', 'Patient asks', 'A patient starts on WhatsApp or your hospital website.'],
                    ['02', 'MediDesk responds', 'The assistant answers using your hospital-approved information.'],
                    ['03', 'Patient books & pays', 'Available slots and M-Pesa deposits help complete the request.'],
                    ['04', 'Doctor sees it', 'The appointment appears in the doctor workspace for follow-up.'],
                ] as [$number, $title, $description])
                    <li class="relative rounded-2xl border border-slate-200 bg-white p-6"><span class="text-sm font-extrabold tracking-widest text-[#1a5276]">{{ $number }}</span><h3 class="mt-4 text-lg font-bold text-[#0a2f44]">{{ $title }}</h3><p class="mt-2 leading-7 text-slate-600">{{ $description }}</p></li>
                @endforeach
            </ol>
        </section>

        <section id="demo" aria-labelledby="demo-video-title" class="bg-[#0a2f44] py-16 text-white sm:py-20">
            <div class="mx-auto grid max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] items-center gap-10 px-4 sm:px-6 lg:grid-cols-[.8fr_1.2fr] lg:px-8">
                <div><p class="text-sm font-extrabold uppercase tracking-[.18em] text-cyan-200">See the experience</p><h2 id="demo-video-title" class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">A front desk that never clocks out</h2><p class="mt-4 leading-7 text-slate-300">A short product walkthrough is coming soon. Book a live demo to see the patient chat and staff workflow today.</p><a href="mailto:sales@axiomforge.co.ke?subject=MediDesk%20AI%20product%20walkthrough" class="mt-6 inline-flex rounded-xl bg-white px-5 py-3 font-bold text-[#0a2f44] transition hover:bg-cyan-50">Request a 3-minute walkthrough</a></div>
                <div class="grid min-h-64 place-items-center rounded-3xl border border-white/15 bg-gradient-to-br from-white/10 to-white/5 p-8 text-center shadow-2xl"><div><span class="mx-auto grid h-16 w-16 place-items-center rounded-full border border-white/25 bg-white/10 text-2xl" aria-hidden="true">▶</span><p class="mt-4 font-bold">Product demo video</p><p class="mt-1 text-sm text-slate-300">3-minute walkthrough · coming soon</p></div></div>
            </div>
        </section>

        <section aria-labelledby="screens-title" class="mx-auto max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
            <div class="mx-auto max-w-2xl text-center"><p class="text-sm font-extrabold uppercase tracking-[.18em] text-[#1a5276]">Designed around real workflows</p><h2 id="screens-title" class="mt-3 text-3xl font-extrabold tracking-tight text-[#0a2f44] sm:text-4xl">A clear view for every conversation</h2></div>
            <div class="mt-10 grid gap-5 lg:grid-cols-3">
                <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div class="grid min-h-52 place-items-center bg-gradient-to-br from-emerald-50 to-cyan-50 p-5"><div class="w-full max-w-xs rounded-2xl border border-slate-200 bg-white p-4 shadow-lg"><p class="text-xs font-bold text-emerald-700">PATIENT CHAT</p><p class="mt-3 max-w-[85%] rounded-xl bg-slate-100 p-3 text-xs text-slate-700">Can I book a visit for tomorrow?</p><p class="mt-2 ml-auto max-w-[90%] rounded-xl bg-emerald-50 p-3 text-xs text-slate-700">Let me check the available times for you.</p></div></div><div class="p-5"><h3 class="font-bold text-[#0a2f44]">Patient chat</h3><p class="mt-1 text-sm leading-6 text-slate-600">Helpful answers and guided appointment requests.</p></div></article>
                <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div class="grid min-h-52 place-items-center bg-gradient-to-br from-sky-50 to-indigo-50 p-5"><div class="w-full max-w-xs rounded-xl border border-slate-200 bg-white p-4 shadow-lg"><p class="text-xs font-bold text-slate-500">DOCTOR DASHBOARD</p><div class="mt-4 grid grid-cols-3 gap-2"><span class="rounded-lg bg-slate-50 p-2 text-center text-xs">Today<br><b class="text-lg">12</b></span><span class="rounded-lg bg-slate-50 p-2 text-center text-xs">Week<br><b class="text-lg">38</b></span><span class="rounded-lg bg-slate-50 p-2 text-center text-xs">Next<br><b class="text-lg">06</b></span></div><div class="mt-3 h-2 rounded bg-emerald-100"></div><div class="mt-2 h-2 w-4/5 rounded bg-slate-100"></div></div></div><div class="p-5"><h3 class="font-bold text-[#0a2f44]">Doctor dashboard</h3><p class="mt-1 text-sm leading-6 text-slate-600">Appointments, availability, and patient context together.</p></div></article>
                <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div class="grid min-h-52 place-items-center bg-gradient-to-br from-amber-50 to-rose-50 p-5"><div class="w-full max-w-xs rounded-xl border border-slate-200 bg-white p-4 shadow-lg"><p class="text-xs font-bold text-slate-500">ILLUSTRATIVE DASHBOARD</p><div class="mt-4 grid grid-cols-2 gap-2"><span class="rounded-lg bg-slate-50 p-3 text-xs">Conversations<b class="mt-1 block text-xl text-[#0a2f44]">248</b></span><span class="rounded-lg bg-slate-50 p-3 text-xs">Appointments<b class="mt-1 block text-xl text-[#1a5276]">64</b></span></div><p class="mt-3 rounded-lg bg-amber-50 p-2 text-xs text-amber-800">Example queue · 2 waiting</p></div></div><div class="p-5"><h3 class="font-bold text-[#0a2f44]">Admin dashboard</h3><p class="mt-1 text-sm leading-6 text-slate-600">A focused snapshot of requests and follow-up for your hospital.</p></div></article>
            </div>
        </section>

        <section id="pricing" class="scroll-mt-24 bg-[#f4f8fb] py-16 sm:py-20">
            <div class="mx-auto max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] px-4 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center"><p class="text-sm font-extrabold uppercase tracking-[.18em] text-[#1a5276]">Straightforward plans</p><h2 class="mt-3 text-3xl font-extrabold tracking-tight text-[#0a2f44] sm:text-4xl">Choose the right start for your hospital</h2><p class="mt-4 text-slate-600">Every plan includes implementation support tailored to your team.</p></div>
                <div class="mt-10 grid items-stretch gap-5 lg:grid-cols-3">
                    @foreach ([
                        ['Starter', 'KES 50,000', 'KES 15,000', ['24/7 AI assistant', 'WhatsApp and web chat', 'Hospital knowledge base']],
                        ['Professional', 'KES 75,000', 'KES 35,000', ['Everything in Starter', 'Appointment booking', 'Doctor dashboard']],
                        ['Enterprise', 'KES 100,000+', 'KES 75,000+', ['Everything in Professional', 'M-Pesa, SMS and escalation', 'Advanced integrations']],
                    ] as [$plan, $setup, $monthly, $items])
                        <article class="flex flex-col rounded-2xl border {{ $plan === 'Professional' ? 'border-[#1a5276] ring-2 ring-[#1a5276]/10' : 'border-slate-200' }} bg-white p-6 shadow-sm">
                            @if ($plan === 'Professional')<span class="mb-3 w-fit rounded-full bg-cyan-50 px-3 py-1 text-xs font-extrabold uppercase tracking-wide text-[#1a5276]">Popular</span>@endif
                            <h3 class="text-lg font-bold text-[#0a2f44]">{{ $plan }}</h3><p class="mt-4 text-3xl font-extrabold tracking-tight text-[#0a2f44]">{{ $setup }}<span class="text-base font-semibold text-slate-500"> setup</span></p><p class="mt-2 text-sm text-slate-600">then <strong>{{ $monthly }}/mo</strong></p>
                            <ul class="mt-6 grid gap-3 text-sm text-slate-600">@foreach ($items as $item)<li class="flex gap-2"><span class="font-bold text-emerald-600" aria-hidden="true">✓</span><span>{{ $item }}</span></li>@endforeach</ul>
                            <a href="mailto:sales@axiomforge.co.ke?subject={{ rawurlencode('MediDesk AI '.$plan.' plan') }}" class="mt-7 inline-flex justify-center rounded-xl {{ $plan === 'Professional' ? 'bg-[#1a5276] text-white hover:bg-[#0a2f44]' : 'border border-slate-300 text-[#0a2f44] hover:bg-slate-50' }} px-4 py-3 text-sm font-bold transition">Talk to our team</a>
                        </article>
                    @endforeach
                </div>
                <p class="mt-6 text-center text-sm text-slate-600">Includes hosting, updates, and support. Final scope is confirmed during your demo.</p>
            </div>
        </section>

        <section aria-labelledby="testimonials-title" class="mx-auto max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
            <div class="rounded-3xl border border-dashed border-slate-300 bg-slate-50 px-6 py-12 text-center">
                <p class="text-sm font-extrabold uppercase tracking-[.18em] text-[#1a5276]">Testimonials</p>
                <h2 id="testimonials-title" class="mt-3 text-3xl font-extrabold tracking-tight text-[#0a2f44]">Built with hospital teams</h2>
                <p class="mx-auto mt-4 max-w-2xl leading-7 text-slate-600">Customer stories are coming soon. Book a demo to see the product and share what your team needs.</p>
            </div>
        </section>

        <section id="faq" class="scroll-mt-24 mx-auto max-w-4xl px-4 py-16 sm:px-6 sm:py-20">
            <div class="text-center"><p class="text-sm font-extrabold uppercase tracking-[.18em] text-[#1a5276]">Questions</p><h2 class="mt-3 text-3xl font-extrabold tracking-tight text-[#0a2f44] sm:text-4xl">Frequently asked questions</h2></div>
            <div class="mt-9 divide-y divide-slate-200 border-y border-slate-200">
                @foreach ([
                    ['Can MediDesk AI connect to our HMIS?', 'Integration options depend on your HMIS and available interfaces. We assess the technical requirements during onboarding before promising a connection.'],
                    ['What technology do patients need?', 'Patients can use a web browser or a supported hospital WhatsApp Business account; they do not need to install a separate app.'],
                    ['Can a staff member take over a conversation?', 'Yes. The escalation queue gives authorized staff the conversation context and a way to respond.'],
                    ['How is hospital information protected?', 'Hospital records are tenant-scoped, and access to staff tools is role-restricted. Security requirements are reviewed with each hospital during implementation.'],
                    ['How long does setup take?', 'Setup depends on your hospital information, integrations, and staff workflows. We confirm a delivery plan during your demo.'],
                    ['Can our hospital try MediDesk AI first?', 'Book a demo to walk through the patient and staff experiences and discuss a trial that fits your setup.'],
                ] as [$question, $answer])
                    <details class="group py-5"><summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-bold text-[#0a2f44]"><span>{{ $question }}</span><span class="text-xl text-[#1a5276] transition group-open:rotate-45" aria-hidden="true">+</span></summary><p class="mt-3 max-w-3xl leading-7 text-slate-600">{{ $answer }}</p></details>
                @endforeach
            </div>
        </section>

        <section id="demo" class="scroll-mt-24 bg-gradient-to-r from-[#0a2f44] to-[#1a5276] px-4 py-16 text-white sm:px-6 sm:py-20">
            <div class="mx-auto grid max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] gap-10 lg:grid-cols-2 lg:items-start">
                <div class="max-w-2xl">
                    <p class="text-sm font-extrabold uppercase tracking-[.18em] text-cyan-200">Let's make care easier to reach</p>
                    <h2 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">Ready to give your patients a 24/7 digital front desk?</h2>
                    <p class="mt-4 max-w-2xl leading-7 text-slate-200">Request a free demo and our team will follow up to learn about your hospital and arrange a walkthrough.</p>
                    @if (filled(config('pearlie.marketing.sales_whatsapp')))
                        <a href="https://wa.me/{{ preg_replace('/[^\\d]/', '', config('pearlie.marketing.sales_whatsapp')) }}" class="mt-6 inline-flex rounded-xl border border-white/30 px-6 py-3.5 font-bold text-white transition hover:bg-white/10">WhatsApp sales</a>
                    @endif
                </div>
                <form method="POST" action="{{ route('marketing.demo.store') }}" class="grid gap-5 rounded-2xl bg-white p-6 text-slate-900 shadow-2xl sm:p-8">
                    @csrf
                    @if (session('demo_request_sent'))
                        <p role="status" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 font-semibold text-emerald-800">Thanks — your demo request has been sent. Our team will be in touch.</p>
                    @endif
                    @if ($errors->any())
                        <p role="alert" class="rounded-lg border border-rose-200 bg-rose-50 p-4 font-semibold text-rose-800">Please check the form and correct the highlighted details.</p>
                    @endif
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="grid gap-2 text-sm font-semibold text-slate-700">Your name
                            <input name="name" autocomplete="name" required maxlength="120" value="{{ old('name') }}" class="rounded-lg border border-slate-300 px-3 py-2.5 text-base font-normal text-slate-900 focus:border-[#1a5276] focus:outline-none focus:ring-2 focus:ring-[#1a5276]/20">
                            @error('name')<span class="text-sm font-medium text-rose-700">{{ $message }}</span>@enderror
                        </label>
                        <label class="grid gap-2 text-sm font-semibold text-slate-700">Work email
                            <input type="email" name="email" autocomplete="email" required maxlength="255" value="{{ old('email') }}" class="rounded-lg border border-slate-300 px-3 py-2.5 text-base font-normal text-slate-900 focus:border-[#1a5276] focus:outline-none focus:ring-2 focus:ring-[#1a5276]/20">
                            @error('email')<span class="text-sm font-medium text-rose-700">{{ $message }}</span>@enderror
                        </label>
                    </div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="grid gap-2 text-sm font-semibold text-slate-700">Hospital or organization
                            <input name="organization" autocomplete="organization" required maxlength="180" value="{{ old('organization') }}" class="rounded-lg border border-slate-300 px-3 py-2.5 text-base font-normal text-slate-900 focus:border-[#1a5276] focus:outline-none focus:ring-2 focus:ring-[#1a5276]/20">
                            @error('organization')<span class="text-sm font-medium text-rose-700">{{ $message }}</span>@enderror
                        </label>
                        <label class="grid gap-2 text-sm font-semibold text-slate-700">Phone (optional)
                            <input type="tel" name="phone" autocomplete="tel" maxlength="40" value="{{ old('phone') }}" class="rounded-lg border border-slate-300 px-3 py-2.5 text-base font-normal text-slate-900 focus:border-[#1a5276] focus:outline-none focus:ring-2 focus:ring-[#1a5276]/20">
                            @error('phone')<span class="text-sm font-medium text-rose-700">{{ $message }}</span>@enderror
                        </label>
                    </div>
                    <label class="grid gap-2 text-sm font-semibold text-slate-700">What would you like to see? (optional)
                        <textarea name="message" rows="3" maxlength="2000" class="rounded-lg border border-slate-300 px-3 py-2.5 text-base font-normal text-slate-900 focus:border-[#1a5276] focus:outline-none focus:ring-2 focus:ring-[#1a5276]/20">{{ old('message') }}</textarea>
                        @error('message')<span class="text-sm font-medium text-rose-700">{{ $message }}</span>@enderror
                    </label>
                    <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-[#1a5276] px-6 py-3.5 font-bold text-white transition hover:bg-[#0a2f44] focus:outline-none focus:ring-2 focus:ring-[#1a5276] focus:ring-offset-2">Request my free demo</button>
                </form>
            </div>
        </section>
    </main>

    <footer class="bg-[#071f2d] text-slate-300">
        <div class="mx-auto grid max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] gap-10 px-4 py-12 sm:px-6 md:grid-cols-3 lg:px-8">
            <div><a href="#home" class="text-xl font-extrabold text-white">MediDesk AI</a><p class="mt-2 text-sm text-slate-400">By AxiomForge Digital Solutions</p><p class="mt-4 max-w-sm text-sm leading-6 text-slate-400">A digital front desk for more accessible, connected hospital experiences.</p></div>
            <div><h2 class="text-sm font-bold uppercase tracking-wider text-white">Explore</h2><ul class="mt-4 grid gap-3 text-sm"><li><a class="hover:text-white" href="#features">Features</a></li><li><a class="hover:text-white" href="#how-it-works">How It Works</a></li><li><a class="hover:text-white" href="#demo">Demo</a></li><li><a class="hover:text-white" href="#pricing">Pricing</a></li><li><a class="hover:text-white" href="#faq">FAQ</a></li></ul></div>
            <div><h2 class="text-sm font-bold uppercase tracking-wider text-white">Talk to our team</h2><ul class="mt-4 grid gap-3 text-sm"><li><a class="hover:text-white" href="mailto:sales@axiomforge.co.ke">sales@axiomforge.co.ke</a></li>@if (filled(config('pearlie.marketing.sales_whatsapp')))<li><a class="hover:text-white" href="tel:{{ config('pearlie.marketing.sales_whatsapp') }}">{{ config('pearlie.marketing.sales_whatsapp') }}</a></li><li><a class="hover:text-white" href="https://wa.me/{{ preg_replace('/[^\\d]/', '', config('pearlie.marketing.sales_whatsapp')) }}">WhatsApp sales</a></li>@endif</ul></div>
        </div>
        <div class="border-t border-white/10"><div class="mx-auto flex max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] flex-col gap-2 px-4 py-4 text-xs text-slate-400 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8"><p>© {{ date('Y') }} MediDesk AI by AxiomForge Digital Solutions.</p><a class="hover:text-white" href="mailto:sales@axiomforge.co.ke">Contact sales</a></div></div>
    </footer>
@endsection
